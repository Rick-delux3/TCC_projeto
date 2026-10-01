<?php

use App\Http\Controllers\InsuranceAnalysisController;
use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\SyncProviderAnalysisStatusJob;
use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Models\User;
use App\Services\Insurance\InsuranceAnalysisService;
use App\Services\Insurance\Providers\InsuranceProviderInterface;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use App\Services\PottencialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['features.insurance_analysis.enabled' => true]);
    Http::preventStrayRequests();
    Queue::fake();
});

function insuranceStatusAnalysis(): InsuranceAnalysis
{
    $lead = Lead::query()->create([
        'tipo_solicitante' => 'locatario',
        'nome' => 'Teste de classificação',
        'email' => 'analysis-status@example.test',
    ]);

    $batch = InsuranceAnalysisBatch::query()->create([
        'lead_id' => $lead->id,
        'status' => 'processing',
        'total_providers' => 1,
    ]);

    return InsuranceAnalysis::query()->create([
        'insurance_analysis_batch_id' => $batch->id,
        'lead_id' => $lead->id,
        'provider' => 'pottencial',
        'product' => 'fianca_locaticia_residencial',
        'status' => 'approved',
        'result' => 'approved',
        'quote_id' => 'test-quote',
    ]);
}

dataset('insurance provider decisions', [
    'explicit approval' => [['status' => 'Approved'], true, 'approved'],
    'normalized approval' => [['status' => ' approved '], true, 'approved'],
    'explicit denial' => [['status' => 'Denied'], true, 'rejected'],
    'explicit rejection' => [['status' => 'Rejected'], true, 'rejected'],
    'explicit refusal' => [['status' => 'Refused'], true, 'rejected'],
    'nested approval' => [['data' => ['status' => 'Approved']], true, 'approved'],
    'preapproval' => [['status' => 'PreApproved'], true, 'manual_review'],
    'under analysis' => [['status' => 'UnderAnalysis'], true, 'processing'],
    'pending' => [['status' => 'Pending'], true, 'processing'],
    'unknown status with quote' => [['status' => 'Unexpected', 'quoteId' => 'test-quote'], true, 'failed'],
    'quoted is not approved' => [['status' => 'Quoted', 'premiumAmount' => 100], true, 'failed'],
    'approval substring is not approval' => [['status' => 'NotApproved'], true, 'failed'],
    'quote without decision' => [['quoteId' => 'test-quote', 'premiumAmount' => 100], true, 'failed'],
    'empty response' => [[], true, 'failed'],
    'empty status' => [['status' => '', 'quoteId' => 'test-quote'], true, 'failed'],
    'null status' => [['status' => null, 'quoteId' => 'test-quote'], true, 'failed'],
    'invalid status type' => [['status' => ['Approved'], 'quoteId' => 'test-quote'], true, 'failed'],
    'numeric status without provider mapping' => [['status' => 8, 'quoteId' => 'test-quote'], true, 'failed'],
    'HTTP failure despite approval body' => [['status' => 'Approved'], false, 'failed'],
]);

