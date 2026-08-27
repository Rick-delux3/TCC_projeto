<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class LeadRetentionEvent extends Model
{
    public const EVENT_SCHEDULED = 'scheduled';

    public const EVENT_CANCELLED = 'cancelled';

    public const EVENT_DELETED = 'deleted';

    public const EVENT_DEFERRED = 'deferred';

    public const EVENT_REVIEW_REQUIRED = 'review_required';

    protected $fillable = [
        'lead_id',
        'company_id',
        'leadlovers_lead_id',
        'event',
        'confirmed_tag_key',
        'operation_version',
        'confirmed_at',
        'deletion_due_at',
        'context',
    ];

    protected $casts = [
        'lead_id' => 'integer',
        'company_id' => 'integer',
        'leadlovers_lead_id' => 'integer',
        'operation_version' => 'integer',
        'confirmed_at' => 'datetime',
        'deletion_due_at' => 'datetime',
        'context' => 'array',
    ];
}
