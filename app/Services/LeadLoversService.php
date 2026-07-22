<?php

namespace App\Services;

use App\Exceptions\LeadLoversRateLimitedException;
use Illuminate\Http\Client\Response;
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
        $this->baseUrl = rtrim((string) config('services.leadlovers.base_url', 'https://llapi.leadlovers.com/webapi/'), '/').'/';
        $this->token = config('services.leadlovers.token');
        $this->machineId = config('services.leadlovers.machine');
        $this->sequence = config('services.leadlovers.sequence_1');
        $this->locatariosequence = config('services.leadlovers.sequence_2');
        $this->step = config('services.leadlovers.step');
    }

    private function isEnabled(): bool
    {
        return (bool) config('services.leadlovers.enabled', false);
    }

    /**
     * Busca todas as tags da conta LeadLovers.
     */
    public function getAllTags(): array
    {
        if (! $this->isEnabled()) {
            Log::notice(
                'Busca de tags ignorada: integração do LeadLovers desativada'
            );

            return [];
        }
        try {
            $response = Http::connectTimeout(10)
                ->timeout(30)
                ->get($this->baseUrl.'Tags', [
                    'token' => $this->token,
                ]);

            if (! $response->successful()) {
                Log::warning('Erro ao buscar tags na LeadLovers', [
                    'status' => $response->status(),
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
        if (! $this->isEnabled()) {
            return [
                'StatusCode' => 503,
                'Message' => 'Integração com a LeadLovers desativada.',
            ];
        }

        try {
            $tagId = (int) ($data['Tag'] ?? 0);

            if ($tagId <= 0) {
                Log::warning('Tentativa de criar lead na LeadLovers sem tag principal valida', [
                    'lead_ref' => $this->emailReference($data['Email'] ?? null),
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
                    'lead_ref' => $this->emailReference($data['Email'] ?? null),
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

            Log::info('Enviando lead para LeadLovers', [
                'lead_ref' => $this->emailReference($payload['Email']),
                'machine' => $payload['MachineCode'],
                'sequence' => $payload['EmailSequenceCode'],
                'step' => $payload['SequenceLevelCode'],
                'tag' => $payload['Tag'],
            ]);

            $response = Http::asJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->withQueryParameters([
                    'token' => $this->token,
                ])
                ->post($this->baseUrl.'Lead', $payload);

            $this->throwIfRateLimited($response);
            $result = $this->responseData($response);

            if (! $response->successful()) {
                Log::warning('LeadLovers respondeu erro ao criar lead', [
                    'status' => $response->status(),
                    'lead_ref' => $this->emailReference($data['Email'] ?? null),
                ]);
            }

            return $result;

        } catch (LeadLoversRateLimitedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erro ao criar lead na LeadLovers', [
                'lead_ref' => $this->emailReference($data['Email'] ?? null),
                'message' => $e->getMessage(),
            ]);

            return [
                'StatusCode' => 500,
                'Message' => 'Falha ao conectar com a LeadLovers.',
            ];
        }
    }

    public function updateLead(array $payload): array
    {

        if (! $this->isEnabled()) {
            return [
                'success' => false,
                'status' => 503,
                'response' => [],
                'raw_body' => null,
                'payload' => [],
                'error' => 'Integração com a LeadLovers desativada',
            ];
        }
        /*
        * Endpoint da documentação:
        * PATCH /webapi/Lead?token={token}
        *
        * O campo Email é obrigatório e normalmente identifica o lead.
        */

        $baseUrl = $this->baseUrl;
        $token = $this->token;

        if (! $baseUrl || ! $token) {
            return [
                'success' => false,
                'status' => null,
                'response' => [],
                'raw_body' => null,
                'payload' => [],
                'error' => 'Configuração da LeadLovers incompleta.',
            ];
        }

        $payload = array_filter($payload, function ($value) {
            return filled($value);
        });

        $endpoint = $this->baseUrl.'Lead';

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->withQueryParameters([
                    'token' => $this->token,
                ])
                ->patch($endpoint, $payload);

            $this->throwIfRateLimited($response);
            $json = $response->json();

            if (! $response->successful()) {
                Log::warning('LeadLovers recusou atualização do lead.', [
                    'status' => $response->status(),
                ]);
            }

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'response' => is_array($json) ? $json : [],
                'raw_body' => null,
                'payload' => [],
                'error' => $response->successful() ? null : 'A LeadLovers recusou a atualização.',
            ];
        } catch (LeadLoversRateLimitedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Falha ao tentar atualizar lead na LeadLovers.', [
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => null,
                'response' => [],
                'raw_body' => null,
                'payload' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Adiciona tag extra ao lead usando ID da tag.
     */
    public function addTagToLeadById(string $email, int|string $tagId, int $score = 0): array
    {

        if (! $this->isEnabled()) {
            return [
                'StatusCode' => 503,
                'Message' => 'Integração com a LeadLovers desativada.',
            ];
        }

        try {
            $response = Http::asJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->withQueryParameters([
                    'token' => $this->token,
                ])
                ->post($this->baseUrl.'Tag', [
                    'Email' => $email,
                    'Tag' => (int) $tagId,
                    'Score' => $score,
                ]);

            $this->throwIfRateLimited($response);
            $result = $this->responseData($response);

            if (! $response->successful()) {
                Log::warning('LeadLovers respondeu erro ao adicionar tag ao lead', [
                    'status' => $response->status(),
                    'lead_ref' => hash(
                        'sha256',
                        mb_strtolower(trim($email))
                    ),
                    'tag_id' => $tagId,
                ]);
            }

            return $result;
        } catch (LeadLoversRateLimitedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erro ao adicionar tag ao lead na LeadLovers', [
                'lead_ref' => hash(
                    'sha256',
                    mb_strtolower(trim($email))
                ),
                'tag_id' => $tagId,
                'message' => $e->getMessage(),
            ]);

            return [
                'StatusCode' => 500,
                'Message' => 'Falha ao adicionar tag ao lead.',
            ];
        }
    }

    private function throwIfRateLimited(Response $response): void
    {
        if ($response->status() === 429) {
            throw LeadLoversRateLimitedException::fromResponse($response);
        }
    }

    private function responseData(Response $response): array
    {
        $data = $response->json();
        $data = is_array($data) ? $data : [];

        if (! $response->successful()) {
            $data['StatusCode'] = $response->status();
        } elseif (! array_key_exists('StatusCode', $data)
            && ! array_key_exists('statusCode', $data)
            && ! array_key_exists('status', $data)) {
            $data['StatusCode'] = $response->status();
        }

        if (! $response->successful()
            && ! isset($data['Message'])
            && ! isset($data['message'])) {
            $data['Message'] = 'A LeadLovers recusou a operação.';
        }

        return $data;
    }

    private function emailReference(?string $email): ?string
    {
        $normalized = mb_strtolower(trim((string) $email));

        return $normalized === '' ? null : hash('sha256', $normalized);
    }
}
