<?php

namespace App\Models;

use App\Services\Insurance\InsuranceAnalysisAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InsuranceAnalysis extends InsuranceExecution
{
    public function currentProviderRun(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(InsuranceProviderRun::class)->latestOfMany();
    }

    public function providerRuns(): HasMany
    {
        return $this->hasMany(InsuranceProviderRun::class);
    }

    protected $table = 'analises_seguro';

    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->eventos();
    }

    public function eventos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InsuranceAnalysisEvent::class, 'insurance_analysis_id');
    }

    public function latestAttemptEvent(): HasOne
    {
        return $this->hasOne(InsuranceAnalysisEvent::class, 'insurance_analysis_id')
            ->ofMany(['id' => 'max'], fn (Builder $query): Builder => $query->whereIn('event_type', InsuranceAnalysisAttempt::START_EVENTS));
    }

    public function hasFinalResultForReanalysis(): bool
    {
        return $this->isApprovedResult() || $this->isRejectedResult();
    }

    public function canRequestProviderReanalysis(): bool
    {
        return $this->hasFinalResultForReanalysis();
    }

    /** @return array{attempt_id: string, is_reanalysis: bool}|null */
    public function currentAttemptContext(bool $lock = false): ?array
    {
        $event = ! $lock && $this->relationLoaded('latestAttemptEvent')
            ? $this->getRelation('latestAttemptEvent')
            : $this->events()
                ->whereIn('event_type', InsuranceAnalysisAttempt::START_EVENTS)
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->latest('id')
                ->first();

        if ($event) {
            $attemptId = data_get($event->payload, 'attempt_id');
            $isReanalysis = (bool) data_get(
                $event->payload,
                'is_reanalysis',
                str_starts_with($event->event_type, 'reanalysis_')
            );
        } else {
            $payload = $this->providerResponsePayload();
            $isReanalysis = (bool) ($payload['too_is_reanalysis']
                ?? $payload['is_reanalysis']
                ?? filled($payload['too_reanalysis_attempt_id'] ?? null));
            $attemptId = $payload['attempt_id']
                ?? ($isReanalysis
                    ? ($payload['too_reanalysis_attempt_id'] ?? null)
                    : ($payload['too_analysis_attempt_id'] ?? null));
        }

        if (! is_string($attemptId) || blank($attemptId)) {
            return null;
        }

        return ['attempt_id' => $attemptId, 'is_reanalysis' => $isReanalysis];
    }
}
