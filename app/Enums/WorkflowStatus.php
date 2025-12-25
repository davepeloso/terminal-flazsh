<?php

namespace App\Enums;

enum WorkflowStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Paused = 'paused';

    /**
     * Check if the status is terminal (cannot progress further).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed]);
    }

    /**
     * Check if the workflow can be resumed from this status.
     */
    public function canResume(): bool
    {
        return in_array($this, [self::Failed, self::Paused]);
    }
}
