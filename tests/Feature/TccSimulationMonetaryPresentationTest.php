<?php

use App\Models\Imobiliaria;
use Illuminate\Support\ViewErrorBag;

it('shows only the reference amount for tcc while retaining the existing expense controls internally', function (string $brand, string $profile, bool $admin) {
    config(['branding.active' => $brand]);
    session()->flashInput(['valor_aluguel' => '1.500,00', 'valor_agua' => '75,00']);
    request()->setLaravelSession(session()->driver());

    $html = view('simulation.partials.form-shell', [
        'profile' => $profile,
        'isAdminSimulation' => $admin,
        'formAction' => '/simulation-test',
        'company' => Imobiliaria::factory()->make(['name' => 'Empresa exemplo']),
        'responsavelTipo' => 'locador',
        'errors' => new ViewErrorBag,
    ])->render();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xpath = new DOMXPath($document);
    $moneyInputs = '//div[contains(concat(" ", normalize-space(@class), " "), " simulation-money ")]/input';

    expect($xpath->query($moneyInputs)->length)->toBe(7)
        ->and($xpath->query($moneyInputs.'[not(ancestor::*[@hidden])]')->length)->toBe($brand === 'tcc' ? 1 : 7)
        ->and($xpath->query('//input[@name="valor_aluguel"]')->item(0)->getAttribute('value'))->toBe('1.500,00')
        ->and($xpath->query('//input[@name="valor_agua"]')->item(0)->getAttribute('value'))->toBe('75,00')
        ->and($xpath->query('//input[@name="valor_aluguel" and @required]')->length)->toBe(1)
        ->and($xpath->query('//input[@name="tipo_locacao"]')->length)->toBe(2)
        ->and($xpath->query('//input[@name="cep"]')->length)->toBe(1);

    $label = trim($xpath->query('//label[@for="valor_aluguel"]')->item(0)->textContent);
    if ($brand === 'tcc') {
        expect($label)->toContain('Valor de referência do serviço')
            ->and($xpath->query('//*[@data-tcc-internal-expenses and @hidden]')->length)->toBe(1)
            ->and($xpath->query('//input[@name="valor_aluguel"]')->item(0)->getAttribute('aria-describedby'))->toBe('valor_aluguel-hint')
            ->and($html)->toContain('Esse valor não é o preço final retornado pela fornecedora.');
    } else {
        expect($label)->toContain('Valor do aluguel')
            ->and($xpath->query('//*[@data-tcc-internal-expenses]')->length)->toBe(0)
            ->and($html)->not->toContain('Valor de referência do serviço');
    }
})->with(['tcc', 'client'])->with(['tenant', 'registered', 'unregistered'])->with([false, true]);
