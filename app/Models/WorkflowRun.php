<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_name',
        'image_directory',
        'output_directory',
        'config',
        'status',
        'current_step',
        'imagen_project_uuid',
        'process_only',
        'started_at',
        'completed_at',
        'failed_at',
        'total_images_processed',
        'total_groups_created',
        'error_message',
        'error_trace',
    ];

    protected $casts = [
        'config' => 'array',
        'process_only' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'total_images_processed' => 'integer',
        'total_groups_created' => 'integer',
    ];

    /**
     * Get the steps for this workflow run.
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class);
    }

    /**
     * Get the files for this workflow run.
     */
    public function files(): HasMany
    {
        return $this->hasMany(WorkflowFile::class);
    }

    /**
     * Check if the workflow is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'failed']);
    }

    /**
     * Check if the workflow can be resumed.
     */
    public function canResume(): bool
    {
        return in_array($this->status, ['failed', 'paused']);
    }
}
