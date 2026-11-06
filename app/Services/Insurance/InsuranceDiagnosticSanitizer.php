<?php

namespace App\Services\Insurance;

final class InsuranceDiagnosticSanitizer
{
    public function connectionFailure(\Illuminate\Http\Client\ConnectionException $exception, string $operation, array $sources = []): array
    {
        $previous = $exception->getPrevious();
        $context = $previous instanceof \GuzzleHttp\Exception\ConnectException ? $previous->getHandlerContext() : [];

        return $this->extract(['errors' => [
            'type' => 'transport_error',
            'operation' => $operation,
            'code' => is_int($context['errno'] ?? null) ? $context['errno'] : null,
            'message' => $exception->getMessage(),
        ]], $sources);
    }

    private const DIAGNOSTICS = ['error', 'errors', 'errorCode', 'errorMessage', 'error_description', 'message', 'messages', 'mensagem', 'mensagens', 'detail', 'title', 'violations', 'validationErrors', 'invalidParams', 'provider_original_description', 'statusDescription', 'rejectionReason', 'reasons', 'step', 'operation'];

    private const WRAPPERS = ['response', 'result', 'data', 'too', 'authentication', 'ficha', 'proposal', 'proposta', 'submit', 'credit', 'analysis', 'status', 'quote', 'quote_latest', 'status_latest', 'payment'];

    private const OMIT = ['request', 'payload', 'headers', 'raw_body', 'stack', 'trace', 'exception', 'value', 'attemptedValue', 'rejectedValue', 'invalidValue', 'pdfCotacao'];

    private const ERROR_FIELDS = ['code', 'errorCode', 'status', 'statusCode', 'httpStatus', 'reasonCode', 'field', 'path', 'propertyPath', 'target', 'reason', 'description', 'severity', 'type', 'source', 'data', 'details', 'success', 'danger', 'warning', 'info'];

    private const SCHEMA_FIELDS = ['CNAE', 'cnae', 'ramoAtividade', 'vinculoEmpresa', 'tipoVinculo', 'tpPessoa', 'empresaConstituida', 'dataFundacao', 'pretendentes', 'cpf', 'cnpj', 'pretendentePrincipal', 'estadoCivil', 'dataNascimento', 'participants', 'documentNumber', 'riskObjects', 'planKey', 'commissionedAgents', 'commissionPercentage', 'paymentConditions', 'paymentType', 'installments', 'periodoIndenitario', 'indiceDeReajusteAluguel', 'tipoPagamento', 'numeroProposta', 'numeroFicha'];

