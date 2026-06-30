@extends('layout-inicial.dashboard_User')

@section('content_w')
@php
    /*
    |--------------------------------------------------------------------------
    | Labels de status dos lotes
    |--------------------------------------------------------------------------
    */
    $batchStatusLabels = [
        'pending' => 'Pendente',
        'running' => 'Em andamento',
        'processing' => 'Processando',
        'done' => 'Concluído',
        'completed' => 'Concluído',
        'finished' => 'Finalizado',
        'failed' => 'Falhou',
        'error' => 'Erro',
    ];

    $batchStatusBadges = [
        'pending' => 'text-bg-warning',
        'running' => 'text-bg-primary',
        'processing' => 'text-bg-primary',
        'done' => 'text-bg-success',
        'completed' => 'text-bg-success',
        'finished' => 'text-bg-success',
        'failed' => 'text-bg-danger',
        'error' => 'text-bg-danger',
    ];

    /*
    |--------------------------------------------------------------------------
    | Labels de status das análises individuais
    |--------------------------------------------------------------------------
    */
    $analysisStatusLabels = [
        'pending' => 'Pendente',
        'processing' => 'Processando',
        'queued' => 'Na fila',
        'running' => 'Executando',
        'approved' => 'Aprovada',
        'Approved' => 'Aprovada',
        'quoted' => 'Cotada',
        'manual_review' => 'Análise manual',
        'rejected' => 'Recusada',
        'denied' => 'Recusada',
        'Denied' => 'Recusada',
        'Refused' => 'Recusada',
        'failed' => 'Falhou',
        'error' => 'Erro',
    ];

    $analysisStatusBadges = [
        'pending' => 'text-bg-warning',
        'processing' => 'text-bg-primary',
        'queued' => 'text-bg-warning',
        'running' => 'text-bg-primary',
        'approved' => 'text-bg-success',
        'Approved' => 'text-bg-success',
        'quoted' => 'text-bg-secondary',
        'manual_review' => 'text-bg-info',
        'rejected' => 'text-bg-danger',
        'denied' => 'text-bg-danger',
        'Denied' => 'text-bg-danger',
        'Refused' => 'text-bg-danger',
        'failed' => 'text-bg-danger',
        'error' => 'text-bg-danger',
    ];

    /*
    |--------------------------------------------------------------------------
    | Eventos importantes para histórico de análise/reanálise
    |--------------------------------------------------------------------------
    | Esses eventos são criados pelos Jobs atualizados.
    | Eles guardam o snapshot dos valores enviados e da resposta da API.
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
    | Eventos de comunicação
    |--------------------------------------------------------------------------
    | Usados para mostrar se PDF/e-mail foi gerado/enviado.
    */
    $communicationEventTypes = [
        'pdf_generated',
        'email_queued',
        'email_sent',
        'email_failed',
    ];

    /*
    |--------------------------------------------------------------------------
    | Labels amigáveis dos eventos
    |--------------------------------------------------------------------------
    */
    $eventTypeLabels = [
        'previous_analysis_snapshot' => 'Análise anterior',
        'analysis_started' => 'Análise iniciada',
        'analysis_completed' => 'Análise concluída',

        'reanalysis_requested' => 'Reanálise solicitada',
        'reanalysis_started' => 'Reanálise iniciada',
        'reanalysis_sent_to_api' => 'Reanálise enviada para API',
        'reanalysis_completed' => 'Reanálise concluída',

        'created_without_body' => 'Análise recebida sem retorno completo',
        'reanalysis_created_without_body' => 'Reanálise recebida sem retorno completo',

        'failed' => 'Falha na análise',
        'reanalysis_failed' => 'Falha na reanálise',

        'invalid_response' => 'Resposta inválida',
        'reanalysis_invalid_response' => 'Resposta inválida na reanálise',

        'pdf_generated' => 'PDF gerado',
        'email_queued' => 'E-mail na fila',
        'email_sent' => 'E-mail enviado',
        'email_failed' => 'Falha no e-mail',
    ];

    /*
    |--------------------------------------------------------------------------
    | Cores dos eventos
    |--------------------------------------------------------------------------
    */
    $eventTypeBadges = [
        'previous_analysis_snapshot' => 'text-bg-secondary',
        'analysis_completed' => 'text-bg-primary',
        'reanalysis_completed' => 'text-bg-warning',

        'analysis_started' => 'text-bg-secondary',
        'reanalysis_started' => 'text-bg-warning',
        'reanalysis_requested' => 'text-bg-warning',
        'reanalysis_sent_to_api' => 'text-bg-warning',

        'created_without_body' => 'text-bg-info',
        'reanalysis_created_without_body' => 'text-bg-info',

        'failed' => 'text-bg-danger',
        'reanalysis_failed' => 'text-bg-danger',

        'invalid_response' => 'text-bg-danger',
        'reanalysis_invalid_response' => 'text-bg-danger',

        'pdf_generated' => 'text-bg-dark',
        'email_queued' => 'text-bg-secondary',
        'email_sent' => 'text-bg-success',
        'email_failed' => 'text-bg-danger',
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
    | Busca o último evento de resultado de uma análise
    |--------------------------------------------------------------------------
    | Serve para mostrar na listagem o último resultado daquela companhia.
    */
    $latestResultEvent = function ($analysis) use ($resultEventTypes) {
        return $analysis->events
            ->whereIn('event_type', $resultEventTypes)
            ->sortByDesc('created_at')
            ->first();
    };


   /*
    |--------------------------------------------------------------------------
    | Identifica se o evento representa uma reanálise
    |--------------------------------------------------------------------------
    | previous_analysis_snapshot é um snapshot da análise antiga,
    | então não deve ser tratado visualmente como reanálise.
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

    $batchStatus = $batch->status ?? 'pending';
    $batchLabel = $batchStatusLabels[$batchStatus] ?? ucfirst(str_replace('_', ' ', $batchStatus));
    $batchBadge = $batchStatusBadges[$batchStatus] ?? 'text-bg-secondary';

    $lead = $batch->lead;

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
@endphp

<div id="dashboardThemeRoot" class="dashboard-shell" data-dashboard-theme="light">
    <div class="container-fluid px-3 px-lg-4 py-4">

        {{-- Mensagens de sessão --}}
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

        {{-- Cabeçalho --}}
        <div class="card border-0 shadow-sm rounded-5 mb-4">
            <div class="card-body p-4 p-lg-5">
                <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
                    <div>
                        <span class="badge {{ $batchBadge }} mb-2">
                            {{ $batchLabel }}
                        </span>

                        <h1 class="h3 fw-bold mb-2">
                            Detalhes do lote #{{ $batch->id }}
                        </h1>

                        <p class="text-muted mb-0">
                            Acompanhe as análises enviadas para as seguradoras neste lote.
                        </p>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('insurance-analyses.index') }}" class="btn btn-outline-secondary">
                            Voltar para análises
                        </a>

                        <a href="{{ route('Dashboard') }}" class="btn btn-outline-primary">
                            Voltar ao dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>

        {{-- Resumo do lote --}}
        <div class="row g-4 mb-4">
            <div class="col-12 col-xl-8">
                <div class="card border-0 shadow-sm rounded-5 h-100">
                    <div class="card-body p-4">
                        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                            <div>
                                <span class="badge text-bg-dark mb-2">
                                    Lead analisado
                                </span>

                                <h2 class="h5 fw-bold mb-1">
                                    {{ $lead?->nome ?? 'Lead não informado' }}
                                </h2>

                                <p class="text-muted small mb-0">
                                    {{ $lead?->email ?? 'E-mail não informado' }}
                                </p>
                            </div>

                            <div class="text-lg-end">
                                <div class="small text-muted">
                                    Criado em
                                </div>

                                <div class="fw-semibold">
                                    {{ $batch->created_at?->format('d/m/Y H:i') ?? 'Data não informada' }}
                                </div>
                            </div>
                        </div>

                        <div class="progress mb-2" style="height: 9px;">
                            <div
                                class="progress-bar"
                                style="width: {{ $progress }}%;"
                                role="progressbar"
                                aria-valuenow="{{ $progress }}"
                                aria-valuemin="0"
                                aria-valuemax="100"
                            ></div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small text-muted">
                                {{ $finishedProviders }} de {{ $totalProviders }} análises finalizadas
                            </span>

                            <span class="small fw-bold">
                                {{ $progress }}%
                            </span>
                        </div>

                        @if ($batch->error_message)
                            <div class="alert alert-danger rounded-4 mt-4 mb-0">
                                <strong>Erro no lote:</strong>
                                {{ $batch->error_message }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Dados principais do lead --}}
            <div class="col-12 col-xl-4">
                <div class="card border-0 shadow-sm rounded-5 h-100">
                    <div class="card-body p-4">
                        <span class="badge text-bg-secondary mb-3">
                            Dados do lead
                        </span>

                        <div class="vstack gap-3">
                            <div>
                                <div class="small text-muted">Telefone</div>
                                <div class="fw-semibold">
                                    {{ $lead?->tel ?? 'Não informado' }}
                                </div>
                            </div>

                            <div>
                                <div class="small text-muted">CPF/CNPJ</div>
                                <div class="fw-semibold">
                                    {{ $lead?->cpf ?? 'Não informado' }}
                                </div>
                            </div>

                            <div>
                                <div class="small text-muted">Tipo de solicitante</div>
                                <div class="fw-semibold">
                                    {{ $lead?->tipo_solicitante ?? 'Não informado' }}
                                </div>
                            </div>

                            <div>
                                <div class="small text-muted">Valor total de encargos</div>
                                <div class="fw-bold text-primary">
                                    R$ {{ number_format((float) ($lead?->despesas?->valor_total_encargos ?? 0), 2, ',', '.') }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Análises por seguradora --}}
        <div class="row g-4">

            <div class="col-12 col-xxl-8">
                <div class="card border-0 shadow-sm rounded-5">
                    <div class="card-body p-4">
                        <div class="mb-4">
                            <span class="badge text-bg-primary mb-2">
                                Seguradoras
                            </span>

                            <h2 class="h5 fw-bold mb-1">
                                Análises deste lote
                            </h2>

                            <p class="text-muted small mb-0">
                                Cada card representa uma análise enviada para uma companhia/provedor.
                            </p>
                        </div>

                        @forelse ($batch->analyses as $analysis)
                            @php
                                $analysisStatus = $analysis->status ?? 'pending';
                                $analysisLabel = $analysisStatusLabels[$analysisStatus] ?? ucfirst(str_replace('_', ' ', $analysisStatus));
                                $analysisBadge = $analysisStatusBadges[$analysisStatus] ?? 'text-bg-secondary';
                                
                                $tooAutoStopped = $analysis->provider === 'too'
                                    && (bool) data_get($analysis->response_payload, 'too_status_check_stopped', false);

                                $tooManualSyncAvailable = $analysis->provider === 'too'
                                    && (bool) data_get($analysis->response_payload, 'too_manual_sync_available', false);

                                $canManualSyncToo = $analysis->provider === 'too'
                                    && filled($analysis->proposal_id)
                                    && $tooAutoStopped
                                    && $tooManualSyncAvailable
                                    && in_array($analysis->status, ['manual_review', 'pending', 'processing'], true)
                                    && !in_array($analysis->status, ['approved', 'rejected', 'failed'], true);

                                $canDefaultSync = $analysis->provider !== 'too'
                                    && filled($analysis->quote_id)
                                    && in_array($analysis->status, ['manual_review', 'quoted', 'pending', 'processing'], true);

                                $canSync = $canDefaultSync || $canManualSyncToo;

                                /*
                                * Evita confusão:
                                * se a Too está apenas aguardando retorno, não mostramos "Reenviar análise".
                                * Reenviar criaria outra proposta, o que não é o objetivo.
                                */
                                $canRetry = in_array($analysis->status, ['failed', 'rejected'], true)
                                    || (
                                        $analysis->provider !== 'too'
                                        && in_array($analysis->status, ['manual_review'], true)
                                    );

                                $premium = $analysis->commercial_premium
                                    ?? $analysis->premium_amount
                                    ?? $analysis->gross_premium
                                    ?? null;

                                /*
                                |--------------------------------------------------------------------------
                                | Eventos de resultado da companhia
                                |--------------------------------------------------------------------------
                                | Aqui entram:
                                | - análise anterior;
                                | - análise original;
                                | - reanálise;
                                | - falhas relevantes.
                                */
                                $resultEvents = $analysis->events
                                    ->whereIn('event_type', $resultEventTypes)
                                    ->sortByDesc('created_at')
                                    ->values();

                                /*
                                |--------------------------------------------------------------------------
                                | Eventos de comunicação
                                |--------------------------------------------------------------------------
                                | Aqui entram:
                                | - PDF gerado;
                                | - e-mail na fila;
                                | - e-mail enviado;
                                | - falha no e-mail.
                                */
                                $communicationEvents = $analysis->events
                                    ->whereIn('event_type', $communicationEventTypes)
                                    ->sortByDesc('created_at')
                                    ->values();
                            @endphp

                            <div class="border rounded-4 p-3 p-lg-4 mb-3">
                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <span class="badge {{ $analysisBadge }}">
                                                {{ $analysisLabel }}
                                            </span>

                                            <span class="small text-muted">
                                                Análise #{{ $analysis->id }}
                                            </span>
                                        </div>

                                        <h3 class="h6 fw-bold mb-1">
                                            {{ ucfirst($analysis->provider ?? 'Provider não informado') }}
                                        </h3>

                                        <p class="text-muted small mb-0">
                                            {{ $analysis->product ?? $analysis->product_key ?? 'Produto não informado' }}
                                        </p>
                                    </div>

                                    <div class="text-lg-end">
                                        <div class="small text-muted">
                                            Cotação
                                        </div>

                                        <div class="fw-semibold">
                                            {{ $analysis->quote_id ?? $analysis->quote_number ?? 'Não gerada' }}
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-3 mt-3">
                                    <div class="col-12 col-md-4">
                                        <div class="bg-light rounded-4 border p-3 h-100">
                                            <div class="small text-muted">Prêmio</div>

                                            <div class="fw-bold">
                                                @if ($premium)
                                                    R$ {{ number_format((float) $premium, 2, ',', '.') }}
                                                @else
                                                    Não informado
                                                @endif
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <div class="bg-light rounded-4 border p-3 h-100">
                                            <div class="small text-muted">Importância segurada</div>

                                            <div class="fw-bold">
                                                @if ($analysis->insured_amount)
                                                    R$ {{ number_format((float) $analysis->insured_amount, 2, ',', '.') }}
                                                @else
                                                    Não informado
                                                @endif
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <div class="bg-light rounded-4 border p-3 h-100">
                                            <div class="small text-muted">Status da companhia</div>

                                            <div class="fw-bold">
                                                {{ $analysis->provider_status ?? 'Não informado' }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                @if ($analysis->error_message)
                                    <div class="alert alert-danger rounded-4 mt-3 mb-0">
                                        <strong>Erro:</strong>
                                        {{ $analysis->error_message }}
                                    </div>
                                @endif

                                @if($canManualSyncToo)
                                    <div class="alert alert-info rounded-4 mt-3 mb-0">
                                        <strong>A Too ainda está analisando o crédito.</strong>

                                        <div class="small mt-1">
                                            A verificação automática foi encerrada após
                                            {{ data_get($analysis->response_payload, 'too_status_check_attempts', 'algumas') }}
                                            tentativas. Você pode verificar manualmente se a companhia já atualizou o status.
                                        </div>
                                    </div>
                                @endif

                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-3">
                                    <div class="small text-muted">
                                        Solicitada em:
                                        {{ $analysis->requested_at ? $analysis->requested_at->format('d/m/Y H:i') : $analysis->created_at?->format('d/m/Y H:i') }}

                                        <br>

                                        Finalizada em:
                                        {{ $analysis->finished_at ? $analysis->finished_at->format('d/m/Y H:i') : 'Em andamento' }}
                                    </div>

                                    <div class="d-flex flex-wrap gap-2">
                                        @if ($canSync)
                                            <form method="POST" action="{{ route('insurance-analyses.sync-status', $analysis) }}">
                                                @csrf

                                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                                    @if ($canManualSyncToo)
                                                        Verificar status na Too
                                                    @else
                                                        Sincronizar status
                                                    @endif
                                                </button>
                                            </form>
                                        @endif

                                        @if ($canRetry)
                                            <form method="POST" action="{{ route('insurance-analyses.retry', $analysis) }}">
                                                @csrf

                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    Reenviar análise
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>

                                {{-- Histórico de análise e reanálise --}}
                                @if ($resultEvents->count() > 0)
                                    <div class="accordion mt-4" id="analysisResultsAccordion{{ $analysis->id }}">
                                        <div class="accordion-item border rounded-4 overflow-hidden">
                                            <h2 class="accordion-header">
                                                <button
                                                    class="accordion-button"
                                                    type="button"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#analysisResultsCollapse{{ $analysis->id }}"
                                                    aria-expanded="true"
                                                    aria-controls="analysisResultsCollapse{{ $analysis->id }}"
                                                >
                                                    Histórico de análises e reanálises
                                                </button>
                                            </h2>

                                            <div
                                                id="analysisResultsCollapse{{ $analysis->id }}"
                                                class="accordion-collapse collapse show"
                                                data-bs-parent="#analysisResultsAccordion{{ $analysis->id }}"
                                            >
                                                <div class="accordion-body">
                                                    <div class="vstack gap-3">

                                                        @foreach ($resultEvents as $event)
                                                            @php
                                                                /*
                                                                |--------------------------------------------------------------------------
                                                                | Dados do evento
                                                                |--------------------------------------------------------------------------
                                                                | payload = valores enviados para a API
                                                                | response = valores retornados pela companhia
                                                                */
                                                                $payload = (array) ($event->payload ?? []);
                                                                $response = (array) ($event->response ?? []);

                                                                /*
                                                                |--------------------------------------------------------------------------
                                                                | Tipo visual do evento
                                                                |--------------------------------------------------------------------------
                                                                */
                                                                if ($event->event_type === 'previous_analysis_snapshot') {
                                                                    $eventLabel = 'Análise anterior';
                                                                    $eventBadge = 'text-bg-secondary';
                                                                } else {
                                                                    $eventIsReanalysis = $isReanalysisEvent($event);

                                                                    $eventLabel = $eventIsReanalysis
                                                                        ? 'Reanálise'
                                                                        : 'Análise original';

                                                                    $eventBadge = $eventIsReanalysis
                                                                        ? 'text-bg-warning'
                                                                        : 'text-bg-primary';
                                                                }

                                                                $attemptId = data_get($payload, 'attempt_id');

                                                                /*
                                                                |--------------------------------------------------------------------------
                                                                | Valores enviados
                                                                |--------------------------------------------------------------------------
                                                                */
                                                                $rentAmount = data_get($payload, 'rent_amount');
                                                                $chargesAmount = data_get($payload, 'charges_amount');
                                                                $totalMonthlyAmount = data_get($payload, 'total_monthly_amount');

                                                                /*
                                                                |--------------------------------------------------------------------------
                                                                | Valores retornados
                                                                |--------------------------------------------------------------------------
                                                                */
                                                                $premiumAmount =
                                                                    data_get($response, 'commercial_premium')
                                                                    ?? data_get($response, 'premium_amount')
                                                                    ?? data_get($response, 'gross_premium');

                                                                $insuredAmount = data_get($response, 'insured_amount');
                                                                $quoteId = data_get($response, 'quote_id');
                                                                $quoteNumber = data_get($response, 'quote_number');
                                                                $providerStatus = data_get($response, 'provider_status');

                                                                /*
                                                                |--------------------------------------------------------------------------
                                                                | PDF e e-mail da mesma rodada
                                                                |--------------------------------------------------------------------------
                                                                */
                                                                $pdfGenerated = $analysis->events->contains(function ($technicalEvent) use ($event, $attemptId) {
                                                                    return $technicalEvent->event_type === 'pdf_generated'
                                                                        && (
                                                                            data_get($technicalEvent->payload, 'source_event_id') === $event->id
                                                                            || data_get($technicalEvent->payload, 'attempt_id') === $attemptId
                                                                        );
                                                                });

                                                                $emailSent = $analysis->events->contains(function ($technicalEvent) use ($attemptId) {
                                                                    return $technicalEvent->event_type === 'email_sent'
                                                                        && data_get($technicalEvent->payload, 'attempt_id') === $attemptId;
                                                                });
                                                            @endphp

                                                            <div class="border rounded-4 p-3">
                                                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                                                                    <div>
                                                                        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                                                            <span class="badge {{ $eventBadge }}">
                                                                                {{ $eventLabel }}
                                                                            </span>

                                                                            <span class="badge text-bg-light border text-dark">
                                                                                {{ $analysisStatusLabels[$event->status] ?? ucfirst(str_replace('_', ' ', $event->status ?? 'status')) }}
                                                                            </span>

                                                                            @if ($pdfGenerated)
                                                                                <span class="badge text-bg-dark">
                                                                                    PDF gerado
                                                                                </span>
                                                                            @endif

                                                                            @if ($emailSent)
                                                                                <span class="badge text-bg-success">
                                                                                    E-mail enviado
                                                                                </span>
                                                                            @endif
                                                                        </div>

                                                                        <div class="fw-semibold">
                                                                            {{ $event->message ?? 'Resultado registrado.' }}
                                                                        </div>

                                                                        <div class="small text-muted">
                                                                            {{ $event->created_at?->format('d/m/Y H:i') }}
                                                                        </div>
                                                                    </div>

                                                                    <div class="text-lg-end">
                                                                        <div class="small text-muted">
                                                                            Cotação
                                                                        </div>

                                                                        <div class="fw-bold">
                                                                            {{ $quoteId ?? $quoteNumber ?? 'Não informada' }}
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                                <div class="row g-3">

                                                                    {{-- Valores enviados para a API --}}
                                                                    <div class="col-12 col-lg-6">
                                                                        <div class="bg-light rounded-4 border p-3 h-100">
                                                                            <div class="fw-bold mb-2">
                                                                                Valores enviados
                                                                            </div>

                                                                            <div class="small text-muted">Aluguel</div>
                                                                            <div class="fw-semibold mb-2">
                                                                                {{ $money($rentAmount) }}
                                                                            </div>

                                                                            <div class="small text-muted">Encargos</div>
                                                                            <div class="fw-semibold mb-2">
                                                                                {{ $money($chargesAmount) }}
                                                                            </div>

                                                                            <div class="small text-muted">Total mensal</div>
                                                                            <div class="fw-semibold">
                                                                                {{ $money($totalMonthlyAmount) }}
                                                                            </div>
                                                                        </div>
                                                                    </div>

                                                                    {{-- Valores retornados pela companhia --}}
                                                                    <div class="col-12 col-lg-6">
                                                                        <div class="bg-light rounded-4 border p-3 h-100">
                                                                            <div class="fw-bold mb-2">
                                                                                Valores retornados
                                                                            </div>

                                                                            <div class="small text-muted">Prêmio retornado</div>
                                                                            <div class="fw-semibold mb-2">
                                                                                {{ $money($premiumAmount) }}
                                                                            </div>

                                                                            <div class="small text-muted">Importância segurada</div>
                                                                            <div class="fw-semibold mb-2">
                                                                                {{ $money($insuredAmount) }}
                                                                            </div>

                                                                            <div class="small text-muted">Status da companhia</div>
                                                                            <div class="fw-semibold">
                                                                                {{ $providerStatus ?? 'Não informado' }}
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                                @if ($attemptId)
                                                                    <div class="small text-muted mt-3">
                                                                        Rodada: {{ $attemptId }}
                                                                    </div>
                                                                @endif
                                                            </div>
                                                        @endforeach

                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                {{-- Eventos técnicos de PDF e e-mail --}}
                                @if ($communicationEvents->count() > 0)
                                    <div class="accordion mt-3" id="technicalEventsAccordion{{ $analysis->id }}">
                                        <div class="accordion-item border rounded-4 overflow-hidden">
                                            <h2 class="accordion-header">
                                                <button
                                                    class="accordion-button collapsed"
                                                    type="button"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#technicalEventsCollapse{{ $analysis->id }}"
                                                    aria-expanded="false"
                                                    aria-controls="technicalEventsCollapse{{ $analysis->id }}"
                                                >
                                                    Eventos de PDF e e-mail
                                                </button>
                                            </h2>

                                            <div
                                                id="technicalEventsCollapse{{ $analysis->id }}"
                                                class="accordion-collapse collapse"
                                                data-bs-parent="#technicalEventsAccordion{{ $analysis->id }}"
                                            >
                                                <div class="accordion-body">
                                                    <div class="vstack gap-2">
                                                        @foreach ($communicationEvents as $event)
                                                            @php
                                                                $eventLabel = $eventTypeLabels[$event->event_type]
                                                                    ?? ucfirst(str_replace('_', ' ', $event->event_type));

                                                                $eventBadge = $eventTypeBadges[$event->event_type]
                                                                    ?? 'text-bg-secondary';
                                                            @endphp

                                                            <div class="border rounded-4 p-3">
                                                                <div class="d-flex justify-content-between gap-3">
                                                                    <div>
                                                                        <span class="badge {{ $eventBadge }}">
                                                                            {{ $eventLabel }}
                                                                        </span>

                                                                        <div class="small text-muted mt-2">
                                                                            {{ $event->message ?? 'Sem mensagem.' }}
                                                                        </div>

                                                                        @if (data_get($event->payload, 'file_name'))
                                                                            <div class="small mt-1">
                                                                                Arquivo:
                                                                                <strong>{{ data_get($event->payload, 'file_name') }}</strong>
                                                                            </div>
                                                                        @endif
                                                                    </div>

                                                                    <div class="small text-muted text-end">
                                                                        {{ $event->created_at?->format('d/m/Y H:i') }}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="text-center py-5">
                                <span class="badge text-bg-light border mb-3">
                                    Sem análises
                                </span>

                                <h3 class="h5 fw-bold">
                                    Nenhuma análise encontrada neste lote.
                                </h3>

                                <p class="text-muted mb-0">
                                    Quando o processamento iniciar, as análises aparecerão aqui.
                                </p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Coluna lateral --}}
            <div class="col-12 col-xxl-4">
                <div class="card border-0 shadow-sm rounded-5 mb-4">
                    <div class="card-body p-4">
                        <span class="badge text-bg-info mb-2">
                            Resumo
                        </span>

                        <h2 class="h5 fw-bold mb-3">
                            Informações do lote
                        </h2>

                        <div class="vstack gap-3">
                            <div class="d-flex justify-content-between border-bottom pb-3">
                                <span class="text-muted">Status</span>
                                <span class="badge {{ $batchBadge }}">{{ $batchLabel }}</span>
                            </div>

                            <div class="d-flex justify-content-between border-bottom pb-3">
                                <span class="text-muted">Total de provedores</span>
                                <strong>{{ $totalProviders }}</strong>
                            </div>

                            <div class="d-flex justify-content-between border-bottom pb-3">
                                <span class="text-muted">Finalizados</span>
                                <strong>{{ $finishedProviders }}</strong>
                            </div>

                            <div class="d-flex justify-content-between border-bottom pb-3">
                                <span class="text-muted">Progresso</span>
                                <strong>{{ $progress }}%</strong>
                            </div>

                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Última atualização</span>
                                <strong>{{ $batch->updated_at?->format('d/m/Y H:i') }}</strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-5">
                    <div class="card-body p-4">
                        <span class="badge text-bg-warning mb-2">
                            Observação
                        </span>

                        <h2 class="h5 fw-bold mb-3">
                            Dados técnicos
                        </h2>

                        <p class="text-muted small mb-0">
                            Payload enviado, resposta bruta da API e logs técnicos completos devem ficar no painel de corretores/admins.
                            No painel da imobiliária, exibimos apenas o resultado operacional da análise.
                        </p>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>
@endsection
