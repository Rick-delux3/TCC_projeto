<?php

namespace App\Jobs;

use App\Models\InsuranceAnalysis;
use App\Services\Insurance\Providers\TooInsuranceProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncTooAnalysisStatusJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $analysisId,
        public int $attemptNumber = 1,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(TooInsuranceProvider $provider): void
    {
        $analysis = InsuranceAnalysis::find($this->analysisId);

        if(!$analysis) return;

        if($analysis->provider !== 'too') return; 

        if(in_array($analysis->status, ['approved', 'rejected', 'failed'], true)) return;

        $result = $provider->getStatus($analysis);

        $providerStatus = data_get($result, 'response.status');

        $delaySeconds = (int) config('services.too.status_check_delay_seconds', 20);
        $maxAttempts = (int) config('services.too.status_check_max_attempts', 15);

        $analysis->refresh();



        if(
            $providerStatus === 'UnderAnalysis' && $this->attemptNumber < $maxAttempts
        )
        {
            $analysis->update([
                'response_payload' => array_merge($analysis->response_payload ?? [], [
                    'too_status_check_stopped' => false,
                    'too_manual_sync_available' => false,
                    'too_status_check_attempts' => $this->attemptNumber,
                    'too_last_auto_check_at' => now()->toDateTimeString(),
                ]),
            ]);

            self::dispatch(
                analysisId: $analysis->id,
                attemptNumber: $this->attemptNumber + 1
            )->delay(now()->addSeconds($delaySeconds));

            return;
        }

        if($providerStatus === 'UnderAnalysis'){
            $analysis->update([
                'status' => 'manual_review',
                'provider_status' => 'Em Análise de Crédito - verificação automática encerrada',
                'response_payload' => array_merge($analysis->response_payload ?? [], [
                    'too_status_check_stopped' => true,
                    'too_status_check_attempts' => $this->attemptNumber,
                    'too_last_auto_check_at' => now()->toDateTimeString(),
                    'too_manual_sync_available' => true,
                ]),
            ]);

            $analysis->events()->create([
                'event_type' => 'too_auto_status_check_stopped',
                'status' => 'manual_review',
                'message' => 'A Too ainda retornou Em Análise de Crédito após as verificações automáticas. A verificação manual foi liberada.',
                'payload' => [
                    'attempts' => $this->attemptNumber,
                    'delay_seconds' => $delaySeconds,
                    'stopped_at' => now()->toDateTimeString(),
                ],
                'response' => $result,
            ]);

            return;
        }

        $analysis->update([
            'response_payload' => array_merge($analysis->response_payload ?? [], [
                'too_status_check_stopped' => false,
                'too_manual_sync_available' => false,
                'too_last_auto_check_at' => now()->toDateTimeString(),
            ]),
        ]);
    }
}
