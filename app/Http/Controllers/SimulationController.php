<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSimulationLeadRequest;
use App\Jobs\SendLeadToLeadLoversJob;
use App\Models\Company;
use App\Models\Lead;
use Illuminate\Http\Request;
use App\Jobs\StartInsuranceAnalysesBatchJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;


class SimulationController extends Controller
{
    /**
     * Mostra o questionário inicial.
     */
    public function start()
    {
        return view('simulation.start');
    }

    public function success()
    {
        return view('simulation.success');
    }

    /**
     * Redireciona o usuário conforme o perfil escolhido.
     */
    public function chooseProfile(Request $request)
    {
        $data = $request->validate([
            'tipo_solicitante' => [
                'required',
                'in:imobiliaria_cadastrada,imobiliaria_nao_cadastrada,locatario,locador',
            ],
        ]);

        return match ($data['tipo_solicitante']) {
            'imobiliaria_cadastrada' => redirect()->route('simulation.registered-company.access'),
            'imobiliaria_nao_cadastrada' => redirect()->route('simulation.unregistered-company.form'),
            'locatario' => redirect()->route('simulation.tenant.form'),
            'locador' => redirect()->route('simulation.landlord.form'),
        };
    }

    /**
     * Tela para digitar a chave da imobiliária cadastrada.
     */
    public function registeredCompanyAccess()
    {
        return view('simulation.registered-company-access');
    }

    /**
     * Valida a chave da imobiliária.
     */
    public function verifyCompanyCode(Request $request)
    {
        $data = $request->validate([
            'lead_access_code' => ['required', 'string', 'max:20'],
        ]);

        // Normaliza o código para evitar erro por minúscula ou espaço.
        $code = mb_strtoupper(trim($data['lead_access_code']));
        $code = str_replace([' ', '-'], '', $code);

        $company = Company::where('lead_access_code', $code)
            ->where('lead_form_active', true)
            ->first();

        if (!$company) {
            return back()
                ->withInput()
                ->withErrors([
                    'lead_access_code' => 'Código inválido ou formulário indisponível.',
                ]);
        }

        return redirect()->route('simulation.registered-company.form', [
            'code' => $company->lead_access_code,
        ]);
    }

    /**
     * Formulário vinculado à imobiliária cadastrada.
     */
    public function registeredCompanyForm(string $code)
    {
        $company = $this->findCompanyByCode($code);

        return view('simulation.forms.registered-company', compact('company'));
    }

    /**
     * Salva lead de imobiliária cadastrada.
     */
    public function storeRegisteredCompanyLead(StoreSimulationLeadRequest $request, string $code)
    {
        $company = $this->findCompanyByCode($code);

        $lead = DB::transaction(function () use ($request, $company){
            return $this->saveLead($request, [
                    'tipo_solicitante' => 'imobiliaria_cadastrada',
                    'company' => $company,
                    'origem' => 'imobiliaria_cadastrada',
                ]);
        });
        

        $this->dispatchLeadFlow($lead);

        return redirect()->route('simulation.success')->with('success', 'Solicitação enviada com sucesso.');
    }

    /**
     * Formulário para imobiliária ainda não cadastrada.
     */
    public function unregisteredCompanyForm()
    {
        return view('simulation.forms.unregistered-company');
    }

    public function storeUnregisteredCompanyLead(StoreSimulationLeadRequest $request)
    {
        $lead = DB::transaction(function () use ($request) {
            return $this->saveLead($request, [
                'tipo_solicitante' => 'imobiliaria_nao_cadastrada',
                'company' => null,
                'origem' => 'imobiliaria_nao_cadastrada',
            ]);
        });

        $this->dispatchLeadFlow($lead);

        return redirect()
            ->route('simulation.success')
            ->with('success', 'Solicitação enviada com sucesso. O resultado será enviado por e-mail.');
    }

    /**
     * Formulário para locatário.
     */
    public function tenantForm()
    {
        return view('simulation.forms.tenant');
    }

    public function storeTenantLead(StoreSimulationLeadRequest $request)
    {
       $lead = DB::transaction(function () use ($request) {
            return $this->saveLead($request, [
                'tipo_solicitante' => 'locatario',
                'company' => null,
                'origem' => 'locatario',
            ]);
        });

        $this->dispatchLeadFlow($lead);

        return redirect()
            ->route('simulation.success')
            ->with('success', 'Solicitação enviada com sucesso. O resultado será enviado por e-mail.');
    }

    /**
     * Formulário para locador.
     */
    public function landlordForm()
    {
        return view('simulation.forms.landlord');
    }

    public function storeLandlordLead(StoreSimulationLeadRequest $request)
    {
        $lead = DB::transaction(function () use ($request) {
            return $this->saveLead($request, [
                'tipo_solicitante' => 'locador',
                'company' => null,
                'origem' => 'locador',
            ]);
        });

        $this->dispatchLeadFlow($lead);

        return redirect()
            ->route('simulation.success')
            ->with('success', 'Solicitação enviada com sucesso. O resultado será enviado por e-mail.');
    }

