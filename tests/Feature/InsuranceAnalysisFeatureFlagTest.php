<?php

use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\StartInsuranceAnalysesBatchJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'features.insurance_analysis.enabled' => false,
        'services.pottencial.enabled' => false,
        'services.too.enabled' => false,
    ]);

    Bus::fake();
    Http::fake();
    Http::preventStrayRequests();
});

it('returns 404 for every public analysis form while disabled', function (string $route) {
    $this->get(route($route))->assertNotFound();
})->with([
    'simulation start' => 'simulation.start',
    'registered company access' => 'simulation.registered-company.access',
    'tenant form' => 'simulation.tenant.form',
]);

it('does not create analyses, batches, jobs, or external requests while disabled', function () {
    $this->post(route('simulation.tenant.store'))->assertNotFound();

    expect(InsuranceAnalysisBatch::query()->count())->toBe(0)
        ->and(InsuranceAnalysis::query()->count())->toBe(0);

    Bus::assertNotDispatched(StartInsuranceAnalysesBatchJob::class);
    Bus::assertNotDispatched(RunProviderAnalysisJob::class);
    Http::assertNothingSent();
});

it('ignores queued analysis jobs that remained in the queue', function () {
    $resolver = app(InsuranceProviderResolver::class);

    (new StartInsuranceAnalysesBatchJob(999))->handle($resolver);
    (new RunProviderAnalysisJob(999, 'disabled-attempt'))->handle($resolver);

    expect(InsuranceAnalysisBatch::query()->count())->toBe(0)
        ->and(InsuranceAnalysis::query()->count())->toBe(0);

    Http::assertNothingSent();
});

it('allows the analysis middleware when the feature is reactivated', function () {
    config(['features.insurance_analysis.enabled' => true]);

    $this->get(route('simulation.start'))->assertOk();
});

it('resolves only individually enabled providers when reactivated', function () {
    config([
        'features.insurance_analysis.enabled' => true,
        'services.pottencial.enabled' => true,
        'services.too.enabled' => false,
    ]);

    expect(app(InsuranceProviderResolver::class)->availableProviders())
        ->toBe(['pottencial']);
});
