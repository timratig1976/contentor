<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowRun extends Model
{
    protected $fillable = [
        'input', 'output', 'trace', 'status', 'duration_ms', 'exit_code',
    ];

    protected $casts = [
        'duration_ms' => 'integer',
        'exit_code' => 'integer',
    ];
}