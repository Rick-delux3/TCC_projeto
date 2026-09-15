@php
    $processingState = is_array($processingState ?? null)
        ? $processingState
        : null;
@endphp

@if ($processingState !== null)
    <div
        id="manualLeadTagProcessing{{ $lead->id }}"
        class="manual-tag-processing-notice"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-manual-tag-processing
        data-request-id="{{ $processingState['request_id'] }}"
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
            solicitou a aplicação da tag
            <strong>{{ $processingState['result_label'] }}</strong>.
        </p>
    </div>
@endif
