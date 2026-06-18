@extends('layout-inicial.dashboard_User')

@section('content_w')
@php
    $batchStatusLabels = [
        'pending' => 'Pendente',
        'running' => 'Em andamento',
        'processing' => 'Processando',
        'done' => 'Concluído',
        'completed' => 'Concluído',
        'finished' => 'Finalizado',
        'failed' => 'Falhou',
        'completed_with_errors' => 'Concluído com erros',
    ];

    $batchStatusBadges = [
        'pending' => 'text-bg-warning',
        'running' => 'text-bg-primary',
        'processing' => 'text-bg-primary',
        'done' => 'text-bg-success',
        'completed' => 'text-bg-success',
        'finished' => 'text-bg-success',
        'failed' => 'text-bg-danger',
        'completed_with_errors' => 'text-bg-warning',
    ];

    $analysisStatusLabels = [
        'pending' => 'Pendente',
        'processing' => 'Processando',
        'queued' => 'Na fila',
        'running' => 'Executando',
        'approved' => 'Aprovada',
        'Approved' => 'Aprovada',
        'rejected' => 'Recusada',
        'Denied' => 'Recusada',
        'Refused' => 'Recusada',
        'manual_review' => 'Análise manual',
        'quoted' => 'Cotada',
        'failed' => 'Falhou',
    ];

    $analysisStatusBadges = [
        'pending' => 'text-bg-warning',
        'processing' => 'text-bg-primary',
        'queued' => 'text-bg-warning',
        'running' => 'text-bg-primary',
        'approved' => 'text-bg-success',
        'Approved' => 'text-bg-success',
        'rejected' => 'text-bg-danger',
        'Denied' => 'text-bg-danger',
        'Refused' => 'text-bg-danger',
        'manual_review' => 'text-bg-info',
        'quoted' => 'text-bg-secondary',
        'failed' => 'text-bg-danger',
    ];

    /*
    |--------------------------------------------------------------------------
    | Eventos que representam resultado de análise/reanálise
    |--------------------------------------------------------------------------
    | Esses eventos são usados para mostrar a última rodada do lote
    | e os valores enviados/retornados por companhia.
    */
    $resultEventTypes = [
        'previous_analysis_snapshot',

        'analysis_completed',
        'reanalysis_completed',

        'created_without_body',
        'reanalysis_created_without_body',

        'failed',
        'reanalysis_failed',

        'invalid_response',
        'reanalysis_invalid_response',
    ];

    /*
    |--------------------------------------------------------------------------
    | Formatação de dinheiro
    |--------------------------------------------------------------------------
    */
    $money = function ($value) {
        if ($value === null || $value === '') {
            return 'Não informado';
        }

        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    };

    /*
    |--------------------------------------------------------------------------
    | Último evento de resultado de uma companhia
    |--------------------------------------------------------------------------
    */
    $latestResultEvent = function ($analysis) use ($resultEventTypes) {
        return $analysis->events
            ->whereIn('event_type', $resultEventTypes)
            ->sortByDesc('created_at')
            ->first();
    };

    /*
    |--------------------------------------------------------------------------
    | Identifica se um evento é reanálise
    |--------------------------------------------------------------------------
    | previous_analysis_snapshot não deve aparecer como reanálise,
    | e sim como "análise anterior".
    */
    $isReanalysisEvent = function ($event) {
        if (!$event) {
            return false;
        }

        if ($event->event_type === 'previous_analysis_snapshot') {
            return false;
        }

        return str_starts_with((string) $event->event_type, 'reanalysis')
            || (bool) data_get($event->payload, 'is_reanalysis');
    };
@endphp

