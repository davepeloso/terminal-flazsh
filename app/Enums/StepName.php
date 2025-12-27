<?php

namespace App\Enums;

enum StepName: string
{
    case Prepare = 'prepare';
    case Analyze = 'analyze';
    case Process = 'process';
    case Upload = 'upload';
    case Monitor = 'monitor';
    case Export = 'export';
    case Download = 'download';
    case Finalize = 'finalize';

    /**
     * Get all step names in execution order.
     */
    public static function inOrder(): array
    {
        return [
            self::Prepare,
            self::Analyze,
            self::Process,
            self::Upload,
            self::Monitor,
            self::Export,
            self::Download,
            self::Finalize,
        ];
    }

    /**
     * Check if this step requires cloud access.
     */
    public function requiresCloud(): bool
    {
        return in_array($this, [self::Upload, self::Monitor, self::Export, self::Download]);
    }

    /**
     * Get the next step in the workflow.
     */
    public function next(): ?self
    {
        $steps = self::inOrder();
        $currentIndex = array_search($this, $steps, true);

        return $currentIndex !== false && isset($steps[$currentIndex + 1])
            ? $steps[$currentIndex + 1]
            : null;
    }
}