    /**
     * Busca imobiliária por código de acesso.
     * Nunca confie em company_id vindo do formulário.
     */
    private function findCompanyByCode(string $code): Company
    {
        $code = mb_strtoupper(trim($code));
        $code = str_replace([' ', '-'], '', $code);

        return Company::where('lead_access_code', $code)
            ->where('lead_form_active', true)
            ->firstOrFail();
    }

    /**
     * Salva o lead de forma centralizada.
     * Essa função evita repetir código nos quatro formulários.
     */
    private function saveLead(StoreSimulationLeadRequest $request, array $context): Lead
    {
        $data = $request->validated();

        $company = $context['company'] ?? null;

        $valorAluguel = (float) ($data['valor_aluguel'] ?? 0);
        $outrasDespesas = (float) ($data['outras_despesas'] ?? 0);

        $valorAluguel = (float) ($data['valor_aluguel'] ?? 0);

        $valorCondominio = (float) ($data['valor_condominio'] ?? 0);
        $valorIptu = (float) ($data['valor_iptu'] ?? 0);
        $valorGas = (float) ($data['valor_gas'] ?? 0);
        $outrasDespesas = (float) ($data['outras_despesas'] ?? 0);

        $valorAgua = isset($data['valor_agua']) && $data['valor_agua'] !== null && $data['valor_agua'] !== ''
            ? (float) $data['valor_agua']
            : $valorAluguel * 0.10;

        $valorLuz = isset($data['valor_luz']) && $data['valor_luz'] !== null && $data['valor_luz'] !== ''
            ? (float) $data['valor_luz']
            : $valorAluguel * 0.10;

        $valorTotalEncargos = $valorAluguel
            + $valorCondominio
            + $valorIptu
            + $valorGas
            + $valorAgua
            + $valorLuz
            + $outrasDespesas;


            $cpfCnpj = $data['cpf_cnpj']
            ?? $data['cpf']
            ?? $data['cnpj']
            ?? null;

        return Lead::updateOrCreate(
            [
                'company_id' => $company?->id,
                'email' => $data['email'],
            ],
            [
                'tipo_solicitante' => $context['tipo_solicitante'],

                'nome' => $data['nome'],
                'email' => $data['email'],
                'cpf' => $data['cpf'] ?? null,
                'tel' => $data['tel'] ?? null,

                'estado_civil' => $data['estado_civil'] ?? null,
                'conjuge_nome' => $data['conjuge_nome'] ?? null,
                'conjuge_cpf' => $data['conjuge_cpf'] ?? null,

                'cep' => $data['cep'] ?? null,
                'logradouro' => $data['logradouro'] ?? null,
                'numero' => $data['numero'] ?? null,
                'complemento' => $data['complemento'] ?? null,
                'bairro' => $data['bairro'] ?? null,
                'cidade_imovel' => $data['cidade_imovel'] ?? null,
                'estado' => $data['estado'] ?? null,

                'valor_aluguel' => $valorAluguel ?: null,
                'valor_condominio' => $valorCondominio,
                'valor_iptu' => $valorIptu,
                'valor_gas' => $valorGas,
                'valor_agua' => $valorAgua,
                'valor_luz' => $valorLuz,
                'outras_despesas' => $outrasDespesas,
                'valor_total_encargos' => $valorTotalEncargos,
                
                'nome_imobiliaria_informada' => $data['nome_imobiliaria_informada'] ?? null,
                'cnpj_imobiliaria_informada' => $data['cnpj_imobiliaria_informada'] ?? null,

                'nome_locador' => $data['nome_locador'] ?? null,
                'telefone_locador' => $data['telefone_locador'] ?? null,
                'email_locador' => $data['email_locador'] ?? null,

                'responsavel_preenchimento' => $data['responsavel_preenchimento'] ?? null,
                'telefone_responsavel' => $data['telefone_responsavel'] ?? null,

                'imobiliaria' => $company?->name ?? ($data['nome_imobiliaria_informada'] ?? null),
                'tags_originais' => $this->tagsAsString($context['tipo_solicitante'], $company),

                'status' => 'novo',
                'origem' => $context['origem'],
                'leadlovers_status' => 'pending',

                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'aceite_termos' => $request->boolean('aceite_termos'),
                'observacoes' => $data['observacoes'] ?? null,
            ]
        );
    }

    /**
     * Salva uma prévia das tags no banco para visualização no dashboard.
     */
    private function tagsAsString(string $tipoSolicitante, ?Company $company): string
    {
        $tags = match ($tipoSolicitante) {
        'imobiliaria_cadastrada' => [
            $company?->name,
        ],

        'imobiliaria_nao_cadastrada' => [
            'imobiliaria morna',
        ],

        'locatario' => [
            'locatario',
        ],

        'locador' => [
            'diretoprop',
        ],

        default => [],
    };

        return collect($tags)->filter()->implode(', ');
    }

    private function dispatchLeadFlow(Lead $lead): void {
        Bus::chain([
            new SendLeadToLeadLoversJob($lead->id),
            new StartInsuranceAnalysesBatchJob($lead->id),
        ])->dispatch();
    }
}
