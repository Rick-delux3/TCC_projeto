<?php

namespace App\Console\Commands\APIs;

use App\Services\Insurance\InsuranceApiAuthenticationCheck;
use Illuminate\Console\Command;

class TestPottencialApiCommand extends Command
{
    protected $signature = 'pottencial:test-api';

    protected $description = 'Verifica a autenticação da Pottencial com uma chamada real, sem usar o cache de tokens';

    public function handle(InsuranceApiAuthenticationCheck $check): int
    {
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
}
