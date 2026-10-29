<?php

namespace App\Console\Commands\APIs;

use App\Services\Insurance\TokioQuoteDiagnostic;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

class TestTokioQuoteCommand extends Command
{
    protected $signature = 'tokio:test-quote
        {--cenario= : aprovada, recusada ou encaminhada; CPFs aleatórios não garantem a decisão esperada}
        {--cpf-aprovado=1 : CPF homologado 1, 2 ou 3, utilizado no cenário aprovada}';

    protected $description = 'Autentica e cria uma cotação de teste PF residencial no Aceite W da Tokio; retorna o diagnóstico em JSON';

    public function handle(TokioQuoteDiagnostic $diagnostic): int
    {
        $scenario = $this->option('cenario');
        $cpfIndex = (string) $this->option('cpf-aprovado');
        if (! in_array($scenario, ['aprovada', 'recusada', 'encaminhada'], true) || ! in_array($cpfIndex, ['1', '2', '3'], true)) {
            $this->writeJson(['success' => false, 'error' => 'Informe --cenario=aprovada, --cenario=recusada ou --cenario=encaminhada; --cpf-aprovado aceita 1, 2 ou 3.']);

            return self::INVALID;
        }

        if (app()->environment('production')) {
            $this->writeJson(['success' => false, 'error' => 'Este diagnóstico está disponível somente fora de produção.']);

            return self::FAILURE;
        }

        $report = $diagnostic->run($scenario, (int) $cpfIndex);
        $this->writeJson($report);

        return $report['success'] ? self::SUCCESS : self::FAILURE;
    }

    private function writeJson(array $report): void
    {
        $this->output->writeln(
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            OutputInterface::OUTPUT_RAW,
        );
    }
}
