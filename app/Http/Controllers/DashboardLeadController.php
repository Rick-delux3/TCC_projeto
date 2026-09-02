<?php

namespace App\Http\Controllers;

use App\Http\Requests\CorrectLeadLoversInitialFailureRequest;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Services\LeadLoversInitialFailureRecoveryService;
use App\Services\LeadReanalysisService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class DashboardLeadController extends Controller
{
    public function __construct(
        private LeadReanalysisService $leadReanalysisService,
        private LeadLoversInitialFailureRecoveryService $leadLoversFailureRecoveryService
    ) {}

    private function getLoggedCompany(): Imobiliaria
    {
        $companyId = session('company_id');

        abort_if(! $companyId, 401, 'Usuário não autenticado.');

        $company = Imobiliaria::find($companyId);

        abort_if(! $company, 404, 'Imobiliária não encontrada.');

        return $company;
    }

    private function authorizeCompanyLead(Lead $lead): Imobiliaria
    {
        $company = $this->getLoggedCompany();

        abort_if(
            (int) $lead->company_id !== (int) $company->id,
            403,
            'Você não tem permissão para acessar este lead.'
        );

        return $company;
    }

    public function update(Request $request, Lead $lead)
    {
        $this->authorizeCompanyLead($lead);

        return $this->saveLeadsUpdates($request, $lead);
    }

    public function adminUpdate(Request $request, Lead $lead)
    {
        $corretor = $this->authorizeAdminAbility('edit-leads');

        return $this->saveLeadsUpdates($request, $lead, $corretor);
    }

    public function reanalyze(Lead $lead)
    {
        $this->authorizeCompanyLead($lead);

        return $this->startLeadReanalysis($lead, 'imobiliaria');
    }

    public function adminReanalyze(Lead $lead)
    {
        $this->authorizeAdminAbility('create-analysis');

        return $this->startLeadReanalysis($lead, 'admin');
    }

    public function correctLeadLoversFailure(
        CorrectLeadLoversInitialFailureRequest $request,
        Lead $lead
    ) {
        $company = $this->authorizeCompanyLead($lead);

        return $this->executeLeadLoversCorrection(
            request: $request,
            lead: $lead,
            corretor: null,
            companyId: (int) $company->id,
        );
    }

    public function adminCorrectLeadLoversFailure(
        CorrectLeadLoversInitialFailureRequest $request,
        Lead $lead
    ) {
        $this->authorizeAdminAbility('edit-leads');

        /** @var Corretor $corretor */
        $corretor = Auth::guard('admin')->user();

        return $this->executeLeadLoversCorrection(
            request: $request,
            lead: $lead,
            corretor: $corretor,
            companyId: null,
        );
    }

    private function authorizeAdminAbility(string $ability): Corretor
    {
        $corretor = Auth::guard('admin')->user();

        abort_if(! $corretor, 401, 'Corretor não identificado!');

        abort_if(
            Gate::forUser($corretor)->denies($ability),
            403,
            'Você não possui permição para executar essa ação!'
        );

        return $corretor;
    }

    private function saveLeadsUpdates(
        Request $request,
        Lead $lead,
        ?Corretor $corretor = null,
    ): RedirectResponse {
        $data = $this->validateLeadUpdateRequest($request);

        $result = $this->leadReanalysisService->updateLeadDataAndMaybeUnlock(
            lead: $lead,
            data: $data,
            corretor: $corretor,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        if (! $result['changed']) {
            return back()->with('error', $result['message']);
        }

        return back()->with('success', $result['message']);
    }

    private function startLeadReanalysis(Lead $lead, string $requestedBy)
    {
        try {
            $total = $this->leadReanalysisService->startGeneralReanalysis(
                lead: $lead,
                requestedBy: $requestedBy,
                options: [
                    'motivosReanalise' => [10],
                    'observacoes' => 'Reanálise geral solicitada após alteração dos dados do lead.',

                ]
            );

            return back()->with(
                'success',
                "Reanálise geral iniciada com sucesso para {$total} companhia(s)."
            );

        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    private function validateLeadUpdateRequest(Request $request): array
    {
        return $request->validate([
            'nome' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'tel' => ['nullable', 'string', 'max:30'],
            'cpf' => ['nullable', 'string', 'max:20'],
            'tipo_solicitante' => ['nullable', 'string', 'max:100'],
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
        ]);
    }

    private function executeLeadLoversCorrection(
        CorrectLeadLoversInitialFailureRequest $request,
        Lead $lead,
        ?Corretor $corretor,
        ?int $companyId,
    ) {
        try {
            $result = $this
                ->leadLoversFailureRecoveryService
                ->correctAndRetry(
                    lead: $lead,
                    data: $request->validated(),
                    corretor: $corretor,
                    companyId: $companyId,
                    ip: $request->ip(),
                    userAgent: $request->userAgent(),
                );

            return back()->with('success', $result['message']);
        } catch (DomainException $exception) {
            return back()->with(
                'error',
                $exception->getMessage()
            );
        }
    }
}
