<?php

namespace App\Jobs;

use App\Exceptions\LeadLoversRateLimitedException;
use App\Models\Lead;
use App\Services\LeadLoversService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateLeadOnLeadLoversJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $leadId,

        /*
         * Guardamos o e-mail original porque o endpoint da LeadLovers
         * usa Email como identificador do lead.
         */
        public string $originalEmail
    ) {}

    public function handle(LeadLoversService $leadLoversService): void
    {
        if (! config('services.leadlovers.enabled', false)) {
            return;
        }

        $lead = Lead::with(['endereco', 'company'])->find($this->leadId);

        if (! $lead) {
            return;
        }

        if (! $this->originalEmail) {
            Log::warning('Lead sem e-mail original para atualização na LeadLovers.', [
                'lead_id' => $lead->id,
            ]);

            return;
        }

        /*
         * Monta payload apenas com campos aceitos pelo endpoint PATCH /Lead.
         * Não envie CPF, aluguel, despesas ou campos que a API não aceita,
         * a não ser que você configure DynamicFields futuramente.
         */
        $payload = [
            'Email' => $this->originalEmail,
            'Name' => $lead->nome,
            'Phone' => $this->onlyNumbers($lead->tel),
            'City' => $lead->endereco?->cidade_imovel,
            'State' => $lead->endereco?->estado,
            'Company' => $lead->company?->name ?? $lead->imobiliaria,
        ];

        /*
         * Remove campos vazios para não apagar dados na LeadLovers sem querer.
         */
        $payload = array_filter($payload, function ($value) {
            return $value !== null && $value !== '';
        });

        try {
            $response = $leadLoversService->updateLead($payload);
        } catch (LeadLoversRateLimitedException $e) {
            if ($this->attempts() >= $this->tries) {
                $lead->forceFill([
                    'leadlovers_status' => 'update_failed',
                    'leadlovers_response' => [
                        'action' => 'update_lead',
                        'success' => false,
                        'message' => 'Limite de requisições persistiu após várias tentativas.',
                    ],
                ])->save();

                throw $e;
            }

            $retryAfter = max(
                1,
                $e->retryAfter
                    ?? (int) config('services.leadlovers.rate_limit_retry_seconds', 60)
            );

            Log::notice('Atualização do lead devolvida à fila por rate limit.', [
                'lead_id' => $lead->id,
                'attempt' => $this->attempts(),
                'retry_after' => $retryAfter,
                'cloudflare_1015' => $e->cloudflareBlocked,
            ]);

            $this->release($retryAfter);

            return;
        }

        if (! ($response['success'] ?? false)) {
            Log::warning('LeadLovers não confirmou atualização do lead.', [
                'lead_id' => $lead->id,
                'lead_ref' => hash('sha256', mb_strtolower(trim($this->originalEmail))),
                'status' => $response['status'] ?? null,
            ]);

            $lead->forceFill([
                'leadlovers_status' => 'update_failed',
                'leadlovers_response' => [
                    'action' => 'update_lead',
                    'success' => false,
                    'updated_at' => now()->toDateTimeString(),
                    'payload' => $payload,
                    'response' => $response,
                ],
            ])->save();

            return;
        }

        $lead->forceFill([
            'leadlovers_status' => 'updated',
            'leadlovers_response' => [
                'action' => 'update_lead',
                'success' => true,
                'updated_at' => now()->toDateTimeString(),
                'payload' => $payload,
                'response' => $response,
            ],
        ])->save();

        Log::info('Lead atualizado na LeadLovers com sucesso.', [
            'lead_id' => $lead->id,
            'lead_ref' => hash('sha256', mb_strtolower(trim($this->originalEmail))),
        ]);
    }

    private function onlyNumbers(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }
}
