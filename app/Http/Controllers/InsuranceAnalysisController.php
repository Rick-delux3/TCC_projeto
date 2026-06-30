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

        if (!in_array($analysis->status, ['failed', 'manual_review', 'rejected'], true)) {
            return back()->with(
                'warning',
                'Essa análise não está em um status permitido para reenvio.'
            );
        }

        $attemptId = $this->restartAnalysisAsReanalysis(
            analysis: $analysis,
            message: 'Reenvio/reanálise da análise solicitado pela imobiliária.'
        );

        RunProviderAnalysisJob::dispatch(
            analysisId: $analysis->id,
            attemptId: $attemptId,
            isReanalysis: true
        );

        return back()->with('success', 'Análise reenviada para a fila como reanálise da companhia.');
    }

    /**
     * Sincroniza o status de uma análise específica com a companhia.
     */
    public function syncStatus(InsuranceAnalysis $analysis)
    {
        $this->authorizeCompanyAccess($analysis);

        $tooAutoStopped = $analysis->provider === 'too' && (bool) data_get($analysis->request_payload, 'too_status_check_stopped', false);

        $canSyncByQuote = filled($analysis->quote_id);

        $canSyncTooManually = $analysis->provider === 'too'
        && filled($analysis->proposal_id)
        && $tooAutoStopped
        && in_array($analysis->status, ['manual_review', 'pending', 'processing'], true);

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
        $batches = InsuranceAnalysisBatch::with([
                'lead.despesas',
                'company',
                'analyses.events',
            ])
            ->latest()
            ->paginate(20);

        return view('dashboard-admin', compact('batches'));
    }

    /**
     * Mostra o detalhe de qualquer lote para o admin/corretor.
     */
    public function adminShow(InsuranceAnalysisBatch $batch)
    {
        $batch->load([
            'lead.despesas',
            'company',
            'analyses.events',
        ]);

        return view('dashboard-admin', compact('batch'));
    }

    /**
     * Reenvia uma análise específica pelo painel admin.
     *
     * Também é tratado como reanálise da companhia, mantendo o histórico.
     */
    public function adminRetry(InsuranceAnalysis $analysis)
    {
        $attemptId = $this->restartAnalysisAsReanalysis(
            analysis: $analysis,
            message: 'Reenvio/reanálise da análise solicitado pelo admin/corretor.',
            requestedBy: 'admin'
        );

        RunProviderAnalysisJob::dispatch(
            analysisId: $analysis->id,
            attemptId: $attemptId,
            isReanalysis: true
        );

        return back()->with('success', 'Análise reenviada para a fila como reanálise da companhia.');
    }

    /**
     * Sincroniza o status de uma análise pelo painel admin.
     */
    public function adminSyncStatus(InsuranceAnalysis $analysis)
    {
        $tooAutoStopped = $analysis->provider === 'too' && (bool) data_get($analysis->request_payload, 'too_status_check_stopped', false);

        $canSyncByQuote = filled($analysis->quote_id);

        $canSyncTooManually = $analysis->provider === 'too'
        && filled($analysis->proposal_id)
        && $tooAutoStopped
        && in_array($analysis->status, ['manual_review', 'pending', 'processing'], true);

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
                'requested_by' => 'admin',
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
            | Reabre o lote
            |--------------------------------------------------------------------------
            | O CompleteInsuranceAnalysesBatchJob recalcula os contadores depois.
            */
            if ($analysis->batch) {
                $completed = $analysis->batch->analyses()
                    ->whereIn('status', ['quoted', 'approved', 'rejected', 'manual_review'])
                    ->count();

                $failed = $analysis->batch->analyses()
                    ->where('status', 'failed')
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
}
