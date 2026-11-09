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
    <main class="container py-4" data-reanalysis-root data-reanalysis-mode="general">
        @include('insurance-analyses.partials.live-content', ['includeLead' => true])
    </main>
    <div class="position-fixed bottom-0 end-0 p-3 d-flex flex-column gap-2" style="z-index: 1080; max-width: 420px" aria-live="polite" data-reanalysis-feedback></div>
    @include('insurance-analyses.partials.realtime', ['realtime' => $pageData['realtime']])
</body>
</html>
