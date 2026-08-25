<?php

namespace App\Support;

use Illuminate\Support\Str;

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
            'leadlovers_key' => 'aprovados',
        ],

        self::REJECTED => [
            'label' => 'Recusado',
            'leadlovers_key' => 'ruim',
        ],

        self::IN_NEGOTIATION => [
            'label' => 'Em negociação',
            'leadlovers_key' => 'em_negociacao',
        ],

        self::RENTAL_CONFIRMED => [
            'label' => 'Fechado aluguel',
            'leadlovers_key' => 'fechado_aluguel',
        ],

        self::NO_RENT_OR_INSURANCE => [
            'label' => 'Não aluguei nem seguro',
            'leadlovers_key' => 'nao_aluguel_nem_seguro',
        ],
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

    public static function currentFromTags(iterable|string|null $tags): ?string
    {
        $currentResult = null;
        $currentPriority = 0;

        foreach (self::tagValues($tags) as $tag) {
            $result = self::resultFromNormalizedTag(
                self::normalizeTag($tag)
            );

            if ($result === null) {
                continue;
            }

            $priority = self::resultPriority($result);

            if ($priority > $currentPriority) {
                $currentResult = $result;
                $currentPriority = $priority;
            }
        }

        return $currentResult;
    }

    /**
     * @return iterable<mixed>
     */
    private static function tagValues(iterable|string|null $tags): iterable
    {
        if ($tags === null) {
            return [];
        }

        if (! is_string($tags)) {
            return $tags;
        }

        return preg_split('/\s*,\s*/u', $tags, -1, PREG_SPLIT_NO_EMPTY)
            ?: [];
    }

    private static function normalizeTag(mixed $tag): string
    {
        return Str::of((string) $tag)
            ->ascii()
            ->lower()
            ->replace(['_', '-'], ' ')
            ->squish()
            ->toString();
    }

    private static function resultFromNormalizedTag(string $tag): ?string
    {
        return match (true) {
            in_array($tag, [
                'fechado aluguel',
                'aluguel fechado',
                'fechado alguel',
            ], true) => self::RENTAL_CONFIRMED,

            in_array($tag, [
                'nao aluguei nem seguro',
                'nao aluguel nem seguro',
            ], true) => self::NO_RENT_OR_INSURANCE,

            $tag === 'em negociacao' => self::IN_NEGOTIATION,

            str_contains($tag, 'recusad')
                || str_contains($tag, 'reprovad')
                || $tag === 'ruim' => self::REJECTED,

            str_contains($tag, 'aprovad') => self::APPROVED,

            default => null,
        };
    }

    private static function resultPriority(string $result): int
    {
        return match ($result) {
            self::RENTAL_CONFIRMED => 5,
            self::NO_RENT_OR_INSURANCE => 4,
            self::IN_NEGOTIATION => 3,
            self::REJECTED => 2,
            self::APPROVED => 1,
            default => 0,
        };
    }
}