<div id="dashboardThemeRoot" class="dashboard-shell" data-dashboard-theme="light">
    <div class="container-fluid px-3 px-lg-4 py-4">

        @if (session('success'))
            <div class="alert alert-success rounded-4 border-0 shadow-sm">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-danger rounded-4 border-0 shadow-sm">
                {{ session('error') }}
            </div>
        @endif

        @if (session('warning'))
            <div class="alert alert-warning rounded-4 border-0 shadow-sm">
                {{ session('warning') }}
            </div>
        @endif

        <div class="card border-0 shadow-sm rounded-5 mb-4">
            <div class="card-body p-4 p-lg-5">
                <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
                    <div>
                        <span class="badge text-bg-primary mb-2">
                            Painel de análises
                        </span>

                        <h1 class="h3 fw-bold mb-2">
                            Acompanhamento das análises
                        </h1>

                        <p class="text-muted mb-0">
                            Consulte os lotes de análises vinculados aos leads da sua imobiliária.
                        </p>
                    </div>

                    <a href="{{ route('Dashboard') }}" class="btn btn-outline-primary">
                        Voltar ao dashboard
                    </a>
                </div>
            </div>
        </div>

        {{-- Cards de estatísticas --}}
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-2">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body">
                        <span class="badge text-bg-primary-subtle text-primary mb-3">Lotes</span>
                        <div class="h3 fw-bold mb-0">{{ $dashboardStats['totalBatches'] }}</div>
                        <div class="small text-muted">registrados</div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-2">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body">
                        <span class="badge text-bg-warning mb-3">Andamento</span>
                        <div class="h3 fw-bold mb-0">{{ $dashboardStats['runningBatches'] }}</div>
                        <div class="small text-muted">em execução</div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-2">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body">
                        <span class="badge text-bg-success mb-3">Concluídos</span>
                        <div class="h3 fw-bold mb-0">{{ $dashboardStats['finishedBatches'] }}</div>
                        <div class="small text-muted">finalizados</div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-2">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body">
                        <span class="badge text-bg-success mb-3">Aprovadas</span>
                        <div class="h3 fw-bold mb-0">{{ $dashboardStats['approvedAnalyses'] }}</div>
                        <div class="small text-muted">análises</div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-2">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body">
                        <span class="badge text-bg-danger mb-3">Recusadas</span>
                        <div class="h3 fw-bold mb-0">{{ $dashboardStats['rejectedAnalyses'] }}</div>
                        <div class="small text-muted">análises</div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-2">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body">
                        <span class="badge text-bg-danger mb-3">Falhas</span>
                        <div class="h3 fw-bold mb-0">{{ $dashboardStats['failedBatches'] }}</div>
                        <div class="small text-muted">lotes</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">

            {{-- Coluna principal --}}
            <div class="col-12 col-xxl-8">

                {{-- Filtros --}}
                <div class="card border-0 shadow-sm rounded-5 mb-4">
                    <div class="card-body p-4">
                        <form method="GET" action="{{ route('insurance-analyses.index') }}" class="row g-3 align-items-end">
                            <div class="col-12 col-lg-6">
                                <label class="form-label small text-muted">
                                    Buscar por lead, e-mail, CPF, cotação ou seguradora
                                </label>

                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    value="{{ $search }}"
                                    placeholder="Ex: João, Pottencial, CPF..."
                                >
                            </div>

                            <div class="col-12 col-lg-3">
                                <label class="form-label small text-muted">
                                    Status do lote
                                </label>

                                <select name="status" class="form-select">
                                    <option value="">Todos</option>

                                    @foreach ($batchStatusLabels as $statusKey => $statusName)
                                        <option value="{{ $statusKey }}" @selected($selectedStatus === $statusKey)>
                                            {{ $statusName }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-12 col-lg-3 d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-fill">
                                    Filtrar
                                </button>

                                <a href="{{ route('insurance-analyses.index') }}" class="btn btn-outline-secondary">
                                    Limpar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                {{-- Lista dos lotes --}}
                <div class="card border-0 shadow-sm rounded-5">
                    <div class="card-body p-4">
                        <div class="mb-4">
                            <span class="badge text-bg-dark mb-2">
                                Histórico
                            </span>

                            <h2 class="h5 fw-bold mb-1">
                                Lotes de análises
                            </h2>

                            <p class="text-muted small mb-0">
                                Cada lote representa um lead enviado para análise nas seguradoras configuradas.
                            </p>
                        </div>

                        @forelse ($batches as $batch)
                            @php
                                $batchStatus = $batch->status ?? 'pending';
                                $batchBadge = $batchStatusBadges[$batchStatus] ?? 'text-bg-secondary';
                                $batchLabel = $batchStatusLabels[$batchStatus] ?? ucfirst(str_replace('_', ' ', $batchStatus));

                                $totalProviders = (int) ($batch->total_providers ?? $batch->analyses->count());
                                $finishedProviders = (int) (
                                    $batch->completed_providers
                                    ?? $batch->analyses
                                        ->whereNotIn('status', ['pending', 'processing', 'queued', 'running'])
                                        ->count()
                                );
                                $progress = $totalProviders > 0
                                    ? min(100, round(($finishedProviders / $totalProviders) * 100))
                                    : 0;

                                $batchResultEvents = $batch->analyses
                                    ->flatMap(fn ($analysis) => $analysis->events)
                                    ->whereIn('event_type', $resultEventTypes)
                                    ->sortByDesc('created_at');

                                $lastBatchResultEvent = $batchResultEvents->first();

                                $lastBatchIsReanalysis = $isReanalysisEvent($lastBatchResultEvent);

                                $lastAttemptId = $lastBatchResultEvent
                                    ? data_get($lastBatchResultEvent->payload, 'attempt_id')
                                    : null;
                            @endphp

                            <div class="border rounded-4 p-3 p-lg-4 mb-3">
                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                                            <span class="badge {{ $batchBadge }}">
                                                {{ $batchLabel }}
                                            </span>

                                            <span class="small text-muted">
                                                Lote #{{ $batch->id }}
                                            </span>

                                            {{-- Mostra se a última rodada registrada foi análise ou reanálise --}}
                                            @if ($lastBatchResultEvent)
                                                <span class="badge {{ $lastBatchIsReanalysis ? 'text-bg-warning' : 'text-bg-info' }}">
                                                    {{ $lastBatchIsReanalysis ? 'Última rodada: Reanálise' : 'Última rodada: Análise' }}
                                                </span>
                                            @endif
                                        </div>

                                        <h3 class="h6 fw-bold mb-1">
                                            {{ $batch->lead?->nome ?? 'Lead não informado' }}
                                        </h3>

                                        <p class="small text-muted mb-0">
                                            {{ $batch->lead?->email ?? 'E-mail não informado' }}
                                        </p>
                                    </div>

                                    <div class="text-lg-end">
                                        <div class="small text-muted">
                                            Criado em
                                        </div>

                                        <div class="fw-semibold">
                                            {{ $batch->created_at?->format('d/m/Y H:i') }}
                                        </div>
                                    </div>
                                </div>

                                <div class="progress mt-3" style="height: 8px;">
                                    <div
                                        class="progress-bar"
                                        style="width: {{ $progress }}%;"
                                        aria-valuenow="{{ $progress }}"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                    ></div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <span class="small text-muted">
                                        {{ $finishedProviders }} de {{ $totalProviders }} análises finalizadas
                                    </span>

                                    <span class="small fw-semibold">
                                        {{ $progress }}%
                                    </span>
                                </div>

                                <div class="row g-2 mt-3">
                                    @foreach ($batch->analyses as $analysis)
                                        @php
                                            $analysisStatus = $analysis->status ?? 'pending';
                                            $analysisBadge = $analysisStatusBadges[$analysisStatus] ?? 'text-bg-secondary';
                                            $analysisLabel = $analysisStatusLabels[$analysisStatus] ?? ucfirst(str_replace('_', ' ', $analysisStatus));

                                            /*
                                            |--------------------------------------------------------------------------
                                            | Último resultado salvo em eventos
                                            |--------------------------------------------------------------------------
                                            | A análise atual guarda o estado mais recente, mas o histórico verdadeiro
                                            | está nos eventos.
                                            */
                                            $lastResultEvent = $latestResultEvent($analysis);

                                            $lastPayload = (array) ($lastResultEvent?->payload ?? []);
                                            $lastResponse = (array) ($lastResultEvent?->response ?? []);

                                            $analysisIsReanalysis = $isReanalysisEvent($lastResultEvent);

                                            $eventPremium =
                                                data_get($lastResponse, 'commercial_premium')
                                                ?? data_get($lastResponse, 'premium_amount')
                                                ?? data_get($lastResponse, 'gross_premium')
                                                ?? $analysis->commercial_premium
                                                ?? $analysis->premium_amount
                                                ?? $analysis->gross_premium
                                                ?? null;

                                            $eventRentAmount = data_get($lastPayload, 'rent_amount');
                                            $eventTotalMonthlyAmount = data_get($lastPayload, 'total_monthly_amount');
                                        @endphp

                                        <div class="col-12 col-md-6">
                                            <div class="bg-light border rounded-4 p-3 h-100">
                                                <div class="d-flex justify-content-between align-items-start gap-2">
                                                    <div>
                                                        <div class="fw-semibold">
                                                            {{ ucfirst($analysis->provider ?? 'Provider') }}
                                                        </div>

                                                        <div class="small text-muted">
                                                            {{ $analysis->quote_id ? 'Cotação: ' . $analysis->quote_id : 'Sem quote_id' }}
                                                        </div>
                                                    </div>

                                                @if ($lastResultEvent)
                                                    <div class="mt-2">
                                                        <span class="badge {{ $analysisIsReanalysis ? 'text-bg-warning' : 'text-bg-info' }}">
                                                            {{ $analysisIsReanalysis ? 'Resultado da reanálise' : 'Resultado da análise' }}
                                                        </span>
                                                    </div>
                                                @endif
                                                    <span class="badge {{ $analysisBadge }}">
                                                        {{ $analysisLabel }}
                                                    </span>
                                                </div>

                                                @if ($eventPremium)
                                                    <div class="small text-muted mt-2">
                                                        Prêmio retornado:
                                                        <strong>{{ $money($eventPremium) }}</strong>
                                                    </div>
                                                @endif

                                                @if ($eventRentAmount !== null && $eventRentAmount !== '')
                                                    <div class="small text-muted mt-1">
                                                        Aluguel enviado:
                                                        <strong>{{ $money($eventRentAmount) }}</strong>
                                                    </div>
                                                @endif

                                                @if ($eventTotalMonthlyAmount !== null && $eventTotalMonthlyAmount !== '')
                                                    <div class="small text-muted mt-1">
                                                        Total mensal enviado:
                                                        <strong>{{ $money($eventTotalMonthlyAmount) }}</strong>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="d-flex justify-content-end mt-3">
                                    <a
                                        href="{{ route('insurance-analyses.show', $batch) }}"
                                        class="btn btn-sm btn-outline-primary"
                                    >
                                        Ver detalhes
                                    </a>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-5">
                                <span class="badge text-bg-light border mb-3">
                                    Sem registros
                                </span>

                                <h3 class="h5 fw-bold">
                                    Nenhum lote de análise encontrado.
                                </h3>

                                <p class="text-muted mb-0">
                                    Assim que um lead for enviado para análise, ele aparecerá nesta página.
                                </p>
                            </div>
                        @endforelse

                        @if ($batches->hasPages())
                            <div class="mt-4">
                                {{ $batches->onEachSide(1)->links('pagination::bootstrap-5') }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Coluna lateral --}}
            <div class="col-12 col-xxl-4">
                <div class="card border-0 shadow-sm rounded-5 mb-4">
                    <div class="card-body p-4">
                        <span class="badge text-bg-primary mb-2">
                            Agora
                        </span>

                        <h2 class="h5 fw-bold mb-3">
                            Análises em andamento
                        </h2>

                        @forelse ($inProgressAnalyses as $analysis)
                            @php
                                $analysisStatus = $analysis->status ?? 'pending';
                                $analysisBadge = $analysisStatusBadges[$analysisStatus] ?? 'text-bg-secondary';
                                $analysisLabel = $analysisStatusLabels[$analysisStatus] ?? ucfirst(str_replace('_', ' ', $analysisStatus));
                            @endphp

                            <div class="border rounded-4 p-3 mb-3">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <div class="fw-semibold">
                                            {{ $analysis->lead?->nome ?? 'Lead sem nome' }}
                                        </div>

                                        <div class="small text-muted">
                                            {{ ucfirst($analysis->provider ?? 'Provider') }}
                                        </div>
                                    </div>

                                    <span class="badge {{ $analysisBadge }}">
                                        {{ $analysisLabel }}
                                    </span>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-4">
                                <i class="bi bi-check-circle text-success fs-3"></i>

                                <p class="text-muted small mb-0 mt-2">
                                    Nenhuma análise em andamento no momento.
                                </p>
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-5">
                    <div class="card-body p-4">
                        <span class="badge text-bg-info mb-2">
                            Status
                        </span>

                        <h2 class="h5 fw-bold mb-3">
                            Como interpretar
                        </h2>

                        <div class="vstack gap-3">
                            <div>
                                <span class="badge text-bg-warning mb-1">Pendente</span>
                                <div class="small text-muted">O lote foi criado e aguarda processamento.</div>
                            </div>

                            <div>
                                <span class="badge text-bg-primary mb-1">Em andamento</span>
                                <div class="small text-muted">O sistema está processando as análises.</div>
                            </div>

                            <div>
                                <span class="badge text-bg-success mb-1">Concluído</span>
                                <div class="small text-muted">Todas as análises do lote foram finalizadas.</div>
                            </div>

                            <div>
                                <span class="badge text-bg-danger mb-1">Falhou</span>
                                <div class="small text-muted">Ocorreu erro em uma ou mais análises.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>
@endsection