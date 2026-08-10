<?php

namespace App\Jobs;

use App\Exceptions\LeadLoversRateLimitedException;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Services\LeadLoversService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendLeadToLeadLoversJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $leadId
    ) {}

    public function handle(LeadLoversService $leadLovers): void
    {
        if (! config('services.leadlovers.enabled', false)) {
            $this->disableInitialSend();

            return;
        }

        if (! $this->claimInitialSend()) {
            return;
        }

        $lead = Lead::with([
            'company',
            'endereco',
            'imobiliariaInformada',
        ])->findOrFail($this->leadId);

        /**
         * Antes de criar o lead, o sistema precisa descobrir
         * qual é a tag principal dele.
         */
        $mainTagId = $this->mainTagIdForLead($lead);

        if (! $mainTagId) {
            Log::warning('Tag principal não encontrada para o lead', [
                'lead_id' => $lead->id,
                'tipo_solicitante' => $lead->tipo_solicitante,
                'company_id' => $lead->company_id,
            ]);

            $this->failInitialSend(
                (int) $lead->id,
                'tag_failed',
                [
                    'message' => 'Tag principal não encontrada.',
                    'tipo_solicitante' => $lead->tipo_solicitante,
                ],
                'O envio inicial aguarda a correção da tag principal.'
            );

            return;
        }

        $sequenceCode = $this->sequenceCodeForLead($lead);

        if (! $sequenceCode) {
            Log::warning('Sequência LeadLovers não encontrada para o lead', [
                'lead_id' => $lead->id,
                'tipo_solicitante' => $lead->tipo_solicitante,
            ]);

            $this->failInitialSend(
                (int) $lead->id,
                'sequence_failed',
                [
                    'message' => 'Sequência da LeadLovers não encontrada.',
                    'tipo_solicitante' => $lead->tipo_solicitante,
                ],
                'O envio inicial aguarda a correção da sequência.'
            );

            return;
        }

        /**
         * Cria o lead na máquina da LeadLovers já com a tag principal.
         */
        try {
            $response = $leadLovers->createLead([
                'Name' => $lead->nome,
                'Email' => $lead->email,
                'Phone' => $lead->tel ?? '',
                'City' => $lead->endereco?->cidade_imovel ?? '',
                'State' => $lead->endereco?->estado ?? '',
                'Company' => $lead->company?->name
                    ?? $lead->imobiliariaInformada?->nome_imobiliaria_informada
                    ?? $lead->imobiliaria
                    ?? '',

                'Tag' => $mainTagId,
                'Score' => 0,

                'CPF' => $lead->cpf,
                'telefone' => $lead->tel,
                'CIVIL' => $lead->estado_civil,
                'conjuge' => $lead->conjuge?->cpf,
                'VALOR' => $lead->despesas?->valor_aluguel,
                'Agua' => $lead->despesas?->valor_agua,
                'Luz' => $lead->despesas?->valor_luz,
                'Gas' => $lead->despesas?->valor_gas,
                'IPTU' => $lead->despesas?->valor_iptu,
                'Condominio' => $lead->despesas?->valor_condominio,
                'OUTRO' => $lead->despesas?->outras_despesas,

                'EmailSequenceCode' => $sequenceCode,
                'SequenceLevelCode' => (int) config('services.leadlovers.step', 1),

                'tipo_solicitante' => $lead->tipo_solicitante,
            ]);
        } catch (LeadLoversRateLimitedException $e) {
            if ($this->attempts() >= $this->tries) {
                $this->failInitialSend(
                    (int) $lead->id,
                    'failed',
                    [
                        'message' => 'Limite de requisições persistiu após várias tentativas.',
                    ],
                    'O envio inicial falhou após as tentativas configuradas.'
                );

                throw $e;
            }

            $retryAfter = max(
                1,
                $e->retryAfter
                    ?? (int) config('services.leadlovers.rate_limit_retry_seconds', 60)
            );

            Log::notice('Envio do lead devolvido à fila por rate limit.', [
                'lead_id' => $lead->id,
                'attempt' => $this->attempts(),
                'retry_after' => $retryAfter,
                'cloudflare_1015' => $e->cloudflareBlocked,
            ]);

            $this->release($retryAfter);

            return;
        }

        if (! is_array($response) || ! $this->leadLoversResponseWasSuccessful($response)) {
            Log::warning('Lead não enviado para LeadLovers', [
                'lead_id' => $lead->id,
                'lead_ref' => hash('sha256', mb_strtolower(trim($lead->email))),
                'status_code' => $response['StatusCode'] ?? null,
            ]);

            $this->failInitialSend(
                (int) $lead->id,
                'failed',
                is_array($response)
                    ? $this->initialResponseSummary($response)
                    : [
                        'success' => false,
                        'status_code' => null,
                    ],
                'A LeadLovers recusou o envio inicial.'
            );

            return;
        }

        /**
         * Opcional: adiciona tags extras além da tag principal.
         */
        /**
         * Marca o envio como concluído.
         */
        $pendingUpdate = $this->completeInitialSend($response);

        if ($pendingUpdate === null) {
            return;
        }

        try {
            $job = new UpdateLeadOnLeadLoversJob(
                leadId: $pendingUpdate['lead_id'],
                originalEmail: $pendingUpdate['original_email'],
                syncVersion: $pendingUpdate['sync_version'],
                requestedFields: $pendingUpdate['requested_fields'],
            );
            $job->onQueue('leadlovers')->delay(now()->addSeconds(max(
                1,
                (int) config(
                    'services.leadlovers.initial_update_delay_seconds',
                    60
                )
            )));
            Bus::dispatch($job);
        } catch (Throwable $exception) {
            Lead::query()
                ->whereKey($pendingUpdate['lead_id'])
                ->where(
                    'leadlovers_update_version',
                    $pendingUpdate['sync_version']
                )
                ->where('leadlovers_update_status', 'pending')
                ->update([
                    'leadlovers_update_status' => 'failed',
                    'leadlovers_update_error' => 'A atualização após o envio inicial não pôde ser colocada na fila.',
                ]);

            Log::warning('Falha ao enfileirar atualização após o envio inicial.', [
                'lead_id' => $pendingUpdate['lead_id'],
                'sync_version' => $pendingUpdate['sync_version'],
                'exception' => $exception::class,
            ]);
        }
    }

    private function completeInitialSend(array $response): ?array
    {
        return DB::transaction(function () use ($response): ?array {
            $lead = Lead::query()
                ->whereKey($this->leadId)
                ->lockForUpdate()
                ->firstOrFail();
            $requestedFields = in_array(
                $lead->leadlovers_update_status,
                ['waiting_initial_send', 'disabled'],
                true
            )
                && is_array($lead->leadlovers_update_response)
                && is_array(
                    $lead->leadlovers_update_response['requested_fields']
                        ?? null
                )
                    ? $lead->leadlovers_update_response['requested_fields']
                    : [];

            $lead->forceFill([
                'leadlovers_status' => 'sent',
                'leadlovers_response' => $this->initialResponseSummary(
                    $response
                ),
                'sent_to_leadlovers_at' => now(),
            ]);

            if ($requestedFields === []) {
                $lead->save();

                return null;
            }

            $originalEmail = trim((string) $lead->email);

            if (
                $originalEmail === ''
                || filter_var($originalEmail, FILTER_VALIDATE_EMAIL) === false
            ) {
                $lead->forceFill([
                    'leadlovers_update_status' => 'failed',
                    'leadlovers_update_error' => 'O e-mail não permite concluir a atualização após o envio inicial.',
                ])->save();

                return null;
            }

            $syncVersion = (int) $lead->leadlovers_update_version + 1;

            $lead->forceFill([
                'leadlovers_update_status' => 'pending',
                'leadlovers_update_version' => $syncVersion,
                'leadlovers_update_error' => null,
                'leadlovers_update_response' => [
                    'requested_fields' => $requestedFields,
                ],
                'leadlovers_update_requested_at' => now(),
            ])->save();

            return [
                'lead_id' => (int) $lead->id,
                'original_email' => $originalEmail,
                'sync_version' => $syncVersion,
                'requested_fields' => $requestedFields,
            ];
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->failInitialSend(
            $this->leadId,
            'failed',
            [
                'success' => false,
                'status_code' => null,
            ],
            'O envio inicial falhou após as tentativas configuradas.'
        );

        Log::warning('Envio inicial do lead esgotou as tentativas.', [
            'lead_id' => $this->leadId,
            'exception' => $exception ? $exception::class : null,
        ]);
    }

    private function claimInitialSend(): bool
    {
        $allowedStatuses = $this->attempts() > 1
            ? ['pending', 'processing']
            : ['pending', 'tag_failed', 'sequence_failed', 'disabled'];

        return Lead::query()
            ->whereKey($this->leadId)
            ->whereIn('leadlovers_status', $allowedStatuses)
            ->update([
                'leadlovers_status' => 'processing',
            ]) === 1;
    }

    private function disableInitialSend(): void
    {
        DB::transaction(function (): void {
            $lead = Lead::query()
                ->whereKey($this->leadId)
                ->whereNotIn('leadlovers_status', ['sent', 'send'])
                ->lockForUpdate()
                ->first();

            if (! $lead) {
                return;
            }

            $wasProcessing = $lead->leadlovers_status === 'processing';
            $attributes = [
                'leadlovers_status' => $wasProcessing ? 'failed' : 'disabled',
            ];

            if ($wasProcessing) {
                $attributes['leadlovers_response'] = [
                    'success' => false,
                    'status_code' => null,
                ];
            }

            if ($lead->leadlovers_update_status === 'waiting_initial_send') {
                $attributes['leadlovers_update_status'] = $wasProcessing
                    ? 'failed'
                    : 'disabled';
                $attributes['leadlovers_update_error'] = $wasProcessing
                    ? 'O envio inicial foi interrompido em estado ambíguo e precisa ser conciliado.'
                    : 'A integração com a LeadLovers está desativada.';
            }

            $lead->forceFill($attributes)->save();
        });
    }

    private function failInitialSend(
        int $leadId,
        string $status,
        array $response,
        string $updateError
    ): void {
        DB::transaction(function () use (
            $leadId,
            $status,
            $response,
            $updateError
        ): void {
            $lead = Lead::query()
                ->whereKey($leadId)
                ->where('leadlovers_status', 'processing')
                ->lockForUpdate()
                ->first();

            if (! $lead) {
                return;
            }

            $attributes = [
                'leadlovers_status' => $status,
                'leadlovers_response' => $response,
            ];

            if ($lead->leadlovers_update_status === 'waiting_initial_send') {
                $attributes['leadlovers_update_status'] = 'failed';
                $attributes['leadlovers_update_error'] = $updateError;
            }

            $lead->forceFill($attributes)->save();
        });
    }

    /**
     * Descobre qual tag principal deve ser enviada no campo "Tag"
     * do endpoint Insert New Lead.
     */
    private function mainTagIdForLead(Lead $lead): ?int
    {
        /**
         * Caso seja imobiliária cadastrada:
         * a tag principal é a própria tag da imobiliária.
         */
        if ($lead->tipo_solicitante === 'imobiliaria_cadastrada') {
            return $this->companyTagId($lead);
        }

        /**
         * Para os demais perfis, convertemos o tipo interno
         * para a key da tag local.
         */
        $tagKey = match ($lead->tipo_solicitante) {
            'locatario' => 'locatario',
            'imobiliaria_nao_cadastrada' => 'imobiliaria_morna',
            'locador' => 'diretoprop',
            default => null,
        };

        if (! $tagKey) {
            return null;
        }

        return LeadLoversTag::where('key', $tagKey)
            ->where('active', true)
            ->value('leadlovers_tag_id');
    }

    /**
     * Descobre o ID da tag da imobiliária cadastrada.
     */
    private function companyTagId(Lead $lead): ?int
    {
        if (! $lead->company) {
            return null;
        }

        /**
         * Melhor opção:
         * usar o ID salvo diretamente na tabela imobiliarias.
         */
        if ($lead->company->leadlovers_tag_id) {
            return (int) $lead->company->leadlovers_tag_id;
        }

        /**
         * Fallback:
         * buscar pelo título da tag igual ao nome da imobiliária.
         */
        return LeadLoversTag::where('title', $lead->company->name)
            ->where('active', true)
            ->value('leadlovers_tag_id');
    }

    private function leadLoversResponseWasSuccessful(array $response): bool
    {
        if (
            array_key_exists('_response_confirmed', $response)
            && $response['_response_confirmed'] !== true
        ) {
            return false;
        }

        $statusCode = $response['StatusCode']
            ?? $response['statusCode']
            ?? $response['status']
            ?? null;
        $success = $response['Success'] ?? $response['success'] ?? null;
        $explicitlyFailed = $success === false
            || $success === 0
            || (
                is_string($success)
                && in_array(
                    mb_strtolower(trim($success)),
                    ['0', 'false', 'no'],
                    true
                )
            )
            || filled($response['Exception'] ?? $response['exception'] ?? null)
            || filled($response['Error'] ?? $response['error'] ?? null);

        if ($explicitlyFailed) {
            return false;
        }

        if ($statusCode !== null) {
            return is_numeric($statusCode)
                && (int) $statusCode >= 200
                && (int) $statusCode < 300;
        }

        $message = (string) ($response['Message'] ?? $response['message'] ?? '');

        return mb_stripos(
            $message,
            'Novo lead inserido na fila para processamento'
        ) !== false;
    }

    private function initialResponseSummary(array $response): array
    {
        $rawStatus = $response['StatusCode']
            ?? $response['statusCode']
            ?? $response['status']
            ?? null;
        $leadCode = $response['Code']
            ?? $response['code']
            ?? $response['Value']
            ?? $response['value']
            ?? null;
        $summary = [
            'success' => $this->leadLoversResponseWasSuccessful($response),
            'status_code' => is_numeric($rawStatus)
                ? (int) $rawStatus
                : null,
        ];

        if (is_numeric($leadCode) && (int) $leadCode > 0) {
            $summary['lead_code'] = (int) $leadCode;
        }

        return $summary;
    }

    private function sequenceCodeForLead(Lead $lead): ?int
    {
        /*
        |--------------------------------------------------------------------------
        | Regra de negócio das sequências
        |--------------------------------------------------------------------------
        | Locatário vai para uma sequência própria.
        | Todos os outros perfis vão para a sequência padrão.
        */

        if ($lead->tipo_solicitante === 'locatario') {
            return (int) config('services.leadlovers.sequence_2');

        }

        return (int) config('services.leadlovers.sequence_1');
    }
}
