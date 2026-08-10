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
use RuntimeException;



class UpdateLeadOnLeadLoversJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;
    
    public bool $failOnTimeout = true;

    public function backoff(): array {
        return [30, 60, 120, 300];
    }

    public function __construct(
        public int $leadId,
        public string $originalEmail,
        public int $syncVersion,
    ) {}

    public function handle(LeadLoversService $leadLoversService): void
    {
        if (! config('services.leadlovers.enabled', false)) {
            return;
        }

        $lead = Lead::with(['endereco', 'company', 'despesas', 'conjuge', 'imobiliariaInformada',])->find($this->leadId);

        if (
            ! $lead
            || (int) $lead->leadlovers_update_version !== $this->syncVersion
        
        
        ) {
            return;
        }

        if (! $this->originalEmail) {
            Log::warning('Lead sem e-mail original para atualização na LeadLovers.', [
                'lead_id' => $lead->id,
            ]);

            return;
        }

        $lead->forceFill([
            'leadlovers_update_status' => 'processing',
        ])->saveQuietly();

        /*
         * Monta payload apenas com campos aceitos pelo endpoint PATCH /Lead.
         * Não envie CPF, aluguel, despesas ou campos que a API não aceita,
         * a não ser que você configure DynamicFields futuramente.
         */
        $payload = [
            'Email' => $this->originalEmail,
            'Name' => (string) $lead->nome,
            'Phone' => $this->onlyNumbers($lead->tel),
            'City' => (string) $lead->endereco?->cidade_imovel,
            'State' => (string) $lead->endereco?->estado,
            'Company' => (string) (
                $lead->company?->name ??
                $lead->imobiliariaInformada?->nome_imobiliaria_informada
                ?? $lead->imobiliaria
                ?? ''
            ),

            'DynamicFields' => $this->dynamicFields($lead),
        ];

        /*
         * Remove campos vazios para não apagar dados na LeadLovers sem querer.
         */
        $payload = array_filter($payload, function ($value) {
            return $value !== null && $value !== '';
        });

        try {
            $response = $leadLoversService->updateLead($payload);

            if (! ($response['success'] ?? false)) {
                $status = is_numeric($response['status'] ?? null)
                    ? (int) $response['status']
                    : null;

                $retryable =
                    $status === null
                    || in_array($status, [408, 425, 429], true)
                    || $status >= 500;

                if ($retryable) {
                    throw new RuntimeException(
                        'Falha transitória ao atualizar o lead na LeadLovers.'
                    );
                }

                Lead::query()
                    ->whereKey($this->leadId)
                    ->where(
                        'leadlovers_update_version',
                        $this->syncVersion
                    )
                    ->update([
                        'leadlovers_update_status' => 'failed',
                        'leadlovers_update_error' =>
                            'A LeadLovers recusou a atualização.',
                        'leadlovers_update_response' => $response,
                    ]);

                return;
            }

            Lead::query()
                ->whereKey($this->leadId)
                ->where(
                    'leadlovers_update_version',
                    $this->syncVersion
                )
                ->update([
                    'leadlovers_update_status' => 'synced',
                    'leadlovers_update_error' => null,
                    'leadlovers_update_response' => $response,
                    'leadlovers_updated_at' => now(),
                ]);

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

    private function dynamicFields(Lead $lead): array
    {
        $values = [
            'cpf' => $lead->cpf,
            'estado_civil' => $lead->estado_civil,
            'conjuge_cpf' => $lead->conjuge?->cpf,
            'valor_aluguel' => $lead->despesas?->valor_aluguel,
            'valor_agua' => $lead->despesas?->valor_agua,
            'valor_luz' => $lead->despesas?->valor_luz,
            'valor_gas' => $lead->despesas?->valor_gas,
            'valor_condominio' => $lead->despesas?->valor_condominio,
            'valor_iptu' => $lead->despesas?->valor_iptu,
            'outras_despesas' => $lead->despesas?->outras_despesas,
        ];


        return collect(
            config('services.leadlovers.dynamic_fields', [])
        )
            ->map(function ($fieldId, string $field) use ($values){
                if(
                    !array_key_exists($field, $values)
                    || ! is_numeric($fieldId)
                    || (int) $fieldId <= 0
                ) {
                    return null;
                }

                return [
                    'Id' => $fieldId,
                    'Value' => (string) ($values[$field] ?? '')
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function onlyNumbers(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }
}
