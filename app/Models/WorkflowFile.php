<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowFile extends Model
{
    protected $fillable = [
        'workflow_run_id',
        'original_path',
        'processed_path',
        'file_type',
        'group_number',
        'exif_data',
        'processed_at',
        'uploaded_at',
    ];

    protected $casts = [
        'exif_data' => 'array',
        'group_number' => 'integer',
        'processed_at' => 'datetime',
        'uploaded_at' => 'datetime',
    ];

    /**
     * Get the workflow run that owns this file.
     */
    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    /**
     * Check if the file has been processed.
     */
    public function isProcessed(): bool
    {
        return !is_null($this->processed_at);
    }

    /**
     * Check if the file has been uploaded.
     */
    public function isUploaded(): bool
    {
        return !is_null($this->uploaded_at);
    }
}
