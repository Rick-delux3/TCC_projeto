<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\LeadLoversSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCompanyLeadLoversLeadsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 900;
    public bool $failOnTimeout = true;

    public function __construct(
        public int $companyId
    ) {}

    public function handle(LeadLoversSyncService $syncService): void
    {
        $company = Company::findOrFail($this->companyId);

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
            $syncService->syncCompanyLeads($company);

            Log::info('JOB: service terminou, marcando como done', [
                'company_id' => $company->id,
            ]);

            $company->update([
                'sync_status' => 'done',
                'sync_finished_at' => now(),
                'sincronizado_em' => now(),
                'sync_error' => null,
            ]);

            Log::info('JOB: sincronização finalizada com sucesso', [
                'company_id' => $company->id,
            ]);
        } catch (Throwable $e) {
            Log::error('JOB: erro ao sincronizar leads da LeadLovers', [
                'company_id' => $company->id,
                'message' => $e->getMessage(),
            ]);

            $company->update([
                'sync_status' => 'failed',
                'sync_error' => 'Não foi possível sincronizar os leads.',
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $company = Company::find($this->companyId);

        if ($company) {
            $company->update([
                'sync_status' => 'failed',
                'sync_error' => 'A sincronização falhou ou excedeu o tempo limite.',
                'sync_finished_at' => now(),
            ]);
        }

        Log::error('JOB: falhou definitivamente', [
            'company_id' => $this->companyId,
            'message' => $exception->getMessage(),
        ]);
    }
}