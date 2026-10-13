@php
    $providerNames = ['pottencial' => 'Pottencial', 'too' => 'Too Seguros', 'porto' => 'Porto Seguro', 'tokio' => 'Tokio Marine'];
    $activeProviders = ['pottencial', 'too'];
    $statusLabels = [
        'pending' => 'Aguardando análise', 'queued' => 'Na fila', 'processing' => 'Em análise',
        'running' => 'Em análise', 'manual_review' => 'Análise manual', 'approved' => 'Aprovada',
        'rejected' => 'Recusada', 'failed' => 'Falha', 'completed' => 'Concluído',
        'completed_with_errors' => 'Concluído com falhas',
    ];
    $formatMoney = static fn ($value, string $currency = 'BRL'): string => $value === null
        ? 'Não informado'
        : ($currency === 'BRL' ? 'R$ ' : $currency.' ').number_format((float) $value, 2, ',', '.');
    $formatDate = static fn (?string $value): string => $value
        ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y') : 'Não informado';
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Análise de {{ $pageData['lead']['nome'] }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
</head>
<body>
    <main class="container py-4">
        @include('insurance-analyses.partials.lead', ['leadData' => $pageData['lead'], 'navigation' => $pageData['navigation']])
        @include('insurance-analyses.partials.batch', ['batchData' => $pageData['batch'], 'progress' => $pageData['progress'], 'result' => $pageData['result'], 'awaitingBatch' => $pageData['awaiting_batch']])
        @include('insurance-analyses.partials.comparison', ['comparison' => $pageData['comparison']])
        @include('insurance-analyses.partials.companies', ['companies' => $pageData['analyses']])
        @include('insurance-analyses.partials.actions', ['permissions' => $pageData['permissions'], 'actions' => $pageData['actions']])
    </main>
    @include('insurance-analyses.partials.realtime', ['realtime' => $pageData['realtime']])
</body>
</html>
