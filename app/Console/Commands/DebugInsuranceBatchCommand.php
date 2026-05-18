<?php

namespace App\Console\Commands;

use App\Models\InsuranceAnalysisBatch;
use Illuminate\Console\Command;

class DebugInsuranceBatchCommand extends Command
{
    protected $signature = 'insurance:debug-batch {batchId}';

    protected $description = 'Mostra informações de debug de um lote de análises';

    public function handle(): int
    {
        $batch = InsuranceAnalysisBatch::with([
            'lead',
            'analyses.events',
        ])->find($this->argument('batchId'));

        if (!$batch) {
            $this->error('Batch não encontrado.');
            return self::FAILURE;
        }

        $this->info("Batch ID: {$batch->id}");
        $this->line("Status: {$batch->status}");
        $this->line("Lead: {$batch->lead?->nome} <{$batch->lead?->email}>");
        $this->line("Total providers: {$batch->total_providers}");
        $this->line("Completed providers: {$batch->completed_providers}");
        $this->line("Failed providers: {$batch->failed_providers}");
        $this->newLine();

        if ($batch->analyses->isEmpty()) {
            $this->warn('Nenhuma análise vinculada a este batch.');
            return self::SUCCESS;
        }

        foreach ($batch->analyses as $analysis) {
            $this->info("Analysis ID: {$analysis->id}");
            $this->line("Provider: {$analysis->provider}");
            $this->line("Status interno: {$analysis->status}");
            $this->line("Provider status: {$analysis->provider_status}");
            $this->line("Quote ID: {$analysis->quote_id}");
            $this->line("Premium: {$analysis->premium_amount}");
            $this->line("Erro: {$analysis->error_message}");
            $this->line("Response payload:");
            $this->line(json_encode($analysis->response_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();

            $this->line('Eventos:');

            foreach ($analysis->events as $event) {
                $this->line("- {$event->event_type} | {$event->status} | {$event->message}");
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }
}