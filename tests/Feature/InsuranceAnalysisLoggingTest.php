<?php

use App\Models\InsuranceQuote;
use App\Services\Insurance\InsuranceAnalysisLogger;
use App\Services\Insurance\InsuranceDiagnosticSanitizer;
use Illuminate\Support\Facades\Log;

it('keeps financial offers while excluding personal data and credentials at every level', function () {
    $payload = [
        'http_status' => 200, 'headers' => ['Authorization' => 'secret'], 'raw_body' => 'secret',
        'response' => [
            'cpf' => '09238037698', 'nome' => 'Sensitive Name', 'mensagens' => ['danger' => ['Sensitive Name']],
            'participants' => [['amount' => 123, 'documentNumber' => '09238037698']],
            'cotacaoInfo' => ['token' => 'secret'],
            'installments' => [['countInstallments' => 30, 'grossAmount' => 1200, 'installments' => [40, 40], 'email' => 'secret@example.com']],
            'calculos' => ['N' => ['opcoesPagamento' => ['credito' => ['prazo' => [30 => ['qtd' => 30, 'vlPremPar' => 40, 'token' => 'secret']]]]]],
            'tipoPagamento' => [['tipo' => 1, 'opcoesParcelamento' => [['tipo' => 2, 'comEntrada' => false, 'parcelas' => [['numeroParcela' => 29, 'valor' => 40, 'descricao' => 'Sensitive Name']]]]]],
        ],
    ];
    $safe = app(InsuranceAnalysisLogger::class)->offers($payload);
    expect(data_get($safe, 'response.installments.0.installments'))->toBe([40, 40])
        ->and(data_get($safe, 'response.calculos.N.opcoesPagamento.credito.prazo.30.vlPremPar'))->toBe(40)
        ->and(data_get($safe, 'response.tipoPagamento.0.opcoesParcelamento.0.parcelas.0.valor'))->toBe(40)
        ->and(json_encode($safe))->not->toContain('secret', 'Sensitive', '09238037698', 'participants', 'cotacaoInfo');
});

it('rejects malformed financial fields and bounds deeply nested responses', function () {
    $logger = app(InsuranceAnalysisLogger::class);
    expect($logger->offers(['amount' => 'Name 123', 'count' => ['cpf' => 'secret'], 'payment_type' => 'secret', 'valor' => INF]))->toBe([]);
    $payload = ['amount' => 10];
    for ($i = 0; $i < 20; $i++) {
        $payload = ['response' => $payload];
    }
    expect($logger->offers($payload))->toBe([]);
});

it('logs committed results with internal correlation for every insurer', function (string $provider) {
    $quote = new InsuranceQuote(['provider' => $provider, 'status' => 'approved', 'plan_key' => 'default']);
    $quote->id = 17;
    $quote->insurance_applicant_scenario_id = 12;
    $connection = Mockery::mock(\Illuminate\Database\Connection::class)->makePartial();
    $connection->shouldReceive('transactionLevel')->andReturn(0);
    $quote = Mockery::mock($quote)->makePartial();
    $quote->shouldReceive('getConnection')->andReturn($connection);
    $channel = Mockery::mock(\Psr\Log\LoggerInterface::class);
    Log::shouldReceive('channel')->with('insurance_analyses')->once()->andReturn($channel);
    $channel->shouldReceive('info')->once()->with('insurance.payment_result', Mockery::on(fn (array $context): bool => $context['provider'] === $provider && $context['execution_id'] === 17
        && $context['attempt_id'] === 'round-test' && $context['status'] === 'approved'
        && $context['offers'] === ['selection' => ['amount' => '40.00', 'count' => 30]]
    ));
    app(InsuranceAnalysisLogger::class)->record($quote, 'round-test', ['selection' => ['amount' => '40.00', 'count' => 30, 'cpf' => 'secret']], payment: true);
})->with(['pottencial', 'too', 'tokio', 'porto']);

