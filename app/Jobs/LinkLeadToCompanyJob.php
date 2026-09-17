<?php

namespace App\Jobs;

use App\Exceptions\LeadLoversApiException;
use App\Services\LeadCompanyLinkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Throwable;

class LinkLeadToCompanyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 30;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public int $requestLogId) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('lead-company-link:'.$this->requestLogId))->releaseAfter(15)->expireAfter(180)];
    }

    /**
     * Execute the job.
     */
    public function handle(LeadCompanyLinkService $links): void
    {
        $syncJob = $links->process($this->requestLogId);

        try {
            $delay = $links->synchronizeCompanyTags($this->requestLogId);
        } catch (LeadLoversApiException $exception) {
            if (! $exception->isTransient) {
                $this->fail($exception);

                return;
            }

            $this->release(max(1, $exception->retryAfterSeconds ?? 30));

            return;
        }

        if ($delay !== null) {
            $this->release($delay);

            return;
        }

        if ($syncJob !== null) {
            Bus::dispatch($syncJob);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(LeadCompanyLinkService::class)->fail($this->requestLogId);
    }
}
