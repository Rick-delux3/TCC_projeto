<?php

namespace App\Console\Commands\APIs;

use App\Models\InsuranceAnalysis;
use App\Models\Lead;
use App\Services\Insurance\PottencialAnalysisDiagnostic;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class TestPottencialAnalysisCommand extends Command
{
    protected $signature = 'pottencial:test-analysis
        {--lead= : ID do lead utilizado no teste}
        {--consultas=3 : Máximo de consultas de resultado (1 a 60)}
        {--intervalo=10 : Segundos entre consultas (1 a 60)}';

    protected $description = 'Cria um orçamento real na Pottencial, consulta a análise e retorna JSON, descartando os registros locais de teste';

    public function handle(PottencialAnalysisDiagnostic $diagnostic): int
    {
        $leadId = $this->positiveInteger('lead', PHP_INT_MAX);
        $maxChecks = $this->positiveInteger('consultas', 60);
        $interval = $this->positiveInteger('intervalo', 60);

        if ($leadId === null || $maxChecks === null || $interval === null) {
            $this->writeJson(['success' => false, 'error' => 'Informe --lead com ID positivo; --consultas e --intervalo devem ser inteiros entre 1 e 60.']);

            return self::INVALID;
        }

        $connection = (new InsuranceAnalysis)->getConnection();
        $originalLevel = $connection->transactionLevel();

        try {
            $lead = Lead::query()->find($leadId);
            if (! $lead) {
                $this->writeJson(['success' => false, 'error' => 'Lead não encontrado.', 'lead_id' => $leadId]);

                return self::INVALID;
            }

            $connection->beginTransaction();
            $report = $diagnostic->run($lead, $maxChecks, $interval);
            $this->writeJson($report);

            return $report['success'] ? self::SUCCESS : ($report['incomplete'] ? self::INVALID : self::FAILURE);
        } catch (Throwable $exception) {
            $this->writeJson([
                'success' => false,
                'lead_id' => $leadId,
                'error' => $exception instanceof \LogicException
                    ? $exception->getMessage()
                    : 'Falha ao executar o diagnóstico local.',
                'exception' => $exception::class,
            ]);

            return self::FAILURE;
        } finally {
            if ($connection->transactionLevel() > $originalLevel) {
                $connection->rollBack($originalLevel);
            }
        }
    }

    private function positiveInteger(string $option, int $max): ?int
    {
        $value = filter_var($this->option($option), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);

        return $value === false ? null : $value;
    }

    private function writeJson(array $data): void
    {
        $this->output->writeln(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            OutputInterface::OUTPUT_RAW,
        );
    }
}