it('defers sanitized logs until commit and tolerates logging failure', function () {
    $quote = Mockery::mock(new InsuranceQuote(['provider' => 'tokio', 'status' => 'rejected']))->makePartial();
    $connection = Mockery::mock(\Illuminate\Database\Connection::class)->makePartial();
    $quote->shouldReceive('getConnection')->andReturn($connection);
    $connection->shouldReceive('transactionLevel')->andReturn(1);
    $callback = null;
    $connection->shouldReceive('afterCommit')->once()->with(Mockery::on(function ($value) use (&$callback): bool {
        $callback = $value;

        return true;
    }));
    Log::shouldReceive('channel')->never();
    app(InsuranceAnalysisLogger::class)->record($quote, 'attempt', ['response' => ['token' => 'secret']]);
    expect($callback)->toBeInstanceOf(Closure::class);
    Log::swap(Mockery::mock(\Illuminate\Log\LogManager::class));
    Log::shouldReceive('channel')->once()->with('insurance_analyses')->andThrow(new RuntimeException('Disk full'));
    $callback();
});

it('logs insurer status messages without confusing technical failure with refusal', function (string $provider, string $status, array $response, string $message, string $summary) {
    $execution = new InsuranceQuote(['provider' => $provider, 'status' => $status]);
    $messages = app(InsuranceAnalysisLogger::class)->messages($execution, ['response' => $response]);
    expect($messages['provider'])->toContain($message)
        ->and($messages['summary'])->toBe($summary)
        ->and($messages['unrecognized_text_omitted'])->toBeFalse();
})->with([
    ['tokio', 'approved', ['mensagens' => ['success' => ['Cadastro aprovado.'], 'danger' => ['Cadastro recusado.']]], 'Cadastro aprovado.', 'Análise aprovada.'],
    ['tokio', 'rejected', ['mensagens' => ['danger' => ['Cadastro recusado.']]], 'Cadastro recusado.', 'Análise recusada.'],
    ['tokio', 'rejected', ['mensagens' => ['danger' => ['Cadastro Reprovado não passível de reanálise.', 'Análise cadastral não aprovada.']]], 'Cadastro Reprovado não passível de reanálise.', 'Análise recusada.'],
    ['too', 'processing', ['provider_original_description' => 'Em análise'], 'Em análise', 'Análise em processamento.'],
    ['pottencial', 'approved', ['status' => 'Approved'], 'Approved', 'Análise aprovada.'],
    ['porto', 'rejected', ['errorMessage' => 'Houve recusa na aceitação do orçamento devido aos critérios de aceitação da cia.'], 'Houve recusa na aceitação do orçamento devido aos critérios de aceitação da cia.', 'Análise recusada.'],
]);

it('omits unknown free text instead of leaking personal data or inventing provider messages', function () {
    $execution = new InsuranceQuote(['provider' => 'tokio', 'status' => 'failed', 'rejection_reason' => 'Nome Particular']);
    $messages = app(InsuranceAnalysisLogger::class)->messages($execution, ['response' => ['message' => "Cadastro recusado. CPF 09238037698\nToken: secret"]]);
    expect($messages)->toBe(['summary' => 'Falha técnica na análise.', 'provider' => [], 'unrecognized_text_omitted' => true]);
    $execution->payment_status = 'unavailable';
    expect(app(InsuranceAnalysisLogger::class)->messages($execution, [], true)['summary'])->toBe('Nenhuma oferta compatível com as condições de pagamento.');
});

