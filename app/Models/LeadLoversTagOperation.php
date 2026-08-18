<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class LeadLoversTagOperation extends Model
{
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

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
