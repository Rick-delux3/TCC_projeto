<?php

use App\Jobs\SendAnalysisResultsEmailJob;
use App\Models\Imobiliaria;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use App\Models\Lead;
use App\Services\Insurance\AnalysisDocumentService;
use App\Services\Insurance\AnalysisEmailDeliveryService;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    config(['features.insurance_analysis.enabled' => true]);
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::disk('local')->put('delivery.pdf', "%PDF-1.4\n%%EOF");
    $this->mock(AnalysisDocumentService::class)->shouldReceive('generate')->andReturn([
        ['path' => Storage::disk('local')->path('delivery.pdf'), 'name' => 'orcamento.pdf'],
    ]);
});

function deliveryBatch(): InsuranceAnalysisBatch
{
    $company = Imobiliaria::factory()->create(['email' => 'principal@example.test']);
    $company->setores()->createMany([
        ['key' => 'financeiro', 'name' => 'Financeiro', 'email' => 'financeiro@example.test'],
        ['key' => 'comercial', 'name' => 'Comercial', 'email' => 'comercial@example.test'],
    ]);
    $lead = Lead::query()->create([
        'nome' => 'Delivery', 'email' => 'tenant@example.test', 'company_id' => $company->id,
        'tipo_solicitante' => 'imobiliaria_cadastrada',
    ]);
    $lead->forceFill(['analysis_final_status' => 'approved'])->save();
    $batch = InsuranceAnalysisBatch::query()->create([
        'lead_id' => $lead->id, 'company_id' => $company->id, 'total_providers' => 1,
        'status' => 'completed', 'finished_at' => now(), 'email_status' => 'queued',
    ]);
    $analysis = $batch->analyses()->create([
        'lead_id' => $lead->id, 'provider' => 'pottencial', 'product' => 'fianca_locaticia_residencial', 'status' => 'approved',
    ]);
    $analysis->events()->create(['event_type' => 'created', 'payload' => ['attempt_id' => 'delivery']]);

    return $batch;
}

function recipientMessage(string $body, Closure $callback): Email
{
    $email = (new Email)->from('sender@example.test')->text($body);
    $callback(new Message($email));
    expect($email->getTo())->toHaveCount(1)
        ->and($email->getCc())->toBe([])->and($email->getBcc())->toBe([])
        ->and($email->getAttachments())->toHaveCount(1);

    return $email;
}

function confirmRecipientMessage(Email $email): SentMessage
{
    return new SentMessage(new \Symfony\Component\Mailer\SentMessage($email, Envelope::create($email)));
}

it('sends individual messages and retries only the address that failed', function () {
    $batch = deliveryBatch();
    $calls = [];
    Mail::shouldReceive('raw')->times(4)->andReturnUsing(function (string $body, Closure $callback) use (&$calls): SentMessage {
        $email = recipientMessage($body, $callback);
        $recipient = $email->getTo()[0]->getAddress();
        $calls[$recipient] = ($calls[$recipient] ?? 0) + 1;
        if ($recipient === 'principal@example.test' && $calls[$recipient] === 1) {
            throw new RuntimeException('Temporary transport failure');
        }

        return confirmRecipientMessage($email);
    });
    $job = new SendAnalysisResultsEmailJob($batch->id, 'delivery');
    expect(fn () => $job->handle())->toThrow(RuntimeException::class)
        ->and($batch->fresh()->email_status)->toBe('queued')
        ->and($batch->fresh()->email_sent_at)->toBeNull();
    expect($calls)->toEqual(['principal@example.test' => 1, 'financeiro@example.test' => 1, 'comercial@example.test' => 1]);
    $job->handle();
    $job->handle();

    expect($calls['principal@example.test'])->toBe(2)
        ->and($batch->fresh()->email_status)->toBe('sent')
        ->and(InsuranceAnalysisEvent::query()->where('event_type', 'email_recipient_sent')->count())->toBe(3)
        ->and($batch->lead->fresh()->analysis_final_status)->toBe('approved');
    Http::assertNothingSent();
});

