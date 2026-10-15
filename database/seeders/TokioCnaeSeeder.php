<?php

namespace Database\Seeders;

use App\Models\AtividadeEconomica;
use App\Models\AtividadeEconomicaCodigo;
use App\Models\CatalogoAtividadeImportacao;
use Illuminate\Database\Seeder;
use JsonException;
use RuntimeException;

class TokioCnaeSeeder extends Seeder
{
    public function run(): void
    {
        $document = $this->readCatalog();
        $catalog = $document['registros'];
        $catalogCodes = array_merge(...array_column($catalog, 'codigos'));
        $source = $this->readSource($document['sha256'], count($catalog), count($catalogCodes));

        $import = (new AtividadeEconomica)->getConnection()->transaction(function () use ($catalog, $catalogCodes, $source): CatalogoAtividadeImportacao {
            $missingCodes = AtividadeEconomicaCodigo::query()
                ->where('seguradora', 'tokio')
                ->orderBy('codigo')
                ->pluck('codigo')
                ->diff($catalogCodes)
                ->values()
                ->all();

            foreach (array_chunk($catalog, 100) as $batch) {
                $activities = array_map(fn (array $row): array => [
                    'descricao' => $row['descricao'],
                    'descricao_normalizada' => $row['descricao_normalizada'],
                    'descricao_busca' => AtividadeEconomica::normalizarBusca($row['descricao']),
                    'ativo' => true,
                ], $batch);

                AtividadeEconomica::query()->upsert(
                    $activities,
                    ['descricao_normalizada'],
                    ['descricao', 'descricao_normalizada', 'descricao_busca'],
                );

                $ids = AtividadeEconomica::query()
                    ->whereIn('descricao_normalizada', array_column($batch, 'descricao_normalizada'))
                    ->pluck('id', 'descricao_normalizada');
                $codes = [];

                foreach ($batch as $row) {
                    $id = $ids->get($row['descricao_normalizada']);

                    if ($id === null) {
                        throw new RuntimeException('Não foi possível localizar a atividade importada: '.$row['descricao']);
                    }

                    foreach ($row['codigos'] as $code) {
                        $codes[] = [
                            'atividade_economica_id' => $id,
                            'seguradora' => 'tokio',
                            'codigo' => $code,
                            'ativo' => true,
                        ];
                    }
                }

                foreach (array_chunk($codes, 100) as $codeBatch) {
                    AtividadeEconomicaCodigo::query()->upsert(
                        $codeBatch,
                        ['seguradora', 'codigo'],
                        ['atividade_economica_id'],
                    );
                }
            }

            return CatalogoAtividadeImportacao::query()->create([
                ...$source,
                'seguradora' => 'tokio',
                'arquivo_catalogo' => basename($this->catalogPath()),
                'codigos_ausentes' => $missingCodes,
                'revisao_necessaria' => $missingCodes !== [],
            ]);
        });

        $this->command?->info("Importação CNAE Tokio #{$import->id}: {$import->quantidade_descricoes} descrições e {$import->quantidade_codigos} códigos.");

        if ($import->revisao_necessaria) {
            $count = count($import->codigos_ausentes);
            $this->command?->warn("{$count} códigos ausentes registrados para revisão na importação #{$import->id}. Nenhum registro foi desativado.");
        }
    }

    protected function catalogPath(): string
    {
        return database_path('seeders/tokio_cnae.csv');
    }

    /** @return array{registros: list<array{descricao: string, descricao_normalizada: string, codigos: list<string>}>, sha256: string} */
    private function readCatalog(): array
    {
        $path = $this->catalogPath();

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Catálogo CNAE Tokio não encontrado ou sem permissão de leitura.');
        }

        $contents = file_get_contents($path);
        $file = fopen('php://temp', 'w+b');

        if ($contents === false || $file === false) {
            throw new RuntimeException('Não foi possível abrir o catálogo CNAE Tokio.');
        }

        fwrite($file, $contents);
        rewind($file);

        $catalog = [];
        $descriptions = [];
        $codes = [];
        $line = 1;

        try {
            if (fgetcsv($file, 0, ',', '"', '') !== ['descricao', 'codigos_cnae']) {
                throw new RuntimeException('Cabeçalho inválido no catálogo CNAE Tokio.');
            }

            while (($row = fgetcsv($file, 0, ',', '"', '')) !== false) {
                $line++;

                if (count($row) !== 2 || ! is_string($row[0]) || ! is_string($row[1])
                    || ! mb_check_encoding($row[0], 'UTF-8')
                    || str_contains($row[0], "\u{FFFD}")
                    || trim($row[0]) === '' || trim($row[0]) !== $row[0]
                    || mb_strlen($row[0], 'UTF-8') > 255
                    || preg_match('/\A[0-9]{5}(?:\|[0-9]{5})*\z/', $row[1]) !== 1) {
                    throw new RuntimeException("Registro inválido no catálogo CNAE Tokio, linha {$line}.");
                }

                $description = AtividadeEconomica::normalizarDescricao($row[0]);

                if (isset($descriptions[$description])) {
                    throw new RuntimeException("Descrição duplicada no catálogo CNAE Tokio, linha {$line}.");
                }

                $descriptions[$description] = true;
                $rowCodes = explode('|', $row[1]);

                foreach ($rowCodes as $code) {
                    if (isset($codes[$code])) {
                        throw new RuntimeException("Código duplicado no catálogo CNAE Tokio, linha {$line}: {$code}.");
                    }

                    $codes[$code] = true;
                }

                $catalog[] = [
                    'descricao' => $row[0],
                    'descricao_normalizada' => $description,
                    'codigos' => $rowCodes,
                ];
            }
        } finally {
            fclose($file);
        }

        if ($catalog === []) {
            throw new RuntimeException('O catálogo CNAE Tokio está vazio.');
        }

        return ['registros' => $catalog, 'sha256' => hash('sha256', $contents)];
    }

    /** @return array{arquivo_origem: string, sha256_origem: string, sha256_catalogo: string, quantidade_descricoes: int, quantidade_codigos: int} */
    private function readSource(string $catalogHash, int $descriptionCount, int $codeCount): array
    {
        $path = $this->catalogPath().'.source.json';

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Identificação da origem do catálogo CNAE Tokio não encontrada.');
        }

        try {
            $source = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Identificação da origem do catálogo CNAE Tokio inválida.', 0, $exception);
        }

        if (! is_array($source)
            || ! is_string($source['arquivo_origem'] ?? null)
            || trim($source['arquivo_origem']) === ''
            || mb_strlen($source['arquivo_origem'], 'UTF-8') > 255
            || ! is_string($source['sha256_origem'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $source['sha256_origem']) !== 1
            || ($source['sha256_catalogo'] ?? null) !== $catalogHash
            || ($source['quantidade_descricoes'] ?? null) !== $descriptionCount
            || ($source['quantidade_codigos'] ?? null) !== $codeCount) {
            throw new RuntimeException('A origem, o hash ou as contagens do catálogo CNAE Tokio não conferem. Revise o CSV e sua identificação antes de importar.');
        }

        return array_intersect_key($source, array_flip([
            'arquivo_origem', 'sha256_origem', 'sha256_catalogo', 'quantidade_descricoes', 'quantidade_codigos',
        ]));
    }
}
