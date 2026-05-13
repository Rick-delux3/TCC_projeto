<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PottencialService
{
    private string $baseUrl;
    private ?string $clientId;
    private ?string $clientSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.pottencial.base_url'), '/');
        $this->clientId = config('services.pottencial.client_id');
        $this->clientSecret = config('services.pottencial.client_secret');
    }

    /**
     * Gera ou recupera do cache o access_token da Pottencial.
     */
    public function getAccessToken(): ?string
    {
        return Cache::remember('pottencial_access_token', now()->addMinutes(55), function () {
            if (!$this->clientId || !$this->clientSecret) {
                Log::error('Credenciais da Pottencial não configuradas.');

                return null;
            }

            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->timeout(30)
                ->post($this->baseUrl . '/oauth/v3/access-token');

            if (!$response->successful()) {
                Log::warning('Erro ao gerar access_token da Pottencial', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();

            Log::info('Access token da Pottencial gerado com sucesso', [
                'expires_in' => $data['expires_in'] ?? null,
                'token_type' => $data['token_type'] ?? null,
            ]);

            return $data['access_token'] ?? null;
        });
    }

    private function authHeaders(): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            throw new \RuntimeException('Não foi possível autenticar na Pottencial.');
        }

        return [
            'client_id' => $this->clientId,
            'access_token' => $token,
        ];
    }

    public function testAuthentication(): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return [
                'success' => false,
                'message' => 'Não foi possível gerar o access_token.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Access token gerado com sucesso.',
            'token_preview' => substr($token, 0, 8) . '...',
        ];
    }

    /**
     * Endpoint principal do seu TCC:
     * Seguro Fiança Locatícia Residencial.
     */
    public function createRentalGuaranteeQuote(array $payload): array
    {
        return $this->postJson('/insurance/v1/fianca-locaticia/quotes', $payload);
    }

    public function getRentalGuaranteeQuote(string $quoteId): array
    {
        return $this->getJson("/insurance/v1/fianca-locaticia/quotes/{$quoteId}");
    }

    

    private function postJson(string $endpoint, array $payload): array
    {
        try {
            $response = Http::asJson()
                ->timeout(60)
                ->withHeaders($this->authHeaders())
                ->post($this->baseUrl . $endpoint, $payload);

            if (!$response->successful()) {
                Log::warning('Erro ao solicitar cotação/análise na Pottencial', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'response' => $response->json() ?? $response->body(),
                ];
            }

            return [
                'success' => true,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::error('Falha inesperada ao chamar API da Pottencial', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'endpoint' => $endpoint,
                'status' => null,
                'response' => [
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    private function getJson(string $endpoint): array
    {
        try {
            $response = Http::timeout(60)
                ->withHeaders($this->authHeaders())
                ->get($this->baseUrl . $endpoint);

            return [
                'success' => $response->successful(),
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ];
        } catch (\Throwable $e) {
            Log::error('Falha inesperada ao consultar API da Pottencial', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'endpoint' => $endpoint,
                'status' => null,
                'response' => [
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }
}