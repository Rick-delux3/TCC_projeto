<?php

namespace App\Http\Controllers;

use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\SyncProviderAnalysisStatusJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use Illuminate\Http\Request;

class InsuranceAnalysisController extends Controller
{
    /**
     * Lista os lotes de análises no dashboard da imobiliária cadastrada.
     *
     * IMPORTANTE:
     * Este método é somente para imobiliária cadastrada.
     *
     * Locatário, locador e imobiliária não cadastrada não terão acesso a esta tela,
     * porque eles não possuem dashboard próprio no sistema.
     *
     * Para esses outros tipos, o resultado será enviado por e-mail
     * através do SendAnalysisResultsEmailJob.
     */
    public function index(Request $request)
    {
        $companyId = $this->currentCompanyId();

        /*
         * Se não existe company_id, significa que não há imobiliária logada.
         * Logo, não existe dashboard para exibir.
         */
        abort_if(!$companyId, 403, 'Empresa não identificada.');

        /*
         * Busca somente os lotes da imobiliária logada.
         *
         * Isso evita que uma imobiliária veja resultados de outra.
         *
         * Também evita exibir leads de locatário, locador ou imobiliária não cadastrada,
         * pois normalmente esses leads terão company_id = null.
         */
        $batches = InsuranceAnalysisBatch::with([
                'lead',
                'analyses',
            ])
            ->where('company_id', $companyId)
            ->latest()
            ->paginate(15);

        return view('', compact('batches'));
    }

    /**
     * Mostra detalhes de um lote específico no dashboard da imobiliária cadastrada.
     *
     * Um lote representa:
     * - 1 lead;
     * - várias análises em companhias diferentes.
     *
     * Exemplo:
     * Batch #10
     * - Pottencial
     * - Porto
     * - Tokio
     */
    public function show(InsuranceAnalysisBatch $batch)
    {
        $companyId = $this->currentCompanyId();

        abort_if(!$companyId, 403, 'Empresa não identificada.');

        /*
         * Garante que a imobiliária só veja lotes dela.
         *
         * Se o lote for de um lead sem company_id, por exemplo locatário direto,
         * ele não deve aparecer no dashboard de imobiliária.
         */
        abort_if(
            (int) $batch->company_id !== (int) $companyId,
            403,
            'Você não tem permissão para acessar esta análise.'
        );

        /*
         * Carrega os dados necessários para a tela:
         * - lead: dados do solicitante;
         * - company: imobiliária vinculada;
         * - analyses.events: análises por companhia + histórico.
         */
        $batch->load([
            'lead',
            'company',
            'analyses.events',
        ]);

        return view('insurance-analyses.show', compact('batch'));
    }

    /**
     * Reenvia uma análise específica para a fila.
     *
     * Este método é usado no dashboard da imobiliária cadastrada.
     *
     * Atenção:
     * Aqui recebemos uma InsuranceAnalysis, não um Batch.
     *
     * Exemplo:
     * Um lote pode ter 3 análises:
     * - Pottencial
     * - Porto
     * - Tokio
     *
     * Se apenas a Pottencial falhou, o retry deve reenviar somente a análise da Pottencial.
     */
    public function retry(InsuranceAnalysis $analysis)
    {
        $this->authorizeCompanyAccess($analysis);

        /*
         * Evita reenvio de análises que não devem ser reenviadas.
         *
         * Exemplo:
         * Se uma análise já foi aprovada, não faz sentido reenviar automaticamente.
         */
        if (!in_array($analysis->status, ['failed', 'manual_review', 'rejected'], true)) {
            return back()->with(
                'warning',
                'Essa análise não está em um status permitido para reenvio.'
            );
        }

        /*
         * Limpa os dados da tentativa anterior.
         *
         * Os eventos antigos continuam salvos em insurance_analysis_events,
         * então o histórico não é perdido.
         */
        $analysis->update([
            'status' => 'pending',
            'result' => null,
            'provider_status' => null,
            'quote_id' => null,
            'request_payload' => null,
            'response_payload' => null,
            'available_plans' => null,
            'available_assistances' => null,
            'premium_amount' => null,
            'insured_amount' => null,
            'error_message' => null,
            'requested_at' => null,
            'finished_at' => null,
        ]);

        $analysis->events()->create([
            'event_type' => 'retry_requested',
            'status' => 'pending',
            'message' => 'Reenvio da análise solicitado pela imobiliária.',
        ]);

        /*
         * Dispara novamente a análise apenas para a companhia desta linha.
         */
        RunProviderAnalysisJob::dispatch($analysis->id);

        return back()->with('success', 'Análise reenviada para a fila.');
    }

