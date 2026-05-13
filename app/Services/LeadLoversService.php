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
    private ?string $locatariosequence;
    private ?string $step;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.leadlovers.base_url', 'https://llapi.leadlovers.com/webapi/'), '/') . '/';
        $this->token = config('services.leadlovers.token');
        $this->machineId = config('services.leadlovers.machine');
        $this->sequence = config('services.leadlovers.sequence_1');
        $this->locatariosequence = config('services.leadlovers.sequence_2');
        $this->step = config('services.leadlovers.step');
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
            $tagId = (int) ($data['Tag'] ?? 0);

            if ($tagId <= 0) {
                Log::warning('Tentativa de criar lead na LeadLovers sem tag principal valida', [
                    'email' => $data['Email'] ?? null,
                ]);

                return [
                    'StatusCode' => 422,
                    'Message' => 'Tag principal nao encontrada.',
                ];

            }
             /*
                |--------------------------------------------------------------------------
                | Sequência dinâmica
                |--------------------------------------------------------------------------
                | Se o Job enviar EmailSequenceCode, usamos a sequência enviada.
                | Caso contrário, usamos a sequência padrão do .env.
                */
            $sequenceCode = (int) ($data['EmailSequenceCode'] ?? $this->sequence);
            $sequenceLevelCode = (int) ($data['SequenceLevelCode'] ?? $this->step ?: 1);

            
            if ($sequenceCode <= 0) {
                Log::warning('Tentativa de criar lead na LeadLovers sem sequência válida', [
                    'email' => $data['Email'] ?? null,
                    'tipo' => $data['tipo_solicitante'] ?? null,
                ]);

                return [
                    'StatusCode' => 422,
                    'Message' => 'Sequência da LeadLovers não encontrada.',
                ];
            }


            $payload = [
                'Name' => $data['Name'],
                'Email' => $data['Email'],
                'Phone' => $data['Phone'] ?? '',
                'City' => $data['City'] ?? '',
                'State' => $data['State'] ?? '',
                'Company' => $data['Company'] ?? '',

                'MachineCode' => (int) $this->machineId,
                'EmailSequenceCode' => $sequenceCode,
                'SequenceLevelCode' => $sequenceLevelCode,

                'Tag' => $tagId,
                'Score' => isset($data['Score']) ? (int) $data['Score'] : 0,
            ];

            Log::info('Payload enviado para LeadLovers', [
                'email' => $payload['Email'],
                'machine' => $payload['MachineCode'],
                'sequence' => $payload['EmailSequenceCode'],
                'step' => $payload['SequenceLevelCode'],
                'tag' => $payload['Tag'],
                'company' => $payload['Company'],
            ]);

            $response = Http::asJson()
                ->timeout(30)
                ->retry(2, 1000)
                ->withQueryParameters([
                    'token' => $this->token,
                ])
                ->post($this->baseUrl . 'Lead', $payload);

            if (!$response->successful()) {
                Log::warning('LeadLovers respondeu erro ao criar lead', [
                    'status' => $response->status(),
                    'email' => $data['Email'] ?? null,
                    'body' => $response->body(),
                ]);
            }

            return $response->json() ?? [
                'StatusCode' => $response->status(),
                'Message' => $response->body(),
            ];
            
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
