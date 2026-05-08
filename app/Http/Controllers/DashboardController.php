<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Jobs\SyncCompanyLeadLoversLeadsJob;

class DashboardController extends Controller
{
    /**
     * Dispara a sincronização dos leads da imobiliária com a LeadLovers.
     * Retorna false se já houver uma sincronização na fila ou em execução.
     */
    private function queueCompanySync(Company $company): bool
    {
        if (in_array($company->sync_status, ['queued', 'running'], true)) {
            return false;
        }

        $company->update([
            'sync_status' => 'queued',
            'sync_error' => null,
        ]);

        SyncCompanyLeadLoversLeadsJob::dispatch($company->id);

        return true;
    }

    /**
     * Garante que a imobiliária tenha uma chave de acesso.
     * Isso protege empresas antigas criadas antes da nova lógica.
     */
    private function ensureLeadAccessCode(Company $company): void
    {
        if (filled($company->lead_access_code)) {
            return;
        }

        do {
            $code = $this->randomAlphaNumericCode(6);
        } while (Company::where('lead_access_code', $code)->exists());

        $company->lead_access_code = $code;

        /**
         * Se lead_form_active estiver null por causa de registros antigos,
         * deixamos ativo para permitir o uso do formulário público.
         */
        if (is_null($company->lead_form_active)) {
            $company->lead_form_active = true;
        }

        $company->save();
    }

    /**
     * Gera uma chave curta e legível.
     * Remove caracteres confusos como O, 0, I e 1.
     */
    private function randomAlphaNumericCode(int $length = 6): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $code;
    }

    /**
     * Endpoint usado pelo JavaScript do dashboard para acompanhar
     * o status da sincronização.
     */
    public function syncStatus(Request $request)
    {
        $companyId = session('company_id');

        if (!$companyId) {
            return response()->json([
                'authenticated' => false,
                'message' => 'Usuário não autenticado.',
            ], 401);
        }

        $company = Company::find($companyId);

        if (!$company) {
            return response()->json([
                'authenticated' => false,
                'message' => 'Empresa não encontrada.',
            ], 404);
        }

        return response()->json([
            'authenticated' => true,
            'sync_status' => $company->sync_status,
            'sync_error' => $company->sync_error,
            'sincronizado_em' => optional($company->sincronizado_em)->format('d/m/Y H:i'),
            'total_leads' => $company->leads()->count(),
        ]);
    }

    /**
     * Dashboard da imobiliária.
     */
    public function index(Request $request)
    {
        $companyId = session('company_id');

        if (!$companyId) {
            return redirect()->route('empresa.login');
        }

        $company = Company::find($companyId);

        if (!$company) {
            return redirect()
                ->route('empresa.login')
                ->withErrors([
                    'email' => 'Empresa não encontrada. Faça login novamente.',
                ]);
        }

        /**
         * Nova lógica:
         * a imobiliária usa chave de acesso, não mais token público direto.
         */
        $this->ensureLeadAccessCode($company);

        /**
         * Primeira sincronização automática.
         */
        if (is_null($company->sincronizado_em)) {
            $this->queueCompanySync($company);
        }

        $recentThreshold = now()->subDays(7);
        $companyTagName = mb_strtolower(trim((string) $company->name));
        $selectedTag = trim((string) $request->query('tag', ''));

        if (mb_strtolower(trim($selectedTag)) === $companyTagName) {
            $selectedTag = '';
        }

        /**
         * Conta as tags salvas nos leads da imobiliária.
         */
        $tagCounts = $company->leads()
            ->pluck('tags_originais')
            ->filter()
            ->flatMap(function ($tags) use ($companyTagName) {
                return collect(preg_split('/\s*,\s*/', $tags))
                    ->filter(fn ($tag) => filled($tag))
                    ->map(fn ($tag) => trim($tag))
                    ->reject(function ($tag) use ($companyTagName) {
                        return mb_strtolower(trim($tag)) === $companyTagName;
                    });
            })
            ->countBy()
            ->sortDesc();

        /**
         * Query principal dos leads exibidos na tabela.
         */
        $leadsQuery = $company->leads()
            ->orderBy('created_at', 'desc');

        if (filled($selectedTag)) {
            $leadsQuery->where(
                'tags_originais',
                'like',
                '%' . addcslashes($selectedTag, '%_\\') . '%'
            );
        }

        $leads = $leadsQuery
            ->paginate(6)
            ->withQueryString();

        /**
         * Estatísticas do dashboard.
         */
        $totalLeads = $company->leads()->count();

        $newLeads = $company->leads()
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhere('status', 'novo');
            })
            ->count();

        $recentLeads = $company->leads()
            ->where('created_at', '>=', $recentThreshold)
            ->count();

        $withPhone = $company->leads()
            ->whereNotNull('tel')
            ->where('tel', '!=', '')
            ->count();

        $latestLead = $company->leads()
            ->latest('created_at')
            ->first();

        $topTags = $tagCounts->take(4);
        $filterTags = $tagCounts;

        $dashboardStats = [
            'totalLeads' => $totalLeads,
            'newLeads' => $newLeads,
            'recentLeads' => $recentLeads,
            'withPhone' => $withPhone,
            'withoutPhone' => max($totalLeads - $withPhone, 0),
            'latestLeadAt' => optional($latestLead)->created_at,
            'filteredLeads' => $leads->total(),
        ];

        /**
         * Nova lógica de acesso:
         * - leadFormUrl leva para a página pública onde a chave será digitada.
         * - leadAccessCode é a chave que a imobiliária deve usar.
         */
        $leadFormUrl = route('simulation.registered-company.access');

        return view('dashboard-user', [
            'company' => $company,

            'leads' => $leads,
            'dashboardStats' => $dashboardStats,

            'topTags' => $topTags,
            'filterTags' => $filterTags,
            'selectedTag' => $selectedTag,

            'syncStatus' => $company->sync_status,
            'syncError' => $company->sync_error,

            /**
             * Mantive leadFormUrl para não quebrar a view atual.
             * Agora ele aponta para a tela de chave, não para /captacao/{token}.
             */
            'leadFormUrl' => $company->lead_form_active
                ? $leadFormUrl
                : null,

            /**
             * Nova variável principal para o dashboard.
             */
            'leadAccessCode' => $company->lead_access_code,

            'leadFormActive' => (bool) $company->lead_form_active,
        ]);
    }

    /**
     * Botão "Sincronizar novamente".
     */
    public function syncAgain()
    {
        $companyId = session('company_id');

        if (!$companyId) {
            return redirect()
                ->route('empresa.login')
                ->with('success', 'Sua sessão expirou. Entre novamente para sincronizar os leads.');
        }

        $company = Company::find($companyId);

        if (!$company) {
            return redirect()
                ->route('empresa.login')
                ->with('success', 'Empresa não encontrada para a sincronização.');
        }

        if (!$this->queueCompanySync($company)) {
            return redirect()
                ->route('Dashboard')
                ->with('success', 'A sincronização já está em andamento.');
        }

        return redirect()
            ->route('Dashboard')
            ->with('success', 'Nova sincronização iniciada com sucesso.');
    }
}
