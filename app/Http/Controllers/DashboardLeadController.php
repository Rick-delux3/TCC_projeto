<?php

namespace App\Http\Controllers;

use App\Jobs\StartInsuranceAnalysesBatchJob;
use App\Models\Imobiliaria;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\StorePublicLeadRequest;
use App\Jobs\UpdateLeadOnLeadLoversJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;




class DashboardLeadController extends Controller
{
    private function getLoggedCompany(): Imobiliaria
    {
        $companyId = session('company_id');

        abort_if(!$companyId, 401, 'Usuário não autenticado.');

        $company = Imobiliaria::find($companyId);

        abort_if(!$company, 404, 'Imobiliária não encontrada.');

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

        $data = $request->validate([
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

            $valorAluguel = (float) ($data['valor_aluguel'] ?? 0);
            $valorAgua = (float) ($data['valor_agua'] ?? 0);
            $valorLuz = (float) ($data['valor_luz'] ?? 0);
            $valorGas = (float) ($data['valor_gas'] ?? 0);
            $valorCondominio = (float) ($data['valor_condominio'] ?? 0);
            $valorIptu = (float) ($data['valor_iptu'] ?? 0);
            $outrasDespesas = (float) ($data['outras_despesas'] ?? 0);

             $valorTotalEncargos =
                $valorAluguel
                + $valorAgua
                + $valorLuz
                + $valorGas
                + $valorCondominio
                + $valorIptu
                + $outrasDespesas;

            $originalEmail = $lead->email;

            $lead->fill([
                'nome' => $data['nome'] ?? $lead->nome,
                'email' => $data['email'] ?? $lead->email,
                'tel' => $data['tel'] ?? $lead->tel,
                'cpf' => $data['cpf'] ?? $lead->cpf,
                'tipo_solicitante' => $data['tipo_solicitante'] ?? $lead->tipo_solicitante,
                'estado_civil' => $data['estado_civil'] ?? $lead->estado_civil,
            ]);

            $endereco = $lead->endereco ?: $lead->endereco()->make();

            $endereco->fill(
                [
                    'cep' => $data['cep'] ?? null,
                    'estado' => $data['estado'] ?? null,
                    'cidade_imovel' => $data['cidade_imovel'] ?? null,
                    'bairro' => $data['bairro'] ?? null,
                    'logradouro' => $data['logradouro'] ?? null,
                    'numero' => $data['numero'] ?? null,
                    'complemento' => $data['complemento'] ?? null,
                ]
            );

            $despesas = $lead->despesas ?: $lead->despesas()->make();

            $despesas->fill([
                'valor_aluguel' => $valorAluguel,
                'valor_agua' => $valorAgua,
                'valor_luz' => $valorLuz,
                'valor_gas' => $valorGas,
                'valor_condominio' => $valorCondominio,
                'valor_iptu' => $valorIptu,
                'outras_despesas' => $outrasDespesas,
                'valor_total_encargos' => $valorTotalEncargos,
            ]);

            $conjuge = $lead->conjuge ?: $lead->conjuge()->make();

            $conjuge->fill([
                'nome' => $data['conjuge_nome'] ?? null,
                'cpf' => $data['conjuge_cpf'] ?? null,
            ]);



            $leadChanged = $lead->isDirty();
            $enderecoChanged = $endereco->isDirty();
            $despesasChanged = $despesas->isDirty();
            $conjugeChanged = $conjuge->isDirty();

            if(!$leadChanged && !$enderecoChanged && !$despesasChanged && !$conjugeChanged){
                 return back()->with(
                    'error',
                    'Altere pelo menos um dado do lead antes de salvar.'
                );
            }

            DB::transaction(function () use ($lead, $endereco, $despesas, $conjuge, $leadChanged, $enderecoChanged, $despesasChanged, $conjugeChanged) {

                if ($leadChanged) $lead->save();

                if ($enderecoChanged) $lead->endereco()->save($endereco);

                if ($despesasChanged) $lead->despesas()->save($despesas);

                if ($conjugeChanged) $lead->conjuge()->save($conjuge);

                $lead->forceFill([
                    'reanalysis_unlocked_at' => now(),
                ])->saveQuietly();
            });

            /*
            |--------------------------------------------------------------------------
            | Atualização na LeadLovers
            |--------------------------------------------------------------------------
            | A atualização na LeadLovers não pode impedir o salvamento local.
            | Se o job falhar, o lead continua salvo corretamente no seu sistema.
            */
            try {
                UpdateLeadOnLeadLoversJob::dispatchAfterResponse($lead->id, $originalEmail);
            } catch (\Throwable $exception) {
                Log::warning('Lead atualizado localmente, mas falhou ao enfileirar atualização na LeadLovers.', [
                    'lead_id' => $lead->id,
                    'message' => $exception->getMessage(),
                ]);
            }

            return back()->with(
                'success',
                'Dados do lead atualizados com sucesso. Agora você pode solicitar uma reanálise.'
            );
    }


    public function reanalyze(Lead $lead)
    {
        $this->authorizeCompanyLead($lead);

         $lead->load(['endereco', 'despesas', 'conjuge']);

         if(!$lead->canRequestReanalysis()){
            return back()->with(
                'error',
                'Para solicitar uma reanálise, primeiro altere e salve algum dado do lead.',
            );
         }

        $lead->forceFill([
            'status' => 'em-andamento',
            'reanalysis_unlocked_at' => null,
        ])->save();

        /*
         * Ajuste o construtor se o seu Job receber o objeto Lead
         * em vez do ID.
         */
        StartInsuranceAnalysesBatchJob::dispatch($lead->id);

        return back()->with('success', 'Reanálise iniciada com sucesso.');
    }
}