<?php

use App\Events\InsuranceAnalysisChanged;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisEvent;
use App\Models\Lead;
use App\Services\Insurance\Payloads\TooRentalGuaranteePayloadBuilder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Bus::fake();
    Mail::fake();
    Event::fake([InsuranceAnalysisChanged::class]);
    Sleep::fake();
    Http::preventStrayRequests();
    config([
        'features.insurance_analysis.enabled' => true,
        'broadcasting.default' => 'reverb',
        'services.too.enabled' => true,
        'services.too.base_url' => 'https://too.example.test',
        'services.too.client_id' => 'test-client',
        'services.too.client_secret' => 'test-secret',
        'services.too.broker_cnpj' => '11222333000181',
        'services.too.broker_name' => 'Corretora teste',
    ]);
    Cache::put('too_access_token', 'private-test-token', 3600);
    $this->lead = Lead::query()->create([
        'nome' => 'Pretendente teste', 'email' => 'integration@example.test', 'cpf' => '07234828702',
        'tel' => '11999998888', 'data_nascimento' => '1992-02-29',
        'tipo_locacao' => 'residencial', 'tipo_solicitante' => 'locador',
    ]);
    $this->lead->endereco()->create([
        'cep' => '01001000', 'logradouro' => 'Praça da Sé', 'numero' => '100',
        'bairro' => 'Sé', 'cidade_imovel' => 'São Paulo', 'estado' => 'SP',
    ]);
    $this->lead->despesas()->create(['valor_aluguel' => 1500, 'valor_agua' => 0, 'valor_luz' => 0]);
    $this->batch = $this->lead->insuranceAnalysesBatches()->create(['status' => 'completed', 'total_providers' => 1, 'finished_at' => now()]);
    $this->previousAnalysis = $this->batch->analyses()->create([
        'lead_id' => $this->lead->id, 'provider' => 'too', 'product' => $this->lead->rentalGuaranteeProduct(),
        'status' => 'approved', 'quote_id' => 'previous-quote', 'response_payload' => ['status' => 'Approved'],
    ]);
    $this->savedLead = $this->lead->fresh()->getAttributes();
    $this->savedAnalysis = $this->previousAnalysis->fresh()->getAttributes();
    $this->savedBatch = $this->batch->fresh()->getAttributes();
    $this->transactionLevel = $this->lead->getConnection()->transactionLevel();
    $this->endpoint = 'https://too.example.test/fianca/proposta/ficha';
    Event::fake([InsuranceAnalysisChanged::class]);
});

afterEach(function () {
    expect(InsuranceAnalysis::query()->count())->toBe(1)
        ->and(InsuranceAnalysisEvent::query()->count())->toBe(0)
        ->and($this->previousAnalysis->fresh()->getAttributes())->toBe($this->savedAnalysis)
        ->and($this->batch->fresh()->getAttributes())->toBe($this->savedBatch)
        ->and($this->lead->fresh()->getAttributes())->toBe($this->savedLead)
        ->and($this->lead->insuranceAnalysesBatches()->count())->toBe(1)
        ->and($this->lead->getConnection()->transactionLevel())->toBe($this->transactionLevel);
    Bus::assertNothingDispatched();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Event::assertNotDispatched(InsuranceAnalysisChanged::class);
});

