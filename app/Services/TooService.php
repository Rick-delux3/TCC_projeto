<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TooService
{
    private string $baseUrl;
    private ?string $clientId;
    private ?string $clientSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.too.base_url'), '/');
        $this->clientId = config('services.too.client_id');
        $this->clientSecret = config('services.too.client_secret');
    }

    /**
     * Gera ou recupera do cache o access_token da Too.
     *
     * Endpoint da collection:
     * POST /authentication
     *
     * Auth:
     * Basic Auth
     * username = client_id
     * password = client_secret
     *
     * Body:
     * grant_type=client_credentials
     */
    public function getAccessToken(): ?string
    {
        return Cache::remember('too_access_token', now()->addMinutes(55), function () {
            if (!$this->baseUrl) {
                Log::error('Base URL da Too não configurada.');

                return null;
            }

            if (!$this->clientId || !$this->clientSecret) {
                Log::error('Credenciais da Too não configuradas.');

                return null;
            }

            $url = $this->baseUrl . '/authentication';

            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->acceptJson()
                ->timeout(30)
                ->post($url, [
                    'grant_type' => 'client_credentials',
                ]);

            if (!$response->successful()) {
                Log::warning('Erro ao gerar access_token da Too', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $this->safeJson($response);

            Log::info('Access token da Too gerado com sucesso', [
                'token_type' => $data['token_type'] ?? null,
                'expires_in' => $data['expires_in'] ?? null,
            ]);

            return $data['access_token']
                ?? $data['accessToken']
                ?? $data['token']
                ?? null;
        });
    }

    /**
     * Headers obrigatórios para as rotas da Too.
     *
     * A collection envia:
     * - clientid
     * - clientsecret
     * - Authorization: Bearer token
     * - Content-Type: application/json
     */
    private function authHeaders(): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            throw new \RuntimeException('Não foi possível autenticar na API da Too.');
        }

        return [
            'clientid' => $this->clientId,
            'clientsecret' => $this->clientSecret,
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    /**
     * Teste simples de autenticação.
     * Use esse método antes de conectar a Too no fluxo de análises.
     */
    public function testAuthentication(): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return [
                'success' => false,
                'message' => 'Não foi possível gerar o access_token da Too.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Access token da Too gerado com sucesso.',
            'token_preview' => substr($token, 0, 8) . '...',
        ];
    }

    /**
     * 1. Registrar ficha/proposta.
     *
     * Endpoint da collection:
     * POST /fianca/proposta/ficha
     */
    public function registerProposalFicha(array $payload): array
    {
        return $this->postJson('/fianca/proposta/ficha', $payload);
    }

    /**
     * 2. Enviar proposta para análise de crédito.
     *
     * Endpoint da collection:
     * POST /fianca/credito/{cpf}/{numeroProposta}/analisar
     */
    public function submitCreditAnalysis(string $cpf, string|int $numeroProposta): array
    {
        $cpf = $this->onlyNumbers($cpf);

        return $this->postJson(
            "/fianca/credito/{$cpf}/{$numeroProposta}/analisar",
            []
        );
    }

    /**
     * 3. Consultar status da proposta/análise.
     *
     * Endpoint da collection:
     * GET /fianca/proposta/{cpf}/status/{numeroProposta}
     */
    public function getProposalStatus(string $cpf, string|int $numeroProposta): array
    {
        $cpf = $this->onlyNumbers($cpf);

        return $this->getJson(
            "/fianca/proposta/v3/{$cpf}/status/{$numeroProposta}"
        );
    }

    /**
     * 4. Solicitar cotação.
     *
     * Endpoint da collection:
     * POST /fianca/proposta/cotacao
     */
    public function requestQuote(array $payload): array
    {
        return $this->postJson('/fianca/proposta/cotacao', $payload);
    }

    /**
     * Futuro: consultar carta/parecer de crédito.
     *
     * Endpoint:
     * GET /fianca/credito/{cpf}/{numeroProposta}/parecer
     */
    public function getCreditOpinion(string $cpf, string|int $numeroProposta): array
    {
        $cpf = $this->onlyNumbers($cpf);

        return $this->getJson(
            "/fianca/credito/{$cpf}/{$numeroProposta}/parecer"
        );
    }

    /**
     * Futuro: consultar PDF da cotação.
     *
     * Endpoint:
     * GET /fianca/proposta/cotacao/{numeroCotacao}/pdf
     */
    public function getQuotePdf(string|int $numeroCotacao): array
    {
        return $this->getJson(
            "/fianca/proposta/cotacao/{$numeroCotacao}/pdf"
        );
    }

    private function postJson(string $endpoint, array $payload): array
    {
        $url = $this->baseUrl . $endpoint;

        if (!$this->baseUrl) {
            return [
                'success' => false,
                'http_status' => null,
                'endpoint' => $endpoint,
                'url' => null,
                'payload' => $payload,
                'response' => [],
                'raw_body' => null,
                'error' => 'Configuração services.too.base_url não encontrada.',
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
            Log::error('Falha inesperada ao chamar API da Too', [
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
        $url = $this->baseUrl . $endpoint;

        if (!$this->baseUrl) {
            return [
                'success' => false,
                'http_status' => null,
                'endpoint' => $endpoint,
                'url' => null,
                'response' => [],
                'raw_body' => null,
                'error' => 'Configuração services.too.base_url não encontrada.',
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
            Log::error('Falha inesperada ao consultar API da Too', [
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

        Log::info('Resposta HTTP da Too', [
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

    private function onlyNumbers(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }
}