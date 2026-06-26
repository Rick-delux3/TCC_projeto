<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSimulationLeadRequest;
use App\Jobs\SendLeadToLeadLoversJob;
use App\Models\Imobiliaria;
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
            'locador' => redirect()->route('simulation.unregistered-company.form'),
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

        $company = Imobiliaria::where('lead_access_code', $code)
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
    public function unregisteredCompanyForm(Request $request)
    {
        $responsavelTipo = $request->get('responsavel_tipo', 'imobiliaria_nao_cadastrada');

        return view('simulation.forms.unregistered-company_landlord', compact('responsavelTipo'));
    }

    public function storeUnregisteredCompanyLead(StoreSimulationLeadRequest $request)
    {
        $data = $request->validated();
        
        $responsavelTipo = $data['responsavel_tipo'];

        $lead = DB::transaction(function () use ($request, $responsavelTipo) {
            return $this->saveLead($request, [
                'tipo_solicitante' => $responsavelTipo,
                'company' => null,
                'origem' => $responsavelTipo,
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
    


    /**
     * Busca imobiliária por código de acesso.
     * Nunca confie em company_id vindo do formulário.
     */
    private function findCompanyByCode(string $code): Imobiliaria
    {
        $code = mb_strtoupper(trim($code));
        $code = str_replace([' ', '-'], '', $code);

        return Imobiliaria::where('lead_access_code', $code)
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

        $leadIdentity = [
            'company_id' => $company?->id,
            'email' => $data['email'],
        ];

        if (!$company) {
            $leadIdentity['origem'] = $context['origem'];
        }

        $lead = Lead::updateOrCreate(
            $leadIdentity,
            [
                'company_id' => $company?->id,
                'tipo_solicitante' => $context['tipo_solicitante'],
                'nome' => $data['nome'],
                'email' => $data['email'],
                'cpf' => $data['cpf'] ?? null,
                'tel' => $data['tel'] ?? null,
                'estado_civil' => $data['estado_civil'] ?? null,
                'imobiliaria' => $company?->name 
                    ?? 
                    (($data['responsavel_tipo'] ?? null) === 'imobiliaria_nao_cadastrada'
                    ? ($data['responsavel_nome'] ?? null)
                    : null),
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

        $lead->endereco()->updateOrCreate(
            ['lead_id' => $lead->id],
            [
                'cep' => $data['cep'] ?? null,
                'logradouro' => $data['logradouro'] ?? null,
                'numero' => $data['numero'] ?? null,
                'complemento' => $data['complemento'] ?? null,
                'bairro' => $data['bairro'] ?? null,
                'cidade_imovel' => $data['cidade_imovel'] ?? null,
                'estado' => $data['estado'] ?? null,
            ]
        );

        $lead->despesas()->updateOrCreate(
            ['lead_id' => $lead->id],
            [
                'valor_aluguel' => $valorAluguel,
                'valor_agua' => $valorAgua,
                'valor_luz' => $valorLuz,
                'valor_gas' => $valorGas,
                'valor_condominio' => $valorCondominio,
                'valor_iptu' => $valorIptu,
                'outras_despesas' => $outrasDespesas,
                'valor_total_encargos' => $valorTotalEncargos,
            ]
        );

        if (filled($data['conjuge_nome'] ?? null) || filled($data['conjuge_cpf'] ?? null)) {
            $lead->conjuge()->updateOrCreate(
                ['lead_id' => $lead->id],
                [
                    'nome' => $data['conjuge_nome'] ?? null,
                    'cpf' => $data['conjuge_cpf'] ?? null,
                ]
            );
        } else {
            $lead->conjuge()->delete();
        }

        $responsavelTipo = $data['responsavel_tipo'] ?? null;

        if ($responsavelTipo === 'locador') {
            $lead->locador()->updateOrCreate(
                ['lead_id' => $lead->id],
                [
                    'nome' => $data['responsavel_nome'] ?? null,
                    'telefone' => $data['responsavel_telefone'] ?? null,
                    'email' => $data['responsavel_email'] ?? null,
                ]
            );

            // Garante que não fique dado duplicado na tabela de imobiliária informada.
            $lead->imobiliariaInformada()->delete();
        }

        if ($responsavelTipo === 'imobiliaria_nao_cadastrada') {
            $lead->imobiliariaInformada()->updateOrCreate(
                ['lead_id' => $lead->id],
                [
                    'nome_imobiliaria_informada' => $data['responsavel_nome'] ?? null,
                    'responsavel_preenchimento' => $data['responsavel_email'] ?? null,
                    'telefone_responsavel' => $data['responsavel_telefone'] ?? null,
                ]
            );

            // Garante que não fique dado duplicado na tabela de locador.
            $lead->locador()->delete();
        }

        return $lead;
        
    }

    /**
     * Salva uma prévia das tags no banco para visualização no dashboard.
     */
    private function tagsAsString(string $tipoSolicitante, ?Imobiliaria $company): string
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
