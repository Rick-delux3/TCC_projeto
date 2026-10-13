<?php

use App\Models\AtividadeEconomica;
use App\Models\AtividadeEconomicaCodigo;
use App\Models\CatalogoAtividadeImportacao;
use Database\Seeders\TokioCnaeSeeder;
use Illuminate\Database\QueryException;

function seedTokioCatalogContents(string $contents, array $sourceOverrides = []): void
{
    $path = tempnam(sys_get_temp_dir(), 'tokio-cnae-');
    file_put_contents($path, $contents);
    $file = fopen($path, 'rb');
    fgetcsv($file, 0, ',', '"', '');
    $descriptionCount = 0;
    $codeCount = 0;

    while (($row = fgetcsv($file, 0, ',', '"', '')) !== false) {
        $descriptionCount++;
        $codeCount += count(explode('|', $row[1] ?? ''));
    }

    fclose($file);
    file_put_contents($path.'.source.json', json_encode(array_replace([
        'arquivo_origem' => 'catalogo-teste.pdf',
        'sha256_origem' => hash('sha256', 'pdf-teste'),
        'sha256_catalogo' => hash('sha256', $contents),
        'quantidade_descricoes' => $descriptionCount,
        'quantidade_codigos' => $codeCount,
    ], $sourceOverrides), JSON_THROW_ON_ERROR));

    try {
        $seeder = new class($path) extends TokioCnaeSeeder
        {
            public function __construct(private string $path) {}

            protected function catalogPath(): string
            {
                return $this->path;
            }
        };
        $seeder->run();
    } finally {
        unlink($path);
        unlink($path.'.source.json');
    }
}

it('imports all unique activities and Tokio codes with their original associations', function (): void {
    $this->seed(TokioCnaeSeeder::class);

    expect(AtividadeEconomica::query()->count())->toBe(1910)
        ->and(AtividadeEconomicaCodigo::query()->where('seguradora', 'tokio')->count())->toBe(2230);

    $mapping = AtividadeEconomicaCodigo::query()->with('atividadeEconomica')->orderBy('codigo')->get()
        ->map(fn (AtividadeEconomicaCodigo $code): string => $code->codigo."\t".$code->atividadeEconomica->descricao."\n")
        ->implode('');

    expect(hash('sha256', $mapping))->toBe('b14014467ae93bce48db4c059605b79437865c666b2c8e3ef4c0fbd20fd736e5');

    $activity = AtividadeEconomica::query()->where('descricao', 'Administração de Cartões de Crédito')->firstOrFail();
    expect($activity->codigos()->orderBy('codigo')->pluck('codigo')->all())->toBe(['23882', '23883']);

    $import = CatalogoAtividadeImportacao::query()->sole();
    expect($import->arquivo_origem)->toBe('geral-tabela-cnae.pdf')
        ->and($import->sha256_origem)->toBe('d0f39bf5957379aba1890450a21a37b1c891c58b313de204f8a9a2e2b230895f')
        ->and($import->arquivo_catalogo)->toBe('tokio_cnae.csv')
        ->and($import->sha256_catalogo)->toBe(hash_file('sha256', database_path('seeders/tokio_cnae.csv')))
        ->and($import->quantidade_descricoes)->toBe(1910)
        ->and($import->quantidade_codigos)->toBe(2230)
        ->and($import->codigos_ausentes)->toBe([])
        ->and($import->revisao_necessaria)->toBeFalse()
        ->and($import->created_at)->not->toBeNull();
});

it('can repeat the complete import without changing ids or reactivating disabled entries', function (): void {
    $this->seed(TokioCnaeSeeder::class);
    $activityIds = AtividadeEconomica::query()->orderBy('id')->pluck('id')->all();
    $codeIds = AtividadeEconomicaCodigo::query()->orderBy('id')->pluck('id')->all();
    $code = AtividadeEconomicaCodigo::query()->where('codigo', '23872')->firstOrFail();
    $createdAt = $code->created_at->toDateTimeString();
    $code->update(['ativo' => false]);
    $code->atividadeEconomica->update(['ativo' => false]);

    $this->travel(1)->days();
    $this->seed(TokioCnaeSeeder::class);

    expect(AtividadeEconomica::query()->orderBy('id')->pluck('id')->all())->toBe($activityIds)
        ->and(AtividadeEconomicaCodigo::query()->orderBy('id')->pluck('id')->all())->toBe($codeIds)
        ->and($code->fresh()->created_at->toDateTimeString())->toBe($createdAt)
        ->and($code->fresh()->ativo)->toBeFalse()
        ->and($code->fresh()->atividadeEconomica->ativo)->toBeFalse()
        ->and(CatalogoAtividadeImportacao::query()->count())->toBe(2);
});

