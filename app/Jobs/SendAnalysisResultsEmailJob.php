<?php

namespace App\Jobs;

use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Barryvdh\DomPDF\Facade\Pdf;

class SendAnalysisResultsEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public int $batchId,
        public ?string $attemptId = null,
        public bool $isReanalysis = false
    ) {}

    public function handle(): void
    {
        if (! config('features.insurance_analysis.enabled', false)) {
            logger()->notice('Job de análise ignorado porque o módulo está desativado.', ['job' => static::class]);

            return;
        }

        $batch = InsuranceAnalysisBatch::with([
            'lead',
            'analyses.events',
            'lead.company',
            'lead.imobiliariaInformada',
            'lead.locador',
        ])->findOrFail($this->batchId);

        /*
        |--------------------------------------------------------------------------
        | Evita envio duplicado por rodada
        |--------------------------------------------------------------------------
        | Não use apenas email_sent_at para bloquear, porque a reanálise precisa
        | enviar novo e-mail com novos PDFs.
        */
        if ($this->emailAlreadySent($batch)) {
            return;
        }

        $lead = $batch->lead;

        if (!$lead) {
            $batch->update([
                'email_status' => 'failed',
                'email_failed_at' => now(),
                'email_error' => 'Lead não encontrado.',
            ]);

            return;
        }

        $recipients = $this->resolveEmailRecipients($lead);

        if (empty($recipients['to'])) {
            $batch->update([
                'email_status' => 'failed',
                'email_failed_at' => now(),
                'email_error' => 'Nenhum destinatário válido encontrado para envio do resultado.',
            ]);

            $this->registerEmailEvent(
                batch: $batch,
                eventType: 'email_failed',
                message: 'Nenhum destinatário válido encontrado para envio do resultado.',
                payload: [
                    'attempt_id' => $this->attemptId,
                    'is_reanalysis' => $this->isReanalysis,
                    'lead_id' => $lead->id,
                    'tipo_solicitante' => $lead->tipo_solicitante,
                    'resolved_recipients' => $recipients,
                ]
            );

            return;
        }


        $resultEvents = $this->resultEventsForCurrentAttempt($batch);

        try {
            $body = $this->buildMessage($batch, $resultEvents);
            $attachments = $this->generatePdfAttachments($batch, $resultEvents);

            Mail::raw($body, function ($message) use ($recipients, $attachments) {
                $subject = $this->isReanalysis
                    ? 'Resultado da sua reanálise de Seguro Fiança'
                    : 'Resultado da sua análise de Seguro Fiança';

                $message->to($recipients['to'])
                    ->subject($subject);

                if (!empty($recipients['cc'])) {
                    $message->cc($recipients['cc']);
                }

                foreach ($attachments as $attachment) {
                    $message->attach($attachment['path'], [
                        'as' => $attachment['name'],
                        'mime' => 'application/pdf',
                    ]);
                }
            });

            /*
            |--------------------------------------------------------------------------
            | Atualiza o lote com o último envio realizado
            |--------------------------------------------------------------------------
            | email_sent_at representa o último e-mail enviado, não bloqueia
            | reanálises futuras.
            */
            $batch->update([
                'email_sent_at' => now(),
                'email_status' => 'sent',
                'email_failed_at' => null,
                'email_error' => null,
            ]);

            $this->registerEmailEvent(
                batch: $batch,
                eventType: 'email_sent',
                message: $this->isReanalysis
                    ? 'E-mail de resultado da reanálise enviado ao lead.'
                    : 'E-mail de resultado da análise enviado ao lead.',
                payload: [
                    'attempt_id' => $this->attemptId,
                    'is_reanalysis' => $this->isReanalysis,
                    'lead_id' => $lead->id,
                    'recipients' => $recipients,
                    'email' => $lead->email,
                    'attachments' => collect($attachments)->pluck('name')->values()->all(),
                ],
                resultEvents: $resultEvents
            );
        } catch (Throwable $e) {
            $this->registerEmailEvent(
                batch: $batch,
                eventType: 'email_failed',
                message: $e->getMessage(),
                payload: [
                    'attempt_id' => $this->attemptId,
                    'is_reanalysis' => $this->isReanalysis,
                    'lead_id' => $lead->id ?? null,
                    'email' => $lead->email ?? null,
                ],
                resultEvents: $resultEvents
            );

            $batch->update([
                'email_status' => 'failed',
                'email_failed_at' => now(),
                'email_error' => $e->getMessage(),
            ]);

            Log::error('Erro ao enviar e-mail de resultado da análise', [
                'batch_id' => $batch->id,
                'attempt_id' => $this->attemptId,
                'is_reanalysis' => $this->isReanalysis,
                'lead_id' => $lead->id ?? null,
                'lead_email' => $lead->email ?? null,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function emailAlreadySent(InsuranceAnalysisBatch $batch): bool
    {
        $query = InsuranceAnalysisEvent::query()
            ->whereHas('analysis', function ($query) use ($batch) {
                $query->where('insurance_analysis_batch_id', $batch->id);
            })
            ->where('event_type', 'email_sent');

        if ($this->attemptId) {
            $query->where('payload->attempt_id', $this->attemptId);
        }

        return $query->exists();
    }

    private function resultEventsForCurrentAttempt(InsuranceAnalysisBatch $batch): Collection
    {
        $eventTypes = [
            'analysis_completed',
            'reanalysis_completed',
            'created_without_body',
            'reanalysis_created_without_body',
            'failed',
            'reanalysis_failed',
            'invalid_response',
            'reanalysis_invalid_response',
        ];

        $query = InsuranceAnalysisEvent::query()
            ->with(['analysis.lead', 'analysis.batch'])
            ->whereHas('analysis', function ($query) use ($batch) {
                $query->where('insurance_analysis_batch_id', $batch->id);
            })
            ->whereIn('event_type', $eventTypes);

        if ($this->attemptId) {
            $query->where('payload->attempt_id', $this->attemptId);
        }

        $events = $query
            ->orderBy('created_at')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Fallback
        |--------------------------------------------------------------------------
        | Se ainda não existirem eventos novos, usa as análises atuais para não
        | quebrar o fluxo antigo. Depois que RunProviderAnalysisJob atualizado
        | estiver rodando, os eventos serão encontrados normalmente.
        */
        if ($events->isNotEmpty()) {
            return $events;
        }

        return $batch->analyses->map(function ($analysis) {
            $event = new InsuranceAnalysisEvent();

            $event->setRelation('analysis', $analysis);
            $event->event_type = $this->isReanalysis ? 'reanalysis_completed' : 'analysis_completed';
            $event->status = $analysis->status;
            $event->message = 'Resultado montado a partir da análise atual.';
            $event->payload = [
                'attempt_id' => $this->attemptId,
                'is_reanalysis' => $this->isReanalysis,
                'rent_amount' => $analysis->rent_amount,
                'charges_amount' => $analysis->charges_amount,
                'total_monthly_amount' => $analysis->total_monthly_amount,
            ];
            $event->response = [
                'provider' => $analysis->provider,
                'provider_status' => $analysis->provider_status,
                'quote_id' => $analysis->quote_id,
                'quote_number' => $analysis->quote_number,
                'product_key' => $analysis->product_key,
                'premium_amount' => $analysis->premium_amount,
                'commercial_premium' => $analysis->commercial_premium,
                'gross_premium' => $analysis->gross_premium,
                'iof' => $analysis->iof,
                'insured_amount' => $analysis->insured_amount,
                'debug' => $analysis->response_payload,
            ];

            return $event;
        });
    }

    private function generatePdfAttachments(InsuranceAnalysisBatch $batch, Collection $resultEvents): array
    {
        /*
        |--------------------------------------------------------------------------
        | PDF opcional com DomPDF
        |--------------------------------------------------------------------------
        | Instale se ainda não tiver:
        | composer require barryvdh/laravel-dompdf
        |
        | Crie a view:
        | resources/views/emails/analysis-result-pdf.blade.php
        */
        

        $attachments = [];

        foreach ($resultEvents as $event) {
            $analysis = $event->analysis;

            if (!$analysis) {
                continue;
            }

            $type = $this->isReanalysis ? 'reanálise' : 'análise';

            $fileName = sprintf(
                'lead-%s-%s-%s.pdf',
                $batch->lead_id,
                $analysis->provider,
                $this->isReanalysis ? 'reanalise' : 'analise'
            );

            $directory = sprintf(
                'analysis-results/lead-%s/%s',
                $batch->lead_id,
                $this->attemptId ?: now()->format('YmdHis')
            );

            $relativePath = "{$directory}/{$fileName}";

            $pdf = Pdf::loadView('emails.analysis-result-pdf', [
                'batch' => $batch,
                'lead' => $batch->lead,
                'event' => $event,
                'analysis' => $analysis,
                'isReanalysis' => $this->isReanalysis,
                'type' => $type,
            ]);

            Storage::disk('local')->put($relativePath, $pdf->output());

            $fullPath = Storage::disk('local')->path($relativePath);

            if ($event->exists && $analysis) {
                $analysis->events()->create([
                    'event_type' => 'pdf_generated',
                    'status' => $event->status,
                    'message' => "PDF de {$type} gerado para {$analysis->provider}.",
                    'payload' => [
                        'attempt_id' => $this->attemptId,
                        'is_reanalysis' => $this->isReanalysis,
                        'source_event_id' => $event->id,
                        'pdf_path' => $relativePath,
                        'file_name' => $fileName,
                    ],
                ]);
            }

            $attachments[] = [
                'path' => $fullPath,
                'name' => $fileName,
            ];
        }

        return $attachments;
    }

    private function registerEmailEvent(
        InsuranceAnalysisBatch $batch,
        string $eventType,
        string $message,
        array $payload = [],
        ?Collection $resultEvents = null
    ): void {
        try {
            $events = $resultEvents ?: $this->resultEventsForCurrentAttempt($batch);

            if ($events->isEmpty()) {
                foreach ($batch->analyses as $analysis) {
                    $analysis->events()->create([
                        'event_type' => $eventType,
                        'status' => $analysis->status,
                        'message' => $message,
                        'payload' => $payload,
                    ]);
                }

                return;
            }

            foreach ($events as $event) {
                $analysis = $event->analysis;

                if (!$analysis || !$analysis->exists) {
                    continue;
                }

                $analysis->events()->create([
                    'event_type' => $eventType,
                    'status' => $event->status,
                    'message' => $message,
                    'payload' => array_merge($payload, [
                        'source_event_id' => $event->id,
                    ]),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Erro ao registrar evento de e-mail da análise', [
                'batch_id' => $batch->id,
                'event_type' => $eventType,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function resolveEmailRecipients($lead): array
    {
        $lead->loadMissing([
            'company',
            'imobiliariaInformada',
            'locador',
        ]);

        $to = [];
        $cc = [];

        switch ($lead->tipo_solicitante) {
            case 'imobiliaria_cadastrada':
                $to[] = $lead->company?->email;

                if ($lead->email) {
                    $cc[] = $lead->email;
                }

                break;

            case 'imobiliaria_nao_cadastrada':
                $to[] = $lead->imobiliariaInformada?->responsavel_preenchimento;

                if ($lead->email) {
                    $cc[] = $lead->email;
                }

                break;

            case 'locador':
                $to[] = $lead->locador?->email;

                if ($lead->email) {
                    $cc[] = $lead->email;
                }

                break;

            case 'locatario':
            default:
                $to[] = $lead->email;
                break;
        }

        $to = $this->validEmails($to);

        /*
        |--------------------------------------------------------------------------
        | Fallback de segurança
        |--------------------------------------------------------------------------
        | Se não encontrou e-mail específico do tipo solicitante,
        | tenta enviar para o e-mail principal do lead.
        */
        if (empty($to) && $lead->email) {
            $to[] = $lead->email;
        }

        $cc = $this->validEmails($cc);

        /*
        |--------------------------------------------------------------------------
        | Evita duplicidade
        |--------------------------------------------------------------------------
        */
        $cc = array_values(array_diff($cc, $to));

        return [
            'to' => array_values(array_unique($to)),
            'cc' => array_values(array_unique($cc)),
        ];
    }

    private function validEmails(array $emails): array
    {
        return collect($emails)
            ->filter()
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    private function buildMessage(InsuranceAnalysisBatch $batch, Collection $resultEvents): string
    {
        $lead = $batch->lead;

        $lines = [];

        $lines[] = "Olá, {$lead->nome}.";
        $lines[] = "";

        if ($this->isReanalysis) {
            $lines[] = "Recebemos o resultado da sua reanálise de Seguro Fiança Locatícia Residencial.";
        } else {
            $lines[] = "Recebemos o resultado da sua análise de Seguro Fiança Locatícia Residencial.";
        }

        $lines[] = "";

        if ($resultEvents->isEmpty()) {
            $lines[] = "Nenhuma análise foi encontrada para este lote.";
            $lines[] = "Nossa equipe irá verificar o processamento.";
            $lines[] = "";

            return implode("\n", $lines);
        }

        $lines[] = "Resultados por companhia:";
        $lines[] = "";

        foreach ($resultEvents as $event) {
            $analysis = $event->analysis;

            if (!$analysis) {
                continue;
            }

            $response = (array) ($event->response ?? []);
            $payload = (array) ($event->payload ?? []);

            $providerStatus = data_get($response, 'provider_status');
            $quoteId = data_get($response, 'quote_id');
            $quoteNumber = data_get($response, 'quote_number');
            $productKey = data_get($response, 'product_key');
            $premiumAmount = data_get($response, 'premium_amount');
            $commercialPremium = data_get($response, 'commercial_premium');
            $grossPremium = data_get($response, 'gross_premium');
            $iof = data_get($response, 'iof');

            $rentAmount = data_get($payload, 'rent_amount');
            $chargesAmount = data_get($payload, 'charges_amount');
            $totalMonthlyAmount = data_get($payload, 'total_monthly_amount');

            $lines[] = "Companhia: " . strtoupper($analysis->provider);
            $lines[] = "Tipo: " . ($this->isReanalysis ? 'Reanálise' : 'Análise');
            $lines[] = "Status: " . $this->formatStatus($event->status ?? $analysis->status);

            if ($providerStatus) {
                $lines[] = "Status da companhia: {$providerStatus}";
            }

            if ($quoteId) {
                $lines[] = "Código da cotação: {$quoteId}";
            }

            if ($quoteNumber) {
                $lines[] = "Número da cotação: {$quoteNumber}";
            }

            if ($productKey) {
                $lines[] = "Produto: {$productKey}";
            }

            if ($rentAmount !== null) {
                $lines[] = "Aluguel enviado: R$ " . number_format((float) $rentAmount, 2, ',', '.');
            }

            if ($chargesAmount !== null) {
                $lines[] = "Encargos enviados: R$ " . number_format((float) $chargesAmount, 2, ',', '.');
            }

            if ($totalMonthlyAmount !== null) {
                $lines[] = "Total mensal enviado: R$ " . number_format((float) $totalMonthlyAmount, 2, ',', '.');
            }

            if ($premiumAmount) {
                $lines[] = "Orçamento estimado: R$ " . number_format((float) $premiumAmount, 2, ',', '.');
            }

            if ($commercialPremium) {
                $lines[] = "Prêmio comercial: R$ " . number_format((float) $commercialPremium, 2, ',', '.');
            }

            if ($grossPremium) {
                $lines[] = "Prêmio bruto: R$ " . number_format((float) $grossPremium, 2, ',', '.');
            }

            if ($iof) {
                $lines[] = "IOF: R$ " . number_format((float) $iof, 2, ',', '.');
            }

            if (($event->status ?? $analysis->status) === 'manual_review') {
                $lines[] = "Observação: sua análise foi recebida e está em negociação/validação pela companhia.";
            }

            if (($event->status ?? $analysis->status) === 'failed' && $analysis->error_message) {
                $lines[] = "Erro técnico: {$analysis->error_message}";
            }

            $lines[] = "";
        }

        $lines[] = "Os PDFs com os detalhes também foram anexados quando disponíveis.";
        $lines[] = "";
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
