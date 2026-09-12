<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Support\LeadLoversInitialFailureCatalog;
use App\Support\ManualLeadResultTags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class CorretorDashboardLeadQuery
{
    public const WITHOUT_RESULT = 'sem_resultado';

    /** @var array{sql: string, bindings: list<string>}|null */
    private ?array $resultExpression = null;

    /** @return array<string, string> */
    public static function requesterOptions(): array
    {
        return [
            'imobiliaria_cadastrada' => 'Imobiliária cadastrada',
            'imobiliaria_nao_cadastrada' => 'Imobiliária não cadastrada',
            'locador' => 'Proprietário / locador',
            'locatario' => 'Locatário',
        ];
    }

    public function base(): Builder
    {
        $source = Lead::query()->createdThroughSystem()->select('leads.*');
        $normalizedTags = "LOWER(COALESCE(tags_originais, ''))";

        foreach (['Ã' => 'a', 'ã' => 'a', 'Ç' => 'c', 'ç' => 'c', '_' => '', '-' => '', ' ' => ''] as $from => $to) {
            $normalizedTags = "REPLACE({$normalizedTags}, '{$from}', '{$to}')";
        }

        foreach ([9, 10, 13] as $character) {
            $normalizedTags = "REPLACE({$normalizedTags}, CHAR({$character}), '')";
        }

        $normalizedTags = $source->getConnection()->getDriverName() === 'sqlite'
            ? "(',' || {$normalizedTags} || ',')"
            : "CONCAT(',', {$normalizedTags}, ',')";

        $source->selectRaw("{$normalizedTags} AS dashboard_tags");
        $result = $this->resultExpression();
        $classified = Lead::query()->fromSub($source, 'leads')
            ->select('leads.*')
            ->selectRaw($result['sql'].' AS dashboard_result', $result['bindings']);

        return Lead::query()->fromSub($classified, 'leads')->select('leads.*');
    }

    /** @param array<string, mixed> $filters */
    public function apply(Builder $query, array $filters): Builder
    {
        $search = $filters['lead_name'] ?? '';

        if ($search !== '') {
            $pattern = '%'.$this->escapeLike($search).'%';
            $digits = preg_replace('/\D/', '', $search);
            $isPhoneOrDocument = preg_match('/\A[\d\s()+.\/-]+\z/', $search) === 1 && $digits !== '';

            $query->where(function (Builder $searchQuery) use ($pattern, $digits, $isPhoneOrDocument): void {
                foreach (['nome', 'email', 'cpf', 'tel'] as $column) {
                    $searchQuery->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$pattern]);
                }

                if ($isPhoneOrDocument) {
                    foreach (['cpf', 'tel'] as $column) {
                        $normalized = "COALESCE({$column}, '')";

                        foreach ([' ', '.', '-', '/', '(', ')', '+'] as $separator) {
                            $normalized = "REPLACE({$normalized}, '{$separator}', '')";
                        }

                        $searchQuery->orWhereRaw("{$normalized} LIKE ?", ['%'.$digits.'%']);
                    }
                }
            });
        }

        $company = $filters['imobiliaria'] ?? '';

        if ($company === 'sem_vinculo') {
            $query->whereNull('company_id');
        } elseif ($company !== '') {
            $query->where('company_id', $company);
        }

        $profile = $filters['tipo_solicitante'] ?? '';

        if ($profile !== '') {
            $this->requesterProfile($query, $profile);
        }

        $result = $filters['resultado'] ?? '';

        if ($result === self::WITHOUT_RESULT) {
            $this->withoutResult($query);
        } elseif ($result !== '') {
            $query->where('dashboard_result', $result);
        }

        if (($filters['leadlovers_sync'] ?? '') === LeadLoversInitialFailureCatalog::DASHBOARD_FILTER_NOT_SENT) {
            $query->notSentToLeadLoversBecauseOfInvalidData();
        }

        return $query;
    }

    public function approvedFirst(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN dashboard_result = ? THEN 0 ELSE 1 END', [ManualLeadResultTags::APPROVED])
            ->latest('created_at')->latest('id');
    }

    public function requesterProfileFor(Lead $lead): ?string
    {
        $profile = mb_strtolower(trim((string) $lead->tipo_solicitante));

        if ($profile !== '') {
            return array_key_exists($profile, self::requesterOptions()) ? $profile : null;
        }

        if (array_key_exists((string) $lead->origem, self::requesterOptions())) {
            return $lead->origem;
        }

        if ($lead->origem !== 'simulacao_publica') {
            return null;
        }

        return match (true) {
            $lead->company_id !== null => 'imobiliaria_cadastrada',
            $lead->imobiliariaInformada !== null => 'imobiliaria_nao_cadastrada',
            $lead->locador !== null => 'locador',
            default => null,
        };
    }

    /** @return array<string, int|Carbon|null> */
    public function statistics(): array
    {
        $stats = $this->base()->toBase()->select([])
            ->selectRaw('COUNT(*) AS total_leads, COUNT(CASE WHEN status = ? THEN 1 END) AS new_leads, COUNT(CASE WHEN created_at >= ? THEN 1 END) AS recent_leads, COUNT(CASE WHEN dashboard_result = ? THEN 1 END) AS approved_leads, COUNT(CASE WHEN dashboard_result = ? THEN 1 END) AS rejected_leads, MAX(created_at) AS latest_lead_at', [
                'novo', now()->subDays(7), ManualLeadResultTags::APPROVED, ManualLeadResultTags::REJECTED,
            ])->first();

        return [
            'totalLeads' => (int) $stats->total_leads,
            'newLeads' => (int) $stats->new_leads,
            'recentLeads' => (int) $stats->recent_leads,
            'totalAprovados' => (int) $stats->approved_leads,
            'totalRecusados' => (int) $stats->rejected_leads,
            'latestLeadAt' => $stats->latest_lead_at === null ? null : Carbon::parse($stats->latest_lead_at),
        ];
    }

    private function requesterProfile(Builder $query, string $profile): void
    {
        $query->where(function (Builder $profiles) use ($profile): void {
            $profiles->whereRaw('LOWER(TRIM(tipo_solicitante)) = ?', [$profile])
                ->orWhere(function (Builder $legacy) use ($profile): void {
                    $legacy->whereRaw("COALESCE(TRIM(tipo_solicitante), '') = ''")
                        ->where(function (Builder $origins) use ($profile): void {
                            $origins->where('origem', $profile)
                                ->orWhere(function (Builder $public) use ($profile): void {
                                    $public->where('origem', 'simulacao_publica');

                                    if ($profile === 'imobiliaria_cadastrada') {
                                        $public->whereNotNull('company_id');
                                    } elseif ($profile === 'imobiliaria_nao_cadastrada') {
                                        $public->whereNull('company_id')->whereHas('imobiliariaInformada');
                                    } elseif ($profile === 'locador') {
                                        $public->whereNull('company_id')->whereDoesntHave('imobiliariaInformada')->whereHas('locador');
                                    } else {
                                        $public->whereRaw('1 = 0');
                                    }
                                });
                        });
                });
        });
    }

    private function withoutResult(Builder $query): void
    {
        $query->whereNull('dashboard_result')
            ->whereNull('leadlovers_confirmed_final_tag_key')
            ->whereIn('leadlovers_status', ['sent', 'send'])
            ->where('leadlovers_lead_id', '>', 0)
            ->whereNotNull('sent_to_leadlovers_at')
            ->whereNull('updated_by_corretor_id')
            ->where('leadlovers_update_version', 0)
            ->where('leadlovers_update_status', 'idle')
            ->whereNull('leadlovers_update_requested_at')
            ->whereNull('leadlovers_update_at')
            ->whereNull('leadlovers_final_tag_confirmed_at')
            ->whereColumn('updated_at', '<=', 'sent_to_leadlovers_at')
            ->whereDoesntHave('leadLoversTagOperation')
            ->whereDoesntHave('activityLogs', function (Builder $logs): void {
                $logs->whereIn('action', ['lead_data_update_requested', 'lead_tag_update_requested', 'lead_updated']);
            });

        foreach (['endereco', 'despesas', 'conjuge', 'lead_empresa', 'imobiliariaInformada', 'locador'] as $relationship) {
            $query->whereDoesntHave($relationship, function (Builder $details): void {
                $details->whereColumn($details->qualifyColumn('updated_at'), '>', 'leads.sent_to_leadlovers_at');
            });
        }
    }

    /** @return array{sql: string, bindings: list<string>} */
    private function resultExpression(): array
    {
        if ($this->resultExpression !== null) {
            return $this->resultExpression;
        }

        $titles = LeadLoversTag::query()->whereIn('key', ManualLeadResultTags::leadloversKeys())->pluck('title', 'key');
        $clauses = [];
        $bindings = [];

        foreach (ManualLeadResultTags::all() as $result => $definition) {
            $clauses[] = 'WHEN leadlovers_confirmed_final_tag_key = ? THEN ?';
            array_push($bindings, $definition['leadlovers_key'], $result);
        }

        $clauses[] = "WHEN NULLIF(TRIM(leadlovers_confirmed_final_tag_key), '') IS NOT NULL THEN NULL";

        /** A confirmação remota prevalece; no legado, o resultado comercial mais avançado prevalece. */
        $aliases = [
            ManualLeadResultTags::RENTAL_CONFIRMED => ['Fechado aluguel', 'Aluguel fechado', 'Fechado alguel'],
            ManualLeadResultTags::NO_RENT_OR_INSURANCE => ['Não aluguei nem seguro', 'Não aluguel nem seguro'],
            ManualLeadResultTags::IN_NEGOTIATION => ['Em negociação'],
            ManualLeadResultTags::REJECTED => ['Ruim', 'Recusado', 'Recusada', 'Recusados', 'Recusadas', 'Reprovado', 'Reprovada', 'Reprovados', 'Reprovadas'],
            ManualLeadResultTags::APPROVED => ['Aprovado', 'Aprovada', 'Aprovados', 'Aprovadas'],
        ];

        foreach ($aliases as $result => $tags) {
            $key = ManualLeadResultTags::leadloversKey($result);
            $tags[] = $key;
            $tags[] = $titles->get($key);
            $patterns = [];

            foreach ($tags as $tag) {
                if (! is_string($tag) || trim($tag) === '') {
                    continue;
                }

                $normalized = str_replace(['ã', 'ç', ' ', '_', '-', "\t", "\r", "\n"], ['a', 'c', '', '', '', '', '', ''], mb_strtolower($tag));
                $patterns[] = '%,'.$this->escapeLike($normalized).',%';
            }

            $patterns = array_values(array_unique($patterns));
            $clauses[] = 'WHEN ('.implode(' OR ', array_fill(0, count($patterns), "dashboard_tags LIKE ? ESCAPE '!'")).') THEN ?';
            array_push($bindings, ...[...$patterns, $result]);
        }

        return $this->resultExpression = ['sql' => 'CASE '.implode(' ', $clauses).' ELSE NULL END', 'bindings' => $bindings];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
