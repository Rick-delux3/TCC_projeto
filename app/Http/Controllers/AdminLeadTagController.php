<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLeadResultTagRequest;
use App\Jobs\ApplyManualLeadResultTagJob;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Services\LeadLoversTagOperationCoordinator;
use App\Support\ManualLeadResultTags;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class AdminLeadTagController extends Controller
{
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
            $lead->leadlovers_status !== 'sent'
            || $lead->sent_to_leadlovers_at === null
        ) {
            return back()->withErrors([
                'result' => 'Este lead ainda não foi enviado para a LeadLovers.',
            ])
                ->withInput();
        }

        if ((int) $lead->leadlovers_lead_id <= 0) {
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
        DB::transaction(function () use (
            $request,
            $lead,
            $corretor,
            $result,
            $resultLabel,
            $selectedTagKey,
            $selectedTag
        ): void {
            $lockedLead = Lead::query()
                ->lockForUpdate()
                ->findOrFail($lead->id);

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

            ApplyManualLeadResultTagJob::dispatch(
                $lockedLead->id,
                $result,
                $corretor->id,
                $request->ip(),
                $request->userAgent(),
                $requestLog->id,
                version: $syncState->version,
            )->afterCommit();
        });

        return back()->with(
            'success',
            sprintf(
                'A alteração para "%s" foi solicitada e será processada em segundo plano.',
                $resultLabel
            )
        );
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
