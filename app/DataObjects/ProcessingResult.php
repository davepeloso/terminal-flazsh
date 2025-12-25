<?php

namespace App\DataObjects;

class ProcessingResult
{
    public function __construct(
        public bool $success = true,
        public ?string $message = null,
        public array $data = [],
    ) {}

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->data,
        ];
    }
}
