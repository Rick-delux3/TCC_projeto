<?php

namespace App\Console\Commands\APIs;

use Illuminate\Console\Command;

class ImportTokioPartnersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tokio:import-partners {file : Planilha XLSX fornecida pela Tokio} {--apply : Associa e cria cadastros; sem esta opção apenas confere}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Confere parceiros ativos Tokio, associa imobiliárias existentes e cria as ausentes sem ativar formulários';

    /**
     * Execute the console command.
     */
    public function handle(\App\Services\Insurance\TokioPartnerSpreadsheet $reader, \App\Services\Insurance\TokioPartnerImporter $importer): int
    {
        try {
            $path = (string) $this->argument('file');
            $rows = $reader->read($path);
            $source = basename($path).'#'.hash_file('sha256', $path);
            $report = $importer->import($rows, $source, (bool) $this->option('apply'));
            $this->table(['Ação', 'Quantidade'], collect($report)->countBy('action')->map(fn ($count, $action) => [$action, $count])->values()->all());
            $conflicts = collect($report)->where('action', 'conflict');
            if ($conflicts->isNotEmpty()) {
                $this->table(['Linha', 'Código', 'Cadastro', 'Ação', 'Motivo'], $conflicts->values()->all());
            }
            $this->info($this->option('apply') ? 'Importação concluída. Conflitos foram preservados para revisão.' : 'Conferência concluída, sem alterações. Use --apply para gravar.');

            return self::SUCCESS;
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
