<?php

namespace App\Services\Lookup;

use App\Rules\CpfOrCnpj;
use App\Support\BirthDate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CpfLookupService
{
    public function resolveBirthdates(array $data): array
    {
        if (! config('features.cpf_lookup.enabled')) {
            return ['data_nascimento' => $data['data_nascimento'], 'conjuge_data_nascimento' => $data['conjuge_data_nascimento'] ?? null];
        }

        $field = strlen((string) ($data['cpf'] ?? '')) === 14 ? 'cpf_responsavel' : 'cpf';
        $dates = ['data_nascimento' => $this->lookupBirthdate($data[$field] ?? '', $field), 'conjuge_data_nascimento' => null];
        if (filled($data['conjuge_cpf'] ?? null)) {
            $dates['conjuge_data_nascimento'] = $this->lookupBirthdate($data['conjuge_cpf'], 'conjuge_cpf');
        }

        return $dates;
    }

    public function lookupBirthdate(string $cpf, string $field = 'cpf'): string
    {
        if (! config('features.cpf_lookup.enabled')) {
            throw ValidationException::withMessages([$field => 'A consulta automática de CPF está desativada.']);
        }
        $cpf = CpfOrCnpj::normalize($cpf);
        if (Validator::make(['cpf' => $cpf], ['cpf' => ['required', 'string', 'size:11', new CpfOrCnpj]])->fails()) {
            throw ValidationException::withMessages([$field => 'Confira o CPF informado.']);
        }
        $secret = (string) config('app.key');
        $key = 'cpf-brasil:'.hash_hmac('sha256', $cpf, $secret);
        try {
            return Cache::lock($key.':lock', 20)->block(3, function () use ($key, $cpf, $field): string {
                $cached = Cache::get($key);
                if (is_string($cached)) {
                    $date = Crypt::decryptString($cached);
                    $this->validateDate($date, $field);

                    return $date;
                }
                $url = (string) config('services.cpf_lookup.cpf_brasil.api_url', 'https://api.cpf-brasil.org/cpf/');
                $token = (string) config('services.cpf_lookup.cpf_brasil.api_key');
                if ($token === '') {
                    $this->fail('MISSING_API_KEY', $field);
                }
                $response = Http::acceptJson()->withHeaders(['X-API-Key' => $token])
                    ->withoutRedirecting()
                    ->connectTimeout((int) config('services.cpf_lookup.cpf_brasil.connect_timeout', 3))
                    ->timeout((int) config('services.cpf_lookup.cpf_brasil.timeout', 10))
                    ->get($url.$cpf);
                $body = $response->json();
                if (! is_array($body) || ! $response->successful() || isset($body['error']) || ($body['success'] ?? null) !== true) {
                    $code = is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : 'INVALID_RESPONSE';
                    $this->fail($code, $field, $response->status());
                }
                if (! is_string(data_get($body, 'data.CPF')) || CpfOrCnpj::normalize($body['data']['CPF']) !== $cpf) {
                    $this->fail('IDENTITY_MISMATCH', $field);
                }
                $raw = data_get($body, 'data.NASC');
                $date = is_string($raw) ? \DateTimeImmutable::createFromFormat('!d/m/Y', $raw) : false;
                if (! $date || $date->format('d/m/Y') !== $raw) {
                    $this->fail('INVALID_BIRTHDATE', $field);
                }
                $normalized = $date->format('Y-m-d');
                $this->validateDate($normalized, $field);
                Cache::put($key, Crypt::encryptString($normalized), now()->addMinutes((int) config('services.cpf_lookup.cache_minutes', 1440)));

                return $normalized;
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            $this->fail('LOOKUP_UNAVAILABLE', $field);
        }
    }

    private function validateDate(string $date, string $field): void
    {
        $validator = Validator::make(['data_nascimento' => $date], ['data_nascimento' => BirthDate::rules()], BirthDate::messages());
        if ($validator->fails()) {
            throw ValidationException::withMessages([$field => $validator->errors()->first('data_nascimento')]);
        }
    }

    private function fail(string $code, string $field, ?int $status = null): never
    {
        $known = ['MISSING_API_KEY', 'INVALID_API_KEY', 'TOKEN_EXPIRED', 'PLAN_EXPIRED', 'PLAN_SUSPENDED', 'QUOTA_EXCEEDED', 'MISSING_CPF_PARAMETER', 'INVALID_CPF_FORMAT', 'CPF_NOT_FOUND', 'DATABASE_NOT_FOUND', 'DATABASE_QUERY_ERROR', 'DATABASE_ERROR', 'INVALID_RESPONSE', 'IDENTITY_MISMATCH', 'INVALID_BIRTHDATE', 'LOOKUP_UNAVAILABLE'];
        Log::warning('Consulta CPF Brasil não concluída.', ['code' => in_array($code, $known, true) ? $code : 'UNKNOWN_ERROR', 'http_status' => $status]);
        $message = match ($code) {
            'CPF_NOT_FOUND' => 'Não foi possível localizar os dados desse CPF. Confira o documento informado.',
            'INVALID_CPF_FORMAT' => 'Confira o CPF informado.',
            'INVALID_BIRTHDATE', 'IDENTITY_MISMATCH' => 'A consulta retornou dados inconsistentes. Não foi possível confirmar o nascimento.',
            default => 'Não foi possível consultar a data de nascimento no momento. Tente novamente mais tarde.',
        };
        throw ValidationException::withMessages([$field => $message]);
    }
}