it('uses the queue backoff and records only unresolved recipients after three failures', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite']);
    $batch = deliveryBatch();
    $calls = [];
    Mail::shouldReceive('raw')->times(5)->andReturnUsing(function (string $body, Closure $callback) use (&$calls): SentMessage {
        $email = recipientMessage($body, $callback);
        $recipient = $email->getTo()[0]->getAddress();
        $calls[$recipient] = ($calls[$recipient] ?? 0) + 1;
        if ($recipient === 'financeiro@example.test') {
            throw new RuntimeException('secret transport details');
        }

        return confirmRecipientMessage($email);
    });
    SendAnalysisResultsEmailJob::dispatch($batch->id, 'delivery')->onQueue('delivery-test')->beforeCommit();
    $worker = app('queue.worker')->setCache(app('cache')->store());
    foreach ([60, 300, null] as $delay) {
        $queued = Queue::connection()->pop('delivery-test');
        expect($queued)->not->toBeNull();
        expect(fn () => $worker->process('database', $queued, new WorkerOptions))->toThrow(RuntimeException::class);
        if ($delay !== null) {
            expect(Queue::connection()->pop('delivery-test'))->toBeNull();
            $this->travel($delay + 1)->seconds();
        }
    }
    $failed = InsuranceAnalysisEvent::query()->where('event_type', 'email_recipient_failed')->get();
    expect($calls)->toEqual(['principal@example.test' => 1, 'financeiro@example.test' => 3, 'comercial@example.test' => 1])
        ->and($failed->pluck('payload.recipient')->all())->toBe(['financeiro@example.test'])
        ->and($batch->fresh()->email_status)->toBe('failed')
        ->and($batch->fresh()->email_error)->not->toContain('secret')
        ->and($batch->fresh()->status)->toBe('completed')
        ->and($batch->lead->fresh()->analysis_final_status)->toBe('approved');
});

it('does not record a cancelled message as sent', function () {
    $batch = deliveryBatch();
    Mail::shouldReceive('raw')->times(3)->andReturnNull();
    expect(fn () => (new SendAnalysisResultsEmailJob($batch->id, 'delivery'))->handle())->toThrow(RuntimeException::class)
        ->and(InsuranceAnalysisEvent::query()->where('event_type', 'email_recipient_sent')->exists())->toBeFalse()
        ->and($batch->fresh()->email_sent_at)->toBeNull();
});

it('does not send remaining addresses or persist a response after a new analysis round starts', function () {
    $batch = deliveryBatch();
    Mail::shouldReceive('raw')->once()->andReturnUsing(function (string $body, Closure $callback) use ($batch): SentMessage {
        $email = recipientMessage($body, $callback);
        $batch->analyses()->first()->events()->create(['event_type' => 'reanalysis_requested', 'payload' => ['attempt_id' => 'new-delivery']]);
        $batch->update(['email_status' => 'pending']);

        return confirmRecipientMessage($email);
    });
    $job = new SendAnalysisResultsEmailJob($batch->id, 'delivery');
    $job->handle();
    $job->failed(new TimeoutExceededException);
    expect($batch->fresh()->email_status)->toBe('pending')
        ->and(InsuranceAnalysisEvent::query()->whereIn('event_type', ['email_recipient_sent', 'email_recipient_failed'])->exists())->toBeFalse();
});

it('consolidates confirmed deliveries after a timeout before finalization', function () {
    $batch = deliveryBatch();
    Mail::shouldReceive('raw')->times(3)->andReturnUsing(fn (string $body, Closure $callback): SentMessage => confirmRecipientMessage(recipientMessage($body, $callback)));
    $job = new SendAnalysisResultsEmailJob($batch->id, 'delivery');
    app(AnalysisEmailDeliveryService::class)->send($batch, 'delivery', 'Resultado', [
        ['path' => Storage::disk('local')->path('delivery.pdf'), 'name' => 'orcamento.pdf'],
    ]);
    expect($batch->fresh()->email_status)->toBe('queued');
    $job->failed(new TimeoutExceededException);
    $sentAt = $batch->fresh()->email_sent_at;
    $job->failed(new TimeoutExceededException);
    expect($batch->fresh()->email_status)->toBe('sent')
        ->and($batch->fresh()->email_sent_at)->toEqual($sentAt)
        ->and(InsuranceAnalysisEvent::query()->where('event_type', 'email_recipient_failed')->exists())->toBeFalse();
});
