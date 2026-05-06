<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadLoversTag extends Model
{
    protected $fillable = [
        'leadlovers_tag_id',
        'title',
        'key',
        'active',
        'raw_payload',
    ];

    protected $casts = [
        'active' => 'boolean',
        'raw_payload' => 'array',
    ];
}