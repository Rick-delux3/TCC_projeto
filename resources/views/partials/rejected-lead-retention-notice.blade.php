@php
    $rejectedRetentionDueAt = $lead->rejected_deletion_due_at;
    $hasConfirmedRejectedRetention =
        $lead->leadlovers_confirmed_final_tag_key === 'ruim'
        && $rejectedRetentionDueAt !== null;
    $tagOperation = $lead->relationLoaded('leadLoversTagOperation')
        ? $lead->leadLoversTagOperation
        : null;
    $resultChangeInProgress = $tagOperation !== null
        && in_array($tagOperation->phase, [
            \App\Services\LeadLoversTagOperationCoordinator::PHASE_PENDING,
            \App\Services\LeadLoversTagOperationCoordinator::PHASE_POSTING,
            \App\Services\LeadLoversTagOperationCoordinator::PHASE_CONFIRMING,
        ], true)
        && filled($tagOperation->desired_tag_key)
        && $tagOperation->desired_tag_key !== 'ruim';

    if ($hasConfirmedRejectedRetention) {
        $remainingSeconds = now()->utc()->diffInSeconds(
            $rejectedRetentionDueAt->copy()->utc(),
            false
        );
        $remainingDays = max(1, (int) ceil($remainingSeconds / 86400));
        $showExactDeletionDate = $remainingSeconds <= 7 * 86400;
        $displayDeletionDueAt = $rejectedRetentionDueAt
            ->copy()
            ->setTimezone('America/Sao_Paulo');
    }
@endphp

@if ($hasConfirmedRejectedRetention)
    <aside
        class="alert {{ $resultChangeInProgress ? 'alert-info' : 'alert-warning' }} d-flex flex-column flex-sm-row align-items-sm-center gap-2 mt-3 mb-0 py-2 px-3 rounded-4 small"
        role="status"
        aria-live="polite"
        data-rejected-retention-notice
    >
        <i
            class="bi {{ $resultChangeInProgress ? 'bi-arrow-repeat' : 'bi-clock-history' }} flex-shrink-0"
            aria-hidden="true"
        ></i>
        <span>
            @if ($resultChangeInProgress)
                Alteração em processamento. A exclusão será cancelada após confirmação da LeadLovers.
            @elseif ($showExactDeletionDate)
                Exclusão automática em {{ $displayDeletionDueAt->format('d/m/Y') }} ás {{ $displayDeletionDueAt->format('H:i') }}.
            @else
                Será excluido automaticamente em {{ $remainingDays }} dias se continuar recusado.
            @endif
        </span>
    </aside>
@endif
