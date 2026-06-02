<?php

namespace App\Services;

use App\Models\Imobiliaria;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LeadLoversSyncService
{
    private string $baseUrl;
    private ?string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            (string) config('services.leadlovers.base_url', 'https://llapi.leadlovers.com/webapi/'),
            '/'
        ) . '/';

        $this->token = config('services.leadlovers.token');
    }

    /**
     * Sincroniza uma quantidade limitada de leads da LeadLovers.
     *
     * Como os leads antigos já foram analisados pelos corretores,
     * a sincronização não precisa trazer tudo.
     *
     * Objetivo:
     * - alimentar o dashboard;
     * - demonstrar eficiência;
     * - evitar job pesado;
     * - não falhar por limite de páginas.
     */
    public function syncCompanyLeads(Imobiliaria $company): array
    {
        if (blank($this->token)) {
            Log::error('Token da LeadLovers não configurado.');

            return [
                'success' => false,
                'message' => 'Token da LeadLovers não configurado.',
                'imported' => 0,
                'scanned' => 0,
                'stopped_reason' => 'missing_token',
            ];
        }

        $page = 1;

        /*
         * Esses limites devem ser suficientes para popular o dashboard.
         * Não precisa buscar todos os leads antigos.
         */
        $maxPages = (int) config('services.leadlovers.sync_max_pages', 15);
        $numRegisters = (int) config('services.leadlovers.sync_page_size', 50);
        $maxImportedLeads = (int) config('services.leadlovers.sync_max_imported_leads', 100);
        $maxScannedLeads = (int) config('services.leadlovers.sync_max_scanned_leads', 500);

        $companyTag = trim($company->name);

        $processedPageHashes = [];

        $imported = 0;
        $scanned = 0;
        $skippedWithoutEmail = 0;
        $skippedWithoutCompanyTag = 0;
        $failedFullLeadRequests = 0;

        $stoppedReason = 'completed';

        Log::info('Iniciando sincronização LeadLovers', [
            'company_id' => $company->id,
            'company_name' => $company->name,
            'max_pages' => $maxPages,
            'num_registers' => $numRegisters,
            'max_imported_leads' => $maxImportedLeads,
            'max_scanned_leads' => $maxScannedLeads,
        ]);

        while (true) {
            /*
             * Não falha mais o job quando atingir o limite.
             * Apenas finaliza a sincronização parcial.
             */
            if ($page > $maxPages) {
                $stoppedReason = 'max_pages_reached';

                Log::warning('Sincronização parcial finalizada por limite máximo de páginas.', [
                    'company_id' => $company->id,
                    'page' => $page,
                    'max_pages' => $maxPages,
                    'imported' => $imported,
                    'scanned' => $scanned,
                ]);

                break;
            }

            if ($imported >= $maxImportedLeads) {
                $stoppedReason = 'max_imported_leads_reached';

                Log::info('Sincronização finalizada por limite de leads importados.', [
                    'company_id' => $company->id,
                    'imported' => $imported,
                    'max_imported_leads' => $maxImportedLeads,
                ]);

                break;
            }

            if ($scanned >= $maxScannedLeads) {
                $stoppedReason = 'max_scanned_leads_reached';

                Log::info('Sincronização finalizada por limite de leads escaneados.', [
                    'company_id' => $company->id,
                    'scanned' => $scanned,
                    'max_scanned_leads' => $maxScannedLeads,
                    'imported' => $imported,
                ]);

                break;
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
                $stoppedReason = 'leadlovers_page_request_failed';

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
                $stoppedReason = 'empty_page';

                Log::info('Página vazia. Finalizando sincronização.', [
                    'company_id' => $company->id,
                    'page' => $page,
                ]);

                break;
            }

            /*
             * Proteção contra paginação repetida.
             */
            $emailsDaPagina = collect($leadsDaPagina)
                ->pluck('Email')
                ->filter()
                ->map(fn ($email) => mb_strtolower(trim($email)))
                ->values()
                ->all();

            $pageHash = md5(json_encode($emailsDaPagina));

            if (isset($processedPageHashes[$pageHash])) {
                $stoppedReason = 'repeated_page_detected';

                Log::warning('Página repetida detectada. Encerrando para evitar loop infinito.', [
                    'company_id' => $company->id,
                    'page' => $page,
                ]);

                break;
            }

            $processedPageHashes[$pageHash] = true;

            foreach ($leadsDaPagina as $leadData) {
                if ($imported >= $maxImportedLeads || $scanned >= $maxScannedLeads) {
                    break;
                }

                $scanned++;

                $email = $leadData['Email'] ?? null;

                if (!$email) {
                    $skippedWithoutEmail++;
                    continue;
                }

                /*
                 * Busca o lead completo para conferir tags.
                 */
                $leadCompletoResponse = Http::timeout(30)
                    ->retry(2, 1000)
                    ->get($this->baseUrl . 'Lead', [
                        'token' => $this->token,
                        'email' => $email,
                    ]);

                if (!$leadCompletoResponse->successful()) {
                    $failedFullLeadRequests++;

                    Log::warning('Erro ao buscar lead completo na LeadLovers', [
                        'company_id' => $company->id,
                        'email' => $email,
                        'status' => $leadCompletoResponse->status(),
                    ]);

                    continue;
                }

                $leadCompleto = $leadCompletoResponse->json();

                $tags = $this->extractTags($leadCompleto);

                $temTagDaImobiliaria = $this->hasCompanyTag($tags, $companyTag);

                if (!$temTagDaImobiliaria) {
                    $skippedWithoutCompanyTag++;
                    continue;
                }

                $lead = $this->saveLeadFromLeadLovers(
                    company: $company,
                    leadData: $leadData,
                    leadCompleto: $leadCompleto,
                    tags: $tags
                );

                $this->saveAddressFromLeadLovers($lead, $leadData);

                $imported++;
            }

            if (count($leadsDaPagina) < $numRegisters) {
                $stoppedReason = 'last_page';

                Log::info('Última página detectada por quantidade menor que numRegisters.', [
                    'company_id' => $company->id,
                    'page' => $page,
                    'count' => count($leadsDaPagina),
                    'num_registers' => $numRegisters,
                ]);

                break;
            }

            $page++;
        }

        $result = [
            'success' => true,
            'message' => 'Sincronização LeadLovers finalizada.',
            'stopped_reason' => $stoppedReason,
            'imported' => $imported,
            'scanned' => $scanned,
            'skipped_without_email' => $skippedWithoutEmail,
            'skipped_without_company_tag' => $skippedWithoutCompanyTag,
            'failed_full_lead_requests' => $failedFullLeadRequests,
            'last_page' => $page,
        ];

        Log::info('Sincronização LeadLovers finalizada', [
            'company_id' => $company->id,
            'company_name' => $company->name,
            'result' => $result,
        ]);

        return $result;
    }

    private function extractTags(array $leadCompleto)
    {
        return collect($leadCompleto['Tags'] ?? [])
            ->map(function ($tag) {
                if (is_array($tag)) {
                    return $tag['Title'] ?? $tag['Name'] ?? null;
                }

                return is_string($tag) ? $tag : null;
            })
            ->filter()
            ->map(fn ($tag) => trim($tag))
            ->values();
    }

    private function hasCompanyTag($tags, string $companyTag): bool
    {
        $normalizedCompanyTag = $this->normalizeTag($companyTag);

        return $tags->contains(function ($tag) use ($normalizedCompanyTag) {
            return $this->normalizeTag($tag) === $normalizedCompanyTag;
        });
    }

    private function normalizeTag(?string $tag): string
    {
        return mb_strtolower(trim((string) $tag));
    }

    private function saveLeadFromLeadLovers(
        Imobiliaria $company,
        array $leadData,
        array $leadCompleto,
        $tags
    ): Lead {
        $email = $leadData['Email'];

        /*
         * ATENÇÃO:
         * Se sua coluna ainda se chama company_id, mantenha company_id.
         * Se você já renomeou para imobiliaria_id, troque aqui.
         */
        $lead = Lead::firstOrNew([
            'email' => $email,
            'company_id' => $company->id,
        ]);

        $lead->fill([
            'nome' => $leadData['Name'] ?? $leadCompleto['Name'] ?? 'Sem Nome',
            'tel' => $leadData['Phone'] ?? $leadCompleto['Phone'] ?? null,
            'imobiliaria' => $company->name,

            /*
             * Como você manteve esses campos em leads,
             * salvamos diretamente na tabela leads.
             */
            'tags_originais' => $tags->implode(', '),
            'leadlovers_status' => 'synced',
            'leadlovers_response' => $leadCompleto,
            'sent_to_leadlovers_at' => now(),

            'status' => $this->definirStatus($tags, $company->name),
        ]);

        if (!$lead->exists) {
            $lead->tipo_solicitante = 'imobiliaria_cadastrada';
            $lead->origem = 'leadlovers_sync';
        }

        $lead->save();

        return $lead;
    }

    private function saveAddressFromLeadLovers(Lead $lead, array $leadData): void
    {
        /*
         * Endereço foi movido para subtabela, então continua pelo relacionamento.
         */
        if (!filled($leadData['City'] ?? null) && !filled($leadData['State'] ?? null)) {
            return;
        }

        $lead->endereco()->updateOrCreate(
            ['lead_id' => $lead->id],
            [
                'cidade_imovel' => $leadData['City'] ?? null,
                'estado' => $leadData['State'] ?? null,
            ]
        );
    }

    private function definirStatus($tags, string $companyTag): string
    {
        $normalizedCompanyTag = $this->normalizeTag($companyTag);

        $statusTags = $tags->reject(function ($tag) use ($normalizedCompanyTag) {
            return $this->normalizeTag($tag) === $normalizedCompanyTag;
        });

        return $statusTags->isNotEmpty()
            ? $statusTags->implode(', ')
            : 'novo';
    }
}