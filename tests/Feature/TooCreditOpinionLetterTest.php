<?php

use App\Services\Insurance\TooService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config([
        'features.insurance_analysis.enabled' => true,
        'services.too.enabled' => true,
        'services.too.base_url' => 'https://too.test',
        'services.too.client_id' => 'client-id',
        'services.too.client_secret' => 'client-secret',
    ]);
    // Token já em cache: o teste cobre só a consulta da carta.
    Cache::put('too_access_token', 'too-token', now()->addMinutes(5));
    $this->pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
    $this->url = 'https://too.test/fianca/credito/98940357876/261113346000013/parecer';
});

it('decodes the credit opinion letter returned as cartaParecerCreditoBase64', function (): void {
    Http::fake([$this->url => Http::response(['cartaParecerCreditoBase64' => base64_encode($this->pdf)])]);

    $contents = app(TooService::class)->getCreditOpinionPdf('989.403.578-76', '261113346000013');

    expect($contents)->toBe($this->pdf);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === $this->url
        && $request->hasHeader('Authorization', 'Bearer too-token')
        && $request->hasHeader('clientid', 'client-id')
        && $request->hasHeader('clientsecret', 'client-secret'));
});

it('never logs the Base64 letter', function (): void {
    $encoded = base64_encode($this->pdf);
    Http::fake([$this->url => Http::response(['cartaParecerCreditoBase64' => $encoded])]);
    Log::spy();

    app(TooService::class)->getCreditOpinionPdf('98940357876', '261113346000013');

    Log::shouldNotHaveReceived('info', fn (string $message, array $context = []): bool => str_contains(json_encode($context), $encoded));
});

it('treats an unusable letter as a documentary failure', function (mixed $body, int $status, string $message): void {
    Http::fake([$this->url => Http::response($body, $status)]);

    expect(fn () => app(TooService::class)->getCreditOpinionPdf('98940357876', '261113346000013'))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'HTTP error' => [['message' => 'Proposta não encontrada'], 404, 'HTTP 404'],
    'missing field' => [['outroCampo' => 'x'], 200, 'sem a carta do parecer'],
    'empty field' => [['cartaParecerCreditoBase64' => ''], 200, 'não disponibilizou o documento'],
    'invalid base64' => [['cartaParecerCreditoBase64' => '***'], 200, 'Base64 inválido'],
    'not a pdf' => [['cartaParecerCreditoBase64' => base64_encode('<html>erro</html>')], 200, 'PDF inválido'],
]);

it('rejects incomplete identifiers without calling the API', function (string $cpf, string $proposal): void {
    Http::fake();

    expect(fn () => app(TooService::class)->getCreditOpinionPdf($cpf, $proposal))
        ->toThrow(RuntimeException::class, 'Identificação da proposta da Too incompleta.');
    Http::assertNothingSent();
})->with([
    'short cpf' => ['123', '261113346000013'],
    'empty proposal' => ['98940357876', ''],
    'path injection' => ['98940357876', '1/../../outra-rota'],
]);
