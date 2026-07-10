<?php

namespace App\Http\Controllers;

use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\SyncProviderAnalysisStatusJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use Illuminate\Http\Request;
use App\Models\Imobiliaria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InsuranceAnalysisController extends Controller
{
    /**
     * Lista os lotes de análises no dashboard da imobiliária cadastrada.
     */
    public function index(Request $request)
    {
        $companyId = $this->currentCompanyId();

        abort_if(!$companyId, 403, 'Empresa não identificada.');

        $company = Imobiliaria::findOrFail($companyId);

        $selectedStatus = $request->query('status');
        $search = trim((string) $request->query('search'));

        /*
        |--------------------------------------------------------------------------
        | Estatísticas dos lotes e análises da imobiliária
        |--------------------------------------------------------------------------
        | completed_with_errors foi adicionado porque o CompleteInsuranceAnalysesBatchJob
        | pode finalizar o lote com uma ou mais falhas.
        */
        $totalBatches = InsuranceAnalysisBatch::where('company_id', $companyId)->count();

        $runningBatches = InsuranceAnalysisBatch::where('company_id', $companyId)
            ->whereIn('status', ['pending', 'running', 'processing'])
            ->count();

        $finishedBatches = InsuranceAnalysisBatch::where('company_id', $companyId)
            ->whereIn('status', ['done', 'completed', 'completed_with_errors', 'finished'])
            ->count();

        $failedBatches = InsuranceAnalysisBatch::where('company_id', $companyId)
            ->whereIn('status', ['failed', 'error', 'completed_with_errors'])
            ->count();

        $approvedAnalyses = InsuranceAnalysis::where('company_id', $companyId)
            ->whereIn('status', ['approved', 'Approved'])
            ->count();

        $rejectedAnalyses = InsuranceAnalysis::where('company_id', $companyId)
            ->whereIn('status', ['rejected', 'refused', 'denied', 'Denied', 'Refused'])
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Análises em andamento
        |--------------------------------------------------------------------------
        */
        $inProgressAnalyses = InsuranceAnalysis::with([
                'lead',
                'events',
            ])
            ->where('company_id', $companyId)
            ->whereIn('status', ['pending', 'processing', 'queued', 'running'])
            ->latest('updated_at')
            ->limit(6)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Query principal dos lotes
        |--------------------------------------------------------------------------
        | Carregamos analyses.events porque as views agora exibem:
        | - análise original;
        | - reanálise;
        | - PDF gerado;
        | - e-mail enviado;
        | usando eventos_analises_seguro.
        */
        $batchesQuery = InsuranceAnalysisBatch::with([
                'lead.despesas',
                'analyses.events',
            ])
            ->where('company_id', $companyId);

        if (filled($selectedStatus)) {
            $batchesQuery->where('status', $selectedStatus);
        }

        if (filled($search)) {
            $batchesQuery->where(function ($query) use ($search) {
                $query->whereHas('lead', function ($leadQuery) use ($search) {
                    $leadQuery
                        ->where('nome', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('cpf', 'like', "%{$search}%");
                })
                ->orWhereHas('analyses', function ($analysisQuery) use ($search) {
                    $analysisQuery
                        ->where('provider', 'like', "%{$search}%")
                        ->orWhere('quote_id', 'like', "%{$search}%")
                        ->orWhere('quote_number', 'like', "%{$search}%")
                        ->orWhere('product_key', 'like', "%{$search}%");
                });
            });
        }

        $batches = $batchesQuery
            ->latest()
            ->paginate(2)
            ->withQueryString();

        $dashboardStats = [
            'totalBatches' => $totalBatches,
            'runningBatches' => $runningBatches,
            'finishedBatches' => $finishedBatches,
            'failedBatches' => $failedBatches,
            'approvedAnalyses' => $approvedAnalyses,
            'rejectedAnalyses' => $rejectedAnalyses,
        ];

        return view('insurance-analyses.dashboard-user.index', [
            'company' => $company,
            'batches' => $batches,
            'inProgressAnalyses' => $inProgressAnalyses,
            'dashboardStats' => $dashboardStats,
            'selectedStatus' => $selectedStatus,
            'search' => $search,
        ]);
    }

    /**
     * Mostra detalhes de um lote específico no dashboard da imobiliária cadastrada.
     */
    public function show(InsuranceAnalysisBatch $batch)
    {
        $companyId = $this->currentCompanyId();

        abort_if(!$companyId, 403, 'Empresa não identificada.');

        abort_if(
            (int) $batch->company_id !== (int) $companyId,
            403,
            'Você não tem permissão para acessar esta análise.'
        );

        $company = Imobiliaria::findOrFail($companyId);

        /*
        |--------------------------------------------------------------------------
        | Carregamento necessário para a nova view
        |--------------------------------------------------------------------------
        | lead.despesas permite mostrar valor atual do lead.
        | analyses.events permite mostrar histórico de análise/reanálise/PDF/e-mail.
        */
        $batch->load([
            'lead.despesas',
            'company',
            'analyses.events',
        ]);

        return view('insurance-analyses.dashboard-user.show', [
            'company' => $company,
            'batch' => $batch,
        ]);
    }

    /**
     * Reenvia uma análise específica para a fila.
     *
     * Com a nova lógica, esse reenvio é tratado como uma reanálise da companhia.
     * O lote não é duplicado, a análise da companhia é reaproveitada e o histórico
     * fica salvo em eventos_analises_seguro com attempt_id.
     */
    public function retry(InsuranceAnalysis $analysis)
    {
        $this->authorizeCompanyAccess($analysis);

        if (!in_array(mb_strtolower((string) $analysis->status), ['failed', 'error'], true)) {
            return back()->with(
                'warning',
                'Essa análise não está em status de falha técnica para reenvio.'
            );
        }

        $attemptId = $this->restartAnalysisAsTechnicalRetry(
            analysis: $analysis,
            message: 'Reenvio da análise solicitado pela imobiliária.'
        );

        RunProviderAnalysisJob::dispatch(
            analysisId: $analysis->id,
            attemptId: $attemptId,
            isReanalysis: false
        );

        return back()->with('success', 'Análise reenviada para a fila como reanálise da companhia.');
    }

    public function providerReanalysis(Request $request, InsuranceAnalysis $analysis)
    {
        $this->authorizeCompanyAccess($analysis);

        return $this->startProviderReanalysisFromLeadUpdate(
            request: $request,
            analysis: $analysis,
            requestedBy: 'Imobiliaria'
        );
    }

    
    /**
     * Sincroniza o status de uma análise específica com a companhia.
     */
    public function syncStatus(InsuranceAnalysis $analysis)
    {
        $this->authorizeCompanyAccess($analysis);

        $responsePayload = $analysis->response_payload ?? [];

        if(is_string($responsePayload)){
            $responsePayload = json_decode($responsePayload, true);
        }

        $isToo = strtolower((string) $analysis->provider) == 'too';

        $tooAutoStopped = $isToo && (bool) data_get($responsePayload, 'too_status_check_stopped', false);

        $tooManualSyncAvailable = $isToo && (bool) data_get($responsePayload, 'too_manual_sync_available', false);

        $canSyncByQuote = !$isToo && filled($analysis->quote_id);

        $canSyncTooManually = $isToo
            && filled($analysis->proposal_id)
            && $tooAutoStopped
            && $tooManualSyncAvailable
            && !in_array($analysis->status, ['approved', 'rejected', 'failed'], true);

        if (!$canSyncByQuote && !$canSyncTooManually) {
            return back()->with(
                'error',
                'Essa análise ainda não está disponível para sincronização manual.'
            );
        }

        $analysis->events()->create([
            'event_type' => $canSyncTooManually ? 'too_manual_sync_requested' : 'sync_requested',
            'status' => $analysis->status,
            'message' => $canSyncTooManually
                ? 'Verificação manual de status da Too solicitada pela imobiliária.'
                : 'Sincronização de status solicitada pela imobiliária.',
            'payload' => [
                'requested_by' => 'imobiliaria',
                'requested_at' => now()->toDateTimeString(),
                'provider' => $analysis->provider,
                'proposal_id' => $analysis->proposal_id,
                'quote_id' => $analysis->quote_id,
            ],
        ]);

        SyncProviderAnalysisStatusJob::dispatch($analysis->id);

        return back()->with('success', $canSyncTooManually
            ? 'Verificação manual do status da Too enviada para a fila.'
            : 'Consulta de status enviada para a fila.'
        );
    }

    /**
     * Lista todos os lotes de análises para o admin/corretor.
     */
    public function adminIndex(Request $request)
    {
        $selectedCompany = $request->query('company_id');

        $selectedStatus = $request->query('status');

        $search = trim((string) $request->query('search'));

        $imobiliarias = Imobiliaria::query()->orderBy('name')->get();

        $batchesQuery = InsuranceAnalysisBatch::with([
            'lead.despesas',
            'company',
            'analyses.events',
        ]);

        if(filled($selectedCompany)){
            $batchesQuery->where('company_id', $selectedCompany);
        }

        if(filled($selectedStatus)){
            $batchesQuery->where('status', $selectedStatus);
        }

        if (filled($search)) {
            $batchesQuery->where(function ($query) use ($search) {
                $query->whereHas('lead', function ($leadQuery) use ($search) {
                    $leadQuery
                        ->where('nome', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('cpf', 'like', "%{$search}%");
                })
                ->orWhereHas('company', function ($companyQuery) use ($search) {
                    $companyQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('nome', 'like', "%{$search}%")
                        ->orWhere('cnpj', 'like', "%{$search}%");
                })
                ->orWhereHas('analyses', function ($analysisQuery) use ($search) {
                    $analysisQuery
                        ->where('provider', 'like', "%{$search}%")
                        ->orWhere('quote_id', 'like', "%{$search}%")
                        ->orWhere('quote_number', 'like', "%{$search}%")
                        ->orWhere('product_key', 'like', "%{$search}%");
                });
            });
        }

        $statsQuery = InsuranceAnalysisBatch::query();

        if (filled($selectedCompany)) {
            $statsQuery->where('company_id', $selectedCompany);
        }

        $analysisStatsQuery = InsuranceAnalysis::query();

        if (filled($selectedCompany)) {
            $analysisStatsQuery->where('company_id', $selectedCompany);
        }

        $dashboardStats = [
            'totalBatches' => (clone $statsQuery)->count(),

            'runningBatches' => (clone $statsQuery)
                ->whereIn('status', ['pending', 'running', 'processing'])
                ->count(),

            'finishedBatches' => (clone $statsQuery)
                ->whereIn('status', ['done', 'completed', 'completed_with_errors', 'finished'])
                ->count(),

            'failedBatches' => (clone $statsQuery)
                ->whereIn('status', ['failed', 'error', 'completed_with_errors'])
                ->count(),

            'approvedAnalyses' => (clone $analysisStatsQuery)
                ->whereIn('status', ['approved', 'Approved'])
                ->count(),

            'rejectedAnalyses' => (clone $analysisStatsQuery)
                ->whereIn('status', ['rejected', 'refused', 'denied', 'Denied', 'Refused'])
                ->count(),
        ];

        $inProgressAnalysesQuery = InsuranceAnalysis::with([
                'lead',
                'company',
                'events',
            ])
            ->whereIn('status', ['pending', 'processing', 'queued', 'running']);

        if (filled($selectedCompany)) {
            $inProgressAnalysesQuery->where('company_id', $selectedCompany);
        }

        $inProgressAnalyses = $inProgressAnalysesQuery
            ->latest('updated_at')
            ->limit(6)
            ->get();

        $batches = $batchesQuery
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('insurance-analyses.dashboard-admin.index', [
            'batches' => $batches,
            'imobiliarias' => $imobiliarias,
            'inProgressAnalyses' => $inProgressAnalyses,
            'dashboardStats' => $dashboardStats,
            'selectedCompany' => $selectedCompany,
            'selectedStatus' => $selectedStatus,
            'search' => $search,
        ]);




    }

    /**
     * Mostra o detalhe de qualquer lote para o admin/corretor.
     */
    public function adminShow(InsuranceAnalysisBatch $batch)
    {
        $batch->load([
            'lead.despesas',
            'lead.endereco',
            'company',
            'analyses.events',
        ]);

        return view('insurance-analyses.dashboard-admin.show', ['batch' => $batch,]);
    }

    /**
     * Reenvia uma análise específica pelo painel admin.
     *
     * Também é tratado como reanálise da companhia, mantendo o histórico.
     */
    public function adminRetry(InsuranceAnalysis $analysis)
    {
         if (!in_array(mb_strtolower((string) $analysis->status), ['failed', 'error'], true)) {
            return back()->with(
                'warning',
                'Essa análise não está em status de falha técnica para reenvio.'
            );
        }

        $attemptId = $this->restartAnalysisAsTechnicalRetry(
            analysis: $analysis,
            message: 'Reenvio/reanálise da análise solicitado pelo admin/corretor.',
            requestedBy: 'admin'
        );

        RunProviderAnalysisJob::dispatch(
            analysisId: $analysis->id,
            attemptId: $attemptId,
            isReanalysis: false
        );

        return back()->with('success', 'Análise reenviada para a fila como nova tentativa técnica.');
    }

    public function adminProviderReanalysis(Request $request, InsuranceAnalysis $analysis)
    {
        return $this->startProviderReanalysisFromLeadUpdate(
            request: $request,
            analysis: $analysis,
            requestedBy: 'admin'
        );
    }

    /**
     * Sincroniza o status de uma análise pelo painel admin.
     */
    public function adminSyncStatus(InsuranceAnalysis $analysis)
    {
        $responsePayload = $analysis->response_payload ?? [];

        if (is_string($responsePayload)) {
            $responsePayload = json_decode($responsePayload, true) ?: [];
        }

        $isToo = strtolower((string) $analysis->provider) === 'too';

        $tooAutoStopped = $isToo
            && (bool) data_get($responsePayload, 'too_status_check_stopped', false);

        $tooManualSyncAvailable = $isToo
            && (bool) data_get($responsePayload, 'too_manual_sync_available', false);

        $canSyncByQuote = !$isToo && filled($analysis->quote_id);

        $canSyncTooManually = $isToo
            && filled($analysis->proposal_id)
            && $tooAutoStopped
            && $tooManualSyncAvailable
            && !in_array($analysis->status, ['approved', 'rejected', 'failed'], true);

        if (!$canSyncByQuote && !$canSyncTooManually) {
            return back()->with(
                'error',
                'Essa análise ainda não está disponível para sincronização manual.'
            );
        }

        $analysis->events()->create([
            'event_type' => $canSyncTooManually ? 'too_manual_sync_requested' : 'sync_requested',
            'status' => $analysis->status,
            'message' => $canSyncTooManually
                ? 'Verificação manual de status da Too solicitada pelo admin/corretor.'
                : 'Sincronização de status solicitada pelo admin/corretor.',
            'payload' => [
                'requested_by' => 'admin',
                'requested_at' => now()->toDateTimeString(),
                'provider' => $analysis->provider,
                'proposal_id' => $analysis->proposal_id,
                'quote_id' => $analysis->quote_id,
            ],
        ]);

        SyncProviderAnalysisStatusJob::dispatch($analysis->id);

        return back()->with(
            'success',
            $canSyncTooManually
                ? 'Verificação manual do status da Too enviada para a fila.'
                : 'Consulta de status enviada para a fila.'
        );
    }

    private function restartAnalysisAsTechnicalRetry(
        InsuranceAnalysis $analysis,
        string $message,
        string $requestedBy = 'imobiliaria'
    ): string {
        $attemptId = (string) Str::uuid();

        $analysis->loadMissing([
            'batch.analyses',
            'lead.despesas',
            'events',
        ]);

        DB::transaction(function () use ($analysis, $attemptId, $message, $requestedBy) {
            /*
            |--------------------------------------------------------------------------
            | Evento antes de limpar a tentativa com falha
            |--------------------------------------------------------------------------
            | Retry técnico não é reanálise. Ele serve para repetir uma análise que
            | falhou por erro de API, timeout, payload inválido, instabilidade etc.
            */
            $analysis->events()->create([
                'event_type' => 'technical_retry_requested',
                'status' => 'pending',
                'message' => $message,
                'payload' => [
                    'attempt_id' => $attemptId,
                    'is_reanalysis' => false,
                    'is_technical_retry' => true,
                    'requested_by' => $requestedBy,
                    'requested_at' => now()->toDateTimeString(),
                    'provider' => $analysis->provider,

                    'previous_status' => $analysis->status,
                    'previous_result' => $analysis->result,
                    'previous_provider_status' => $analysis->provider_status,

                    'previous_proposal_id' => $analysis->proposal_id,
                    'previous_quote_id' => $analysis->quote_id,
                    'previous_quote_number' => $analysis->quote_number,

                    'previous_premium_amount' => $analysis->premium_amount,
                    'previous_commercial_premium' => $analysis->commercial_premium,
                    'previous_gross_premium' => $analysis->gross_premium,
                    'previous_iof' => $analysis->iof,
                    'previous_insured_amount' => $analysis->insured_amount,

                    'previous_error_message' => $analysis->error_message,

                    'rent_amount' => $analysis->rent_amount,
                    'charges_amount' => $analysis->charges_amount,
                    'total_monthly_amount' => $analysis->total_monthly_amount,
                ],
                'response' => $analysis->response_payload,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Limpa somente o resultado técnico da tentativa anterior
            |--------------------------------------------------------------------------
            | Como isso NÃO é reanálise, o próximo RunProviderAnalysisJob deve rodar
            | com isReanalysis=false, executando o fluxo normal/inicial do provider.
            */
            $analysis->forceFill([
                'status' => 'pending',
                'result' => null,
                'provider_status' => null,

                /*
                |--------------------------------------------------------------------------
                | Em retry técnico, limpamos a proposta/cotação anterior.
                |--------------------------------------------------------------------------
                | Isso representa uma nova tentativa técnica da análise, não uma
                | reanálise oficial de uma proposta aprovada/recusada.
                */
                'proposal_id' => null,
                'quote_id' => null,
                'quote_number' => null,
                'product_key' => null,

                'commercial_premium' => null,
                'gross_premium' => null,
                'iof' => null,

                'request_payload' => null,
                'response_payload' => null,

                'available_plans' => null,
                'available_assistances' => null,

                'premium_amount' => null,
                'insured_amount' => null,

                'error_message' => null,
                'requested_at' => null,
                'finished_at' => null,
            ])->save();

            /*
            |--------------------------------------------------------------------------
            | Reabre o lote para processamento
            |--------------------------------------------------------------------------
            */
            if ($analysis->batch) {
                $completed = $analysis->batch->analyses()
                    ->whereIn('status', [
                        'quoted',
                        'approved',
                        'rejected',
                        'denied',
                        'refused',
                        'manual_review',
                    ])
                    ->count();

                $failed = $analysis->batch->analyses()
                    ->whereIn('status', ['failed', 'error'])
                    ->count();

                $analysis->batch->forceFill([
                    'status' => 'processing',
                    'completed_providers' => $completed,
                    'failed_providers' => $failed,
                    'finished_at' => null,
                    'started_at' => now(),
                ])->save();
            }
        });

        return $attemptId;
    }

    /**
     * Reinicia uma análise individual como reanálise da companhia.
     *
     * Esse método:
     * - cria attempt_id;
     * - registra snapshot anterior no evento;
     * - limpa os campos de resultado;
     * - marca o lote como processing;
     * - NÃO cria outro lote;
     * - NÃO cria outra análise;
     * - preserva histórico em eventos_analises_seguro.
     */
    private function restartAnalysisAsReanalysis(
        InsuranceAnalysis $analysis,
        string $message,
        string $requestedBy = 'imobiliaria',
        array $options = []
    ): string {

        $isToo = mb_strtolower((string) $analysis->provider) === 'too';

        $previousResponsePayload = $analysis->response_payload ?? [];

        if(is_string($previousResponsePayload)){
            $previousResponsePayload = json_decode($previousResponsePayload, true) ?: [];
        }

        $tooNumeroProposta = data_get($previousResponsePayload, 'numeroProposta')
            ?? data_get($previousResponsePayload, 'numero_proposta')
            ?? $analysis->proposal_id;

        $tooNumeroFicha = data_get($previousResponsePayload, 'numeroFicha')
            ?? data_get($previousResponsePayload, 'numero_ficha')
            ?? data_get($previousResponsePayload, 'numeroProposta')
            ?? $analysis->proposal_id;

        $attemptId = (string) Str::uuid();

        $analysis->loadMissing([
            'batch.analyses',
            'lead.despesas',
            'events',
        ]);

        DB::transaction(function () use (
            $analysis,
            $attemptId,
            $message,
            $requestedBy,
            $options,
            $isToo,
            $previousResponsePayload,
            $tooNumeroProposta,
            $tooNumeroFicha
            
            ) {
            /*
            |--------------------------------------------------------------------------
            | Evento antes de limpar a análise
            |--------------------------------------------------------------------------
            | Guarda o estado anterior para comparação no histórico.
            */
            $analysis->events()->create([
                'event_type' => 'reanalysis_requested',
                'status' => 'pending',
                'message' => $message,
                'payload' => [
                    'attempt_id' => $attemptId,
                    'is_reanalysis' => true,
                    'requested_by' => $requestedBy,
                    'requested_at' => now()->toDateTimeString(),
                    'provider' => $analysis->provider,
                    'reanalysis_options' => $options,

                    'previous_status' => $analysis->status,
                    'previous_result' => $analysis->result,
                    'previous_quote_id' => $analysis->quote_id,
                    'previous_quote_number' => $analysis->quote_number,
                    'previous_premium_amount' => $analysis->premium_amount,
                    'previous_commercial_premium' => $analysis->commercial_premium,
                    'previous_gross_premium' => $analysis->gross_premium,
                    'previous_iof' => $analysis->iof,
                    'previous_insured_amount' => $analysis->insured_amount,

                    'rent_amount' => $analysis->rent_amount,
                    'charges_amount' => $analysis->charges_amount,
                    'total_monthly_amount' => $analysis->total_monthly_amount,
                ],
                'response' => $analysis->response_payload,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Reseta somente o resultado da análise
            |--------------------------------------------------------------------------
            | Mantém dados contratuais e valores enviados atuais.
            */
            $analysis->forceFill([
                'status' => 'pending',
                'result' => null,
                'provider_status' => null,
                'quote_id' => null,
                'quote_number' => null,
                'product_key' => null,
                'commercial_premium' => null,
                'gross_premium' => null,
                'iof' => null,
                'request_payload' => null,
                'response_payload' => $isToo
                    ? array_merge($previousResponsePayload, [
                        'numeroProposta' => $tooNumeroProposta,
                        'numeroFicha' => $tooNumeroFicha,
                        'too_reanalysis_started_at' => now()->toDateTimeString(),
                        'too_reanalysis_attempt_id' => $attemptId,
                    ])
                    : null,
                'available_plans' => null,
                'available_assistances' => null,
                'premium_amount' => null,
                'insured_amount' => null,
                'error_message' => null,
                'requested_at' => null,
                'finished_at' => null,
            ])->save();

            /*
            |--------------------------------------------------------------------------
            | Reabre o lote
            |--------------------------------------------------------------------------
            | O CompleteInsuranceAnalysesBatchJob recalcula os contadores depois.
            */
            if ($analysis->batch) {
                $completed = $analysis->batch->analyses()
                    ->whereIn('status', [
                        'quoted',
                        'approved',
                        'rejected',
                        'denied',
                        'refused',
                        'manual_review',
                    ])
                    ->count();

                $failed = $analysis->batch->analyses()
                    ->whereIn('status', ['failed', 'error'])
                    ->count();

                $analysis->batch->forceFill([
                    'status' => 'processing',
                    'completed_providers' => $completed,
                    'failed_providers' => $failed,
                    'finished_at' => null,
                    'started_at' => now(),
                ])->save();
            }
        });

        return $attemptId;
    }

        private function startProviderReanalysisFromLeadUpdate(
        Request $request,
        InsuranceAnalysis $analysis,
        string $requestedBy
    ) {
        $analysis->loadMissing([
            'lead.endereco',
            'lead.despesas', 
            'lead.conjuge',
            'batch.analyses',
            'events',
        ]);

        if (! $analysis->canRequestProviderReanalysis()) {
            return back()->with(
                'error',
                'Esta companhia só pode ser reanalisada se o resultado dela estiver aprovado ou recusado.'
            );
        }

        $data = $this->validateProviderReanalysisRequest($request, $analysis);

        /*
        |--------------------------------------------------------------------------
        | Atualização dos dados do lead
        |--------------------------------------------------------------------------
        | Ideal: extrair a lógica de atualização para um service reaproveitável,
        | porque a mesma regra será usada no DashboardLeadController.
        */
        $changed = app(\App\Services\LeadReanalysisDataService::class)
            ->updateLeadFromArray($analysis->lead, $data);

        if (! $changed) {
            return back()->with(
                'error',
                'Altere pelo menos um dado do lead antes de solicitar a reanálise desta companhia.'
            );
        }

        $options = $this->reanalysisOptionsForProvider($analysis, $data);

        $attemptId = $this->restartAnalysisAsReanalysis(
            analysis: $analysis,
            message: 'Reanálise por companhia solicitada após alteração dos dados do lead.',
            requestedBy: $requestedBy,
            options: $options
        );

        RunProviderAnalysisJob::dispatch(
            analysisId: $analysis->id,
            attemptId: $attemptId,
            isReanalysis: true,
            options: $options
        );

        return back()->with(
            'success',
            'Reanálise enviada somente para a companhia selecionada.'
        );
    }

    /**
     * Recupera o company_id da imobiliária logada.
     */
    private function currentCompanyId(): ?int
    {
        return auth()->user()?->company_id
            ?? session('company_id');
    }

    /**
     * Protege ações feitas pela imobiliária cadastrada.
     */
    private function authorizeCompanyAccess(InsuranceAnalysis $analysis): void
    {
        $companyId = $this->currentCompanyId();

        abort_if(!$companyId, 403, 'Empresa não identificada.');

        abort_if(
            (int) $analysis->company_id !== (int) $companyId,
            403,
            'Você não tem permissão para acessar essa análise.'
        );
    }

    private function reanalysisOptionsForProvider(InsuranceAnalysis $analysis, array $data): array
    {
        if (! $analysis->isTooProvider()) {
            return [];
        }

        $reason = (int) ($data['too_reanalysis_reason'] ?? 10);

        return [
            'motivosReanalise' => [$reason],
            'observacoes' => $data['too_reanalysis_observations']
                ?? 'Reanálise solicitada após alteração dos dados do lead.',
        ];
    }

    private function validateProviderReanalysisRequest(Request $request, InsuranceAnalysis $analysis): array
    {
        $data = $request->validate([
            'nome' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'tel' => ['nullable', 'string', 'max:30'],
            'cpf' => ['nullable', 'string', 'max:20'],
            'estado_civil' => ['nullable', 'string', 'max:100'],

            'valor_aluguel' => ['nullable', 'numeric', 'min:0'],
            'valor_agua' => ['nullable', 'numeric', 'min:0'],
            'valor_luz' => ['nullable', 'numeric', 'min:0'],
            'valor_gas' => ['nullable', 'numeric', 'min:0'],
            'valor_condominio' => ['nullable', 'numeric', 'min:0'],
            'valor_iptu' => ['nullable', 'numeric', 'min:0'],
            'outras_despesas' => ['nullable', 'numeric', 'min:0'],

            'cep' => ['nullable', 'string', 'max:20'],
            'estado' => ['nullable', 'string', 'max:2'],
            'cidade_imovel' => ['nullable', 'string', 'max:255'],
            'bairro' => ['nullable', 'string', 'max:255'],
            'logradouro' => ['nullable', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:30'],
            'complemento' => ['nullable', 'string', 'max:255'],

            'too_reanalysis_reason' => ['nullable', 'integer', 'between:1,10'],
            'too_reanalysis_observations' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($analysis->isTooProvider()) {
            $reason = (int) ($data['too_reanalysis_reason'] ?? 10);
            $observations = $data['too_reanalysis_observations'] ?? null;

            if (in_array($reason, [3, 7, 10], true) && blank($observations)) {
                abort(422, 'Informe as observações para este motivo de reanálise da Too.');
            }
        }

        return $data;
    }
}
