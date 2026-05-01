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

    public function createLead(array $data): array
    {
        return $this->post('Lead', [
            'Name' => $data['Name'] ?? null,
            'Email' => $data['Email'] ?? null,
            'Phone' => $data['Phone'] ?? '',
            'City' => $data['City'] ?? '',
            'State' => $data['State'] ?? '',
            'MachineCode' => $this->machineId,
            'EmailSequenceCode' => $this->sequence,
            'SequenceLevelCode' => 1,
        ]);
    }

    public function getAllTags(): array
    {
        return $this->get('Tags');
    }

    public function createTag(string $title): array
    {
        return $this->post('Tags', [
            'Title' => $title,
        ]);
    }

    public function addTagToLead(string $email, string $tag): array
    {
        return $this->post('Tag', [
            'Email' => $email,
            'Tag' => $tag,
        ]);
    }

    public function getLeadByEmail(string $email): array
    {
        return $this->get('Lead', [
            'email' => $email,
        ]);
    }

    public function getLeadsPage(int $page = 1): array
    {
        return $this->get('Leads', [
            'page' => $page,
        ]);
    }

    public function getOfficialCompanyTags(): array
    {
        $response = $this->getAllTags();

        $tags = $response['Tags'] ?? [];

        if (!is_array($tags)) {
            return [];
        }

        return collect($tags)
            ->pluck('Title')
            ->filter(fn ($title) => is_string($title))
            ->filter(fn ($title) => str_starts_with($title, 'Imobiliária'))
            ->sort()
            ->values()
            ->all();
    }

    private function get(string $endpoint, array $query = []): array
    {
        try {
            $response = Http::timeout(20)
                ->retry(2, 1000)
                ->get($this->baseUrl . $endpoint, array_merge([
                    'token' => $this->token,
                ], $query));

            if (!$response->successful()) {
                Log::warning('Erro GET na API LeadLovers', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'status' => $response->status(),
                    'message' => 'Erro na comunicação com a LeadLovers.',
                    'data' => $response->json(),
                ];
            }

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::error('Falha GET na API LeadLovers', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => null,
                'message' => 'Falha ao conectar com a LeadLovers.',
                'data' => null,
            ];
        }
    }

    private function post(string $endpoint, array $payload = []): array
    {
        try {
            $response = Http::asJson()
                ->timeout(20)
                ->retry(2, 1000)
                ->withQueryParameters([
                    'token' => $this->token,
                ])
                ->post($this->baseUrl . $endpoint, $payload);

            if (!$response->successful()) {
                Log::warning('Erro POST na API LeadLovers', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'status' => $response->status(),
                    'message' => 'Erro na comunicação com a LeadLovers.',
                    'data' => $response->json(),
                ];
            }

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::error('Falha POST na API LeadLovers', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => null,
                'message' => 'Falha ao conectar com a LeadLovers.',
                'data' => null,
            ];
        }
    }
}