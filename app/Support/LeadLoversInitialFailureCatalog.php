<?php

namespace App\Support;

use App\Models\Lead;
use Illuminate\Support\Carbon;

final class LeadLoversInitialFailureCatalog
{
    /**
     * Mantém compatibilidade com URLs já compartilhadas e salvas.
     */
    public const DASHBOARD_FILTER_NOT_SENT = 'not_sent_invalid_data';

    /**
     * Create a new class instance.
     */
    private const CORRECTABLE_FIELDS = [
        'PHONE_EXISTS' => ['tel'],
        'EMAIL_EXISTS' => ['email'],
    ];

    /**
     * @return array<string, string>
     */
    public function dashboardSyncOptions(): array
    {
        return [
            self::DASHBOARD_FILTER_NOT_SENT => 'Não enviados à LeadLovers',
        ];
    }

    public function describe(Lead $lead): array
    {
        $response = is_array($lead->leadlovers_response)
            ? $lead->leadlovers_response
            : [];

        $httpStatus = $this->normalizeHttpStatus(
            $lead->leadlovers_initial_error_status
                ?? data_get($response, 'status_code')
        );

        $errorCode = $this->normalizeErrorCode(
            $lead->leadlovers_initial_error_code
                ?? data_get($response, 'error_code')
        );

        $operation = $this->normalizeOperation(
            $lead->leadlovers_initial_error_operation
                ?? data_get($response, 'operation')
        );

        $failed = in_array($lead->leadlovers_status, [
            'failed',
            'tag_failed',
            'sequence_failed',
        ], true);

        $notSent = ! in_array($lead->leadlovers_status, [
            'sent',
            'send',
        ], true)
            && blank($lead->leadlovers_lead_id)
            && blank($lead->sent_to_leadlovers_at);

        $configurationFailure = $this->isTagConfigurationFailure(
            $lead,
            $operation
        ) || $this->isMachineConfigurationFailure(
            $lead,
            $operation
        );

        /*
         * Somente códigos previamente mapeados podem abrir o modal.
         */
        $fields = $failed
            && $notSent
            && $httpStatus === 400
            && ! $configurationFailure
            && (config('branding.active') !== 'tcc' || ! str_starts_with($operation ?? '', 'machine_'))
            ? (self::CORRECTABLE_FIELDS[$errorCode] ?? [])
            : [];

        $correctable = $failed
            && $notSent
            && $httpStatus === 400
            && $fields !== [];

        $recovery = $this->recoveryPresentation($lead);

        return [
            'failed' => $failed,
            'not_sent' => $notSent,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'operation' => $operation,
            'message' => $failed
                ? $this->message(
                    $lead,
                    $httpStatus,
                    $errorCode,
                    $operation
                )
                : ($recovery['message'] ?? null),
            'recovery' => $recovery,
            'correctable' => $correctable,
            'fields' => $fields,
        ];
    }

    private function message(
        Lead $lead,
        ?int $httpStatus,
        ?string $errorCode,
        ?string $operation,
    ): string {
        return match (true) {
            config('branding.active') === 'tcc'
                && ($operation === 'integration_disabled' || $errorCode === 'INTEGRATION_DISABLED') => 'A integração com a LeadLovers está desativada. Não há novas tentativas automáticas enquanto ela permanecer desativada.',

            config('branding.active') === 'tcc'
                && $operation === 'machine_confirmation'
                && $httpStatus === null && $errorCode === null => 'A associação à máquina da LeadLovers não foi confirmada a tempo. Isso não comprova erro de configuração. A sincronização precisa ser revisada antes de retomar as tentativas.',

            config('branding.active') === 'tcc'
                && $httpStatus === 429 => 'O limite de requisições da LeadLovers foi atingido e este ciclo de tentativas foi encerrado. A sincronização precisa ser revisada antes de retomar.',

            config('branding.active') === 'tcc'
                && $httpStatus === 403 => 'A LeadLovers negou permissão para esta operação. Um administrador precisa verificar as permissões da integração.',

            config('branding.active') === 'tcc'
                && str_starts_with($operation ?? '', 'machine_')
                && ! $this->isMachineConfigurationFailure($lead, $operation)
                && in_array($httpStatus, [400, 404, 409, 422], true)
                && ! in_array($errorCode, ['TIMEOUT', 'TRANSACTION_FAILED'], true) => 'A LeadLovers recusou a operação de associação à máquina. Um administrador precisa revisar a resposta da integração antes de retomar. Não é necessário alterar os dados do lead.',

            $this->isTagConfigurationFailure($lead, $operation) => 'A tag principal necessária para enviar o lead não está configurada corretamente.',

            $this->isMachineConfigurationFailure($lead, $operation) => 'A máquina, sequência ou etapa da LeadLovers não está configurada corretamente ou recusou a associação. Os dados do lead não precisam ser alterados.',

            $httpStatus === 400 && $errorCode === 'PHONE_EXISTS' => 'O telefone informado já está cadastrado na LeadLovers. Corrija o telefone para tentar o envio novamente.',

            $httpStatus === 400 && $errorCode === 'EMAIL_EXISTS' => 'O e-mail informado já está cadastrado na LeadLovers e o sistema não conseguiu conciliá-lo automaticamente. Corrija o e-mail para tentar novamente.',

            $errorCode === 'LOCAL_QUEUE_DISPATCH_FAILED' => 'Os dados foram corrigidos, mas o reenvio não pôde ser colocado na fila. A fila do sistema precisa ser verificada.',

            $httpStatus === 401 => 'A LeadLovers recusou as credenciais da integração. Os dados do lead não precisam ser alterados; a configuração deve ser corrigida por um administrador.',

            $httpStatus === 422
                && $errorCode === 'TIMEOUT' => 'A LeadLovers não concluiu a operação dentro do tempo esperado. Nenhuma correção nos dados do lead é necessária.',

            $httpStatus === 422
                && $errorCode === 'TRANSACTION_FAILED' => 'A LeadLovers não conseguiu concluir a transação. Nenhuma correção nos dados do lead é necessária.',

            $httpStatus === 429 => 'O limite de requisições da LeadLovers foi atingido. O sistema realizará novas tentativas automaticamente.',

            $httpStatus !== null && $httpStatus >= 500 => 'A LeadLovers apresentou uma indisponibilidade interna. Nenhuma correção nos dados do lead é necessária.',

            in_array($errorCode, [
                'CONNECTION_FAILED',
                'LOCAL_RATE_LIMIT',
                'INVALID_RESPONSE',
            ], true) => 'O envio não foi concluído por uma falha técnica temporária na comunicação com a LeadLovers. Os dados do lead não precisam ser alterados.',

            in_array($errorCode, [
                'INTEGRATION_DISABLED',
                'MISSING_TOKEN',
                'INVALID_API_URL',
            ], true) => 'A integração com a LeadLovers não está configurada corretamente. Os dados do lead não precisam ser alterados.',

            $httpStatus === 400 && filled($errorCode) => "A LeadLovers recusou os dados do lead (HTTP 400, código {$errorCode}). A correção ainda não está disponível para este código.",

            $httpStatus === 400 => 'A LeadLovers recusou os dados do lead com erro HTTP 400. A correção ainda não está disponível sem um código mapeado.',

            $httpStatus !== null && filled($errorCode) => "A LeadLovers recusou o envio. Erro HTTP {$httpStatus}, código {$errorCode}.",

            $httpStatus !== null => "A LeadLovers recusou o envio com erro HTTP {$httpStatus}.",

            default => 'O envio para a LeadLovers falhou, mas o serviço não informou um motivo específico.',
        };
    }

