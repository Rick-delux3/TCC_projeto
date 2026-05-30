<?php

namespace App\Jobs;

use App\Models\InsuranceAnalysisBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

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
        $batch = InsuranceAnalysisBatch::with([
            'lead',
            'analyses',
        ])->findOrFail($this->batchId);

        /*
         * Evita envio duplicado.
         */
        if ($batch->email_sent_at) {
            return;
        }

        $lead = $batch->lead;

        if (!$lead || !$lead->email) {
            $batch->update([
                'email_status' => 'failed',
                'email_failed_at' => now(),
                'email_error' => 'Lead sem e-mail válido.',
            ]);

            return;
        }

        try {
            $body = $this->buildMessage($batch);

            Mail::raw($body, function ($message) use ($lead) {
                $message->to($lead->email)
                    ->subject('Resultado da sua análise de Seguro Fiança');
            });

            $batch->update([
                'email_sent_at' => now(),
                'email_status' => 'sent',
                'email_failed_at' => null,
                'email_error' => null,
            ]);
        } catch (Throwable $e) {
            /*
             * Salva o erro no banco para você ver no dashboard/admin depois.
             */
            $batch->update([
                'email_status' => 'failed',
                'email_failed_at' => now(),
                'email_error' => $e->getMessage(),
            ]);

            Log::error('Erro ao enviar e-mail de resultado da análise', [
                'batch_id' => $batch->id,
                'lead_id' => $lead->id ?? null,
                'lead_email' => $lead->email ?? null,
                'message' => $e->getMessage(),
            ]);

            /*
             * Como SMTP pode voltar depois, re-lançamos a exception para o Laravel
             * controlar retry/failure. Se estiver usando Gmail com limite estourado,
             * não adianta retry imediato; nesse caso use MAIL_MAILER=log ou Mailtrap.
             */
            throw $e;
        }
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
            $response = $analysis->response_payload['response'] ?? [];

            $lines[] = "Companhia: " . strtoupper($analysis->provider);
            $lines[] = "Status: " . $this->formatStatus($analysis->status);

            if ($analysis->provider_status) {
                $lines[] = "Status da companhia: {$analysis->provider_status}";
            }

            if ($analysis->quote_id) {
                $lines[] = "Código da cotação: {$analysis->quote_id}";
            }

            if ($analysis->quote_number) {
                $lines[] = "Número da cotação: {$analysis->quote_number}";
            }

            $productKey = $analysis->product_key ?? $response['productKey'] ?? null;

            if ($productKey) {
                $lines[] = "Produto: {$productKey}";
            }

            if ($analysis->premium_amount) {
                $lines[] = "Orçamento estimado: R$ " . number_format((float) $analysis->premium_amount, 2, ',', '.');
            }

            $commercialPremium = $analysis->commercial_premium ?? $response['commercialPremium'] ?? null;

            if ($commercialPremium) {
                $lines[] = "Prêmio comercial: R$ " . number_format((float) $commercialPremium, 2, ',', '.');
            }

            $grossPremium = $analysis->gross_premium ?? $response['grossPremium'] ?? null;

            if ($grossPremium) {
                $lines[] = "Prêmio bruto: R$ " . number_format((float) $grossPremium, 2, ',', '.');
            }

            $iof = $analysis->iof ?? $response['iof'] ?? null;

            if ($iof) {
                $lines[] = "IOF: R$ " . number_format((float) $iof, 2, ',', '.');
            }

            if ($analysis->status === 'manual_review') {
                $lines[] = "Observação: sua análise foi recebida e está em negociação/validação pela companhia.";
            }

            if ($analysis->status === 'failed' && $analysis->error_message) {
                $lines[] = "Erro técnico: {$analysis->error_message}";
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