it('updates existing associations and preserves absent codes and other insurers', function (): void {
    seedTokioCatalogContents("descricao,codigos_cnae\nAtividade original,23872|23873\nAtividade ausente,23874\n");
    $existingCode = AtividadeEconomicaCodigo::query()->where('codigo', '23872')->firstOrFail();
    $otherInsurer = AtividadeEconomicaCodigo::factory()->create(['seguradora' => 'outra', 'codigo' => '23872']);

    seedTokioCatalogContents("descricao,codigos_cnae\nAtividade corrigida,23872|23875\n");

    expect($existingCode->fresh()->atividadeEconomica->descricao)->toBe('Atividade corrigida')
        ->and(AtividadeEconomicaCodigo::query()->where('seguradora', 'tokio')->count())->toBe(4)
        ->and(AtividadeEconomicaCodigo::query()->where('seguradora', 'tokio')->whereIn('codigo', ['23873', '23874'])->where('ativo', true)->count())->toBe(2)
        ->and($otherInsurer->fresh()->atividade_economica_id)->toBe($otherInsurer->atividade_economica_id)
        ->and(AtividadeEconomica::query()->where('descricao', 'Atividade ausente')->firstOrFail()->ativo)->toBeTrue();

    $imports = CatalogoAtividadeImportacao::query()->orderBy('id')->get();
    expect($imports[0]->codigos_ausentes)->toBe([])
        ->and($imports[1]->codigos_ausentes)->toBe(['23873', '23874'])
        ->and($imports[1]->revisao_necessaria)->toBeTrue()
        ->and($imports[1]->sha256_catalogo)->not->toBe($imports[0]->sha256_catalogo);

    seedTokioCatalogContents("descricao,codigos_cnae\nAtividade corrigida,23872|23875\nAtividade original,23873\nAtividade ausente,23874\n");
    expect(CatalogoAtividadeImportacao::query()->latest('id')->firstOrFail()->revisao_necessaria)->toBeFalse()
        ->and($imports[1]->fresh()->codigos_ausentes)->toBe(['23873', '23874']);
});

it('rejects an invalid catalog before writing any records', function (string $contents): void {
    expect(fn () => seedTokioCatalogContents($contents))->toThrow(RuntimeException::class);

    expect(AtividadeEconomica::query()->count())->toBe(0)
        ->and(AtividadeEconomicaCodigo::query()->count())->toBe(0)
        ->and(CatalogoAtividadeImportacao::query()->count())->toBe(0);
})->with([
    'wrong header' => "codigo,descricao\n23872,Atividade\n",
    'empty catalog' => "descricao,codigos_cnae\n",
    'empty description' => "descricao,codigos_cnae\n,23872\n",
    'empty code' => "descricao,codigos_cnae\nAtividade,\n",
    'invalid UTF-8' => "descricao,codigos_cnae\nAtividade\xFF,23872\n",
    'too long' => 'descricao,codigos_cnae'."\n".str_repeat('a', 256).",23872\n",
    'extra column' => "descricao,codigos_cnae\nAtividade,23872,extra\n",
    'invalid code' => "descricao,codigos_cnae\nAtividade,abc\n",
    'case insensitive duplicate' => "descricao,codigos_cnae\nIndústria,23872\nINDÚSTRIA,23873\n",
    'code shared across descriptions' => "descricao,codigos_cnae\nPrimeira,23872\nSegunda,23872\n",
    'duplicate within group' => "descricao,codigos_cnae\nAtividade,23872|23872\n",
    'late invalid row' => "descricao,codigos_cnae\n".implode('', array_map(fn (int $index): string => "Atividade {$index},".(24000 + $index)."\n", range(1, 101)))."Inválida,abc\n",
]);

it('enforces unique descriptions and insurer codes at database level', function (): void {
    $activity = AtividadeEconomica::factory()->create(['descricao' => 'Indústria']);
    expect(fn () => AtividadeEconomica::factory()->create(['descricao' => 'INDÚSTRIA']))->toThrow(QueryException::class);

    AtividadeEconomicaCodigo::factory()->create(['atividade_economica_id' => $activity->id, 'codigo' => '23872']);
    expect(fn () => AtividadeEconomicaCodigo::factory()->create(['atividade_economica_id' => $activity->id, 'codigo' => '23872']))->toThrow(QueryException::class);
    expect(fn () => $activity->delete())->toThrow(QueryException::class);
});

it('rejects unverified source metadata without changing the existing catalog or history', function (array $overrides): void {
    seedTokioCatalogContents("descricao,codigos_cnae\nOriginal,23872\n");
    expect(fn () => seedTokioCatalogContents("descricao,codigos_cnae\nNova,23873\n", $overrides))->toThrow(RuntimeException::class);

    expect(AtividadeEconomica::query()->pluck('descricao')->all())->toBe(['Original'])
        ->and(AtividadeEconomicaCodigo::query()->pluck('codigo')->all())->toBe(['23872'])
        ->and(CatalogoAtividadeImportacao::query()->count())->toBe(1);
})->with([
    'changed csv hash' => [['sha256_catalogo' => str_repeat('0', 64)]],
    'missing source filename' => [['arquivo_origem' => '']],
    'invalid pdf hash' => [['sha256_origem' => 'invalid']],
    'incorrect description count' => [['quantidade_descricoes' => 2]],
    'incorrect code count' => [['quantidade_codigos' => 2]],
]);

it('rolls back the catalog if recording the import history fails', function (): void {
    CatalogoAtividadeImportacao::creating(function (): void {
        throw new RuntimeException('Falha simulada ao gravar histórico.');
    });

    try {
        expect(fn () => seedTokioCatalogContents("descricao,codigos_cnae\nAtividade,23872\n"))
            ->toThrow(RuntimeException::class, 'Falha simulada ao gravar histórico.');
    } finally {
        CatalogoAtividadeImportacao::flushEventListeners();
    }

    expect(AtividadeEconomica::query()->count())->toBe(0)
        ->and(AtividadeEconomicaCodigo::query()->count())->toBe(0)
        ->and(CatalogoAtividadeImportacao::query()->count())->toBe(0);
});
