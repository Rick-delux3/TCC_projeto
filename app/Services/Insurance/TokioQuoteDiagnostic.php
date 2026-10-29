<?php

namespace App\Services\Insurance;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use stdClass;
use Throwable;

class TokioQuoteDiagnostic
{
    public const ENDPOINT = 'https://apim-act.tokiomarine.com.br/massificados/cotador-fianca-service-w/api/cotar';

    public const APPROVED_CPFS = ['09238037698', '38329560856', '38566978889'];

    public function __construct(
        private TokioAuthentication $authentication,
        private InsuranceApiHttpResult $httpResult,
    ) {}

    public function run(string $scenario, int $cpfIndex = 1): array
    {
        $report = [
            'success' => false,
            'scenario' => $scenario,
            'endpoint' => self::ENDPOINT,
            'fallback_used' => false,
            'attempts' => [],
            'note' => 'Sucesso indica resposta HTTP 2xx, não aprovação. CPF não homologado pode retornar recusa ou encaminhamento para análise. Nenhum lead ou análise local é criado.',
        ];

        if (! in_array($scenario, ['aprovada', 'recusada', 'encaminhada'], true) || $cpfIndex < 1 || $cpfIndex > 3) {
            return array_replace($report, ['error' => 'Cenário ou índice de CPF inválido.']);
        }

        if (rtrim((string) config('services.tokio.base_url_w'), '/').'/cotar' !== self::ENDPOINT) {
            return array_replace($report, ['error' => 'TOKIO_BASE_URL_W deve apontar para o Aceite W documentado.']);
        }

        $primary = $this->headers('services.tokio');
        $support = $this->headers('services.tokio.support');
        $headers = $primary ?? $support;
        if ($headers === null) {
            return array_replace($report, ['error' => 'Configure código do corretor, TOKIO_CPF_USUÁRIO e senha operadora, ou o conjunto TOKIO_SUPORTE_*.']);
        }
        $report['fallback_used'] = $primary === null;

        $cpf = $scenario === 'aprovada' ? self::APPROVED_CPFS[$cpfIndex - 1] : $this->randomCpf();
        $payload = $this->payload($cpf);
        $report['request_body'] = $payload;
        $report['fixture'] = 'Exemplo PF residencial do suporte: assistência Completa, 12 meses, pintura interna/externa e aluguel 400. Não altera os padrões da integração futura.';

        $authentication = $this->authentication->requestToken();
        $report['authentication'] = [
            'success' => $authentication['success'],
            'http_status' => $authentication['http_status'],
            'message' => $authentication['message'],
        ];
        if (! $authentication['success']) {
            return array_replace($report, ['error' => 'Falha ao obter novo token OAuth. O cURL do suporte contém um Bearer antigo, não credenciais OAuth alternativas; ele não será reutilizado.']);
        }

        $secrets = array_values(array_filter([
            $authentication['access_token'],
            config('services.tokio.client_secret'),
            config('services.tokio.client_id'),
            config('services.tokio.scope'),
            $primary['senhaOperadora'] ?? null,
            $primary['cpfUsuario'] ?? null,
            $support['senhaOperadora'] ?? null,
            $support['cpfUsuario'] ?? null,
        ], fn ($value): bool => is_string($value) && $value !== ''));

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $source = $report['fallback_used'] ? 'suporte' : 'env';
            try {
                $response = Http::acceptJson()->withToken($authentication['access_token'])
                    ->withHeaders($headers)->connectTimeout(10)->timeout(60)->withoutRedirecting()
                    ->post(self::ENDPOINT, $payload);

                try {
                    $body = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
                    $format = 'json';
                } catch (JsonException) {
                    $body = $response->body();
                    $format = 'text';
                }

                $report['attempts'][] = [
                    'credentials_source' => $source,
                    'http_status' => $response->status(),
                    'http_description' => $this->httpResult->description($response->status()),
                    'response_format' => $format,
                    'response_body' => $this->redact($body, $secrets),
                ];

                if ($attempt === 0 && in_array($response->status(), [401, 403], true)
                    && ! $report['fallback_used'] && $support !== null && $support !== $headers) {
                    $headers = $support;
                    $report['fallback_used'] = true;

                    continue;
                }

                $report['success'] = $response->successful();

                return $report;
            } catch (ConnectionException) {
                $report['attempts'][] = ['credentials_source' => $source, 'http_status' => null];

                return array_replace($report, ['error' => 'Falha de conexão, DNS, TLS ou timeout. Resultado remoto desconhecido; a cotação não foi repetida.']);
            } catch (Throwable) {
                return array_replace($report, ['error' => 'Falha ao processar o diagnóstico. Resultado remoto não confirmado; a cotação não foi repetida.']);
            }
        }

