@php
    $processingState = is_array($processingState ?? null)
        ? $processingState
        : null;
@endphp

@if ($processingState !== null)
    <div
        id="leadDataSyncProcessing{{ $lead->id }}"
        class="manual-tag-processing-notice"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-lead-data-sync-processing
        data-request-id="{{ $processingState['request_id'] }}"
        data-sync-version="{{ $processingState['sync_version'] }}"
    >
        <div class="manual-tag-processing-notice__state">
            <i
                class="bi bi-arrow-repeat manual-tag-processing-notice__icon"
                aria-hidden="true"
            ></i>
            <strong>Em processamento</strong>
        </div>

        <p class="manual-tag-processing-notice__message">
            Corretor <strong>{{ $processingState['corretor_name'] }}</strong>
            solicitou a alteração dos dados do lead e a sincronização com a
            <strong>LeadLovers</strong>.
        </p>
    </div>
@endif
