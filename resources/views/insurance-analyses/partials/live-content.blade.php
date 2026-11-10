@php
    $providerNames = ['pottencial' => 'Pottencial', 'too' => 'Too Seguros', 'porto' => 'Porto Seguro', 'tokio' => 'Tokio Marine'];
    $activeProviders = $pageData['providers']['enabled'] ?? [];
    $statusLabels = [
        'pending' => 'Aguardando análise', 'queued' => 'Na fila', 'processing' => 'Em análise',
        'running' => 'Em análise', 'manual_review' => 'Análise manual', 'approved' => 'Aprovada',
        'rejected' => 'Recusada', 'failed' => 'Falha', 'completed' => 'Concluído',
        'completed_with_errors' => 'Concluído com falhas',
        'manual_required' => 'Cotação manual necessária',
    ];
    $formatMoney = static fn ($value, string $currency = 'BRL'): string => $value === null
        ? 'Não informado'
        : ($currency === 'BRL' ? 'R$ ' : $currency.' ').number_format((float) $value, 2, ',', '.');
    $formatDate = static fn (?string $value): string => $value
        ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y') : 'Não informado';
@endphp
@if ($includeLead ?? false)
    @include('insurance-analyses.partials.lead', ['leadData' => $pageData['lead'], 'navigation' => $pageData['navigation']])
@endif

        @include('insurance-analyses.partials.batch', ['batchData' => $pageData['batch'], 'progress' => $pageData['progress'], 'result' => $pageData['result'], 'awaitingBatch' => $pageData['awaiting_batch']])
        @include('insurance-analyses.partials.comparison', ['comparison' => $pageData['comparison']])
        @include('insurance-analyses.partials.companies', ['companies' => $pageData['analyses']])
        @include('insurance-analyses.partials.actions', ['permissions' => $pageData['permissions'], 'actions' => $pageData['actions']])
