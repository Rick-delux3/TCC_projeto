<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CepService
{
    /**
     * Create a new class instance.
     */
    public function find(string $cep): ?array
    {
        $cep = $this->onlyNumbers($cep);

        if (strlen($cep) !== 8) {
            return null;
        }

        return Cache::remember("cep:{$cep}", now()->addDays(30), function () use ($cep) {
            return $this->fromViaCep($cep)
                ?? $this->fromBrasilApi($cep);
        });
    }

    private function fromViaCep(string $cep): ?array
    {
         try {
            $response = Http::timeout(5)
                ->retry(2, 300)
                ->get("https://viacep.com.br/ws/{$cep}/json/");

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            if (($data['erro'] ?? false) === true) {
                return null;
            }

            return [
                'cep' => $this->onlyNumbers($data['cep'] ?? $cep),
                'logradouro' => $data['logradouro'] ?? '',
                'bairro' => $data['bairro'] ?? '',
                'cidade' => $data['localidade'] ?? '',
                'estado' => $data['uf'] ?? '',
                'complemento' => $data['complemento'] ?? '',
                'ibge' => $data['ibge'] ?? null,
                'source' => 'viacep',
            ];
        } catch (\Throwable $e) {
            Log::warning('Erro ao consultar CEP no ViaCEP', [
                'cep' => $cep,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function fromBrasilApi(string $cep): ?array
    {
         try {
            $response = Http::timeout(5)
                ->retry(2, 300)
                ->get("https://brasilapi.com.br/api/cep/v2/{$cep}");

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            return [
                'cep' => $this->onlyNumbers($data['cep'] ?? $cep),
                'logradouro' => $data['street'] ?? '',
                'bairro' => $data['neighborhood'] ?? '',
                'cidade' => $data['city'] ?? '',
                'estado' => $data['state'] ?? '',
                'complemento' => '',
                'ibge' => null,
                'source' => 'brasilapi',
            ];
        } catch (\Throwable $e) {
            Log::warning('Erro ao consultar CEP na BrasilAPI', [
                'cep' => $cep,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function onlyNumbers(?string $value): string
    {
        return preg_replace('/\D/', '', $value ?? '');
    }
}
