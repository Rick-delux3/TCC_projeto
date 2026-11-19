@if ($insuranceAnalysisEnabled && $lead->latestInsuranceAnalysisBatch?->status === 'processing')
    <div class="d-flex align-items-center gap-2 mt-3 small fw-semibold text-primary-emphasis" role="status">
        <span class="spinner-border spinner-border-sm flex-shrink-0 motion-reduce:animate-none" aria-hidden="true"></span>
        Análise em andamento
    </div>
@endif
