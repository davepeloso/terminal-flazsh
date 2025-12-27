<?php

namespace App\DataObjects;

readonly class WorkflowConfig
{
    public function __construct(
        public string $projectName,
        public string $imageDirectory,
        public string $outputDirectory,
        public bool $processOnly,
        public ?string $apiKey,
        public int $profileKey,
        public string $levelLow,
        public string $levelHigh,
        public string $gamma,
        public string $outputPrefix,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            projectName: $data['project_name'],
            imageDirectory: $data['image_directory'],
            outputDirectory: $data['output_directory'],
            processOnly: $data['process_only'] ?? false,
            apiKey: $data['api_key'] ?? null,
            profileKey: $data['profile_key'] ?? 309406,
            levelLow: $data['level_low'] ?? '40%',
            levelHigh: $data['level_high'] ?? '140%',
            gamma: $data['gamma'] ?? '1.0',
            outputPrefix: $data['output_prefix'] ?? 'flambient',
        );
    }

    public function toArray(): array
    {
        return [
            'project_name' => $this->projectName,
            'image_directory' => $this->imageDirectory,
            'output_directory' => $this->outputDirectory,
            'process_only' => $this->processOnly,
            'api_key' => $this->apiKey ? '***REDACTED***' : null,
            'profile_key' => $this->profileKey,
            'level_low' => $this->levelLow,
            'level_high' => $this->levelHigh,
            'gamma' => $this->gamma,
            'output_prefix' => $this->outputPrefix,
        ];
    }
}