        return $report;
    }

    /** @return array{codigoCorretor: string, cpfUsuario: string, senhaOperadora: string}|null */
    private function headers(string $prefix): ?array
    {
        $headers = [];
        foreach (['codigo_corretor' => 'codigoCorretor', 'cpf_usuario' => 'cpfUsuario', 'senha_operadora' => 'senhaOperadora'] as $key => $header) {
            $value = config("{$prefix}.{$key}");
            if (! is_string($value) || trim($value) === '' || preg_match('/[\r\n]/', $value)) {
                return null;
            }
            $headers[$header] = trim($value);
        }
        $headers['cpfUsuario'] = preg_replace('/[.\-\s]/', '', $headers['cpfUsuario']);
        if (! ctype_digit($headers['codigoCorretor']) || ! preg_match('/^\d{11}$/D', $headers['cpfUsuario'])) {
            return null;
        }

        return $headers;
    }

    private function payload(string $cpf): array
    {
        $start = CarbonImmutable::today('America/Sao_Paulo');
        $end = $start->addYearNoOverflow();

        return [
            'tpPessoa' => 'F',
            'icResidencial' => 'S',
            'dadosItem' => ['tipoImovel' => 10000, 'motivoContratacao' => 10008],
            'dadosLocacao' => [
                'cep' => '04247002',
                'endereco' => 'AV S JOAO',
                'numero' => '1459',
                'bairro' => 'STA CECILIA',
                'inicioVigenciaContrato' => $start->format('d/m/Y'),
                'fimVigenciaContrato' => $end->format('d/m/Y'),
                'inicioVigenciaSeguro' => $start->format('d/m/Y'),
                'fimVigenciaSeguro' => $end->format('d/m/Y'),
                'assistenciaDesejada' => '26206',
                'pinturaNova' => 'S',
            ],
            'pretendentes' => [['cpf' => $cpf, 'pretendentePrincipal' => 'S', 'nome' => 'PRETENDENTE TESTE HOMOLOGACAO']],
            'coberturas' => ['vlAluguel' => 400.0, 'icPinturaInterna' => 'S', 'icPinturaExterna' => 'S'],
            'pcComissao' => 15,
            'pcRemuneracao' => 0,
            'pcDesconto' => 0,
            'pcAgravo' => 0,
        ];
    }

    private function randomCpf(): string
    {
        do {
            $cpf = str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
            for ($length = 9; $length <= 10; $length++) {
                $sum = 0;
                for ($index = 0; $index < $length; $index++) {
                    $sum += (int) $cpf[$index] * ($length + 1 - $index);
                }
                $remainder = $sum % 11;
                $cpf .= $remainder < 2 ? '0' : (string) (11 - $remainder);
            }
        } while (preg_match('/^(\d)\1{10}$/', $cpf) || in_array($cpf, self::APPROVED_CPFS, true));

        return $cpf;
    }

    private function redact(mixed $value, array $secrets): mixed
    {
        if (is_array($value) || $value instanceof stdClass) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = preg_match('/token|secret|senha|authorization|cpfusuario/i', (string) $key)
                    ? '[OCULTO]'
                    : $this->redact($item, $secrets);
            }

            return $value instanceof stdClass ? (object) $result : $result;
        }

        return is_string($value) ? str_replace($secrets, '[OCULTO]', $value) : $value;
    }
}