    private function isTagConfigurationFailure(
        Lead $lead,
        ?string $operation,
    ): bool {
        return $lead->leadlovers_status === 'tag_failed'
            || $operation === 'main_tag';
    }

    private function isMachineConfigurationFailure(
        Lead $lead,
        ?string $operation,
    ): bool {
        if (config('branding.active') === 'tcc') {
            return $lead->leadlovers_status === 'sequence_failed'
                || $operation === 'machine_configuration';
        }

        return $lead->leadlovers_status === 'sequence_failed'
            || ($operation !== null && str_starts_with(
                $operation,
                'machine_'
            ));
    }

    /**
     * @return array{label: string, message: string, next_retry_at: ?string}|null
     */
    private function recoveryPresentation(Lead $lead): ?array
    {
        $recovery = $lead->leadlovers_recovery;

        if (config('branding.active') !== 'tcc'
            || ! config('services.leadlovers.enabled')
            || $lead->leadlovers_status !== 'processing'
            || ! is_array($recovery)
            || ! in_array($recovery['status'] ?? null, ['scheduled', 'processing'], true)) {
            return null;
        }

        $confirming = ($recovery['mode'] ?? null) === 'machine_confirmation';

        if ($recovery['status'] === 'processing') {
            return [
                'label' => $confirming ? 'Confirmando vínculo' : 'Sincronizando com LeadLovers',
                'message' => 'O sistema está consultando a LeadLovers para verificar a sincronização. Não é necessário reenviar o cadastro.',
                'next_retry_at' => null,
            ];
        }

        try {
            $nextRetry = filled($recovery['next_retry_at'] ?? null)
                ? Carbon::parse($recovery['next_retry_at'])
                : null;
        } catch (\Throwable) {
            return null;
        }

        if ($nextRetry === null) {
            return null;
        }

        $message = $confirming
            ? 'A associação à máquina ainda aguarda confirmação. O sistema verificará o vínculo novamente, sem repetir o cadastro.'
            : 'A sincronização será verificada novamente de forma automática.';
        $message .= $nextRetry->isFuture()
            ? ' Próxima tentativa prevista para '.$nextRetry->copy()->timezone(config('app.timezone'))->format('d/m/Y H:i T').'.'
            : ' A próxima tentativa aguarda execução na fila.';

        return [
            'label' => $confirming ? 'Confirmação agendada' : 'Nova tentativa agendada',
            'message' => $message,
            'next_retry_at' => $nextRetry->toIso8601String(),
        ];
    }

    private function normalizeHttpStatus(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $status = (int) $value;

        return $status >= 100 && $status <= 599
            ? $status
            : null;
    }

    private function normalizeErrorCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $code = mb_strtoupper(trim($value));

        return preg_match(
            '/\A[A-Z0-9_.-]{1,100}\z/',
            $code
        ) === 1
            ? $code
            : null;
    }

    private function normalizeOperation(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $operation = trim($value);

        return preg_match(
            '/\A[A-Za-z0-9_.-]{1,64}\z/',
            $operation
        ) === 1
            ? $operation
            : null;
    }
}
