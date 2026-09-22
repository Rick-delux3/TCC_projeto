<?php

namespace App\Services;

use App\Events\DashboardActivityChanged;
use App\Jobs\LinkLeadToCompanyJob;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LeadCompanyLinkService
{
    /** @param array<int, string> $fields
     * @return array<int, string>
     */
    public function withoutLegacyCompanyUpdate(Lead $lead, int $syncVersion, array $fields): array
    {
        if (! in_array('company', $fields, true) || ! $lead->activityLogs()
            ->where('action', 'lead_company_link_requested')
            ->where('new_values->status', 'completed')
            ->where('new_values->sync_version', $syncVersion)->exists()) {
            return $fields;
        }

        return array_values(array_diff($fields, ['company']));
    }

    public function request(Lead $lead, int $companyId, Corretor $corretor, ?string $ip, ?string $userAgent): CorretorActivityLog
    {
        Gate::forUser($corretor)->authorize('link-lead-company');

        $requestLog = $lead->getConnection()->transaction(function () use ($lead, $companyId, $corretor, $ip, $userAgent): CorretorActivityLog {
            $company = Imobiliaria::query()->lockForUpdate()->find($companyId);
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            $error = $this->validationError($lead, $company);

            if ($error !== null) {
                throw ValidationException::withMessages(['company_id' => $error]);
            }

            if ($lead->activityLogs()->where('action', 'lead_company_link_requested')->where('new_values->status', 'queued')->exists()) {
                throw ValidationException::withMessages(['company_id' => 'Este lead já possui uma solicitação de vínculo aguardando processamento.']);
            }

            return $lead->activityLogs()->create([
                'corretor_id' => $corretor->id,
                'action' => 'lead_company_link_requested',
                'old_values' => ['company_id' => $lead->company_id, 'imobiliaria' => $lead->imobiliaria],
                'new_values' => ['company_id' => $companyId, 'status' => 'queued'],
                'description' => 'Solicitou o vínculo do lead a uma imobiliária.',
                'ip' => $ip === null ? null : mb_substr($ip, 0, 45),
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 2000),
            ]);
        });

        try {
            Bus::dispatch((new LinkLeadToCompanyJob((int) $requestLog->id))->afterCommit());
        } catch (Throwable $exception) {
            $this->fail((int) $requestLog->id);

            throw $exception;
        }

        return $requestLog;
    }

    public function process(int $requestLogId): void
    {
        (new Lead)->getConnection()->transaction(function () use ($requestLogId): void {
            $requestLog = CorretorActivityLog::query()
                ->where('action', 'lead_company_link_requested')
                ->where('model_type', Lead::class)
                ->lockForUpdate()->find($requestLogId);

            if ($requestLog === null) {
                return;
            }

            $values = $requestLog->new_values;
            $company = Imobiliaria::query()->lockForUpdate()->find($values['company_id']);
            $lead = Lead::query()->lockForUpdate()->find($requestLog->model_id);

            if ($values['status'] === 'completed') {
                $sync = $values['company_tag_sync'] ?? null;

                if ($sync !== null && ! in_array($sync['status'], ['synced', 'completed_locally'], true)
                    && $lead !== null && $company !== null && (int) $lead->company_id === (int) $company->id
                    && ! $lead->activityLogs()->where('action', 'lead_company_link_requested')
                        ->where('id', '>', $requestLog->id)->where('new_values->status', 'completed')->exists()) {
                    $this->updateInternalTags($lead, $company, array_filter([
                        $sync['previous']['name'] ?? null,
                        $sync['previous']['company_name'] ?? null,
                    ]));
                    $requestLog->update(['new_values' => [
                        ...$values,
                        'company_tag_sync' => [...$sync, 'status' => 'completed_locally', 'error' => null],
                    ]]);
                }

                return;
            }

            if ($values['status'] !== 'queued') {
                return;
            }

            $corretor = $requestLog->corretor;
            $error = $corretor === null || Gate::forUser($corretor)->denies('link-lead-company')
                ? 'O corretor não possui mais permissão para vincular leads.'
                : $this->validationError($lead, $company);

            if ($error === null && (int) $lead->company_id !== (int) ($requestLog->old_values['company_id'] ?? null)) {
                $error = 'O vínculo deste lead mudou após a solicitação. Solicite a troca novamente.';
            }

            if ($error !== null) {
                $requestLog->update(['new_values' => [...$values, 'status' => 'rejected', 'reason' => $error]]);

                return;
            }

            $previousCompanyId = $lead->company_id;

            $previousNames = $this->companyTagNames($lead->company);
            $pendingTransfers = $lead->activityLogs()->where('action', 'lead_company_link_requested')
                ->where('new_values->status', 'completed')
                ->whereIn('new_values->company_tag_sync->status', ['pending', 'confirming'])->get();

            foreach ($pendingTransfers as $pendingTransfer) {
                $previousNames = [...$previousNames, ...array_filter([
                    data_get($pendingTransfer->new_values, 'company_tag_sync.previous.name'),
                    data_get($pendingTransfer->new_values, 'company_tag_sync.previous.company_name'),
                ])];
                $pendingValues = $pendingTransfer->new_values;
                $pendingTransfer->update(['new_values' => [
                    ...$pendingValues,
                    'company_tag_sync' => [...$pendingValues['company_tag_sync'], 'status' => 'completed_locally', 'error' => null],
                ]]);
            }

            $this->updateInternalTags($lead, $company, $previousNames);

            $lead->company()->associate($company);
            $lead->forceFill(['imobiliaria' => $company->name, 'updated_by_corretor_id' => $corretor->id])->save();
            $lead->insuranceAnalysesBatches()->update(['company_id' => $company->id]);
            $lead->insuranceAnalyses()->update(['company_id' => $company->id]);

            $values = [
                ...$values,
                'status' => 'completed',
                'completed_at' => now()->toIso8601String(),
            ];
            $requestLog->update(['new_values' => $values]);

            DashboardActivityChanged::dispatch('lead', (int) $lead->id, (int) $company->id, 'lead.company.linked');

            if ($previousCompanyId !== null) {
                DashboardActivityChanged::dispatch('lead', (int) $lead->id, (int) $previousCompanyId, 'lead.company.unlinked');
            }
        }, 3);
    }

    private function validationError(?Lead $lead, ?Imobiliaria $company): ?string
    {
        if ($lead === null || ! in_array($lead->origem, Lead::SYSTEM_ORIGINS, true)) {
            return 'O lead não está disponível para vínculo.';
        }

        if ($company === null || ! $company->lead_form_active) {
            return 'A imobiliária selecionada não está disponível ou está inativa.';
        }

        if ((int) $lead->company_id === (int) $company->id) {
            return 'Este lead já está vinculado à imobiliária selecionada.';
        }

        if ($company->leads()->where('email', $lead->email)->exists()) {
            return 'A imobiliária selecionada já possui outro lead com este e-mail.';
        }

        $batches = $lead->insuranceAnalysesBatches()->whereNotNull('company_id');
        $analyses = $lead->insuranceAnalyses()->whereNotNull('company_id');

        if ($lead->company_id !== null) {
            $batches->where('company_id', '!=', $lead->company_id);
            $analyses->where('company_id', '!=', $lead->company_id);
        }

        if ($batches->exists() || $analyses->exists()) {
            return 'As análises deste lead já possuem vínculo. Revise o cadastro antes de continuar.';
        }

        return null;
    }

    /** @return array<int, string> */
    private function companyTagNames(?Imobiliaria $company): array
    {
        if ($company === null) {
            return [];
        }

        $catalogName = $company->leadlovers_tag_id === null ? null : LeadLoversTag::query()
            ->where('leadlovers_tag_id', $company->leadlovers_tag_id)->value('title');

        return array_filter([$company->name, $company->leadlovers_tag_name, $catalogName]);
    }

    /** @param array<int, string> $previousNames */
    private function updateInternalTags(Lead $lead, Imobiliaria $company, array $previousNames): void
    {
        $previousNames = collect($previousNames)->map(fn (string $name): string => Str::lower(Str::squish($name)));
        $tags = collect(explode(',', (string) $lead->tags_originais))
            ->map(fn (string $tag): string => trim($tag))
            ->filter(fn (string $tag): bool => $tag !== '' && ! $previousNames->containsStrict(Str::lower(Str::squish($tag))));
        $tags->push($company->name);
        $lead->update(['tags_originais' => $tags->unique(fn (string $tag): string => Str::lower(Str::squish($tag)))->implode(', ')]);
    }

    public function fail(int $requestLogId): void
    {
        (new Lead)->getConnection()->transaction(function () use ($requestLogId): void {
            $requestLog = CorretorActivityLog::query()
                ->where('action', 'lead_company_link_requested')
                ->where('model_type', Lead::class)
                ->lockForUpdate()->find($requestLogId);

            if ($requestLog === null) {
                return;
            }

            $values = $requestLog->new_values;

            if ($values['status'] === 'queued') {
                $requestLog->update(['new_values' => [...$values, 'status' => 'failed', 'reason' => 'Não foi possível processar a solicitação de vínculo.']]);
            }
        });
    }
}
