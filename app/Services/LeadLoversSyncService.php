<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LeadLoversSyncService
{
    private string $baseUrl;
    private ?string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.leadlovers.base_url', 'https://llapi.leadlovers.com/webapi/'), '/') . '/';
        $this->token = config('services.leadlovers.token');
    }

    public function syncCompanyLeads(Company $company): void
    {
        $page = 1;
        $maxPages = 25;
        $numRegisters = 50;
        $companyTag = trim($company->name);
        $processedPageHashes = [];

        Log::info('Iniciando sincronização LeadLovers', [
            'company_id' => $company->id,
            'company_name' => $company->name,
        ]);

        do {
            if ($page > $maxPages) {
                Log::warning('Sincronização interrompida por limite máximo de páginas.', [
                    'company_id' => $company->id,
                    'page' => $page,
                    'max_pages' => $maxPages,
                ]);

                throw new RuntimeException('Limite maximo de paginas atingido antes do fim da sincronizacao.');
            }

            Log::info('Buscando página de leads na LeadLovers', [
                'company_id' => $company->id,
                'page' => $page,
            ]);

            $response = Http::timeout(30)
                ->retry(2, 1000)
                ->get($this->baseUrl . 'Leads', [
                    'token' => $this->token,
                    'page' => $page,
                    'numRegisters' => $numRegisters,
                ]);

            if (!$response->successful()) {
                Log::warning('Erro ao buscar página de leads na LeadLovers', [
                    'company_id' => $company->id,
                    'page' => $page,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                break;
            }

            $data = $response->json();
            $leadsDaPagina = $data['Data'] ?? [];

            Log::info('Página recebida da LeadLovers', [
                'company_id' => $company->id,
                'page' => $page,
                'count' => count($leadsDaPagina),
            ]);

            if (empty($leadsDaPagina)) {
                Log::info('Página vazia. Finalizando sincronização.', [
                    'company_id' => $company->id,
                    'page' => $page,
                ]);

                break;
            }

            $emailsDaPagina = collect($leadsDaPagina)
                ->pluck('Email')
                ->filter()
                ->values()
                ->all();

            $pageHash = md5(json_encode($emailsDaPagina));

            if (isset($processedPageHashes[$pageHash])) {
                Log::warning('Página repetida detectada. Encerrando para evitar loop infinito.', [
                    'company_id' => $company->id,
                    'page' => $page,
                ]);

                break;
            }

            $processedPageHashes[$pageHash] = true;

            foreach ($leadsDaPagina as $leadData) {
                $email = $leadData['Email'] ?? null;

                if (!$email) {
                    continue;
                }

                $leadCompletoResponse = Http::timeout(30)
                    ->retry(2, 1000)
                    ->get($this->baseUrl . 'Lead', [
                        'token' => $this->token,
                        'email' => $email,
                    ]);

                if (!$leadCompletoResponse->successful()) {
                    Log::warning('Erro ao buscar lead completo na LeadLovers', [
                        'company_id' => $company->id,
                        'email' => $email,
                        'status' => $leadCompletoResponse->status(),
                    ]);

                    continue;
                }

                $leadCompleto = $leadCompletoResponse->json();

                $tags = collect($leadCompleto['Tags'] ?? [])
                    ->map(function ($tag) {
                        if (is_array($tag)) {
                            return $tag['Title'] ?? null;
                        }

                        return is_string($tag) ? $tag : null;
                    })
                    ->filter()
                    ->map(fn ($tag) => trim($tag))
                    ->values();

                $temTagDaImobiliaria = $tags->contains(function ($tag) use ($companyTag) {
                    return mb_strtolower($tag) === mb_strtolower($companyTag);
                });

                if (!$temTagDaImobiliaria) {
                    continue;
                }

                Lead::updateOrCreate(
                    [
                        'email' => $email,
                        'company_id' => $company->id,
                    ],
                    [
                        'nome' => $leadData['Name'] ?? 'Sem Nome',
                        'tel' => $leadData['Phone'] ?? null,
                        'cidade_imovel' => $leadData['City'] ?? null,
                        'imobiliaria' => $company->name,
                        'tags_originais' => $tags->implode(', '),
                        'status' => $this->definirStatus($tags, $companyTag),
                    ]
                );
            }

            if (count($leadsDaPagina) < $numRegisters) {
                Log::info('Última página detectada por quantidade menor que numRegisters.', [
                    'company_id' => $company->id,
                    'page' => $page,
                    'count' => count($leadsDaPagina),
                ]);

                break;
            }

            $page++;

        } while (true);

        Log::info('Sincronização LeadLovers finalizada', [
            'company_id' => $company->id,
            'company_name' => $company->name,
        ]);
    }

    private function definirStatus($tags, string $companyTag): string
    {
        $statusTags = $tags->reject(function ($tag) use ($companyTag) {
            return mb_strtolower($tag) === mb_strtolower($companyTag);
        });

        return $statusTags->isNotEmpty()
            ? $statusTags->implode(', ')
            : 'novo';
    }
}