it('runs the Too ficha credit status and quote flow with the existing builder', function () {
    Cache::forget('too_access_token');
    $requests = [];
    Http::fake(function (Request $request, array $options) use (&$requests) {
        $requests[] = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);
        expect($options['allow_redirects'])->toBeFalse();
        $temporary = InsuranceAnalysis::query()->whereKeyNot($this->previousAnalysis->id)->sole();
        expect($temporary->provider)->toBe('too')->and($temporary->insurance_analysis_batch_id)->toBeNull();

        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/authentication' => Http::response(['access_token' => 'private-test-token']),
            '/fianca/proposta/ficha' => Http::response(['numeroProposta' => 123, 'numeroFicha' => 456], 201),
            '/fianca/credito/07234828702/123/analisar' => Http::response('', 204),
            '/fianca/proposta/v3/07234828702/status/123' => Http::response(['proposta' => ['status' => 8, 'descricaoStatus' => 'Aprovada']]),
            '/fianca/proposta/cotacao' => Http::response(['numeroCotacao' => 789, 'premio' => 150.25]),
            default => throw new RuntimeException('Endpoint inesperado'),
        };
    });

    expect(Artisan::call('too:test-analysis', ['--lead' => $this->lead->id]))->toBe(0);
    $output = Artisan::output();
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($report['success'])->toBeTrue()->and($report['analysis']['status'])->toBe('approved')
        ->and($report['analysis']['proposal_id'])->toBe('123')
        ->and($report['analysis']['numero_ficha'])->toBe('456')
        ->and($report['analysis']['quote_id'])->toBe('789')
        ->and((float) $report['analysis']['premium_amount'])->toBe(150.25)
        ->and(array_column($report['steps'], 'operation'))->toBe(['ficha', 'credit', 'status', 'quote'])
        ->and(array_column($report['steps'], 'http_status'))->toBe([201, 204, 200, 200])
        ->and($output)->not->toContain('private-test-token', 'test-secret')
        ->and($requests)->toBe([
            'POST /authentication', 'POST /fianca/proposta/ficha', 'POST /fianca/credito/07234828702/123/analisar',
            'GET /fianca/proposta/v3/07234828702/status/123', 'POST /fianca/proposta/cotacao',
        ]);
    Http::assertSent(fn (Request $request): bool => $request->url() === $this->endpoint
        && $request['pretendentes'][0]['cpf'] === '07234828702'
        && $request['pretendentes'][0]['dataNascimento'] === '1992-02-29'
        && (float) $request['pretendentes'][0]['rendaFixaMensal'] === 6000.0
        && $request['pretendentes'][0]['vinculoEmpregaticio'] === config('services.too.default_employment')
        && $request['pretendentes'][0]['profissao'] === config('services.too.default_profession')
        && $request['locacao']['finalidadeLocacao'] === 'Residencial');
    Sleep::assertNeverSlept();
});

it('does not request a quote for a refusal and uses the representative CPF for a company', function () {
    $this->lead->update(['cpf' => null, 'tipo_locacao' => 'comercial']);
    $this->lead->lead_empresa()->create(['cnpj' => '11222333000181', 'cpf_responsavel' => '07234828702', 'nome_responsavel' => 'Responsável teste']);
    $this->savedLead = $this->lead->fresh()->getAttributes();
    Http::fakeSequence()->push(['numeroFicha' => 123], 201)->push([], 202)
        ->push(['proposta' => ['status' => 6, 'descricaoStatus' => 'Recusada']]);

    expect(Artisan::call('too:test-analysis', ['--lead' => $this->lead->id]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['analysis']['status'])->toBe('rejected')->and($report['analysis']['quote_id'])->toBeNull();
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->url() === $this->endpoint
        && $request['pretendentes'][0]['cpf'] === '07234828702'
        && $request['pretendentes'][0]['nome'] === 'Responsável teste'
        && $request['locacao']['finalidadeLocacao'] === 'Comercial');
});

it('polls pending credit and quotes once after approval', function () {
    Http::fakeSequence()->push(['numeroFicha' => 123])->push([], 204)
        ->push(['proposta' => ['status' => 5]])->push(['proposta' => ['status' => 8]])
        ->push(['numeroCotacao' => 789, 'premio' => 250.5]);

    expect(Artisan::call('too:test-analysis', ['--lead' => $this->lead->id, '--intervalo' => 2]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($report['steps'], 'operation'))->toBe(['ficha', 'credit', 'status', 'status', 'quote']);
    Http::assertSentCount(5);
    Sleep::assertSleptTimes(1);
});

it('keeps pending or preapproved credit incomplete at the consultation limit', function (int $status) {
    Http::fakeSequence()->push(['numeroFicha' => 123])->push([], 204)
        ->push(['proposta' => ['status' => $status]])->push(['proposta' => ['status' => $status]]);

    expect(Artisan::call('too:test-analysis', ['--lead' => $this->lead->id, '--consultas' => 2, '--intervalo' => 1]))->toBe(2);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['incomplete'])->toBeTrue()->and($report['analysis']['finished_at'])->toBeNull()
        ->and($report['analysis']['quote_id'])->toBeNull();
    Http::assertSentCount(4);
    Sleep::assertSleptTimes(1);
})->with([5, 16]);

