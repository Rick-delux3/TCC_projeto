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
        $this->baseUrl = rtrim((string) config('services.pottencial.base_url'), '/');
        $this->clientId = config('services.pottencial.client_id');
        $this->clientSecret = config('services.pottencial.client_secret');
    }

    /**
     * Gera ou recupera do cache o access_token da Pottencial.
     */
    public function getAccessToken(): ?string
    {
        $this->ensureEnabled();

        return Cache::remember('pottencial_access_token', now()->addMinutes(55), function () {
            if (!$this->baseUrl) {
                Log::error('Base URL da Pottencial não configurada.');

                return null;
            }


            if (!$this->clientId || !$this->clientSecret) {
                Log::error('Credenciais da Pottencial não configuradas.');

                return null;
            }

            $url = $this->baseUrl . '/oauth/v3/access-token';


            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->acceptJson()
                ->timeout(30)
                ->post($url);

            if (!$response->successful()) {
                Log::warning('Erro ao gerar access_token da Pottencial', [
                    'status' => $response->status(),
                    'http_status' => $response->status(),
                    'raw_body' => $response->body(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $this->safeJson($response);

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
        return $this->postJson($this->rentalEndpoint(), $payload);
    }

    public function getRentalGuaranteeQuote(string $quoteId): array
    {
        return $this->getJson($this->rentalEndpoint() . "/{$quoteId}");
    }


    

    private function postJson(string $endpoint, array $payload): array
    {
        $this->ensureEnabled();

        $url = $this->baseUrl . $endpoint;

        if(!$this->baseUrl){
            return [
                'success' => false,
                'http_status' => null,
                'endpoint' => $endpoint,
                'url' => null,
                'payload' => $payload,
                'response' => [],
                'raw_body' => null,
                'error' => 'Configuração services.pottencial.base_url não encontrada.',
            ];
        }


        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(60)
                ->withHeaders($this->authHeaders())
                ->post($url, $payload);

             return $this->normalizeResponse(
                response: $response,
                endpoint: $endpoint,
                url: $url,
                payload: $payload
            );
        
            
        } catch (\Throwable $e) {
            Log::error('Falha inesperada ao chamar API da Pottencial', [
                'endpoint' => $endpoint,
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'http_status' => null,
                'endpoint' => $endpoint,
                'url' => $url,
                'payload' => $payload,
                'response' => [
                    'message' => $e->getMessage(),
                ],
                'raw_body' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function getJson(string $endpoint): array
    {
        $this->ensureEnabled();

        $url = $this->baseUrl . $endpoint;

        if(!$this->baseUrl){
            return [
                'success' => false,
                'http_status' => null,
                'endpoint' => $endpoint,
                'url' => null,
                'response' => [],
                'raw_body' => null,
                'error' => 'Configuração services.pottencial.base_url não encontrada.',
            ];
        }

        try {
            $response = Http::acceptJson()
                ->timeout(60)
                ->withHeaders($this->authHeaders())
                ->get($url);

            return $this->normalizeResponse(
                response: $response,
                endpoint: $endpoint,
                url: $url
            );
        } catch (\Throwable $e) {
            Log::error('Falha inesperada ao consultar API da Pottencial', [
                'endpoint' => $endpoint,
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'http_status' => null,
                'endpoint' => $endpoint,
                'url' => $url,
                'response' => [
                    'message' => $e->getMessage(),
                ],
                'raw_body' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function ensureEnabled(): void
    {
        if (! config('features.insurance_analysis.enabled', false)) {
            throw new \LogicException('O sistema de análises está temporariamente desativado.');
        }

        if (! config('services.pottencial.enabled', false)) {
            throw new \LogicException('O provider pottencial está desativado.');
        }
    }
    private function normalizeResponse($response, string $endpoint, string $url, ?array $payload = null): array
    {
        $json = $this->safeJson($response);
        $rawBody = $response->body();

        $normalized = [
            'success' => $response->successful(),
            'http_status' => $response->status(),
            'endpoint' => $endpoint,
            'url' => $url,
            'response' => is_array($json) ? $json : [],
            'raw_body' => $rawBody,
            'headers' => $response->headers(),
        ];

        if ($payload !== null) {
            $normalized['payload'] = $payload;
        }

        Log::info('Resposta HTTP da Pottencial', [
            'endpoint' => $endpoint,
            'url' => $url,
            'http_status' => $normalized['http_status'],
            'success' => $normalized['success'],
            'response' => $normalized['response'],
            'raw_body' => $normalized['raw_body'],
        ]);

        return $normalized;
    }

    private function safeJson($response): mixed
    {
        try {
            return $response->json();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function rentalEndpoint(): string
    {
        return '/' . ltrim(
            (string) config(
                'services.pottencial.rental_endpoint',
                '/insurance/v1/fianca-locaticia-mensalizado-pf/quotes'
            ),
            '/'
        );
    }
}
