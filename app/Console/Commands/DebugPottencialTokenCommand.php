<?php

namespace App\Console\Commands;

use App\Services\PottencialService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DebugPottencialTokenCommand extends Command
{
    /**
     * --fresh: apaga o token em cache e gera outro.
     * --show: mostra o token completo no terminal.
     *
     * Por segurança, se não usar --show, ele mostra apenas uma prévia.
     */
    protected $signature = 'pottencial:token {--fresh} {--show}';

    protected $description = 'Visualiza o access_token gerado pela Pottencial para debug local';

    public function handle(PottencialService $pottencialService): int
    {
        /*
         * Segurança básica:
         * evita mostrar token completo em produção.
         */
        if (app()->environment('production')) {
            $this->error('Este comando não deve ser executado em produção.');
            return self::FAILURE;
        }

        /*
         * Se usar --fresh, apaga o cache e força gerar um novo token.
         */
        if ($this->option('fresh')) {
            Cache::forget('pottencial_access_token');
            $this->warn('Token em cache removido. Um novo token será gerado.');
        }

        $token = $pottencialService->getAccessToken();

        if (!$token) {
            $this->error('Não foi possível gerar o access_token da Pottencial.');
            return self::FAILURE;
        }

        $this->info('Access token gerado com sucesso.');
        $this->line('Tamanho: ' . strlen($token) . ' caracteres');

        /*
         * Mostra o token completo apenas se você pedir explicitamente.
         */
        if ($this->option('show')) {
            $this->warn('Token completo:');
            $this->line($token);
        } else {
            $this->line('Prévia: ' . substr($token, 0, 20) . '...');
            $this->comment('Use --show para exibir o token completo.');
        }

        return self::SUCCESS;
    }
}