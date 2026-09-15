<?php

namespace App\Services;

final class LeadLoversLeadResolver
{
    /**
     * @return array<string, mixed>
     */
    public function searchPayload(string $email): array
    {
        return [
            'page' => 1,
            'pageSize' => 10,
            'filters' => [
                'staticFields' => [
                    'email' => [$email],
                ],
            ],
        ];
    }

    /**
     * @return array{outcome: string, lead_id: int|null, total: int, records: int}
     */
    public function uniqueExactMatch(array $result, string $email): array
    {
        $records = is_array($result['records'] ?? null)
            ? $result['records']
            : [];
        $total = is_int($result['total'] ?? null)
            ? $result['total']
            : -1;
        $recordCount = count($records);
        $pagination = is_array($result['pagination'] ?? null)
            ? $result['pagination']
            : [];
        $summary = [
            'outcome' => 'ambiguous',
            'lead_id' => null,
            'total' => $total,
            'records' => $recordCount,
        ];

        if (
            ($pagination['current'] ?? null) !== 1
            || ($pagination['next'] ?? null) !== null
            || ($pagination['prev'] ?? null) !== null
            || ! is_int($pagination['pages'] ?? null)
            || $pagination['pages'] > 1
        ) {
            return $summary;
        }

        if ($total === 0 && $recordCount === 0) {
            $summary['outcome'] = 'missing';

            return $summary;
        }

        if ($total !== 1 || $recordCount !== 1) {
            return $summary;
        }

        $record = $records[0];

        if (! is_array($record)) {
            $summary['outcome'] = 'invalid_contract';

            return $summary;
        }

        $remoteEmail = $this->normalizedEmail($record['email'] ?? null);
        $expectedEmail = $this->normalizedEmail($email);

        if (
            $remoteEmail === null
            || $expectedEmail === null
            || ! hash_equals($expectedEmail, $remoteEmail)
        ) {
            $summary['outcome'] = 'email_mismatch';

            return $summary;
        }

        $leadId = $this->positiveInteger($record['leadId'] ?? null);

        if ($leadId === null) {
            $summary['outcome'] = 'missing_lead_id';

            return $summary;
        }

        $summary['outcome'] = 'matched';
        $summary['lead_id'] = $leadId;

        return $summary;
    }

    public function normalizedEmail(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $email = mb_strtolower(trim($value));

        return $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
                ? $email
                : null;
    }

    public function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A[1-9]\d*\z/', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }
}
