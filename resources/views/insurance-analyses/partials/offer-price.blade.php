@php
    // Pottencial: o preço é a 1ª parcela, que concentra o arredondamento do prêmio.
    $recurring = $recurring ?? null;
    $splitInstallments = $recurring !== null && (int) $count > 1
        && round((float) $recurring, 2) !== round((float) $amount, 2);
@endphp
<div data-analysis-state="{{ $displayState['state'] ?? $offerStatus }}" aria-busy="{{ ($displayState['processing'] ?? false) ? 'true' : 'false' }}">
@if ($offerStatus === 'approved' && (float) $amount > 0)
    <p class="ia-price fs-4 fw-bold mb-0">{{ $formatMoney($amount) }}</p>
    @if ($splitInstallments)
        <p class="small text-body-secondary mb-0">1ª parcela · depois {{ (int) $count - 1 }}× de {{ $formatMoney($recurring) }}</p>
    @else
        <p class="small text-body-secondary mb-0">Por parcela{{ $count ? ' · '.$count.' parcelas' : '' }}</p>
    @endif
@else
    <p @class(['mb-0', 'text-danger text-decoration-underline' => $offerStatus === 'rejected', 'text-warning-emphasis text-decoration-underline' => $offerStatus === 'failed', 'text-body-secondary' => ! in_array($offerStatus, ['rejected', 'failed'], true)]) role="status">
        @if ($displayState['processing'] ?? false)
            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
        @endif
        @if ($offerStatus === 'failed' && ! empty($dangerMessages))
            Falha na cotação
        @else
            {{ ($displayState['state'] ?? null) === 'manual_required' ? ($manualMessage ?: 'Entre em contato com o corretor.') : ($displayState['label'] ?? match ($offerStatus) {
                'rejected' => 'Cotação recusada',
                'failed' => 'Não foi possível concluir a cotação.',
                'manual_required' => $manualMessage ?: 'Entre em contato com o corretor.',
                'approved' => 'Aprovada. Aguardando confirmação do preço.',
                default => 'Em análise',
            }) }}
        @endif
    </p>
@endif
@if (! empty($dangerMessages))
    @unless (in_array($offerStatus, ['failed', 'rejected'], true))
        <p class="small fw-semibold mt-2 mb-0">Outras tentativas sem cotação</p>
    @endunless
    <ul class="small text-start text-break ps-3 mt-2 mb-0" aria-label="Motivos informados pela seguradora">
        @foreach ($dangerMessages as $reason)
            <li>{{ $reason }}</li>
        @endforeach
    </ul>
@endif
</div>
