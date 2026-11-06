<div data-analysis-state="{{ $displayState['state'] ?? $offerStatus }}" aria-busy="{{ ($displayState['processing'] ?? false) ? 'true' : 'false' }}">
@if ($offerStatus === 'approved' && (float) $amount > 0)
    <p class="fs-4 fw-bold mb-0">{{ $formatMoney($amount) }}</p>
    <p class="small text-body-secondary mb-0">Por parcela{{ $count ? ' · '.$count.' parcelas' : '' }}</p>
@else
    <p @class(['mb-0', 'text-danger text-decoration-underline' => $offerStatus === 'rejected', 'text-warning-emphasis text-decoration-underline' => $offerStatus === 'failed', 'text-body-secondary' => ! in_array($offerStatus, ['rejected', 'failed'], true)]) role="status">
        {{ ($displayState['state'] ?? null) === 'manual_required' ? ($manualMessage ?: 'Entre em contato com o corretor.') : ($displayState['label'] ?? match ($offerStatus) {
            'rejected' => 'Cotação recusada',
            'failed' => 'Não foi possível concluir a cotação.',
            'manual_required' => $manualMessage ?: 'Entre em contato com o corretor.',
            'approved' => 'Aprovada. Aguardando confirmação do preço.',
            default => 'Em análise',
        }) }}
    </p>
@endif

</div>
