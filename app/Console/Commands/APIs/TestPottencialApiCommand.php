<?php

namespace App\Console\Commands\APIs;

use App\Models\InsuranceAnalysis;
use App\Services\Insurance\InsuranceApiAuthenticationCheck;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Console\Output\OutputInterface;

class TestPottencialApiCommand extends Command
{
    protected $signature = 'pottencial:test-api
        {--analise= : ID local da análise Pottencial; exibe requests e responses salvos sem chamar a API}';

    protected $description = 'Testa autenticação Pottencial ou consulta os payloads completos de uma análise salva com --analise';

    private bool $readFailed = false;

    public function handle(InsuranceApiAuthenticationCheck $check): int
    {
        if ($this->option('analise') !== null) {
            return $this->showStoredAnalysis((string) $this->option('analise'));
        }

        $this->info('Pottencial: teste de autenticação via Basic Auth.');
        $result = $check->check('pottencial');
        if ($result['endpoint'] !== null) {
            $this->line('POST '.$result['endpoint']);
        }
        $this->line('HTTP: '.($result['http_status'] ?? 'sem resposta').' | Duração: '.$result['duration_ms'].' ms');
        $result['success'] ? $this->info($result['message']) : $this->error($result['message']);
        $this->comment('Este teste verifica apenas a autenticação; não valida cotações nem análises.');

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }

    private function showStoredAnalysis(string $id): int
    {
        $this->readFailed = false;
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('Informe --analise com um ID local inteiro positivo.');

            return self::INVALID;
        }

        $analysis = InsuranceAnalysis::query()->where('provider', 'pottencial')->with([
            'events' => fn ($query) => $query->orderBy('id'),
            'providerRuns' => fn ($query) => $query->orderBy('id'),
            'providerRuns.round',
            'providerRuns.scenarios' => fn ($query) => $query->orderBy('id'),
            'providerRuns.scenarios.quotes' => fn ($query) => $query->orderBy('id'),
            'providerRuns.scenarios.quotes.events' => fn ($query) => $query->orderBy('id'),
        ])->find($id);
        if ($analysis === null) {
            $this->error('Análise Pottencial não encontrada para este ID local.');

            return self::FAILURE;
        }

        $this->info('Consulta somente de leitura. Dados completos exibidos apenas no terminal.');
        $this->line('Request: request_payload. Response: response_payload e respostas dos eventos.');
        $this->line('Nenhuma API será chamada. Conteúdo não persistido originalmente não pode ser recuperado.');
        $this->dumpRecord('Análise consolidada / legado', $analysis);
        foreach ($analysis->events as $event) {
            $this->dumpRecord('Evento da análise', $event);
        }

        foreach ($analysis->providerRuns as $run) {
            if ($run->round !== null) {
                $this->dumpRecord('Rodada', $run->round, ['lead_snapshot']);
            }
            $this->dumpRecord('Execução Pottencial', $run);
            foreach ($run->scenarios as $scenario) {
                $this->dumpRecord('Cenário', $scenario);
                foreach ($scenario->quotes as $quote) {
                    $this->dumpRecord('Cotação por plano / cenário', $quote);
                    foreach ($quote->events as $event) {
                        $this->dumpRecord('Evento da cotação', $event);
                    }
                }
            }
        }

        return $this->readFailed ? self::FAILURE : self::SUCCESS;
    }

    /** @param list<string> $except */
    private function dumpRecord(string $title, Model $record, array $except = []): void
    {
        $this->newLine();
        $this->info($title.' #'.$record->getKey());
        try {
            $attributes = $record->attributesToArray();
            foreach ($except as $attribute) {
                unset($attributes[$attribute]);
            }
            $json = json_encode($attributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $this->output->writeln($json, OutputInterface::OUTPUT_RAW);
        } catch (\Throwable) {
            $this->readFailed = true;
            $this->error('Não foi possível ler os dados completos deste registro. Verifique sua integridade e a chave de criptografia.');
        }
    }
}
