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

    public function __construct(
        public int $batchId
    ) {}

    public function handle(): void
    {
        $batch = InsuranceAnalysisBatch::with('lead', 'analyses')->findOrFail($this->batchId);

        if ($batch->email_sent_at) {
            return;
        }

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
        $lines = [];

        $lines[] = "Olá, {$batch->lead->nome}.";
        $lines[] = "";
        $lines[] = "Recebemos os resultados da sua análise de Seguro Fiança:";
        $lines[] = "";

        foreach ($batch->analyses as $analysis) {
            $lines[] = strtoupper($analysis->provider);
            $lines[] = "Status: {$analysis->status}";

            if ($analysis->premium_amount) {
                $lines[] = "Orçamento estimado: R$ " . number_format($analysis->premium_amount, 2, ',', '.');
            }

            $lines[] = "";
        }

        $lines[] = "A imobiliária/corretora também recebeu esses resultados no dashboard.";

        return implode("\n", $lines);
    }
}