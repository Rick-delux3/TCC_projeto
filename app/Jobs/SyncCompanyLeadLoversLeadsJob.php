<?php

namespace App\Jobs;

use App\Exceptions\LeadLoversRateLimitedException;
use App\Models\Imobiliaria;
use App\Services\LeadLoversSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCompanyLeadLoversLeadsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 540;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $companyId
    ) {}

    public function uniqueFor(): int
    {
        $maximumRetryDelay = max(
            1,
            (int) config('services.leadlovers.rate_limit_max_retry_seconds', 900)
        );

        return max(
            7200,
            (($this->timeout + $maximumRetryDelay) * $this->tries) + 600
        );
    }

    public function uniqueId(): string
    {
        return "leadlovers-company:{$this->companyId}";
    }

    public function handle(LeadLoversSyncService $syncService): void
    {
        if (! config('services.leadlovers.enabled', false)) {
            Imobiliaria::whereKey($this->companyId)->update([
                'sync_status' => 'cancelled',
                'sync_error' => 'A sincronização está temporariamente indisponível.',
                'sync_finished_at' => now(),
            ]);

            Log::notice(
                'Sincronização da LeadLovers ignorada: integração desativada.',
                ['company_id' => $this->companyId]
            );

            return;
        }

        $company = Imobiliaria::findOrFail($this->companyId);

        Log::info('JOB: sincronização iniciada', [
            'company_id' => $company->id,
            'company_name' => $company->name,
        ]);

        $company->update([
            'sync_status' => 'running',
            'sync_started_at' => now(),
            'sync_error' => null,
        ]);

        try {
            $result = $syncService->syncCompanyLeads($company);

            if (! ($result['success'] ?? false)) {
                $company->update([
                    'sync_status' => 'failed',
                    'sync_error' => $result['message']
                        ?? 'Não foi possível sincronizar os leads.',
                    'sync_finished_at' => now(),
                ]);

                Log::warning('JOB: serviço não concluiu a sincronização.', [
                    'company_id' => $company->id,
                    'stopped_reason' => $result['stopped_reason'] ?? null,
                ]);

                return;
            }

            $partialReasons = [
                'max_pages_reached',
                'max_imported_leads_reached',
                'max_scanned_leads_reached',
            ];

            Log::info('JOB: service terminou, marcando como done', [
                'company_id' => $company->id,
            ]);

            $isPartial = in_array($result['stopped_reason'] ?? null, $partialReasons, true);

            $company->update([
                'sync_status' => $isPartial
                    ? 'completed_with_warning'
                    : 'completed',

                'sincronizado_em' => now(),

                'sync_finished_at' => now(),

                'sync_error' => $isPartial
                    ? 'Sincronização parcial concluída. Foram importados leads suficientes para exibição no dashboard.'
                    : null,

            ]);

            Log::info('JOB: sincronização finalizada com sucesso', [
                'company_id' => $company->id,
            ]);
        } catch (LeadLoversRateLimitedException $e) {
            $retryAfter = max(
                1,
                $e->retryAfter
                    ?? (int) config('services.leadlovers.rate_limit_retry_seconds', 60)
            );

            if ($this->attempts() >= $this->tries) {
                $company->update([
                    'sync_status' => 'failed',
                    'sync_error' => 'A LeadLovers manteve o bloqueio temporário após várias tentativas.',
                    'sync_finished_at' => now(),
                ]);

                throw $e;
            }

            $company->update([
                'sync_status' => 'queued',
                'sync_error' => "A LeadLovers limitou as requisições. Nova tentativa em {$retryAfter} segundos.",
                'sync_started_at' => null,
                'sync_finished_at' => null,
            ]);

            Log::notice('JOB: sincronização devolvida à fila por rate limit.', [
                'company_id' => $company->id,
                'attempt' => $this->attempts(),
                'retry_after' => $retryAfter,
                'cloudflare_1015' => $e->cloudflareBlocked,
            ]);

            $this->release($retryAfter);
        } catch (Throwable $e) {
            Log::error('JOB: erro ao sincronizar leads da LeadLovers', [
                'company_id' => $company->id,
                'message' => $e->getMessage(),
            ]);

            $company->update([
                'sync_status' => 'failed',
                'sync_error' => 'Não foi possível sincronizar os leads.',
                'sync_finished_at' => now(),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $company = Imobiliaria::find($this->companyId);

        if ($company) {
            $company->update([
                'sync_status' => 'failed',
                'sync_error' => $company->sync_error
                    ?: 'A sincronização falhou ou excedeu o tempo limite.',
                'sync_finished_at' => now(),
            ]);
        }

        Log::error('JOB: falhou definitivamente', [
            'company_id' => $this->companyId,
            'message' => $exception->getMessage(),
        ]);
    }
}
