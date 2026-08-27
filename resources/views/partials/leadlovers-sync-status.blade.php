@php
    $failure = $failure
        ?? app(\App\Support\LeadLoversInitialFailureCatalog::class)
            ->describe($lead);

    $leadLoversInitialStatus = filled($lead->leadlovers_status)
        ? (string) $lead->leadlovers_status
        : 'pending';
    $leadLoversUpdateStatus = filled($lead->leadlovers_update_status)
        ? (string) $lead->leadlovers_update_status
        : 'idle';
    $leadLoversInitialConfirmed = in_array(
        $leadLoversInitialStatus,
        ['sent', 'send'],
        true
    )
        && (int) $lead->leadlovers_lead_id > 0
        && filled($lead->sent_to_leadlovers_at);

    if ($leadLoversInitialConfirmed) {
        $leadLoversPresentation = match ($leadLoversUpdateStatus) {
            'pending' => [
                'label' => 'Sincronização pendente',
                'class' => 'text-bg-warning text-dark',
                'icon' => 'bi-clock-history',
            ],
            'processing' => [
                'label' => 'Sincronizando com LeadLovers',
                'class' => 'text-bg-warning text-dark',
                'icon' => 'bi-arrow-repeat',
            ],
            'failed' => [
                'label' => 'Falha na sincronização',
                'class' => 'text-bg-danger',
                'icon' => 'bi-cloud-slash',
            ],
            'disabled' => [
                'label' => 'Integração desativada',
                'class' => 'text-bg-secondary',
                'icon' => 'bi-pause-circle',
            ],
            'synced', 'idle', 'waiting_initial_send' => [
                'label' => 'Sincronizado com LeadLovers',
                'class' => 'text-bg-success',
                'icon' => 'bi-cloud-check',
            ],
            default => [
                'label' => 'Status da sincronização desconhecido',
                'class' => 'text-bg-secondary',
                'icon' => 'bi-question-circle',
            ],
        };
    } else {
        $leadLoversPresentation = match (true) {
            $failure['failed'] && $failure['not_sent']
                && $failure['http_status'] === 400 => [
                    'label' => 'Não enviado à LeadLovers',
                    'class' => 'text-bg-danger',
                    'icon' => 'bi-cloud-slash',
                ],
            $failure['failed'] => [
                'label' => 'Falha na integração',
                'class' => 'text-bg-danger',
                'icon' => 'bi-exclamation-octagon',
            ],
            $leadLoversInitialStatus === 'processing' => [
                'label' => 'Enviando à LeadLovers',
                'class' => 'text-bg-warning text-dark',
                'icon' => 'bi-arrow-repeat',
            ],
            $leadLoversInitialStatus === 'disabled' => [
                'label' => 'Integração desativada',
                'class' => 'text-bg-secondary',
                'icon' => 'bi-pause-circle',
            ],
            in_array($leadLoversInitialStatus, ['sent', 'send'], true) => [
                'label' => 'Envio inicial não confirmado',
                'class' => 'text-bg-warning text-dark',
                'icon' => 'bi-exclamation-triangle',
            ],
            default => [
                'label' => 'Aguardando envio inicial',
                'class' => 'text-bg-info',
                'icon' => 'bi-hourglass',
            ],
        };
    }

    $leadLoversFailureMessage = $failure['failed']
        ? $failure['message']
        : ($leadLoversInitialConfirmed && $leadLoversUpdateStatus === 'failed'
            ? 'Os dados foram salvos no sistema, mas a LeadLovers não confirmou a atualização.'
            : null);
    $showLeadLoversFailureMessage = $showLeadLoversFailureMessage
        ?? filled($leadLoversFailureMessage);
    $leadLoversFailureMessageMode = $leadLoversFailureMessageMode
        ?? 'compact';
    $leadLoversTechnicalReference = $failure['failed']
        ? collect([
            $failure['http_status'] !== null
                ? 'HTTP '.$failure['http_status']
                : null,
            $failure['error_code'],
        ])->filter()->implode(' · ')
        : null;
@endphp

<div class="leadlovers-sync-status {{ $leadLoversFailureMessageMode === 'full' ? 'leadlovers-sync-status--full' : 'leadlovers-sync-status--compact' }}">
    @if ($showLeadLoversBadge ?? true)
        <span class="badge {{ $leadLoversPresentation['class'] }}">
            <i
                class="bi {{ $leadLoversPresentation['icon'] }} me-1"
                aria-hidden="true"
            ></i>
            {{ $leadLoversPresentation['label'] }}
        </span>
    @endif

    @if ($showLeadLoversFailureMessage && filled($leadLoversFailureMessage))
        @if ($leadLoversFailureMessageMode === 'full')
            <div
                class="alert alert-danger rounded-4 mt-3 mb-0 leadlovers-sync-status__alert"
                role="alert"
            >
                <div>{{ $leadLoversFailureMessage }}</div>
                @if (filled($leadLoversTechnicalReference))
                    <div class="small mt-1">
                        Referência técnica: {{ $leadLoversTechnicalReference }}
                    </div>
                @endif
            </div>
        @else
            <span class="small leadlovers-sync-status__message">
                {{ $leadLoversFailureMessage }}
            </span>
        @endif
    @endif
</div>
