<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LeadLoversService
{
    private string $baseUrl;
    private ?string $token;
    private ?string $machineId;
    private ?string $sequence;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.leadlovers.base_url', 'https://llapi.leadlovers.com/webapi/'), '/') . '/';
        $this->token = config('services.leadlovers.token');
        $this->machineId = config('services.leadlovers.machine');
        $this->sequence = config('services.leadlovers.sequence');
    }

    /**
     * Busca todas as tags da conta LeadLovers.
     */
    public function getAllTags(): array
    {
        try {
            $response = Http::timeout(30)
                ->retry(2, 1000)
                ->get($this->baseUrl . 'Tags', [
                    'token' => $this->token,
                ]);

            if (!$response->successful()) {
                Log::warning('Erro ao buscar tags na LeadLovers', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::error('Falha ao buscar tags na LeadLovers', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Insere o lead na máquina da LeadLovers.
     * O campo Tag precisa receber o ID da tag principal.
     */
    public function createLead(array $data): array
    {
        try {
            $response = Http::asJson()
                ->timeout(30)
                ->retry(2, 1000)
                ->withQueryParameters([
                    'token' => $this->token,
                ])
                ->post($this->baseUrl . 'Lead', [
                    'Name' => $data['Name'],
                    'Email' => $data['Email'],
                    'Phone' => $data['Phone'] ?? '',
                    'City' => $data['City'] ?? '',
                    'State' => $data['State'] ?? '',

                    'MachineCode' => (int) $this->machineId,
                    'EmailSequenceCode' => (int) $this->sequence,
                    'SequenceLevelCode' => 1,

                    // Tag principal do lead.
                    'Tag' => isset($data['Tag']) ? (int) $data['Tag'] : 0,

                    // Pontuação opcional.
                    'Score' => isset($data['Score']) ? (int) $data['Score'] : 0,
                ]);

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::error('Erro ao criar lead na LeadLovers', [
                'email' => $data['Email'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return [
                'StatusCode' => 500,
                'Message' => 'Falha ao conectar com a LeadLovers.',
            ];
        }
    }

    /**
     * Adiciona tag extra ao lead usando ID da tag.
     */
    public function addTagToLeadById(string $email, int|string $tagId, int $score = 0): array
    {
        try {
            $response = Http::asJson()
                ->timeout(30)
                ->retry(2, 1000)
                ->withQueryParameters([
                    'token' => $this->token,
                ])
                ->post($this->baseUrl . 'Tag', [
                    'Email' => $email,
                    'Tag' => (int) $tagId,
                    'Score' => $score,
                ]);

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::error('Erro ao adicionar tag ao lead na LeadLovers', [
                'email' => $email,
                'tag_id' => $tagId,
                'message' => $e->getMessage(),
            ]);

            return [
                'StatusCode' => 500,
                'Message' => 'Falha ao adicionar tag ao lead.',
            ];
        }
    }
}