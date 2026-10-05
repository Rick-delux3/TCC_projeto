<?php

namespace App\Http\Controllers;

use App\Http\Resources\InsuranceAnalysisLeadResource;
use App\Jobs\SyncProviderAnalysisStatusJob;
use App\Models\Corretor;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Services\Insurance\InsuranceAnalysisPageService;
use App\Services\LeadReanalysisService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InsuranceAnalysisController extends Controller
{
    public function __construct(
        private LeadReanalysisService $leadReanalysisService,
        private InsuranceAnalysisPageService $analysisPageService,
    ) {}

    public function index(): RedirectResponse
    {
        abort_if(! $this->currentCompanyId(), 403);

        return redirect()->to(route('company.dashboard').'#leads-section');
    }

    public function show(InsuranceAnalysisBatch $batch): RedirectResponse
    {
        $lead = $batch->lead()->firstOrFail();
        $this->authorizeCompanyLead($lead);

        return redirect()->route('insurance-analyses.lead', ['lead' => $lead]);
    }

    public function showLead(Lead $lead): View
    {
        $this->authorizeCompanyLead($lead);

        return $this->leadView($lead, 'company');
    }

    public function leadData(Lead $lead): InsuranceAnalysisLeadResource
    {
        $this->authorizeCompanyLead($lead);

        return new InsuranceAnalysisLeadResource($this->analysisPageService->read($lead, 'company', Auth::guard('web')->user()));
    }

    /**
     * Retry técnico de uma análise pela imobiliária.
     */
    public function retry(InsuranceAnalysis $analysis): RedirectResponse
    {
        $this->authorizeAnalysis($analysis, 'web', 'requestAnalysis');

        try {
            $this->leadReanalysisService->startTechnicalRetry(
                analysis: $analysis,
                requestedBy: 'imobiliaria'
            );

            return back()->with(
                'success',
                'Análise reenviada para a fila como nova tentativa técnica.'
            );
        } catch (DomainException $exception) {
            return back()->with('warning', $exception->getMessage());
        }
    }

    /**
     * Reanálise por companhia solicitada pela imobiliária.
     */
    public function providerReanalysis(Request $request, InsuranceAnalysis $analysis): RedirectResponse
    {
        $this->authorizeAnalysis($analysis, 'web', 'reanalyzeWithChanges');

        return $this->startProviderReanalysisFromLeadUpdate(
            request: $request,
            analysis: $analysis,
            requestedBy: 'imobiliaria'
        );
    }

    /**
     * Sincroniza o status de uma análise específica com a companhia.
     */
    public function syncStatus(InsuranceAnalysis $analysis): RedirectResponse
    {
        $this->authorizeAnalysis($analysis, 'web', 'viewAnalyses');

        return $this->syncAnalysisStatus(
            analysis: $analysis,
            requestedBy: 'imobiliaria'
        );
    }

    public function adminIndex(): RedirectResponse
    {
        $this->authorizeAdminAbility('view-analyses');

        return redirect()->to(route('Dashboard-Admin').'#leads-section');
    }

    public function adminShow(InsuranceAnalysisBatch $batch): RedirectResponse
    {
        $this->authorizeAdminAbility('view-analyses');
        $lead = $batch->lead()->firstOrFail();
        Gate::forUser(Auth::guard('admin')->user())->authorize('viewAnalyses', $lead);

        return redirect()->route('admin.insurance-analyses.lead', ['lead' => $lead]);
    }

    public function adminShowLead(Lead $lead): View
    {
        Gate::forUser(Auth::guard('admin')->user())->authorize('viewAnalyses', $lead);

        return $this->leadView($lead, 'admin');
    }

    public function adminLeadData(Lead $lead): InsuranceAnalysisLeadResource
    {
        Gate::forUser(Auth::guard('admin')->user())->authorize('viewAnalyses', $lead);

        return new InsuranceAnalysisLeadResource($this->analysisPageService->read($lead, 'admin', Auth::guard('admin')->user()));
    }

    /**
     * Retry técnico de uma análise pelo painel admin.
     */
    public function adminRetry(InsuranceAnalysis $analysis): RedirectResponse
    {
        $this->authorizeAnalysis($analysis, 'admin', 'requestAnalysis');

        try {
            $this->leadReanalysisService->startTechnicalRetry(
                analysis: $analysis,
                requestedBy: 'admin'
            );

            return back()->with(
                'success',
                'Análise reenviada para a fila como nova tentativa técnica.'
            );
        } catch (DomainException $exception) {
            return back()->with('warning', $exception->getMessage());
        }
    }

    /**
     * Reanálise por companhia solicitada pelo admin/corretor.
     */
    public function adminProviderReanalysis(Request $request, InsuranceAnalysis $analysis): RedirectResponse
    {
        $this->authorizeAnalysis($analysis, 'admin', 'reanalyzeWithChanges');

        return $this->startProviderReanalysisFromLeadUpdate(
            request: $request,
            analysis: $analysis,
            requestedBy: 'admin'
        );
    }

    /**
     * Sincroniza o status de uma análise pelo painel admin.
     */
    public function adminSyncStatus(InsuranceAnalysis $analysis): RedirectResponse
    {
        $this->authorizeAnalysis($analysis, 'admin', 'viewAnalyses');

        return $this->syncAnalysisStatus(
            analysis: $analysis,
            requestedBy: 'admin'
        );
    }

    /**
     * Atualiza dados do lead e inicia reanálise somente daquela companhia.
     */
    private function startProviderReanalysisFromLeadUpdate(
        Request $request,
        InsuranceAnalysis $analysis,
        string $requestedBy
    ): RedirectResponse {
        $analysis->loadMissing([
            'lead.endereco',
            'lead.despesas',
            'lead.conjuge',
            'batch.analyses',
            'events',
        ]);

        if (! $analysis->lead) {
            return back()->with(
                'error',
                'Lead não encontrado para solicitar reanálise desta companhia.'
            );
        }

        $data = $this->validateProviderReanalysisRequest($request, $analysis);
        $options = $this->reanalysisOptionsForProvider($analysis, $data);

        try {
            $corretor = $requestedBy === 'admin'
                ? Auth::guard('admin')->user()
                : null;

            $updateResult = $this->leadReanalysisService
                ->updateLeadDataAndMaybeUnlock(
                    lead: $analysis->lead,
                    data: $data,
                    corretor: $corretor instanceof Corretor
                        ? $corretor
                        : null,
                    ip: $request->ip(),
                    userAgent: $request->userAgent(),
                );

            if (! $updateResult['changed']) {
                return back()->with('error', $updateResult['message']);
            }

            /*
            |--------------------------------------------------------------------------
            | Recarrega a análise após salvar os dados do lead
            |--------------------------------------------------------------------------
            | Evita usar relação antiga no payload da reanálise.
            */
            $analysis = InsuranceAnalysis::query()->findOrFail($analysis->id);

            $this->leadReanalysisService->startProviderReanalysis(
                analysis: $analysis,
                requestedBy: $requestedBy,
                options: $options
            );

            return back()->with(
                'success',
                'Reanálise enviada somente para a companhia selecionada.'
            );
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    /**
     * Recupera o company_id da imobiliária logada.
     */
    private function currentCompanyId(): ?int
    {
        return Auth::guard('web')->user()?->company_id;
    }

    private function authorizeCompanyLead(Lead $lead): void
    {
        Gate::forUser(Auth::guard('web')->user())->authorize('viewAnalyses', $lead);
    }

    private function leadView(Lead $lead, string $viewerType): View
    {
        return view('insurance-analyses.index', $this->analysisPageService->read(
            $lead, $viewerType, Auth::guard($viewerType === 'admin' ? 'admin' : 'web')->user(),
        ));
    }

    /**
     * Protege ações feitas pela imobiliária cadastrada.
     */
    private function authorizeAnalysis(InsuranceAnalysis $analysis, string $guard, string $ability): void
    {
        $lead = $analysis->lead()->firstOrFail();
        Gate::forUser(Auth::guard($guard)->user())->authorize($ability, $lead);
        $analysis->setRelation('lead', $lead);
    }

    /**
     * Protege ações do painel admin/corretor.
     */
    private function authorizeAdminAbility(string $ability): void
    {
        $corretor = Auth::guard('admin')->user();

        abort_if(! $corretor, 401, 'Corretor não autenticado.');

        abort_if(
            Gate::forUser($corretor)->denies($ability),
            403,
            'Você não possui permissão para executar esta ação.'
        );
    }

    /**
     * Monta opções específicas de reanálise por provider.
     */
    private function reanalysisOptionsForProvider(InsuranceAnalysis $analysis, array $data): array
    {
        if (! $analysis->isTooProvider()) {
            return [];
        }

        $reason = (int) ($data['too_reanalysis_reason'] ?? 10);

        return [
            'motivosReanalise' => [$reason],
            'observacoes' => filled($data['too_reanalysis_observations'] ?? null)
                ? $data['too_reanalysis_observations']
                : 'Reanálise solicitada após alteração dos dados do lead.',
        ];
    }

    /**
     * Valida dados alteráveis na reanálise por companhia.
     */
    private function validateProviderReanalysisRequest(Request $request, InsuranceAnalysis $analysis): array
    {
        $data = $request->validate([
            'nome' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'tel' => ['nullable', 'string', 'max:30'],
            'cpf' => ['nullable', 'string', 'max:20'],
            'estado_civil' => ['nullable', 'string', 'max:100'],
            'conjuge_nome' => ['nullable', 'string', 'max:255'],
            'conjuge_cpf' => ['nullable', 'string', 'max:20'],

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
                throw ValidationException::withMessages([
                    'too_reanalysis_observations' => 'Informe as observações para este motivo de reanálise da Too.',
                ]);
            }
        }

        return $data;
    }

    /**
     * Sincronização manual de status, usada por imobiliária e admin.
     */
    private function syncAnalysisStatus(InsuranceAnalysis $analysis, string $requestedBy): RedirectResponse
    {
        $responsePayload = $analysis->providerResponsePayload();

        $isToo = mb_strtolower((string) $analysis->provider) === 'too';
        $normalizedStatus = mb_strtolower((string) $analysis->status);

        $tooAutoStopped = $isToo
            && (bool) data_get($responsePayload, 'too_status_check_stopped', false);

        $tooManualSyncAvailable = $isToo
            && (bool) data_get($responsePayload, 'too_manual_sync_available', false);

        $finalStatuses = [
            'approved',
            'quoted',
            'rejected',
            'denied',
            'refused',
            'failed',
            'error',
        ];

        $canSyncByQuote = ! $isToo && filled($analysis->quote_id);

        $canSyncTooManually = $isToo
            && filled($analysis->tooNumeroProposta())
            && $tooAutoStopped
            && $tooManualSyncAvailable
            && ! in_array($normalizedStatus, $finalStatuses, true);

        if (! $canSyncByQuote && ! $canSyncTooManually) {
            return back()->with(
                'error',
                'Essa análise ainda não está disponível para sincronização manual.'
            );
        }

        $isAdmin = $requestedBy === 'admin';

        $attempt = $analysis->currentAttemptContext();

        if ($attempt === null) {
            return back()->with('error', 'Não foi possível identificar a rodada desta análise para consultar o status.');
        }

        $analysis->events()->create([
            'event_type' => $canSyncTooManually ? 'too_manual_sync_requested' : 'sync_requested',
            'status' => $analysis->status,
            'message' => $canSyncTooManually
                ? ($isAdmin
                    ? 'Verificação manual de status da Too solicitada pelo admin/corretor.'
                    : 'Verificação manual de status da Too solicitada pela imobiliária.')
                : ($isAdmin
                    ? 'Sincronização de status solicitada pelo admin/corretor.'
                    : 'Sincronização de status solicitada pela imobiliária.'),
            'payload' => [
                'attempt_id' => $attempt['attempt_id'],
                'is_reanalysis' => $attempt['is_reanalysis'],
                'requested_by' => $requestedBy,
                'requested_at' => now()->toDateTimeString(),
                'provider' => $analysis->provider,
                'proposal_id' => $analysis->proposal_id,
                'quote_id' => $analysis->quote_id,
            ],
        ]);

        SyncProviderAnalysisStatusJob::dispatch(
            analysisId: $analysis->id,
            attemptId: $attempt['attempt_id'],
            isReanalysis: $attempt['is_reanalysis'],
        );

        return back()->with(
            'success',
            $canSyncTooManually
                ? 'Verificação manual do status da Too enviada para a fila.'
                : 'Consulta de status enviada para a fila.'
        );
    }
}
