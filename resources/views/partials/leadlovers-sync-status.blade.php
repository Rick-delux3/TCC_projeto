@php
    $leadLoversUpdateStatus = filled($lead->leadlovers_update_status)
        ? (string) $lead->leadlovers_update_status
        : 'idle';

    $leadLoversUpdatePresentation = match ($leadLoversUpdateStatus) {
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
        'synced' => [
            'label' => 'Sincronizado com LeadLovers',
            'class' => 'text-bg-success',
            'icon' => 'bi-cloud-check',
        ],
        'failed' => [
            'label' => 'Falha na sincronização',
            'class' => 'text-bg-danger',
            'icon' => 'bi-cloud-slash',
        ],
        'waiting_initial_send' => [
            'label' => 'Aguardando envio inicial',
            'class' => 'text-bg-info',
            'icon' => 'bi-hourglass',
        ],
        'disabled' => [
            'label' => 'Integração desativada',
            'class' => 'text-bg-secondary',
            'icon' => 'bi-pause-circle',
        ],
        'idle' => [
            'label' => 'Sem atualização pendente',
            'class' => 'text-bg-secondary',
            'icon' => 'bi-cloud',
        ],
        default => [
            'label' => 'Status de sincronização desconhecido',
            'class' => 'text-bg-danger',
            'icon' => 'bi-question-circle',
        ],
    };
@endphp

@if ($showLeadLoversBadge ?? true)
    <span class="badge {{ $leadLoversUpdatePresentation['class'] }}">
        <i
            class="bi {{ $leadLoversUpdatePresentation['icon'] }} me-1"
            aria-hidden="true"
        ></i>
        {{ $leadLoversUpdatePresentation['label'] }}
    </span>
@endif

@if (($showLeadLoversFailureMessage ?? false) && $leadLoversUpdateStatus === 'failed')
    <div class="alert alert-danger rounded-4 mt-3 mb-0" role="alert">
        Os dados foram salvos no sistema, mas a LeadLovers não confirmou a atualização.
    </div>
@endif
