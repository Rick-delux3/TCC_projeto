<?php

namespace App\Console\Commands;

use App\Models\InsuranceAnalysisBatch;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Console\Output\OutputInterface;

class DebugInsuranceBatchCommand extends Command
{
    protected $signature = 'insurance:debug-batch {batchId : ID do lote de análises}';

    protected $description = 'Exibe no terminal as rodadas, cotações, payloads e respostas persistidas do lote';

    private bool $readFailed = false;

    public function handle(): int
    {
        $this->readFailed = false;
        $batchId = (string) $this->argument('batchId');
        if (! ctype_digit($batchId) || (int) $batchId < 1) {
            $this->error('Informe um ID de lote inteiro positivo.');

            return self::FAILURE;
        }

        $batch = InsuranceAnalysisBatch::with([
            'analyses' => fn ($query) => $query->orderBy('id'),
            'analyses.events' => fn ($query) => $query->orderBy('id'),
            'rounds' => fn ($query) => $query->orderBy('id'),
            'rounds.providerRuns' => fn ($query) => $query->orderBy('id'),
            'rounds.providerRuns.scenarios' => fn ($query) => $query->orderBy('id'),
            'rounds.providerRuns.scenarios.quotes' => fn ($query) => $query->orderBy('id'),
            'rounds.providerRuns.scenarios.quotes.events' => fn ($query) => $query->orderBy('id'),
        ])->find($batchId);

        if (! $batch) {
            $this->error('Batch não encontrado.');

            return self::FAILURE;
        }

        $this->info('Diagnóstico somente de leitura — saída exclusiva no terminal.');
        $this->line('Dados completos já persistidos, sem mascaramento. Nenhuma API será chamada.');
        $this->line('Respostas não armazenadas originalmente não podem ser recuperadas por este comando.');
        $this->dumpRecord('Lote', $batch);

        foreach ($batch->analyses as $analysis) {
            $this->dumpRecord('Análise consolidada / legado', $analysis);
            foreach ($analysis->events as $event) {
                $this->dumpRecord('Evento da análise', $event);
            }
        }

        if ($batch->rounds->isEmpty()) {
            $this->line('Nenhuma rodada da arquitetura de múltiplas cotações registrada neste lote.');
        }

        foreach ($batch->rounds as $round) {
            $this->dumpRecord('Rodada', $round);
            foreach ($round->providerRuns as $run) {
                $provider = $batch->analyses->firstWhere('id', $run->insurance_analysis_id)?->provider ?? 'não identificada';
                $this->dumpRecord('Execução da seguradora '.$provider.' (selected_quote_id identifica a cotação escolhida)', $run);
                foreach ($run->scenarios as $scenario) {
                    $this->dumpRecord('Cenário de pretendentes', $scenario);
                    foreach ($scenario->quotes as $quote) {
                        $this->dumpRecord('Cotação individual por plano / cenário', $quote);
                        foreach ($quote->events as $event) {
                            $this->dumpRecord('Evento da cotação — payload e resposta completos', $event);
                        }
                    }
                }
            }
        }

        return $this->readFailed ? self::FAILURE : self::SUCCESS;
    }

    private function dumpRecord(string $title, Model $record): void
    {
        $this->newLine();
        $this->info($title.' #'.$record->getKey());

        try {
            $json = json_encode(
                $record->attributesToArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
            $this->output->writeln($json, OutputInterface::OUTPUT_RAW);
        } catch (\Throwable $exception) {
            $this->readFailed = true;
            $this->output->writeln(
                'Não foi possível ler este registro: '.json_encode($exception->getMessage(), JSON_INVALID_UTF8_SUBSTITUTE),
                OutputInterface::OUTPUT_RAW
            );
        }
    }
}
