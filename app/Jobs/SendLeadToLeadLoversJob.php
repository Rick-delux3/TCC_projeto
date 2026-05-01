<?php

namespace App\Jobs;

use App\Models\Lead;
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
    )
    {}

   
    public function handle(LeadLoversService $leadLovers): void
    {
        $lead = Lead::with('company')->findOrFail($this->leadId);
        $company = $lead->company;


        if (!$company) throw new \Exception('Empresa do Lead não encontrada');


        $response = $leadLovers->createLead([
            'Name' => $lead->nome,
            'Email' => $lead->email,
            'Phone' => $lead->tel ?? '',
            'City' => $lead->cidade_imovel ?? '',
            'State' => $lead->estado ?? '',
        ]);

         if (!is_array($response) || ($response['StatusCode'] ?? null) !== 200) {
            Log::warning('Lead não enviado para LeadLovers', [
                'lead_id' => $lead->id,
                'email' => $lead->email,
                'response' => $response,
            ]);

            $lead->update([
                'leadlovers_status' => 'failed',
                'leadlovers_response' => json_encode($response),
            ]);

            return;
        }

        $leadLovers->addTagToLead($lead->email, $company->name);

        $lead->update([
            'leadlovers_status' => 'sent',
            'leadlovers_response' => json_encode($response),
            'sent_to_leadlovers_at' => now(),
        ]);
    
    }
}
