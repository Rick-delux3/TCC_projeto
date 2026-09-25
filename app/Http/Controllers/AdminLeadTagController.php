<?php

namespace App\Http\Controllers;

use App\Events\DashboardActivityChanged;
use App\Http\Requests\UpdateLeadResultTagRequest;
use App\Jobs\ApplyManualLeadResultTagJob;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Services\CorretorDashboardLeadQuery;
use App\Services\LeadLoversResultTagService;
use App\Services\LeadLoversTagOperationCoordinator;
use App\Support\ManualLeadResultTags;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class AdminLeadTagController extends Controller
{
    public function __construct(private CorretorDashboardLeadQuery $dashboardLeadQuery) {}

    public function update(
        UpdateLeadResultTagRequest $request,
        Lead $lead
    ): RedirectResponse {
        $corretor = $request->user('admin');

        abort_unless(
            $corretor instanceof Corretor,
            403
        );

        if (! config('services.leadlovers.enabled', false)) {
            return back()
                ->withErrors([
                    'result' => 'A integração com a LeadLovers está desativada.',
                ])
                ->withInput();
        }

        if (
            ! $lead->awaitingLeadLoversOutageRecovery()
            && ($lead->leadlovers_status !== 'sent'
            || $lead->sent_to_leadlovers_at === null)
        ) {
            return back()->withErrors([
                'result' => 'Este lead ainda não foi enviado para a LeadLovers.',
            ])
                ->withInput();
        }

        if (! $lead->awaitingLeadLoversOutageRecovery() && (int) $lead->leadlovers_lead_id <= 0) {
            return back()->withErrors([
                'result' => 'O lead não possui um ID remoto válido da LeadLovers.',
            ])->withInput();
        }

        $validated = $request->validated();

        $result = (string) $validated['result'];

        $resultLabel = ManualLeadResultTags::label($result);

        $selectedTagKey = ManualLeadResultTags::leadloversKey($result);

        if (
            $resultLabel === null
            || $selectedTagKey === null
        ) {
            return back()
                ->withErrors([
                    'result' => 'Não foi possível mapear o resultado selecionado.',
                ])
                ->withInput();
        }

        if (
            $this->dashboardLeadQuery->withResult(Lead::query())->findOrFail($lead->id)->dashboard_result
            === $result
        ) {
            return $this->repeatedResultResponse($resultLabel);
        }

        /*
         * Confirma se as cinco tags finais estão cadastradas.
         */
        $expectedTagKeys = collect(
            ManualLeadResultTags::leadLoversKeys()
        );

        $resultTagCatalog = LeadLoversTag::query()
            ->whereIn('key', $expectedTagKeys->all())
            ->get()
            ->keyBy('key');

        $missingTagKeys = $expectedTagKeys->diff(
            $resultTagCatalog->keys()
        );

        if ($missingTagKeys->isNotEmpty()) {
            return back()
                ->withErrors([
                    'result' => 'O catálogo de tags finais está incompleto. '
                        .'Atualize as tags antes de tentar novamente.',
                ])
                ->withInput();
        }

        $invalidTag = $resultTagCatalog->first(
            fn (LeadLoversTag $tag): bool => (int) $tag->leadlovers_tag_id <= 0
        );

        if ($invalidTag instanceof LeadLoversTag) {
            return back()
                ->withErrors([
                    'result' => 'O catálogo possui uma tag com ID LeadLovers inválido.',
                ])
                ->withInput();
        }

        $selectedTag = $resultTagCatalog->get(
            $selectedTagKey
        );

        if (
            ! $selectedTag instanceof LeadLoversTag
            || ! $selectedTag->active
        ) {
            return back()
                ->withErrors([
                    'result' => 'A tag correspondente ao resultado está desativada.',
                ])
                ->withInput();
        }

        /*
         * A solicitação e o agendamento do Job pertencem
         * à mesma transação lógica.
         */
        $resultChangeQueued = DB::transaction(function () use (
            $request,
            $lead,
            $corretor,
            $result,
            $resultLabel,
            $selectedTagKey,
            $resultTagCatalog,
            $selectedTag
        ): bool {
            $lockedLead = $this->dashboardLeadQuery->withResult(Lead::query())
                ->lockForUpdate()
                ->findOrFail($lead->id);

            $deferRemoteTag = $lockedLead->awaitingLeadLoversOutageRecovery();
            if (! $deferRemoteTag && ($lockedLead->leadlovers_status !== 'sent'
                || $lockedLead->sent_to_leadlovers_at === null
                || (int) $lockedLead->leadlovers_lead_id <= 0)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'result' => 'Este lead ainda não foi enviado para a LeadLovers.',
                ]);
            }

            if (
                $lockedLead->dashboard_result === $result
            ) {
                return false;
            }

            $requestLog = CorretorActivityLog::create([
                'corretor_id' => $corretor->id,
                'action' => 'lead_tag_update_requested',
                'model_type' => Lead::class,
                'model_id' => $lockedLead->id,

                'old_values' => [
                    'tags_originais' => $lockedLead->tags_originais,

                    'updated_by_corretor_id' => $lockedLead->updated_by_corretor_id,
                ],

                'new_values' => [
                    'requested_result' => $result,
                    'requested_label' => $resultLabel,
                    'leadlovers_tag_key' => $selectedTagKey,
                    'leadlovers_tag_id' => (int) $selectedTag->leadlovers_tag_id,
                ],

                'description' => sprintf(
                    'Solicitou a alteração do resultado comercial do lead para "%s".',
                    $resultLabel
                ),

                'ip' => $this->normalizedIp(
                    $request->ip()
                ),

                'user_agent' => $this->normalizedUserAgent(
                    $request->userAgent()
                ),
            ]);

            $syncState = app(LeadLoversTagOperationCoordinator::class)
                ->registerManualDesired(
                    leadId: $lockedLead->id,
                    tagKey: $selectedTagKey,
                    result: $result,
                    requestLogId: $requestLog->id,
                    corretorId: $corretor->id,
                );

            if ($deferRemoteTag) {
                $lockedLead->forceFill([
                    'tags_originais' => app(LeadLoversResultTagService::class)->replaceLocalFinalTag(
                        currentTagString: $lockedLead->tags_originais,
                        catalog: $resultTagCatalog,
                        selectedTag: $selectedTag,
                    ),
                    'updated_by_corretor_id' => $corretor->id,
                ])->save();
            } else {
                ApplyManualLeadResultTagJob::dispatch(
                    $lockedLead->id,
                    $result,
                    $corretor->id,
                    $request->ip(),
                    $request->userAgent(),
                    $requestLog->id,
                    version: $syncState->version,
                )->afterCommit();
            }

            DashboardActivityChanged::dispatch(
                resource: 'lead',
                resourceId: (int) $lockedLead->id,
                companyId: $lockedLead->company_id !== null
                    ? (int) $lockedLead->company_id
                    : null,
                change: 'lead.tags.processing',
            );

            return true;
        });

        if (! $resultChangeQueued) {
            return $this->repeatedResultResponse($resultLabel);
        }

        return back()->with(
            'success',
            sprintf(
                'A alteração para "%s" foi solicitada e será processada em segundo plano.',
                $resultLabel
            )
        );
    }

    private function repeatedResultResponse(
        string $resultLabel
    ): RedirectResponse {
        return back()
            ->withErrors([
                'result' => sprintf(
                    'O lead já possui o status "%s". Selecione outro status.',
                    $resultLabel
                ),
            ])
            ->withInput();
    }

    private function normalizedIp(
        ?string $ip
    ): ?string {

        if (blank($ip)) {
            return null;
        }

        return mb_substr(
            trim($ip),
            0,
            45
        );
    }

    private function normalizedUserAgent(
        ?string $userAgent
    ): ?string {

        if (blank($userAgent)) {
            return null;
        }

        return mb_substr(
            trim($userAgent),
            0,
            2000
        );
    }
}
