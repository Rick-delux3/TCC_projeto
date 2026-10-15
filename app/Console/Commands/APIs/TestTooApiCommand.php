<?php

namespace App\Console\Commands\APIs;

use App\Services\Insurance\InsuranceApiAuthenticationCheck;
use Illuminate\Console\Command;

class TestTooApiCommand extends Command
{
    protected $signature = 'too:test-api {--auth=basic : basic (serviço atual) ou headers (OpenAPI fornecido)}';

    protected $description = 'Verifica a autenticação da Too com uma chamada real, sem usar o cache de tokens';

    public function handle(InsuranceApiAuthenticationCheck $check): int
    {
        $mode = (string) $this->option('auth');
        if (! in_array($mode, ['basic', 'headers'], true)) {
            $this->error('Use --auth=basic ou --auth=headers.');

            return self::INVALID;
        }
        $this->info($mode === 'basic' ? 'Too: Basic Auth e grant_type=client_credentials (serviço atual).' : 'Too: headers clientid/clientsecret (OpenAPI fornecido).');
        $result = $check->check('too', $mode);
        if ($result['endpoint'] !== null) {
            $this->line('POST '.$result['endpoint']);
        }
        $this->line('HTTP: '.($result['http_status'] ?? 'sem resposta').' | Duração: '.$result['duration_ms'].' ms');
        $result['success'] ? $this->info($result['message']) : $this->error($result['message']);
        $this->comment('Este teste verifica apenas a autenticação; não valida cotações nem análises.');

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }
}
