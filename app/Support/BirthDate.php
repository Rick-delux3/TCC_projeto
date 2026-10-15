<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class BirthDate
{
    public static function normalize(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    public static function earliest(): CarbonImmutable
    {
        return self::today()->subYearsNoOverflow((int) config('simulation.birth_date.maximum_age') + 1)->addDay();
    }

    public static function latest(): CarbonImmutable
    {
        return self::today()->subYearsNoOverflow((int) config('simulation.birth_date.minimum_age'));
    }

    /** @return list<string> */
    public static function rules(): array
    {
        return [
            'bail',
            'required',
            'string',
            'date_format:Y-m-d',
            'before_or_equal:'.self::today()->toDateString(),
            'before:'.self::latest()->addDay()->toDateString(),
            'after_or_equal:'.self::earliest()->toDateString(),
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'data_nascimento.required' => 'Informe a data de nascimento.',
            'data_nascimento.string' => 'Informe uma data de nascimento válida.',
            'data_nascimento.date_format' => 'Informe uma data de nascimento válida, com dia, mês e ano de quatro dígitos.',
            'data_nascimento.before_or_equal' => 'A data de nascimento não pode estar no futuro.',
            'data_nascimento.before' => 'É necessário ter pelo menos '.config('simulation.birth_date.minimum_age').' anos completos para solicitar a análise.',
            'data_nascimento.after_or_equal' => 'A idade informada não pode ultrapassar '.config('simulation.birth_date.maximum_age').' anos.',
        ];
    }

    private static function today(): CarbonImmutable
    {
        return CarbonImmutable::today(config('simulation.birth_date.timezone'));
    }
}
