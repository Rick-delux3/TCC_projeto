<?php

namespace App\Support;

final class ManualLeadResultTags
{


    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const RENTAL_CONFIRMED = 'rental_confirmed';
    public const NO_RENT_OR_INSURANCE = 'no_rent_or_insurance';
    public const IN_NEGOTIATION = 'in_negotiation';

    private const DEFINITIONS = [
        self::APPROVED => [
            'label' => 'Aprovado',
            'leadlovers_key' => 'aprovados'
        ],

        self::REJECTED => [
            'label' => 'Recusado',
            'leadlovers_key' => 'ruim'
        ],

        self::IN_NEGOTIATION => [
            'label' => 'Em negociação',
            'leadlovers_key' => 'em_negociacao',
        ],

        self::RENTAL_CONFIRMED => [
            'label' => 'Fechado aluguel',
            'leadlovers_key' => 'fechado_aluguel'
        ],

        self::NO_RENT_OR_INSURANCE => [
            'label' => 'Não aluguei nem seguro',
            'leadlovers_key' => 'nao_aluguel_nem_seguro'
        ]
    ];


    public static function all(): array
    {
        return self::DEFINITIONS;
    }

    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function leadloversKeys(): array
    {
        return array_values(
            array_column(self::DEFINITIONS, 'leadlovers_key')
        );
    }

    public static function definition(string $result): ?array
    {
        return self::DEFINITIONS[$result] ?? null;
    }

    public static function label(string $result): ?string
    {
        return self::definition($result)['label'] ?? null;
    }

    public static function leadloversKey(string $result): ?string
    {
        return self::definition($result)['leadlovers_key'] ?? null;
    }





}
