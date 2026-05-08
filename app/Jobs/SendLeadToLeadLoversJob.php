<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Services\LeadLoversService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendLeadToLeadLoversJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public int $leadId
    ) {}

    public function handle(LeadLoversService $leadLovers): void
    {
        $lead = Lead::with('company')->findOrFail($this->leadId);

        /**
         * Antes de criar o lead, o sistema precisa descobrir
         * qual é a tag principal dele.
         */
        $mainTagId = $this->mainTagIdForLead($lead);

        if (!$mainTagId) {
            Log::warning('Tag principal não encontrada para o lead', [
                'lead_id' => $lead->id,
                'tipo_solicitante' => $lead->tipo_solicitante,
                'company_id' => $lead->company_id,
            ]);

            $lead->update([
                'leadlovers_status' => 'tag_failed',
                'leadlovers_response' => [
                    'message' => 'Tag principal não encontrada.',
                    'tipo_solicitante' => $lead->tipo_solicitante,
                ],
            ]);

            return;
        }

        /**
         * Cria o lead na máquina da LeadLovers já com a tag principal.
         */
        $response = $leadLovers->createLead([
            'Name' => $lead->nome,
            'Email' => $lead->email,
            'Phone' => $lead->tel ?? '',
            'City' => $lead->cidade_imovel ?? '',
            'State' => $lead->estado ?? '',
            'Company' => $lead->imobiliaria ?? $lead->nome_imobiliaria_informada ?? '',
            'Tag' => $mainTagId,
            'Score' => 0,
        ]);

        if (!is_array($response) || ($response['StatusCode'] ?? null) !== 200) {
            Log::warning('Lead não enviado para LeadLovers', [
                'lead_id' => $lead->id,
                'email' => $lead->email,
                'response' => $response,
            ]);

            $lead->update([
                'leadlovers_status' => 'failed',
                'leadlovers_response' => $response,
            ]);

            return;
        }

        /**
         * Opcional: adiciona tags extras além da tag principal.
         */
        /**
         * Marca o envio como concluído.
         */
        $lead->update([
            'leadlovers_status' => 'sent',
            'leadlovers_response' => $response,
            'sent_to_leadlovers_at' => now(),
        ]);
    }

    /**
     * Descobre qual tag principal deve ser enviada no campo "Tag"
     * do endpoint Insert New Lead.
     */
    private function mainTagIdForLead(Lead $lead): ?int
    {
        /**
         * Caso seja imobiliária cadastrada:
         * a tag principal é a própria tag da imobiliária.
         */
        if ($lead->tipo_solicitante === 'imobiliaria_cadastrada') {
            return $this->companyTagId($lead);
        }

        /**
         * Para os demais perfis, convertemos o tipo interno
         * para a key da tag local.
         */
        $tagKey = match ($lead->tipo_solicitante) {
            'locatario' => 'locatario',
            'imobiliaria_nao_cadastrada' => 'imobiliaria_morna',
            'locador' => 'proprietario',
            default => null,
        };

        if (!$tagKey) {
            return null;
        }

        return LeadLoversTag::where('key', $tagKey)
            ->where('active', true)
            ->value('leadlovers_tag_id');
    }

    /**
     * Descobre o ID da tag da imobiliária cadastrada.
     */
    private function companyTagId(Lead $lead): ?int
    {
        if (!$lead->company) {
            return null;
        }

        /**
         * Melhor opção:
         * usar o ID salvo diretamente na tabela companies.
         */
        if ($lead->company->leadlovers_tag_id) {
            return (int) $lead->company->leadlovers_tag_id;
        }

        /**
         * Fallback:
         * buscar pelo título da tag igual ao nome da imobiliária.
         */
        return LeadLoversTag::where('title', $lead->company->name)
            ->where('active', true)
            ->value('leadlovers_tag_id');
    }

    /**
     * Aplica tags extras, se você quiser usar além da tag principal.
     */
    
}