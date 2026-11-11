<?php

namespace App\Services\Insurance;

class TokioPartnerSpreadsheet
{
    /** @return list<array{row: int, code: string, name: string, document: string, status: string}> */
    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 10_000_000) {
            throw new \InvalidArgumentException('Planilha ausente, ilegível ou maior que 10 MB.');
        }
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('O arquivo deve ser uma planilha XLSX válida.');
        }
        try {
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($this->xml($zip, 'xl/sharedStrings.xml')->si as $item) {
                    $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $strings[] = implode('', array_map('strval', $item->xpath('.//m:t') ?: []));
                }
            }
            $sheet = $this->xml($zip, 'xl/worksheets/sheet1.xml');
            $rows = [];
            $header = false;
            foreach ($sheet->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $cell) {
                    $column = preg_replace('/\d/', '', (string) $cell['r']);
                    $value = (string) $cell->v;
                    if (isset($cell->f)) {
                        throw new \InvalidArgumentException('A planilha de parceiros não deve conter fórmulas.');
                    }
                    $cells[$column] = trim(match ((string) $cell['t']) {
                        's' => $strings[(int) $value] ?? '',
                        'inlineStr' => (string) $cell->is->t,
                        default => $value,
                    });
                }
                if (implode('', $cells) === '') {
                    continue;
                }
                if (! $header) {
                    if (($cells['A'] ?? '') !== 'Código' || ($cells['B'] ?? '') !== 'Nome' || ($cells['C'] ?? '') !== 'CPF/CNPJ' || ($cells['F'] ?? '') !== 'Status') {
                        throw new \InvalidArgumentException('Cabeçalho incompatível: esperado Código, Nome, CPF/CNPJ e Status nas colunas A, B, C e F.');
                    }
                    $header = true;

                    continue;
                }
                $rows[] = ['row' => (int) $row['r'], 'code' => $cells['A'] ?? '', 'name' => $cells['B'] ?? '',
                    'document' => \only_numbers($cells['C'] ?? ''), 'status' => $cells['F'] ?? ''];
            }
            if (! $header || count($rows) > 10000) {
                throw new \InvalidArgumentException('Planilha vazia ou excedendo 10.000 parceiros.');
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function xml(\ZipArchive $zip, string $entry): \SimpleXMLElement
    {
        $stat = $zip->statName($entry);
        if (! $stat || $stat['size'] > 10_000_000) {
            throw new \InvalidArgumentException('Estrutura XLSX inválida ou excedendo o limite de leitura.');
        }
        $content = $zip->getFromName($entry);
        if ($content === false || stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
            throw new \InvalidArgumentException('XML da planilha inválido.');
        }
        $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new \InvalidArgumentException('XML da planilha inválido.');
        }
        $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return $xml;
    }
}
