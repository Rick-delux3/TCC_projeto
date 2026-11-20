<?php

use App\Models\Imobiliaria;
use Illuminate\Support\ViewErrorBag;

it('shows the rental expense controls for both brands', function (string $brand, string $profile, bool $admin) {
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
        ->and($xpath->query($moneyInputs.'[not(ancestor::*[@hidden])]')->length)->toBe(7)
        ->and($xpath->query('//input[@name="valor_aluguel"]')->item(0)->getAttribute('value'))->toBe('1.500,00')
        ->and($xpath->query('//input[@name="valor_agua"]')->item(0)->getAttribute('value'))->toBe('75,00')
        ->and($xpath->query('//input[@name="valor_aluguel" and @required]')->length)->toBe(1)
        ->and($xpath->query('//input[@name="tipo_locacao"]')->length)->toBe(2)
        ->and($xpath->query('//input[@name="cep"]')->length)->toBe(1);

    $label = trim($xpath->query('//label[@for="valor_aluguel"]')->item(0)->textContent);
    expect($label)->toContain('Valor do aluguel')
        ->and($xpath->query('//select[@id="expenseSelector" and not(ancestor::*[@hidden])]/option[@value!=""]')->length)->toBe(5)
        ->and($html)->not->toContain('Valor de referência do serviço');
})->with(['tcc', 'client'])->with(['tenant', 'registered', 'unregistered'])->with([false, true]);
