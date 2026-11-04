<?php

use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceQuote;
use App\Models\Lead;
use App\Services\Insurance\InsuranceApiAuthenticationCheck;
use App\Services\Insurance\InsuranceQuoteCoordinator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

it('prints complete saved Pottencial requests responses and all quote rounds without side effects', function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->mock(InsuranceApiAuthenticationCheck::class)->shouldNotReceive('check');
    $lead = Lead::query()->create(['nome' => 'Stored applicant', 'email' => 'stored@example.test', 'cpf' => '52998224725', 'tipo_locacao' => 'residencial', 'tipo_solicitante' => 'locatario']);
    $lead->despesas()->create(['valor_aluguel' => 400]);
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'total_providers' => 1, 'status' => 'processing']);
    $analysis = $batch->analyses()->create(['lead_id' => $lead->id, 'provider' => 'pottencial', 'status' => 'failed',
        'request_payload' => ['participants' => [['documentNumber' => '52998224725']]],
        'response_payload' => ['errors' => ['Consolidated error']],
    ]);
    foreach (['first-attempt', 'second-attempt'] as $attempt) {
        $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => $attempt]]);
        app(InsuranceQuoteCoordinator::class)->prepare($analysis, $attempt, false);
    }
    $quotes = InsuranceQuote::orderBy('id')->get();
    foreach ($quotes as $quote) {
        $quote->update(['request_payload' => ['full_body' => ['documentNumber' => '52998224725', 'planKey' => $quote->plan_key]],
            'response_payload' => ['http_status' => 404, 'response' => ['errors' => [['message' => 'Person missing '.$quote->id, 'code' => '09.80']]]],
        ]);
        $quote->events()->create(['event_type' => 'payment_failed', 'response' => ['raw_body' => '<error>Original '.$quote->id.'</error>']]);
    }
    config(['features.insurance_analysis.enabled' => false]);
    Log::shouldReceive('info', 'warning', 'error', 'debug', 'log', 'channel')->never();
    Cache::shouldReceive('put', 'add', 'remember', 'forever', 'forget')->never();
    $queries = [];
    $analysis->getConnection()->listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    expect(Artisan::call('pottencial:test-api', ['--analise' => $analysis->id]))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('request_payload', 'response_payload', '52998224725', 'Consolidated error', 'first-attempt', 'second-attempt', '09.80');
    foreach ($quotes as $quote) {
        expect($output)->toContain($quote->plan_key, 'Person missing '.$quote->id, '<error>Original '.$quote->id.'</error>');
    }
    expect($queries)->not->toBeEmpty();
    foreach ($queries as $sql) {
        expect(strtolower(ltrim($sql)))->toStartWith('select');
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('supports a saved legacy analysis and rejects a different provider', function (string $provider, int $exitCode) {
    Http::preventStrayRequests();
    $lead = Lead::query()->create(['nome' => 'Legacy', 'email' => 'legacy@example.test']);
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'total_providers' => 1, 'status' => 'completed']);
    $analysis = $batch->analyses()->create(['lead_id' => $lead->id, 'provider' => $provider, 'status' => 'failed',
        'request_payload' => ['body' => ['preserved' => true]], 'response_payload' => ['message' => 'Full legacy response'],
    ]);
    expect(Artisan::call('pottencial:test-api', ['--analise' => $analysis->id]))->toBe($exitCode);
    if ($provider === 'pottencial') {
        expect(Artisan::output())->toContain('preserved', 'Full legacy response');
    } else {
        expect(Artisan::output())->toContain('não encontrada')->not->toContain('Full legacy response');
    }
    Http::assertNothingSent();
})->with([['pottencial', 0], ['too', 1]]);

it('rejects an invalid or absent local analysis without authenticating', function (string $id, int $exitCode) {
    $this->mock(InsuranceApiAuthenticationCheck::class)->shouldNotReceive('check');
    expect(Artisan::call('pottencial:test-api', ['--analise' => $id]))->toBe($exitCode);
})->with([['0', 2], ['abc', 2], ['999999', 1]]);

it('preserves the authentication test when no analysis option is supplied', function () {
    $this->mock(InsuranceApiAuthenticationCheck::class)->shouldReceive('check')->once()->with('pottencial')->andReturn([
        'success' => true, 'message' => 'Authentication confirmed', 'endpoint' => '/oauth/v3/access-token', 'http_status' => 200, 'duration_ms' => 12,
    ]);
    expect(Artisan::call('pottencial:test-api'))->toBe(0);
    expect(Artisan::output())->toContain('Authentication confirmed', 'HTTP: 200');
});
