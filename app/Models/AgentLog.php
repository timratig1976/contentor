<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent', 'provider', 'model', 'input', 'output',
        'status', 'tokens_used', 'duration_ms',
    ];

    protected $casts = [
        'tokens_used' => 'integer',
        'duration_ms' => 'integer',
    ];
}