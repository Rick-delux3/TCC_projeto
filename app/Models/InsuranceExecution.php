<?php

namespace App\Models;



use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


abstract class InsuranceExecution extends Model
{
    use HasFactory;

    public ?string $executionAttemptId = null;

    public function updateForAttempt(array $attributes): void
    {
        \App\Services\Insurance\InsuranceAnalysisAttempt::run(
            $this,
            $this->executionAttemptId ?? '',
            fn () => $this->forceFill($attributes)->save(),
        );
    }



    protected $fillable = [
        'insurance_analysis_batch_id',
        'lead_id',
        'company_id',
        'provider',
        'product',
        'status',
        'provider_status',
        'result',
        'quote_id',
        'quote_number',
        'proposal_id',
        'policy_id',
        'product_key',
        'rent_amount',
        'charges_amount',
        'total_monthly_amount',
        'premium_amount',
        'commercial_premium',
        'gross_premium',
        'iof',
        'insured_amount',
        'plan_key',
        'multiple',
        'lease_start_date',
        'lease_end_date',
        'inhabited',
        'available_plans',
        'available_assistances',
        'payment_type',
        'installments',
        'request_payload',
        'response_payload',
        'rejection_reason',
        'error_message',
        'quote_pdf_path',
        'requested_at',
        'finished_at',
        'pdf_generated_at',
        'email_sent_at',
    ];

    protected $casts = [
        'available_plans' => 'array',
        'available_assistances' => 'array',
        'request_payload' => 'array',
        'response_payload' => 'array',

        'rent_amount' => 'decimal:2',
        'charges_amount' => 'decimal:2',
        'total_monthly_amount' => 'decimal:2',
        'premium_amount' => 'decimal:2',
        'commercial_premium' => 'decimal:2',
        'gross_premium' => 'decimal:2',
        'iof' => 'decimal:2',
        'insured_amount' => 'decimal:2',

        'lease_start_date' => 'date',
        'lease_end_date' => 'date',

        'inhabited' => 'boolean',

        'requested_at' => 'datetime',
        'finished_at' => 'datetime',
        'pdf_generated_at' => 'datetime',
        'email_sent_at' => 'datetime',

    ];

    public function batch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->lote();
    }

    public function lote(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(InsuranceAnalysisBatch::class, 'insurance_analysis_batch_id');
    }

    public function lead(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->imobiliaria();
    }

    public function imobiliaria(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Imobiliaria::class, 'company_id');
    }

    public function isApprovedResult(): bool
    {
        return mb_strtolower(trim((string) $this->status)) === 'approved';
    }

    public function isRejectedResult(): bool
    {
        return in_array(mb_strtolower((string) $this->status),
            [
                'rejected',
                'denied',
                'refused',
            ], true);
    }

    public function isTooProvider(): bool
    {
        return mb_strtolower((string) $this->provider) === 'too';
    }

    public function tooNumeroProposta(): ?string
    {
        return $this->proposal_id
            ?? data_get($this->providerResponsePayload(), 'numeroProposta');
    }

    public function tooNumeroFicha(): ?string
    {
        return data_get($this->providerResponsePayload(), 'numeroFicha')
            ?? data_get($this->providerResponsePayload(), 'numeroProposta')
            ?? $this->proposal_id;
    }

    public function providerResponsePayload(): array
    {
        $payload = $this->response_payload;

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        return is_array($payload) ? $payload : [];
    }

    abstract public function events(): \Illuminate\Database\Eloquent\Relations\HasMany;

    abstract public function currentAttemptContext(bool $lock = false): ?array;
}
