<?php 

namespace App\Services;

use Illuminate\Support\Facades\Http;

class LeadLoversService
{
    private $baseUrl;
    private $token;
    private $machineId;
    private $sequence;
    private $page;
    private $startdate;
    private $enddate;

    public function __construct()
    {
        $this->baseUrl = 'https://llapi.leadlovers.com/webapi/';
        $this->token = config('services.leadlovers.token');
        $this->machineId = config('services.leadlovers.machine');
        $this->sequence = config('services.leadlovers.sequence');
    }

    // Criar lead
    public function createLead(array $data)
    {
        return Http::withHeaders([
            'Content-Type' => 'application/json'
        ])->
        post($this->baseUrl . 'Lead?token=' . $this->token, [

            "Name" => $data["Name"],
            "Email" => $data["Email"],
            "Phone" => $data["Phone"],
            "City" => $data["City"],
            "State" => $data["State"],
            
            "MachineCode" => $this->machineId,
            "EmailSequenceCode" => $this->sequence,
            "SequenceLevelCode" => 1 // primeiro passo
        ])->json();

        
    }

    
    //Coleção de Tags
    public function getAllTags(){
        return Http::get($this->baseUrl . 'Tag', [
            'token' => $this->token
        ])->json();
    }

    //Criar tag
    public function createTag(array $data){
        return Http::withHeaders([
            'Content-Type' => 'application/json'
        ])->
        post($this->baseUrl . 'Tags?token=' . $this->token, [
            'Title' => $data['Title']
        ])->json();
    }
    
    // Adicionar tag

    public function addTag(array $data){
        return Http::withHeaders([
            'Content-Type' => 'application/json'
        ])-> 
        post($this->baseUrl . 'Tag?token=' . $this->token, [
            "Email" => $data['Email'],
            'Tag' => $data['Tag']
        ])->json();
    }


}









?>