<?php

use Symfony\Component\HttpKernel\Exception\HttpException;

it('preserves error actions and isolates the standalone logo layout to tcc', function (string $brand, int $code, string $primaryRoute, string $secondaryRoute): void {
    $this->withoutVite();
    config(['branding.active' => $brand]);
    $view = $this->view('errors.'.$code, [
        'exception' => new HttpException($code),
        'errors' => new \Illuminate\Support\ViewErrorBag,
    ]);
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.(string) $view);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//body[contains(@class,"tcc-error-body")]')->length)->toBe($brand === 'tcc' ? 1 : 0)
        ->and($xpath->query('//main[contains(@class,"tcc-error-main")]')->length)->toBe($brand === 'tcc' ? 1 : 0)
        ->and($xpath->query('//body/header')->length)->toBe($brand === 'tcc' ? 0 : 1)
        ->and($xpath->query('//aside/img[contains(@class,"error-background-logo")][@src="'.asset('imgs/Logo_NVS.png').'"][@aria-hidden="true"]')->length)->toBe($brand === 'tcc' ? 1 : 0)
        ->and($xpath->query('//main//h1')->length)->toBe(1);

    foreach ([$primaryRoute, $secondaryRoute] as $route) {
        expect($xpath->query('//div[contains(@class,"error-content")]//a[@href="'.route($route).'"]')->length)->toBe(1);
    }

    $originalImage = $code === 419 ? 'imgs/segure-chave-a-mao-ao-ar-livre.jpg' : 'imgs/divulgar-imoveis-online-site-para-imobiliaria.jpg';
    expect($xpath->query('//aside/img[@src="'.asset($originalImage).'"]')->length)->toBe($brand === 'client' ? 1 : 0);
    $view->assertSee('Erro '.$code);
})->with(['tcc', 'client'])->with([
    [403, 'admin.ceo.login', 'index'],
    [404, 'index', 'empresa.login'],
    [419, 'empresa.login', 'index'],
    [500, 'index', 'empresa.login'],
]);
