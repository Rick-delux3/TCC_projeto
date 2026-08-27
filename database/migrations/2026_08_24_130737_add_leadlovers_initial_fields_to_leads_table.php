<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedSmallInteger('leadlovers_initial_error_status')
                ->nullable()
                ->after('leadlovers_response');

            $table->string('leadlovers_initial_error_code', 100)
                ->nullable()
                ->after('leadlovers_initial_error_status');

            $table->string('leadlovers_initial_error_operation', 64)
                ->nullable()
                ->after('leadlovers_initial_error_code');

            $table->text('leadlovers_initial_error_detail')
                ->nullable()
                ->after('leadlovers_initial_error_operation');

            $table->timestamp('leadlovers_initial_failed_at')
                ->nullable()
                ->after('leadlovers_initial_error_detail');

            $table->index(
                [
                    'leadlovers_status',
                    'leadlovers_initial_error_status',
                    'sent_to_leadlovers_at',
                ],
                'leads_ll_failure_filter_idx'
            );
        });

        DB::table('leads')
            ->select([
                'id',
                'leadlovers_response',
                'updated_at',
            ])->whereIn('leadlovers_status', [
                'failed',
                'tag_failed',
                'sequence_failed',
            ])
            ->whereNotNull('leadlovers_response')
            ->orderBy('id')
            ->chunkById(200, function ($leads): void {
                foreach ($leads as $lead) {
                    $response = json_decode(
                        (string) $lead->leadlovers_response,
                        true
                    );

                    if (! is_array($response)) {
                        continue;
                    }

                    $statusCode = $response['status_code'] ?? null;

                    $statusCode = is_numeric($statusCode)
                        ? (int) $statusCode
                        : null;

                    if (
                        $statusCode !== null
                        && ($statusCode < 100 || $statusCode > 599)
                    ) {
                        $statusCode = null;
                    }

                    $errorCode = $response['error_code'] ?? null;

                    if (is_string($errorCode)) {
                        $errorCode = mb_strtoupper(trim($errorCode));

                        if (
                            preg_match(
                                '/\A[A-Z0-9_.-]{1,100}\z/',
                                $errorCode
                            ) !== 1
                        ) {
                            $errorCode = null;
                        }
                    } else {
                        $errorCode = null;
                    }

                    $operation = $response['operation'] ?? null;

                    $operation = is_string($operation)
                        ? mb_strcut(
                            trim($operation),
                            0,
                            64,
                            'UTF-8'
                        )
                        : null;

                    $detail = $this->sanitizeHistoricalDetail(
                        $response['safe_reason'] ?? null
                    );

                    DB::table('leads')
                        ->where('id', $lead->id)
                        ->update([
                            'leadlovers_initial_error_status' => $statusCode,
                            'leadlovers_initial_error_code' => $errorCode,
                            'leadlovers_initial_error_operation' => $operation,
                            'leadlovers_initial_error_detail' => $detail,
                            'leadlovers_initial_failed_at' => $lead->updated_at
                                ?? now(),
                        ]);
                }
            });
    }

    private function sanitizeHistoricalDetail(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace(
            '/<script\b[^>]*>.*?<\/script>/is',
            ' ',
            html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ) ?? '';
        $value = strip_tags($value);
        $value = preg_replace(
            '/\b([A-Z0-9._%+\-]+)@([A-Z0-9.\-]+\.[A-Z]{2,})\b/iu',
            '[redacted-email]',
            $value
        ) ?? '';
        $value = preg_replace(
            '/(?<!\d)(?:\+?55[\s.\-]*)?(?:\(?\d{2}\)?[\s.\-]*)?9?\d{4}[\s.\-]*\d{4}(?!\d)/',
            '[redacted-phone]',
            $value
        ) ?? '';
        $value = preg_replace(
            '/\b(token|authorization|api[\s_-]*key)\s*[:=]\s*[^\s,;]+/iu',
            '$1=[redacted]',
            $value
        ) ?? '';
        $value = preg_replace(
            '/\b(?:sk|pk)-[A-Za-z0-9_-]{8,}\b/u',
            '[redacted-credential]',
            $value
        ) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === ''
            ? null
            : mb_strcut($value, 0, 1000, 'UTF-8');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_ll_failure_filter_idx');

            $table->dropColumn(
                [
                    'leadlovers_initial_error_status',
                    'leadlovers_initial_error_code',
                    'leadlovers_initial_error_operation',
                    'leadlovers_initial_error_detail',
                    'leadlovers_initial_failed_at',
                ]
            );
        });
    }
};