it('preserves documented API errors with their original structure', function (array $payload, string $source, mixed $expected) {
    $entries = app(InsuranceDiagnosticSanitizer::class)->extract($payload);
    expect(collect($entries)->firstWhere('source', $source)['data'])->toBe($expected);
})->with([
    'Pottencial error array' => [
        ['http_status' => 400, 'response' => ['errors' => [['code' => 'INVALID_PLAN', 'message' => 'PlanKey não disponível.', 'field' => 'riskObjects.0.planKey']]]],
        'response.errors', [['code' => 'INVALID_PLAN', 'message' => 'PlanKey não disponível.', 'field' => 'riskObjects.0.planKey']],
    ],
    'Too nested validation' => [
        ['response' => ['too' => ['ficha' => ['http_status' => 422, 'response' => ['errors' => ['email' => ['E-mail obrigatório.']]]]]]],
        'response.too.ficha.response.errors', ['email' => ['E-mail obrigatório.']],
    ],
    'Field keyed messages' => [['response' => ['errors' => ['cpf' => 'CPF inválido.', 'nome' => 'Nome obrigatório.']]], 'response.errors', ['cpf' => 'CPF inválido.', 'nome' => 'Nome obrigatório.']],
    'Tokio danger and warning' => [
        ['http_status' => 400, 'response' => ['mensagens' => ['danger' => ['O campo CNAE deve ser preenchido.'], 'warning' => ['Verifique o ramo de atividade.']]]],
        'response.mensagens', ['danger' => ['O campo CNAE deve ser preenchido.'], 'warning' => ['Verifique o ramo de atividade.']],
    ],
    'Quoted schema field' => [['response' => ['mensagens' => ['danger' => ["O campo 'CNAE' deve ser preenchido."]]]], 'response.mensagens', ['danger' => ["O campo 'CNAE' deve ser preenchido."]]],
    'Porto errorCode' => [['response' => ['errorCode' => 409, 'errorMessage' => 'Houve recusa na aceitação do orçamento.']], 'response.errorCode', '409'],
    'Porto message' => [['response' => ['status' => 400, 'message' => 'Orçamento não pertence ao corretor.']], 'response.message', 'Orçamento não pertence ao corretor.'],
    'Plain authentication response' => [['http_status' => 401, 'response' => [], 'raw_body' => 'Invalid access_token header'], 'body_message', 'Invalid access_token header'],
    'Porto textual response' => [['http_status' => 401, 'response' => 'Invalid access_token header'], 'response', 'Invalid access_token header'],
]);

it('redacts echoed request data credentials and rejected values from diagnostic arrays', function () {
    config(['services.too.client_secret' => 'sensitive-short-secret']);
    $entries = app(InsuranceDiagnosticSanitizer::class)->extract([
        'http_status' => 422,
        'payload' => ['nome' => 'Pessoa Particular', 'address' => ['street' => 'Rua Privada']],
        'response' => ['errors' => [[
            'code' => 'INVALID_DOCUMENT', 'field' => 'participants.0.documentNumber',
            'message' => "Pessoa Particular: CPF 092.380.376-98; CNPJ 11.222.333/0001-81; email teste@example.com; telefone (11) 99999-1234; nascimento 01/01/1990; Rua Privada; Bearer abc.secret; client_secret=sensitive-short-secret\nNova linha",
            'rejectedValue' => '09238037698', 'password' => 'another-secret',
            'privateRemark' => 'UnrelatedPrivateName',
        ]]],
        'headers' => ['Authorization' => 'private-token'],
    ]);
    $json = json_encode($entries, JSON_UNESCAPED_UNICODE);
    expect($json)->toContain('INVALID_DOCUMENT', 'participants.0.documentNumber', '[REDACTED]')
        ->not->toContain('Pessoa', 'Particular', 'Rua Privada', '092.380', '11.222', 'teste@example.com', '99999', '01/01/1990', 'abc.secret', 'sensitive-short-secret', 'another-secret', 'private-token', 'rejectedValue', 'UnrelatedPrivateName', '\\n');
});

it('bounds diagnostic output and omits HTML documents', function () {
    $entries = app(InsuranceDiagnosticSanitizer::class)->extract(['http_status' => 502, 'raw_body' => '<html><body>Private server output</body></html>']);
    expect(collect($entries)->firstWhere('source', 'body_message')['data'])->toBe('[HTML OMITTED]');
    $entries = app(InsuranceDiagnosticSanitizer::class)->extract(['response' => ['errors' => array_fill(0, 100, ['message' => str_repeat('Invalid ', 2000)])]]);
    expect($entries[0]['data'])->toHaveCount(30)
        ->and(mb_strlen($entries[0]['data'][0]['message']))->toBeLessThanOrEqual(2000);
});
