<?php

namespace App\Services;

use App\Events\DashboardActivityChanged;
use App\Exceptions\LeadLoversApiException;
use App\Jobs\LinkLeadToCompanyJob;
use App\Jobs\UpdateLeadOnLeadLoversJob;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LeadCompanyLinkService
{
    public function __construct(
        private readonly LeadLoversApiClient $leadLovers,
        private readonly LeadLoversLeadResolver $leadResolver,
    ) {}

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

            if ($lead->activityLogs()->where('action', 'lead_company_link_requested')
                ->where('new_values->status', 'completed')
                ->whereIn('new_values->company_tag_sync->status', ['pending', 'confirming'])->exists()) {
                throw ValidationException::withMessages(['company_id' => 'A troca anterior ainda possui tags pendentes de sincronização. Aguarde a conclusão ou reprocesse a solicitação antes de trocar novamente.']);
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

    public function process(int $requestLogId): ?UpdateLeadOnLeadLoversJob
    {
        return (new Lead)->getConnection()->transaction(function () use ($requestLogId): ?UpdateLeadOnLeadLoversJob {
            $requestLog = CorretorActivityLog::query()
                ->where('action', 'lead_company_link_requested')
                ->where('model_type', Lead::class)
                ->lockForUpdate()->find($requestLogId);

            if ($requestLog === null) {
                return null;
            }

            $values = $requestLog->new_values;
            $company = Imobiliaria::query()->lockForUpdate()->find($values['company_id']);
            $lead = Lead::query()->lockForUpdate()->find($requestLog->model_id);

            if ($values['status'] === 'completed') {
                if (isset($values['company_tag_sync'])
                    && $lead !== null && config('services.leadlovers.enabled', false)
                    && (int) $lead->company_id === (int) $values['company_id']
                    && in_array($lead->leadlovers_status, ['sent', 'send'], true) && $lead->sent_to_leadlovers_at !== null
                    && (int) $lead->leadlovers_update_version === ($values['sync_version'] ?? null)
                    && in_array($lead->leadlovers_update_status, ['failed', 'disabled', 'waiting_initial_send'], true)) {
                    $lead->update(['leadlovers_update_status' => 'pending', 'leadlovers_update_error' => null]);
                }

                return $this->pendingSyncJob($lead, $values);
            }

            if ($values['status'] !== 'queued') {
                return null;
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

                return null;
            }

            $previousCompanyId = $lead->company_id;

            if ($previousCompanyId !== null) {
                $values['company_tag_sync'] = [
                    'status' => 'pending',
                    'previous' => $this->companyTag($lead->company),
                    'selected' => $this->companyTag($company),
                ];
            }

            $lead->company()->associate($company);
            $lead->forceFill(['imobiliaria' => $company->name, 'updated_by_corretor_id' => $corretor->id])->save();
            $lead->insuranceAnalysesBatches()->update(['company_id' => $company->id]);
            $lead->insuranceAnalyses()->update(['company_id' => $company->id]);

            $this->prepareCompanySync($lead);

            $values = [
                ...$values,
                'status' => 'completed',
                'completed_at' => now()->toIso8601String(),
                'sync_version' => (int) $lead->leadlovers_update_version,
            ];
            $requestLog->update(['new_values' => $values]);

            DashboardActivityChanged::dispatch('lead', (int) $lead->id, (int) $company->id, 'lead.company.linked');

            if ($previousCompanyId !== null) {
                DashboardActivityChanged::dispatch('lead', (int) $lead->id, (int) $previousCompanyId, 'lead.company.unlinked');
            }

            return $this->pendingSyncJob($lead, $values);
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

        if ($lead->company_id !== null && ($this->companyTag($lead->company) === null || $this->companyTag($company) === null)) {
            return 'Revise as tags da imobiliária atual e da nova imobiliária antes de realizar a troca.';
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

    /** @return array{id: int, name: string, company_name: string}|null */
    private function companyTag(?Imobiliaria $company): ?array
    {
        if ($company === null) {
            return null;
        }

        $tagId = $this->leadResolver->positiveInteger($company->leadlovers_tag_id);
        $tag = $tagId === null
            ? LeadLoversTag::query()->where('title', $company->name)->where('active', true)->first()
            : LeadLoversTag::query()->where('leadlovers_tag_id', $tagId)->first();
        $tagId ??= $this->leadResolver->positiveInteger($tag?->leadlovers_tag_id);

        if ($tagId === null) {
            return null;
        }

        return [
            'id' => $tagId,
            'name' => $tag?->title ?: ($company->leadlovers_tag_name ?: $company->name),
            'company_name' => $company->name,
        ];
    }

    /** Return the delay before checking the asynchronous tag operation again. */
    public function synchronizeCompanyTags(int $requestLogId): ?int
    {
        $requestLog = CorretorActivityLog::query()
            ->where('action', 'lead_company_link_requested')->where('model_type', Lead::class)
            ->find($requestLogId);
        $values = $requestLog?->new_values ?? [];
        $sync = $values['company_tag_sync'] ?? null;

        if (($values['status'] ?? null) !== 'completed' || $sync === null || $sync['status'] === 'synced') {
            return null;
        }

        $lead = Lead::query()->find($requestLog->model_id);

        if ($lead === null || (int) $lead->company_id !== (int) $values['company_id']) {
            return null;
        }

        if (! config('services.leadlovers.enabled', false)
            || ! in_array($lead->leadlovers_status, ['sent', 'send'], true)
            || $lead->sent_to_leadlovers_at === null) {
            return 60;
        }

        $notBefore = $lead->sent_to_leadlovers_at->copy()->addSeconds(max(0, (int) config('services.leadlovers.initial_update_delay_seconds', 60)));

        if ($notBefore->isFuture()) {
            return max(1, (int) ceil(now()->diffInSeconds($notBefore)));
        }

        $remoteLeadId = $this->leadResolver->positiveInteger($sync['remote_lead_id'] ?? $lead->leadlovers_lead_id);

        if ($remoteLeadId === null) {
            $match = $this->leadResolver->uniqueExactMatch(
                $this->leadLovers->searchLeads($this->leadResolver->searchPayload($lead->email)), $lead->email,
            );

            if ($match['outcome'] !== 'matched') {
                throw new \RuntimeException('Não foi possível identificar com segurança o lead na LeadLovers para trocar a tag da imobiliária.');
            }

            $remoteLeadId = $match['lead_id'];
        }

        $remoteTags = $this->leadLovers->listLeadTags($remoteLeadId);
        $remoteIds = array_column($remoteTags, 'id');
        $previousId = $sync['previous']['id'];
        $selectedId = $sync['selected']['id'];
        $selectedPresent = in_array($selectedId, $remoteIds, true);
        $previousPresent = $previousId !== $selectedId && in_array($previousId, $remoteIds, true);

        if ($selectedPresent && ! $previousPresent) {
            $this->completeCompanyTags($requestLogId);

            return null;
        }

        $delay = max(1, (int) config('services.leadlovers.tag_confirmation_delay_seconds', 15));

        if ($sync['status'] === 'confirming') {
            $sync['confirmation_checks'] = ($sync['confirmation_checks'] ?? 0) + 1;
            $requestLog->update(['new_values' => [...$values, 'company_tag_sync' => $sync]]);
            $canRetryUncertain = ! isset($sync['bulk_action'])
                && $sync['confirmation_checks'] >= max(1, (int) config('services.leadlovers.tag_uncertain_retry_checks', 2))
                && ($sync['post_attempts'] ?? 1) < max(1, (int) config('services.leadlovers.tag_max_post_attempts', 2))
                && isset($sync['posted_at'])
                && Carbon::parse($sync['posted_at'])->addSeconds(max(1, (int) config('services.leadlovers.tag_posting_stale_seconds', 60)))->isPast();

            if (! $canRetryUncertain) {
                return $delay;
            }
        }

        $sync = [
            ...$sync, 'status' => 'confirming', 'remote_lead_id' => $remoteLeadId, 'error' => null,
            'post_attempts' => ($sync['post_attempts'] ?? 0) + 1,
            'confirmation_checks' => 0, 'posted_at' => now()->toIso8601String(),
        ];
        $requestLog->update(['new_values' => [...$values, 'company_tag_sync' => $sync]]);

        try {
            $action = $this->leadLovers->mutateLeadTags([
                'applyTags' => $selectedPresent ? [] : [$selectedId],
                'removeTags' => $previousPresent ? [$previousId] : [],
                'leadsIds' => [$remoteLeadId],
            ]);
        } catch (LeadLoversApiException $exception) {
            if ($exception->isTransient && $exception->errorCode !== 'LOCAL_RATE_LIMIT' && $exception->statusCode !== 429) {
                return $delay;
            }

            $requestLog->update(['new_values' => [...$values, 'company_tag_sync' => [...$sync, 'status' => 'pending']]]);

            throw $exception;
        }

        $requestLog->update(['new_values' => [...$values, 'company_tag_sync' => [...$sync, 'bulk_action' => $action]]]);

        return $delay;
    }

    private function completeCompanyTags(int $requestLogId): void
    {
        (new Lead)->getConnection()->transaction(function () use ($requestLogId): void {
            $requestLog = CorretorActivityLog::query()->lockForUpdate()->findOrFail($requestLogId);
            $values = $requestLog->new_values;
            $sync = $values['company_tag_sync'];
            $lead = Lead::query()->lockForUpdate()->find($requestLog->model_id);

            if ($lead === null || (int) $lead->company_id !== (int) $values['company_id'] || $sync['status'] === 'synced') {
                return;
            }

            $previousNames = collect([$sync['previous']['name'], $sync['previous']['company_name']])
                ->map(fn (string $name): string => Str::lower(Str::squish($name)));
            $tags = collect(explode(',', (string) $lead->tags_originais))
                ->map(fn (string $tag): string => trim($tag))
                ->filter(fn (string $tag): bool => $tag !== '' && ! $previousNames->containsStrict(Str::lower(Str::squish($tag))));
            $tags->push($sync['selected']['name']);
            $lead->update(['tags_originais' => $tags->unique(fn (string $tag): string => Str::lower(Str::squish($tag)))->implode(', ')]);
            $requestLog->update(['new_values' => [
                ...$values,
                'company_tag_sync' => [...$sync, 'status' => 'synced', 'error' => null, 'confirmed_at' => now()->toIso8601String()],
            ]]);

            DashboardActivityChanged::dispatch('lead', (int) $lead->id, (int) $lead->company_id, 'lead.company.tags.synced');
        }, 3);
    }

    private function prepareCompanySync(Lead $lead): void
    {
        $pendingFields = in_array($lead->leadlovers_update_status, ['pending', 'processing', 'failed', 'waiting_initial_send', 'disabled'], true)
            ? data_get($lead->leadlovers_update_response, 'requested_fields', [])
            : [];
        $pendingFields = is_array($pendingFields) ? array_filter($pendingFields, 'is_string') : [];
        $fields = array_values(array_unique([...$pendingFields, 'company']));

        $status = match (true) {
            ! config('services.leadlovers.enabled', false) => 'disabled',
            in_array($lead->leadlovers_status, ['sent', 'send'], true) && $lead->sent_to_leadlovers_at !== null => 'pending',
            $lead->leadlovers_status === 'failed' => 'failed',
            default => 'waiting_initial_send',
        };

        $lead->forceFill([
            'leadlovers_update_status' => $status,
            'leadlovers_update_version' => (int) $lead->leadlovers_update_version + 1,
            'leadlovers_update_response' => ['requested_fields' => $fields],
            'leadlovers_update_requested_at' => now(),
            'leadlovers_update_error' => match ($status) {
                'disabled' => 'Integração com a LeadLovers desativada.',
                'failed' => 'O envio inicial falhou e precisa ser conciliado antes da sincronização do vínculo.',
                default => null,
            },
        ])->save();
    }

    /** @param array{company_id: int, status: string, completed_at?: string, sync_version?: int} $values */
    private function pendingSyncJob(?Lead $lead, array $values): ?UpdateLeadOnLeadLoversJob
    {
        if ($lead === null
            || (int) $lead->company_id !== (int) $values['company_id']
            || $lead->leadlovers_update_status !== 'pending'
            || (int) $lead->leadlovers_update_version !== ($values['sync_version'] ?? null)) {
            return null;
        }

        $job = (new UpdateLeadOnLeadLoversJob(
            (int) $lead->id,
            (int) $lead->leadlovers_update_version,
            $lead->leadlovers_update_response['requested_fields'],
        ))->onQueue('leadlovers')->afterCommit();

        $notBefore = $lead->sent_to_leadlovers_at?->copy()->addSeconds(max(0, (int) config('services.leadlovers.initial_update_delay_seconds', 60)));

        if ($notBefore?->isFuture()) {
            $job->delay($notBefore);
        }

        return $job;
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
            } elseif ($values['status'] === 'completed') {
                $tagsPending = isset($values['company_tag_sync']) && $values['company_tag_sync']['status'] !== 'synced';
                $error = $tagsPending
                    ? 'O vínculo foi salvo, mas a troca da tag da imobiliária ainda não foi confirmada na LeadLovers. Reprocesse a solicitação.'
                    : 'O vínculo foi salvo, mas a sincronização não pôde ser colocada na fila.';

                if ($tagsPending) {
                    $requestLog->update(['new_values' => [
                        ...$values,
                        'company_tag_sync' => [...$values['company_tag_sync'], 'error' => $error],
                    ]]);
                }

                Lead::query()->whereKey($requestLog->model_id)
                    ->where('leadlovers_update_version', $values['sync_version'])
                    ->where('leadlovers_update_status', 'pending')
                    ->update([
                        'leadlovers_update_status' => 'failed',
                        'leadlovers_update_error' => $error,
                    ]);
            }
        });
    }
}
