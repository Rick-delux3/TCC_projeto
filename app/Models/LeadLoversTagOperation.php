<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LeadLoversTagOperation extends Model
{
    private const MANUAL_PROCESSING_PHASES = [
        'pending',
        'posting',
        'confirming',
    ];

    protected $table = 'leadlovers_tag_operations';

    protected $guarded = [];

    protected $casts = [
        'version' => 'integer',
        'desired_request_log_id' => 'integer',
        'desired_corretor_id' => 'integer',
        'desired_batch_id' => 'integer',
        'desired_is_reanalysis' => 'boolean',
        'inflight_version' => 'integer',
        'inflight_request_log_id' => 'integer',
        'inflight_corretor_id' => 'integer',
        'inflight_batch_id' => 'integer',
        'inflight_is_reanalysis' => 'boolean',
        'action_id' => 'integer',
        'action_total' => 'integer',
        'outcome_uncertain' => 'boolean',
        'post_attempts' => 'integer',
        'confirmation_checks' => 'integer',
        'post_started_at' => 'datetime',
        'last_posted_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function desiredRequestLog(): BelongsTo
    {
        return $this->belongsTo(
            CorretorActivityLog::class,
            'desired_request_log_id'
        );
    }

    public function inflightRequestLog(): BelongsTo
    {
        return $this->belongsTo(
            CorretorActivityLog::class,
            'inflight_request_log_id'
        );
    }

    public function activeManualRequestLog(): ?CorretorActivityLog
    {
        if (! in_array($this->phase, self::MANUAL_PROCESSING_PHASES, true)) {
            return null;
        }

        $requests = [];

        if ($this->desired_source === 'manual') {
            $requests[] = [
                $this->desiredRequestLog,
                $this->desired_request_log_id,
                $this->desired_corretor_id,
            ];
        }

        if ($this->inflight_source === 'manual') {
            $requests[] = [
                $this->inflightRequestLog,
                $this->inflight_request_log_id,
                $this->inflight_corretor_id,
            ];
        }

        foreach ($requests as [$request, $requestId, $corretorId]) {
            if (
                $request instanceof CorretorActivityLog
                && $request->id === $requestId
                && $request->corretor_id === $corretorId
                && $request->action === 'lead_tag_update_requested'
                && $request->model_type === Lead::class
                && $request->model_id === $this->lead_id
            ) {
                return $request;
            }
        }

        return null;
    }
}
