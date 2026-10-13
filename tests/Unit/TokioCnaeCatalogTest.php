<?php

beforeEach(function (): void {
    $path = dirname(__DIR__, 2).'/database/seeders/tokio_cnae.csv';
    $this->contents = file_get_contents($path);
    $file = fopen($path, 'rb');
    $this->header = fgetcsv($file, 0, ',', '"', '');
    $this->rows = [];

    try {
        while (($row = fgetcsv($file, 0, ',', '"', '')) !== false) {
            $this->rows[] = $row;
        }
    } finally {
        fclose($file);
    }
});

it('contains the expected UTF-8 catalog schema and no empty fields', function (): void {
    expect($this->header)->toBe(['descricao', 'codigos_cnae'])
        ->and(mb_check_encoding($this->contents, 'UTF-8'))->toBeTrue()
        ->and($this->contents)->not->toContain("\u{FFFD}")
        ->and($this->rows)->toHaveCount(1910);

    foreach ($this->rows as $index => $row) {
        $this->assertCount(2, $row, 'CSV row '.($index + 2));
        expect($row[0])->not->toBe('')->toBe(trim($row[0]))
            ->and($row[1])->toMatch('/\A[0-9]{5}(?:\|[0-9]{5})*\z/');
    }
});

it('has unique descriptions regardless of case and preserves 2230 distinct codes', function (): void {
    $descriptions = [];
    $codes = [];

    foreach ($this->rows as [$description, $groupedCodes]) {
        $descriptions[] = mb_convert_case($description, MB_CASE_FOLD, 'UTF-8');
        array_push($codes, ...explode('|', $groupedCodes));
    }

    expect(array_unique($descriptions))->toHaveCount(1910)
        ->and($codes)->toHaveCount(2230)
        ->and(array_unique($codes))->toHaveCount(2230);
});

it('groups repeated descriptions while retaining all source codes and the first spelling', function (): void {
    $catalog = array_column($this->rows, 1, 0);

    expect($catalog['Administração de Cartões de Crédito'])->toBe('23882|23883')
        ->and($catalog['Horticultura, exceto morango'])->toBe('26360|25444')
        ->and($catalog)->not->toHaveKey('Horticultura, Exceto Morango');
});

/**
 * Baseline independently verified against all 90 pages of geral-tabela-cnae.pdf.
 * Source SHA-256: d0f39bf5957379aba1890450a21a37b1c891c58b313de204f8a9a2e2b230895f.
 * Each code maps to the first source spelling of its case-insensitive description group.
 * The digest covers sorted code + tab + description + LF, including 13 cross-page descriptions.
 * Review a replacement source before updating this baseline.
 */
it('preserves every verified source mapping including accents and cross-page descriptions', function (): void {
    $descriptionsByCode = [];

    foreach ($this->rows as [$description, $groupedCodes]) {
        foreach (explode('|', $groupedCodes) as $code) {
            $descriptionsByCode[$code] = $description;
        }
    }

    ksort($descriptionsByCode, SORT_NUMERIC);
    $canonicalMapping = '';

    foreach ($descriptionsByCode as $code => $description) {
        $canonicalMapping .= $code."\t".$description."\n";
    }

    expect(hash('sha256', $canonicalMapping))->toBe('b14014467ae93bce48db4c059605b79437865c666b2c8e3ef4c0fbd20fd736e5');
});
