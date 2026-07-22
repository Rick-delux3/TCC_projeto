<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CpfLookupService
{
    private ?int $cache_minutes;
    private string $baseUrl;
    private string $apiKey;
    private string $provider;
    private bool $enable;


    
    public function __construct() {
        $this->cache_minutes = (int) config('services.cpf_lookup.cache_minutes', 10080);
        $this->baseUrl = rtrim((string) config('services.cpf_lookup.cpfhub.base_url'), '/');
        $this->apiKey = config('services.cpf_lookup.cpfhub.api_key');
        $this->provider = config('services.cpf_lookup.provider', 'cpfhub');
        $this->enable = config('services.cpf_lookup.enabled');


    }
    /**
     * Retorna a data de nascimento no formato exigido pela Too:
     *
     * Y/m/d
     *
     * Exemplo:
     * 1990/06/15
     */
    public function birthdateForToo(?string $cpf): ?string
    {
        if (! config('features.insurance_analysis.enabled', false)) {
            return $this->fallbackBirthdate();
        }

        $cpf = $this->onlyNumbers($cpf);

        if (strlen($cpf) !== 11) {
            return $this->fallbackBirthdate();
        }

        /*
        |--------------------------------------------------------------------------
        | Cache por CPF
        |--------------------------------------------------------------------------
        | Importante para:
        | - economizar consultas;
        | - evitar rate limit;
        | - evitar cobrar novamente em reanálises.
        */
        $cacheKey = 'cpfhub_birthdate_' . sha1($cpf);

        return Cache::remember(
            $cacheKey,
            now()->addMinutes($this->cache_minutes),
            function () use ($cpf) {
                $birthdate = $this->fetchBirthdate($cpf);

                return $birthdate ?: $this->fallbackBirthdate();
            }
        );
    }

    /**
     * Decide qual provider será usado.
     */
    private function fetchBirthdate(string $cpf): ?string
    {
        if (!$this->enable) {
            return null;
        }

        $provider = $this->provider;

        return match ($provider) {
            'cpfhub' => $this->fetchFromCpfHub($cpf),
            default => null,
        };
    }

    /**
     * Consulta a CPFHub.
     *
     * Endpoint:
     * GET https://api.cpfhub.io/v1/cpf/{cpf}
     *
     * Header:
     * x-api-key: SUA_API_KEY
     */
    private function fetchFromCpfHub(string $cpf): ?string
    {
        $baseUrl = $this->baseUrl;
        $apiKey = $this->apiKey;

        if (!$baseUrl || !$apiKey) {
            Log::warning('CPFHub não configurado corretamente.');

            return null;
        }

        $url = "{$baseUrl}/v1/cpf/{$cpf}";

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'x-api-key' => $apiKey,
                ])
                ->timeout(30)
                ->get($url);

            $data = $this->safeJson($response);

            /*
            |--------------------------------------------------------------------------
            | Logs somente em desenvolvimento
            |--------------------------------------------------------------------------
            | Em produção, não é recomendado salvar CPF, nome e nascimento em log.
            */
            if (config('app.debug')) {
                Log::info('Resposta CPFHub', [
                    'http_status' => $response->status(),
                    'success' => $response->successful(),
                    'response' => $data,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 404 não é erro fatal
            |--------------------------------------------------------------------------
            | A documentação informa que 404 significa CPF não encontrado
            | e não consome crédito.
            */
            if ($response->status() === 404) {
                Log::info('CPF não encontrado na CPFHub', [
                    'cpf' => config('app.debug') ? $cpf : null,
                ]);

                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | 429 - Rate limit
            |--------------------------------------------------------------------------
            | No plano grátis, há limite de 1 requisição a cada 2 segundos.
            */
            if ($response->status() === 429) {
                Log::warning('Rate limit excedido na CPFHub', [
                    'retry_after' => $response->header('Retry-After'),
                ]);

                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | 401 e 403 normalmente indicam problema de configuração/créditos.
            |--------------------------------------------------------------------------
            */
            if (in_array($response->status(), [401, 403], true)) {
                Log::warning('Erro de autenticação ou créditos na CPFHub', [
                    'http_status' => $response->status(),
                ]);

                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | 400 e 422 indicam CPF inválido.
            |--------------------------------------------------------------------------
            */
            if (in_array($response->status(), [400, 422], true)) {
                Log::warning('CPF inválido enviado para CPFHub', [
                    'http_status' => $response->status(),
                    'cpf' => config('app.debug') ? $cpf : null,
                ]);

                return null;
            }

            if (!$response->successful()) {
                Log::warning('Falha inesperada ao consultar CPFHub', [
                    'http_status' => $response->status(),
                    'body' => config('app.debug') ? $response->body() : null,
                ]);

                return null;
            }

            if (data_get($data, 'success') !== true) {
                Log::warning('CPFHub retornou success diferente de true.');

                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | Campo correto da CPFHub
            |--------------------------------------------------------------------------
            | Exemplo:
            | data.birthDate = "15/06/1990"
            */
            $birthdate = data_get($data, 'data.birthDate');

            return $this->formatBirthdateForToo($birthdate);
        } catch (\Throwable $e) {
            Log::warning('Erro inesperado ao consultar CPFHub', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Converte a data para o formato exigido pela Too.
     *
     * CPFHub retorna:
     * d/m/Y
     *
     * Too espera:
     * Y/m/d
     */
    private function formatBirthdateForToo(?string $birthdate): ?string
    {
        if (!$birthdate) {
            return null;
        }

        $birthdate = trim($birthdate);

        $formats = [
            'd/m/Y',
            'd-m-Y',
            'Y-m-d',
            'Y/m/d',
        ];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $birthdate);

                if ($date) {
                    return $date->format('Y/m/d');
                }
            } catch (\Throwable) {
                // Tenta o próximo formato.
            }
        }

        try {
            return Carbon::parse($birthdate)->format('Y/m/d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeJson($response): array
    {
        try {
            return $response->json() ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function fallbackBirthdate(): ?string
    {
        return config('services.cpf_lookup.fallback_birthdate', '1985/12/10');
    }

    private function onlyNumbers(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }
}
