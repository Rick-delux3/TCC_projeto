<?php

namespace App\Console\Commands\APIs;

use App\Services\Insurance\PortoAuthentication;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

class PortoTokenCommand extends Command
{
    protected $signature = 'porto:token {--show : Exibe o token completo no terminal, fora de produção}';

    protected $description = 'Obtém um novo access_token OAuth da Porto, sem criar cotações ou armazenar o token';

    public function handle(PortoAuthentication $authentication): int
    {
        if ($this->option('show') && app()->environment('production')) {
            $this->error('A exibição do token completo não está disponível em produção.');

            return self::FAILURE;
        }

        $result = $authentication->requestToken();
        $this->line('HTTP: '.($result['http_status'] ?? 'sem resposta'));
        if (! $result['success']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);
        $this->line('Validade informada: '.($result['expires_in'] !== null ? $result['expires_in'].' segundos' : 'não informada'));
        if ($this->option('show')) {
            $this->output->writeln($result['access_token'], OutputInterface::OUTPUT_RAW);
        } else {
            $this->comment('Use --show para exibir o token completo.');
        }

        return self::SUCCESS;
    }
}
