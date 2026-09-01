<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringEvent extends Model
{
    protected $table = 'monitoring_events';

    protected $fillable = [
        'type', 'status', 'url', 'query', 'provider', 'model',
        'credits', 'input', 'payload', 'duration_ms', 'error',
    ];

    protected $casts = [
        'input' => 'array',
        'payload' => 'array',
        'credits' => 'float',
        'duration_ms' => 'integer',
    ];
}
