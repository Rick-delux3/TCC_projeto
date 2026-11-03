<?php

use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceQuote;
use App\Models\Lead;
use App\Services\Insurance\InsuranceQuoteCoordinator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

it('prints complete quote diagnostics without external requests or persistence', function (string $provider): void {
    Http::fake();
    Queue::fake();
    $lead = Lead::query()->create([
        'nome' => 'Diagnostic applicant', 'cpf' => '52998224725', 'email' => 'diagnostic@example.test',
        'data_nascimento' => '1990-05-01', 'tipo_locacao' => 'residencial', 'tipo_solicitante' => 'locatario',
    ]);
    $lead->despesas()->create(['valor_aluguel' => 1000]);
    $lead->conjuge()->create(['nome' => 'Diagnostic spouse', 'cpf' => '11144477735']);
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'status' => 'processing', 'total_providers' => 1]);
    $analysis = InsuranceAnalysis::query()->create([
        'insurance_analysis_batch_id' => $batch->id, 'lead_id' => $lead->id, 'provider' => $provider,
        'status' => 'pending', 'rent_amount' => 1000, 'product' => 'fianca_locaticia_residencial',
    ]);
    foreach (['diagnostic-first', 'diagnostic-second'] as $attempt) {
        $analysis->events()->create(['event_type' => 'analysis_started', 'payload' => ['attempt_id' => $attempt]]);
        app(InsuranceQuoteCoordinator::class)->prepare($analysis, $attempt, false);
    }
    $quotes = InsuranceQuote::orderBy('id')->get();
    foreach ($quotes as $quote) {
        $quote->update([
            'quote_id' => 'external-'.$quote->id,
            'request_payload' => ['documentNumber' => '52998224725'],
            'response_payload' => ['http_status' => 404, 'endpoint' => '/quotes', 'response' => ['errors' => [['code' => '09.80', 'message' => 'Unable to find person with ID=person-'.$quote->id]]]],
        ]);
        $quote->events()->create([
            'event_type' => 'payment_failed', 'message' => 'Full payment failure',
            'payload' => ['step' => 'payment'],
            'response' => ['raw_body' => '<error>Payment detail '.$quote->id.'</error>', 'offers' => [['installment' => 42.50]]],
        ]);
    }
    config(['features.insurance_analysis.enabled' => false]);
    Log::shouldReceive('info', 'warning', 'error', 'debug', 'log', 'channel')->never();
    Cache::shouldReceive('put', 'add', 'remember', 'forever', 'forget')->never();
    $queries = [];
    $batch->getConnection()->listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(Artisan::call('insurance:debug-batch', ['batchId' => $batch->id]))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('diagnostic-first', 'diagnostic-second', 'primary', 'spouse', $provider, '52998224725', '09.80', 'Full payment failure');
    foreach ($quotes as $quote) {
        expect($output)->toContain('external-'.$quote->id, 'person-'.$quote->id, '<error>Payment detail '.$quote->id.'</error>');
    }
    expect($queries)->not->toBeEmpty();
    foreach ($queries as $sql) {
        expect(strtolower(ltrim($sql)))->toStartWith('select');
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['pottencial', 'too', 'tokio']);

it('supports legacy batches without rounds', function (): void {
    $lead = Lead::query()->create(['nome' => 'Legacy', 'cpf' => '52998224725', 'email' => 'legacy@example.test']);
    $batch = InsuranceAnalysisBatch::query()->create(['lead_id' => $lead->id, 'status' => 'completed', 'total_providers' => 1]);
    $analysis = InsuranceAnalysis::query()->create([
        'insurance_analysis_batch_id' => $batch->id, 'lead_id' => $lead->id, 'provider' => 'pottencial',
        'status' => 'failed', 'response_payload' => ['errors' => ['Original legacy error']],
    ]);
    $analysis->events()->create(['event_type' => 'failed', 'response' => ['message' => 'Historical response']]);
    expect(Artisan::call('insurance:debug-batch', ['batchId' => $batch->id]))->toBe(0);
    expect(Artisan::output())->toContain('Original legacy error', 'Historical response', 'Nenhuma rodada');
});

it('rejects invalid or missing batches', function (string $id, string $message): void {
    expect(Artisan::call('insurance:debug-batch', ['batchId' => $id]))->toBe(1);
    expect(Artisan::output())->toContain($message);
})->with([
    ['0', 'inteiro positivo'], ['abc', 'inteiro positivo'], ['999999', 'Batch não encontrado.'],
]);