it('reports each HTTP failure and stops without replaying mutations', function (int $position, int $status) {
    $bodies = [['numeroFicha' => 123], [], ['proposta' => ['status' => 8]], ['numeroCotacao' => 789, 'premio' => 150.25]];
    $sequence = Http::fakeSequence();
    for ($index = 0; $index < $position; $index++) {
        $sequence->push($bodies[$index]);
    }
    $sequence->push('<erro>detalhe original</erro>', $status, ['Retry-After' => '60']);

    expect(Artisan::call('too:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $last = $report['steps'][$position];
    expect($last['http_status'])->toBe($status)->and($last['http_description'])->not->toBeEmpty()
        ->and($last['raw_body'])->toBe('<erro>detalhe original</erro>')
        ->and($last['headers']['Retry-After'])->toBe(['60']);
    Http::assertSentCount($position + 1);
})->with([
    'ficha validation' => [0, 422], 'credit access' => [1, 403], 'status redirect' => [2, 302],
    'status unavailable' => [2, 503], 'quote rate limit' => [3, 429], 'unknown status' => [0, 599],
]);

it('finishes a failed diagnostic consultation without dispatching retries', function () {
    Http::fakeSequence()->push(['numeroFicha' => 123])->push([], 204)
        ->push(['proposta' => ['status' => 5]])->push(['error' => 'unavailable'], 503);

    expect(Artisan::call('too:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['analysis']['status'])->toBe('failed')->and($report['steps'][3]['http_status'])->toBe(503);
    Http::assertSentCount(4);
});

it('separates authentication failure from the operation that needed the token', function (bool $duringStatus) {
    if (! $duringStatus) {
        Cache::forget('too_access_token');
    }
    Http::fake(function (Request $request) use ($duringStatus) {
        if ($duringStatus && str_ends_with($request->url(), '/ficha')) {
            return Http::response(['numeroFicha' => 123]);
        }
        if ($duringStatus && str_ends_with($request->url(), '/analisar')) {
            Cache::forget('too_access_token');

            return Http::response([], 204);
        }

        return Http::response(['error' => 'invalid_client'], 401);
    });

    expect(Artisan::call('too:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $last = end($report['steps']);
    expect($last['operation'])->toBe('authentication')->and($last['http_status'])->toBe(401)
        ->and($last['endpoint'])->toBe('/authentication');
    Http::assertSentCount($duringStatus ? 3 : 1);
})->with([false, true]);

it('does not accept an approval with an empty quote as integration success', function () {
    Http::fakeSequence()->push(['numeroFicha' => 123])->push([], 204)
        ->push(['proposta' => ['status' => 8]])->push([], 200);

    expect(Artisan::call('too:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['success'])->toBeFalse()->and($report['analysis']['status'])->toBe('approved')
        ->and($report['analysis']['error'])->toContain('cotação não retornou');
    Http::assertSentCount(4);
});

it('does not invent an HTTP response on a connection failure', function () {
    Http::fake(['*' => Http::failedConnection('Timeout')]);
    expect(Artisan::call('too:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['steps'][0]['http_status'])->toBeNull()->and($report['steps'])->toHaveCount(1);
});

it('validates arguments and provider availability before outbound requests', function (array $options, bool $enabled, int $exit) {
    config(['services.too.enabled' => $enabled]);
    if ($enabled === false) {
        $options['--lead'] = $this->lead->id;
    }
    expect(Artisan::call('too:test-analysis', $options))->toBe($exit);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['success'])->toBeFalse();
    Http::assertNothingSent();
})->with([
    'missing lead' => [[], true, 2], 'invalid ID' => [['--lead' => 'abc'], true, 2],
    'unknown lead' => [['--lead' => 999999999], true, 2],
    'invalid checks' => [['--lead' => 1, '--consultas' => 0], true, 2],
    'disabled' => [[], false, 1],
]);

it('rejects incomplete lead data without fabricating a birthdate', function () {
    $this->lead->update(['data_nascimento' => null]);
    $this->savedLead = $this->lead->fresh()->getAttributes();
    expect(Artisan::call('too:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['analysis']['status'])->toBe('failed')->and($report['analysis']['error'])->toContain('nascimento');
    Http::assertNothingSent();
});

it('cleans up the analysis and events after an unexpected local exception', function () {
    $this->mock(TooRentalGuaranteePayloadBuilder::class)->shouldReceive('buildFichaPayload')->once()->andThrow(new RuntimeException('Unexpected'));
    expect(Artisan::call('too:test-analysis', ['--lead' => $this->lead->id]))->toBe(1);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['success'])->toBeFalse();
    Http::assertNothingSent();
});
