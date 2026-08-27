<?php

namespace App\Http\Controllers;

use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Support\LeadLoversInitialFailureCatalog;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private LeadLoversInitialFailureCatalog $leadLoversFailureCatalog,
    ) {}

    private function ensureLeadAccessCode(Imobiliaria $company): void
    {
        if (filled($company->lead_access_code)) {
            return;
        }

        do {
            $code = $this->randomAlphaNumericCode(6);
        } while (Imobiliaria::where('lead_access_code', $code)->exists());

        $company->lead_access_code = $code;

        if (is_null($company->lead_form_active)) {
            $company->lead_form_active = true;
        }

        $company->save();
    }

    private function randomAlphaNumericCode(int $length = 6): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $code;
    }

    public function index(Request $request)
    {
        $companyId = session('company_id');

        if (! $companyId) {
            return redirect()->route('empresa.login');
        }

        $company = Imobiliaria::find($companyId);

        if (! $company) {
            return redirect()
                ->route('empresa.login')
                ->withErrors([
                    'email' => 'Empresa não encontrada. Faça login novamente.',
                ]);
        }

        $this->ensureLeadAccessCode($company);

        $recentThreshold = now()->subDays(7);
        $companyTagName = mb_strtolower(trim((string) $company->name));
        $selectedTag = trim((string) $request->query('tag', ''));
        $leadSearch = trim(preg_replace('/\s+/', ' ', (string) $request->query('lead_name', '')));
        $leadLoversSyncOptions = $this->leadLoversFailureCatalog
            ->dashboardSyncOptions();
        $selectedLeadLoversSync = trim(
            (string) $request->query('leadlovers_sync', '')
        );

        if (! array_key_exists(
            $selectedLeadLoversSync,
            $leadLoversSyncOptions
        )) {
            $selectedLeadLoversSync = '';
        }

        if (mb_strtolower(trim($selectedTag)) === $companyTagName) {
            $selectedTag = '';
        }

        $tagCounts = $company->leads()
            ->createdThroughSystem()
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

        $leadsQuery = $company->leads()
            ->createdThroughSystem()
            ->with([
                'endereco',
                'despesas',
                'conjuge',
                'imobiliariaInformada',
                'locador',
                'leadLoversTagOperation',
            ])
            ->orderBy('created_at', 'desc');

        if (filled($selectedTag)) {
            $leadsQuery->where(
                'tags_originais',
                'like',
                '%'.addcslashes($selectedTag, '%_\\').'%'
            );
        }

        if (filled($leadSearch)) {
            $exactNameExists = $company->leads()
                ->createdThroughSystem()
                ->where('nome', $leadSearch)
                ->exists();

            if ($exactNameExists) {
                $leadsQuery->where('nome', $leadSearch);
            } else {
                $leadsQuery->where(
                    'nome',
                    'like',
                    addcslashes($leadSearch, '%_\\').'%'
                );
            }
        }

        if (
            $selectedLeadLoversSync
            === LeadLoversInitialFailureCatalog::DASHBOARD_FILTER_NOT_SENT
        ) {
            $leadsQuery->notSentToLeadLoversBecauseOfInvalidData();
        }

        $leads = $leadsQuery
            ->paginate(6)
            ->withQueryString();

        $notSentToLeadLoversCount = $company->leads()
            ->notSentToLeadLoversBecauseOfInvalidData()
            ->count();

        $leadLoversFailures = $leads->getCollection()
            ->mapWithKeys(fn (Lead $lead): array => [
                (int) $lead->id => $this->leadLoversFailureCatalog->describe($lead),
            ])
            ->all();

        $totalLeads = $company->leads()
            ->createdThroughSystem()
            ->count();

        $newLeads = $company->leads()
            ->createdThroughSystem()
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhere('status', 'novo');
            })
            ->count();

        $recentLeads = $company->leads()
            ->createdThroughSystem()
            ->where('created_at', '>=', $recentThreshold)
            ->count();

        $withPhone = $company->leads()
            ->createdThroughSystem()
            ->whereNotNull('tel')
            ->where('tel', '!=', '')
            ->count();

        $latestLead = $company->leads()
            ->createdThroughSystem()
            ->latest('created_at')
            ->first();

        $dashboardStats = [
            'totalLeads' => $totalLeads,
            'newLeads' => $newLeads,
            'recentLeads' => $recentLeads,
            'withPhone' => $withPhone,
            'withoutPhone' => max($totalLeads - $withPhone, 0),
            'latestLeadAt' => optional($latestLead)->created_at,
            'filteredLeads' => $leads->total(),
        ];

        $leadFormUrl = route('simulation.registered-company.access');

        return view('imobiliaria.dashboard-user', [
            'company' => $company,
            'leads' => $leads,
            'dashboardStats' => $dashboardStats,
            'topTags' => $tagCounts->take(4),
            'filterTags' => $tagCounts,
            'selectedTag' => $selectedTag,
            'leadSearch' => $leadSearch,
            'leadFormUrl' => $company->lead_form_active
                ? $leadFormUrl
                : null,
            'leadAccessCode' => $company->lead_access_code,
            'leadFormActive' => (bool) $company->lead_form_active,
            'selectedLeadLoversSync' => $selectedLeadLoversSync,
            'leadLoversSyncOptions' => $leadLoversSyncOptions,
            'notSentToLeadLoversCount' => $notSentToLeadLoversCount,
            'leadLoversFailures' => $leadLoversFailures,
        ]);
    }
}