it('persists only explicit provider decisions across creation reanalysis and synchronization', function (
    array $response,
    bool $success,
    string $expectedStatus,
    string $operation,
) {
    $analysis = insuranceStatusAnalysis();
    $result = ['success' => $success, 'http_status' => $success ? 200 : 500, 'response' => $response];
    $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'status-test']]);
    if (in_array($operation, ['create', 'reanalyze'], true)) {
        $analysis->update(['status' => 'pending']);
    }

    if ($operation === 'legacy_sync') {
        $this->mock(PottencialService::class)
            ->shouldReceive('getRentalGuaranteeQuote')->once()->with('test-quote')->andReturn($result);

        app(InsuranceAnalysisService::class)->syncStatus($analysis);
    } else {
        $provider = Mockery::mock(InsuranceProviderInterface::class);
        $provider->shouldReceive(match ($operation) {
            'create' => 'requestAnalysis',
            'reanalyze' => 'requestReanalysis',
            'sync' => 'getStatus',
        })->once()->andReturn($result);

        $resolver = $this->mock(InsuranceProviderResolver::class);
        $resolver->shouldReceive('resolve')->once()->with('pottencial')->andReturn($provider);

        $job = $operation === 'sync'
            ? new SyncProviderAnalysisStatusJob($analysis->id, 'status-test')
            : new RunProviderAnalysisJob($analysis->id, 'status-test', $operation === 'reanalyze');
        if (! $success && $job instanceof RunProviderAnalysisJob) {
            expect(fn () => $job->handle($resolver))->toThrow(RuntimeException::class);
            expect($analysis->fresh()->status)->toBe('processing')
                ->and($analysis->fresh()->finished_at)->toBeNull();
            Queue::assertNotPushed(CompleteInsuranceAnalysesBatchJob::class);
            $job->failed(new RuntimeException('Provider retries exhausted'));
        } else {
            $job->handle($resolver);
        }

        Queue::assertPushed(CompleteInsuranceAnalysesBatchJob::class);
    }

    $analysis->refresh();
    expect($analysis->status)->toBe($expectedStatus)
        ->and($analysis->result)->toBe(in_array($expectedStatus, ['failed', 'processing'], true) ? null : $expectedStatus)
        ->and($analysis->isApprovedResult())->toBe($expectedStatus === 'approved')
        ->and($analysis->events()->latest('id')->first()->status)->toBe($expectedStatus);

    if ($expectedStatus === 'failed') {
        expect($analysis->finished_at)->not->toBeNull()
            ->and($analysis->error_message)->not->toBeEmpty();
    }

    Http::assertNothingSent();
})->with('insurance provider decisions')->with(['create', 'reanalyze', 'sync', 'legacy_sync']);

it('does not treat historical quotes as approval or a final decision for reanalysis', function () {
    $analysis = new InsuranceAnalysis(['status' => 'quoted', 'result' => 'approved', 'premium_amount' => 100]);

    expect($analysis->isApprovedResult())->toBeFalse()
        ->and($analysis->hasFinalResultForReanalysis())->toBeFalse();
});

it('counts only explicit approvals in company and broker dashboards', function () {
    $company = Imobiliaria::factory()->create();
    $otherCompany = Imobiliaria::factory()->create();
    $this->actingAs(User::factory()->create(['company_id' => $company->id]));

    foreach (['approved', 'Approved', 'quoted', 'rejected', 'failed'] as $status) {
        insuranceStatusAnalysis()->update(['company_id' => $company->id, 'status' => $status]);
    }
    insuranceStatusAnalysis()->update(['company_id' => $otherCompany->id, 'status' => 'approved']);

    $controller = app(InsuranceAnalysisController::class);
    $companyStats = $controller->index(Request::create('/'))->getData()['dashboardStats'];
    $brokerStats = $controller->adminIndex(Request::create('/'))->getData()['dashboardStats'];
    $filteredStats = $controller->adminIndex(Request::create('/', 'GET', [
        'company_id' => $company->id,
    ]))->getData()['dashboardStats'];

    expect($companyStats['approvedAnalyses'])->toBe(2)
        ->and($brokerStats['approvedAnalyses'])->toBe(3)
        ->and($filteredStats['approvedAnalyses'])->toBe(2);
});

it('keeps Too preapproval distinct from approval', function (array $response, string $status) {
    $analysis = insuranceStatusAnalysis();
    $analysis->update(['provider' => 'too', 'status' => 'pending']);
    $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'too-decision']]);
    $provider = Mockery::mock(InsuranceProviderInterface::class);
    $provider->shouldReceive('requestAnalysis')->once()->andReturn([
        'success' => true,
        'http_status' => 200,
        'response' => $response,
    ]);
    $resolver = $this->mock(InsuranceProviderResolver::class);
    $resolver->shouldReceive('resolve')->with('too')->once()->andReturn($provider);

    (new RunProviderAnalysisJob($analysis->id, 'too-decision'))->handle($resolver);

    expect($analysis->fresh()->status)->toBe($status)
        ->and($analysis->fresh()->isApprovedResult())->toBe($status === 'approved');
    Http::assertNothingSent();
})->with([
    'approved credit' => [['status' => 'Approved', 'quoteId' => 'too-quote'], 'approved'],
    'denied credit' => [['status' => 'Denied'], 'rejected'],
    'credit in progress' => [['status' => 'UnderAnalysis'], 'processing'],
    'preapproved credit' => [['status' => 'UnderAnalysis', 'too_internal_decision' => 'PreApproved'], 'manual_review'],
]);
