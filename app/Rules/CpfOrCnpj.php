<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CpfOrCnpj implements ValidationRule
{
    public static function normalize(mixed $value): mixed
    {
        return is_string($value) ? preg_replace('/[.\\/\\s-]+/', '', $value) : $value;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^(?:[0-9]{11}|[0-9]{14})$/D', $value) !== 1) {
            $fail('Informe um CPF com 11 dígitos ou CNPJ com 14 dígitos.');

            return;
        }

        $isCpf = strlen($value) === 11;
        $valid = preg_match('/^([0-9])\\1+$/D', $value) !== 1;

        for ($length = $isCpf ? 9 : 12; $valid && $length < strlen($value); $length++) {
            $sum = 0;

            for ($index = 0; $index < $length; $index++) {
                $weight = $isCpf ? $length + 1 - $index : (($length - 1 - $index) % 8) + 2;
                $sum += (int) $value[$index] * $weight;
            }

            $remainder = $sum % 11;
            $digit = $remainder < 2 ? 0 : 11 - $remainder;
            $valid = (int) $value[$length] === $digit;
        }

        if (! $valid) {
            $fail($isCpf ? 'O CPF informado é inválido.' : 'O CNPJ informado é inválido.');
        }
    }
}
