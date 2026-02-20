<?php 

namespace App\Services;

use Illuminate\Support\Facades\Http;

class LeadLoversService
{
    private $baseUrl;
    private $email;
    private $token;
    private $leadId

    public function __construct()
    {
        $this->baseUrl = 'https://llapi.leadlovers.com/webapi/';
        $this->email = config('services.leadlovers.email');
        $this->token = config('services.leadlovers.token');
    }

    // Criar lead
    public function createLead($data)
    {
        return Http::post($this->baseUrl.'Leads', [
            "email" => $this->email,
            "token" => $this->token,
            "lead"  => $data
        ])->json();
    }

    // Inserir lead na máquina e funil
    public function addLeadToMachine($leadId, $machineId, $funnelId, $sequence)
    {
        return Http::post($this->baseUrl.'Leads/addToMachine', [
            "email"     => $this->email,
            "token"     => $this->token,
            "leadId"    => $leadId,
            "machineId" => $machineId,
            "funnelId"  => $funnelId,
            "sequence"  => $sequence,
        ])->json();
    }
}









?>