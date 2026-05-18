<?php

namespace App\Jobs;

use App\Models\InsuranceAnalysisBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAnalysisResultsEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public int $batchId
    ) {}

    public function handle(): void
    {
        $batch = InsuranceAnalysisBatch::with('lead', 'analyses')->findOrFail($this->batchId);

        if ($batch->email_sent_at) return;

        $lead = $batch->lead;

        if(!$lead || !$lead->email) return;

        // Depois você troca por um Mailable bonito.
        Mail::raw($this->buildMessage($batch), function ($message) use ($batch) {
            $message->to($batch->lead->email)
                ->subject('Resultado da sua análise de Seguro Fiança');
        });

        $batch->update([
            'email_sent_at' => now(),
        ]);
    }

    private function buildMessage(InsuranceAnalysisBatch $batch): string
    {
        $lead = $batch->lead;

        $lines = [];

        $lines[] = "Olá, {$lead->nome}.";
        $lines[] = "";
        $lines[] = "Recebemos o resultado da sua análise de Seguro Fiança Locatícia Residencial.";
        $lines[] = "";

        if ($batch->analyses->isEmpty()) {
            $lines[] = "Nenhuma análise foi encontrada para este lote.";
            $lines[] = "Nossa equipe irá verificar o processamento.";
            $lines[] = "";

            return implode("\n", $lines);
        }

        $lines[] = "Resultados por companhia:";
        $lines[] = "";

        foreach ($batch->analyses as $analysis) {
            $lines[] = "Companhia: " . strtoupper($analysis->provider);
            $lines[] = "Status: " . $this->formatStatus($analysis->status);

            if ($analysis->provider_status) {
                $lines[] = "Status da companhia: {$analysis->provider_status}";
            }

            if ($analysis->quote_id) {
                $lines[] = "Código da cotação: {$analysis->quote_id}";
            }

            if ($analysis->premium_amount) {
                $lines[] = "Orçamento estimado: R$ " . number_format($analysis->premium_amount, 2, ',', '.');
            }

            if ($analysis->error_message) {
                $lines[] = "Observação: {$analysis->error_message}";
            }

            $lines[] = "";
        }

        $lines[] = "Em breve, a imobiliária ou corretora poderá entrar em contato com mais informações.";
        $lines[] = "";
        $lines[] = "Este é um e-mail automático.";

        return implode("\n", $lines);
    }

    private function formatStatus(?string $status): string
    {
        return match ($status) {
            'approved' => 'Aprovado',
            'rejected' => 'Recusado',
            'manual_review' => 'Em negociação',
            'quoted' => 'Cotado',
            'failed' => 'Falha técnica',
            'processing' => 'Em processamento',
            'pending' => 'Pendente',
            default => 'Não informado',
        };
    }
}