    public function extract(array $payload, array $sources = []): array
    {
        $secrets = [];
        foreach (array_merge([$payload], $sources) as $source) {
            if (is_array($source)) {
                $this->collectSensitiveValues($source, $secrets);
            }
        }
        foreach (['pottencial', 'too', 'tokio', 'porto'] as $provider) {
            $settings = config('services.'.$provider, []);
            if (is_array($settings)) {
                $this->collectSensitiveValues($settings, $secrets);
            }
        }
        usort($secrets, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $entries = [];
        $remaining = 500;
        $this->extractNodes($payload, $secrets, $entries, $remaining);

        return $entries;
    }

    private function extractNodes(array $payload, array $secrets, array &$entries, int &$remaining, string $path = '', int $depth = 0): void
    {
        if ($depth >= 10 || count($entries) >= 50 || $remaining-- <= 0) {
            return;
        }
        foreach (array_slice($payload, 0, 50, true) as $key => $value) {
            if (count($entries) >= 50 || $remaining <= 0) {
                break;
            }
            $source = ltrim($path.'.'.$this->text((string) $key, $secrets), '.');
            if (in_array($key, self::DIAGNOSTICS, true)) {
                $fieldErrors = in_array($key, ['errors', 'validationErrors'], true) && is_array($value) && ! array_is_list($value);
                $safe = $this->sanitizeNode($value, $secrets, $remaining, fieldErrors: $fieldErrors);
                if ($safe !== null && $safe !== [] && $safe !== '') {
                    $entries[] = ['source' => $source, 'data' => $safe];
                }
            } elseif ($key === 'response' && is_string($value)) {
                $entries[] = ['source' => $source, 'data' => $this->text($value, $secrets)];
            } elseif (is_array($value) && (in_array($key, self::WRAPPERS, true) || is_int($key) || $path === 'response.too' || $path === 'too')) {
                $this->extractNodes($value, $secrets, $entries, $remaining, $source, $depth + 1);
            }
        }
        $http = $payload['http_status'] ?? null;
        if (is_int($http) && $http >= 400 && $http <= 599 && count($entries) < 50) {
            $entries[] = ['source' => ltrim($path.'.http_status', '.'), 'data' => $http];
            if (is_string($payload['raw_body'] ?? null) && count($entries) < 50 && empty($payload['response'])) {
                $decoded = json_decode($payload['raw_body'], true);
                $message = is_string($decoded) ? $decoded : $payload['raw_body'];
                $entries[] = ['source' => ltrim($path.'.body_message', '.'), 'data' => $this->text($message, $secrets)];
            }
        }
    }

    private function sanitizeNode(mixed $value, array $secrets, int &$remaining, int $depth = 0, bool $fieldErrors = false): mixed
    {
        if ($depth >= 6 || $remaining-- <= 0) {
            return '[TRUNCATED]';
        }
        if (is_array($value)) {
            $safe = [];
            foreach (array_slice($value, 0, 30, true) as $key => $item) {
                if ($remaining <= 0) {
                    $safe['_truncated'] = true;
                    break;
                }
                if (in_array($key, self::OMIT, true)) {
                    continue;
                }
                if (! is_int($key) && ! $fieldErrors && ! in_array($key, self::ERROR_FIELDS, true) && ! in_array($key, self::DIAGNOSTICS, true)) {
                    continue;
                }
                $safeKey = is_int($key) ? count($safe) : $this->text((string) $key, $secrets);
                $safe[$safeKey] = ! $fieldErrors && $this->sensitiveKey((string) $key) && ! is_array($item)
                    ? '[REDACTED]'
                    : $this->sanitizeNode($item, $secrets, $remaining, $depth + 1);
            }

            return $safe;
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return $this->text((string) $value, $secrets);
        }

        return is_bool($value) ? $value : null;
    }

    private function collectSensitiveValues(array $payload, array &$values, int $depth = 0, bool $sensitive = false): void
    {
        if ($depth >= 12 || count($values) >= 1000) {
            return;
        }
        foreach (array_slice($payload, 0, 200, true) as $key => $value) {
            if (in_array($key, self::DIAGNOSTICS, true)) {
                continue;
            }
            $hide = $sensitive || $this->sensitiveKey((string) $key);
            if (is_array($value)) {
                $this->collectSensitiveValues($value, $values, $depth + 1, $hide);
            } elseif ($hide && is_scalar($value) && strlen(trim((string) $value)) >= 3) {
                $values[] = trim((string) $value);
                if (preg_match('/nome|name/i', (string) $key)) {
                    foreach (preg_split('/\s+/u', trim((string) $value)) ?: [] as $part) {
                        if (mb_strlen($part) >= 3) {
                            $values[] = $part;
                        }
                    }
                }
            }
        }
    }

    private function sensitiveKey(string $key): bool
    {
        return (bool) preg_match('/cpf|cnpj|document|nome|name|email|e_mail|telefone|phone|celular|mobile|nasc|birth|endere|address|street|logradouro|bairro|complemento|cep|zip|senha|password|secret|token|authorization|client_?id|clientid|codigo_corretor|codigoCorretor|codigoParceiro|cpf_usuario|cookie/i', $key);
    }

    private function text(string $value, array $secrets): string
    {
        if (preg_match('/<(?:!doctype|html|script|body)\b/i', $value)) {
            return '[HTML OMITTED]';
        }
        $value = mb_substr($value, 0, 8000);
        foreach ($secrets as $secret) {
            $value = str_ireplace($secret, '[REDACTED]', $value);
        }
        $patterns = [
            '~https?://[^\s<>"\']+~iu',
            '/\b(?:Bearer|Basic)\s+\S+/iu',
            '/\b(?:access[_-]?token|client[_-]?secret|senha(?:Operadora)?|password|authorization|token)\s*[=:]\s*["\']?[^\s,;"\']+/iu',
            '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.[\p{L}]{2,}/u',
            '/\b\d{2,4}[-\/.]\d{2}[-\/.]\d{2,4}\b/u',
            '/(?<!\w)\+?\d[\d\s().\/\-]{6,}\d(?!\w)/u',
            '/\b[A-Za-z0-9_+\/=.-]{32,}\b/u',
            '/\b(?:nome|name|razão social|endereço|address|logradouro)\s*[=:]\s*[^,;\r\n]+/iu',
        ];
        $value = preg_replace($patterns, '[REDACTED]', $value) ?? '[REDACTED]';
        $value = preg_replace_callback('/(["\'])(.*?)\1/us', static fn (array $match): string => in_array($match[2], self::SCHEMA_FIELDS, true) ? $match[0] : '[REDACTED]', $value) ?? '[REDACTED]';
        $value = preg_replace('/[\x00-\x1F\x7F\p{Cf}]+/u', ' ', strip_tags($value)) ?? '[REDACTED]';

        return mb_substr(trim($value), 0, 2000);
    }
}