    /**
     * Sincroniza o status de uma análise específica com a companhia.
     *
     * Use quando a análise estiver:
     * - manual_review;
     * - quoted;
     * - pending;
     * - processing.
     *
     * Exemplo:
     * A companhia retornou "UnderAnalysis".
     * Depois de um tempo, a imobiliária ou admin pode clicar em "sincronizar".
     */
    public function syncStatus(InsuranceAnalysis $analysis)
    {
        $this->authorizeCompanyAccess($analysis);

        if (!$analysis->quote_id) {
            return back()->with(
                'error',
                'Não é possível consultar o status porque essa análise ainda não possui quote_id.'
            );
        }

        $analysis->events()->create([
            'event_type' => 'sync_requested',
            'status' => $analysis->status,
            'message' => 'Sincronização de status solicitada pela imobiliária.',
        ]);

        SyncProviderAnalysisStatusJob::dispatch($analysis->id);

        return back()->with('success', 'Consulta de status enviada para a fila.');
    }

    /**
     * Lista todos os lotes de análises para o admin/corretor.
     *
     * Esta tela é diferente do dashboard da imobiliária.
     *
     * O admin/corretor pode visualizar:
     * - leads de imobiliária cadastrada;
     * - leads de imobiliária não cadastrada;
     * - leads de locatário;
     * - leads de locador.
     *
     * Mesmo que esses outros tipos não tenham dashboard próprio,
     * o admin pode acompanhar todos no painel administrativo.
     */
    public function adminIndex(Request $request)
    {
        $batches = InsuranceAnalysisBatch::with([
                'lead',
                'company',
                'analyses',
            ])
            ->latest()
            ->paginate(20);

        return view('admin.insurance-analyses.index', compact('batches'));
    }

    /**
     * Mostra o detalhe de qualquer lote para o admin/corretor.
     *
     * Aqui não filtramos por company_id,
     * porque o admin precisa enxergar todos os resultados.
     */
    public function adminShow(InsuranceAnalysisBatch $batch)
    {
        $batch->load([
            'lead',
            'company',
            'analyses.events',
        ]);

        return view('admin.insurance-analyses.show', compact('batch'));
    }

    /**
     * Reenvia uma análise específica pelo painel admin.
     *
     * O admin pode reenviar qualquer análise,
     * inclusive de leads sem company_id.
     */
    public function adminRetry(InsuranceAnalysis $analysis)
    {
        $analysis->update([
            'status' => 'pending',
            'result' => null,
            'provider_status' => null,
            'quote_id' => null,
            'request_payload' => null,
            'response_payload' => null,
            'available_plans' => null,
            'available_assistances' => null,
            'premium_amount' => null,
            'insured_amount' => null,
            'error_message' => null,
            'requested_at' => null,
            'finished_at' => null,
        ]);

        $analysis->events()->create([
            'event_type' => 'retry_requested',
            'status' => 'pending',
            'message' => 'Reenvio da análise solicitado pelo admin/corretor.',
        ]);

        RunProviderAnalysisJob::dispatch($analysis->id);

        return back()->with('success', 'Análise reenviada para a fila.');
    }

    /**
     * Sincroniza o status de uma análise pelo painel admin.
     */
    public function adminSyncStatus(InsuranceAnalysis $analysis)
    {
        if (!$analysis->quote_id) {
            return back()->with(
                'error',
                'Não é possível consultar o status porque essa análise ainda não possui quote_id.'
            );
        }

        $analysis->events()->create([
            'event_type' => 'sync_requested',
            'status' => $analysis->status,
            'message' => 'Sincronização de status solicitada pelo admin/corretor.',
        ]);

        SyncProviderAnalysisStatusJob::dispatch($analysis->id);

        return back()->with('success', 'Consulta de status enviada para a fila.');
    }

    /**
     * Recupera o company_id da imobiliária logada.
     *
     * No seu projeto, a imobiliária pode estar identificada por:
     * - auth()->user()->company_id;
     * - session('company_id').
     *
     * Esse método é usado apenas nas telas da imobiliária cadastrada.
     */
    private function currentCompanyId(): ?int
    {
        return auth()->user()?->company_id
            ?? session('company_id');
    }

    /**
     * Protege ações feitas pela imobiliária cadastrada.
     *
     * Uma imobiliária só pode:
     * - ver;
     * - reenviar;
     * - sincronizar;
     *
     * análises vinculadas ao próprio company_id.
     *
     * Leads de locatário, locador e imobiliária não cadastrada normalmente
     * não possuem company_id e, por isso, não entram neste dashboard.
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