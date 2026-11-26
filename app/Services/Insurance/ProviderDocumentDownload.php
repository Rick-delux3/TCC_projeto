<?php

namespace App\Services\Insurance;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProviderDocumentDownload
{
    /** @param list<string> $downloadHosts */
    public function fetch(string $url, array $headers, array $downloadHosts = [], ?int $timeout = null): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $this->validateUrl($url, [$host]);
        $body = $this->request($url, $headers, $timeout);
        if (str_starts_with($body, '%PDF-')) {
            return $this->validatePdf($body);
        }

        $data = json_decode($body, true);
        $link = is_array($data) ? ($data['url'] ?? $data['downloadUrl'] ?? null) : null;
        if (is_string($link)) {
            $this->validateUrl($link, array_merge([$host], $downloadHosts));

            return $this->validatePdf($this->request($link, []));
        }

        $encoded = is_string($data) ? $data : (is_array($data) ? ($data['base64'] ?? $data['pdf'] ?? null) : null);
        if (is_string($encoded)) {
            $encoded = preg_replace('#^data:application/pdf;base64,#', '', $encoded);
            $decoded = base64_decode($encoded, true);
            if ($decoded !== false) {
                return $this->validatePdf($decoded);
            }
        }

        throw new RuntimeException('A companhia não retornou um documento PDF reconhecido.');
    }

    /**
     * Decodifica um PDF entregue como Base64 dentro de um JSON da companhia
     * (Too: cartaParecerCreditoBase64). O tamanho é conferido antes de decodificar
     * e a decodificação é estrita; o conteúdo nunca entra em mensagens de erro.
     */
    public function decodeBase64Pdf(string $encoded, string $provider): string
    {
        $encoded = preg_replace('/\s+/', '', preg_replace('#^data:application/pdf;base64,#', '', trim($encoded)));
        $maxBytes = (int) config('analysis_documents.max_bytes');
        if ($encoded === '') {
            throw new RuntimeException("A {$provider} não disponibilizou o documento solicitado.");
        }
        // Base64 ocupa ~4/3 do binário: descarta antes de alocar algo acima do limite.
        if (strlen($encoded) > intdiv($maxBytes * 4, 3) + 4) {
            throw new RuntimeException("O documento da {$provider} está acima do limite permitido.");
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new RuntimeException("A {$provider} retornou um documento em Base64 inválido.");
        }

        return $this->validatePdf($decoded);
    }

    public function validatePdf(string $contents): string
    {
        if (strlen($contents) > (int) config('analysis_documents.max_bytes')
            || ! str_starts_with($contents, '%PDF-')
            || ! str_contains(substr($contents, -1024), '%%EOF')) {
            throw new RuntimeException('Documento PDF inválido, incompleto ou acima do limite permitido.');
        }

        return $contents;
    }

    private function request(string $url, array $headers, ?int $timeout = null): string
    {
        $response = Http::withHeaders($headers)->accept('application/pdf, application/json')
            ->connectTimeout(10)->timeout($timeout ?? (int) config('analysis_documents.download_timeout'))
            ->withOptions(['allow_redirects' => false, 'stream' => true])->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível obter o documento da companhia. HTTP '.$response->status());
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        try {
            while (! $stream->eof()) {
                $body .= $stream->read(8192);
                if (strlen($body) > (int) config('analysis_documents.max_bytes') * 2) {
                    throw new RuntimeException('Resposta do documento acima do limite permitido.');
                }
            }
        } finally {
            $stream->close();
        }

        return $body;
    }

    private function validateUrl(string $url, array $hosts): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ! in_array(strtolower($parts['host'] ?? ''), array_map('strtolower', array_filter($hosts)), true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new RuntimeException('Endereço de download do documento não autorizado.');
        }
    }
}
