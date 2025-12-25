<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStep extends Model
{
    protected $fillable = [
        'workflow_run_id',
        'step_name',
        'status',
        'input_data',
        'output_data',
        'metadata',
        'started_at',
        'completed_at',
        'duration_seconds',
        'error_message',
        'retry_count',
    ];

    protected $casts = [
        'input_data' => 'array',
        'output_data' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_seconds' => 'integer',
        'retry_count' => 'integer',
    ];

    /**
     * Get the workflow run that owns this step.
     */
    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    /**
     * Check if the step is complete.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the step failed.
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }
}
