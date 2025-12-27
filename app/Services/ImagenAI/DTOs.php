<?php

namespace App\Services\ImagenAI;

use Carbon\Carbon;

/**
 * Project created in Imagen AI.
 */
readonly class ImagenProject
{
    public function __construct(
        public string $uuid,
        public ?string $name,
        public Carbon $createdAt,
    ) {}
}

/**
 * Editing profile available in Imagen AI.
 */
readonly class ImagenProfile
{
    public function __construct(
        public string $key,
        public string $name,
        public ?string $profileType = null,
        public ?string $imageType = null,
        public ?string $photographyType = null,
    ) {}
}

/**
 * Temporary upload link for a file.
 */
readonly class ImagenUploadLink
{
    public function __construct(
        public string $filename,
        public string $uploadUrl,
    ) {}
}

/**
 * Result of uploading multiple files.
 */
readonly class ImagenUploadResult
{
    public function __construct(
        public string $projectUuid,
        public int $totalFiles,
        public array $succeeded,
        public array $failed,
    ) {}

    public function isFullySuccessful(): bool
    {
        return count($this->failed) === 0;
    }

    public function getSuccessRate(): float
    {
        if ($this->totalFiles === 0) {
            return 0.0;
        }
        return (count($this->succeeded) / $this->totalFiles) * 100;
    }
}

/**
 * Response from starting an edit.
 */
readonly class ImagenEditResponse
{
    public function __construct(
        public string $projectUuid,
        public string $status,
        public string $message,
    ) {}
}

/**
 * Current edit status.
 */
readonly class ImagenEditStatus
{
    public function __construct(
        public string $status,
        public int $progress,
        public ?string $message,
        public bool $isComplete,
        public bool $isFailed,
    ) {}
}

/**
 * Response from exporting a project.
 */
readonly class ImagenExportResponse
{
    public function __construct(
        public string $projectUuid,
        public string $status,
        public string $message,
    ) {}
}

/**
 * Download link for an edited file.
 */
readonly class ImagenDownloadLink
{
    public function __construct(
        public string $filename,
        public string $downloadUrl,
        public string $fileType, // 'xmp' or 'jpeg'
    ) {}
}

/**
 * Result of downloading multiple files.
 */
readonly class ImagenDownloadResult
{
    public function __construct(
        public int $totalFiles,
        public array $succeeded, // Array of file paths
        public array $failed,     // Array of filenames
    ) {}

    public function isFullySuccessful(): bool
    {
        return count($this->failed) === 0;
    }

    public function getSuccessRate(): float
    {
        if ($this->totalFiles === 0) {
            return 0.0;
        }
        return (count($this->succeeded) / $this->totalFiles) * 100;
    }
}

/**
 * Complete workflow result.
 */
readonly class ImagenWorkflowResult
{
    public function __construct(
        public string $projectUuid,
        public ImagenUploadResult $uploadResult,
        public ImagenEditStatus $editStatus,
        public ImagenDownloadResult $downloadResult,
    ) {}

    public function isFullySuccessful(): bool
    {
        return $this->uploadResult->isFullySuccessful()
            && $this->editStatus->isComplete
            && $this->downloadResult->isFullySuccessful();
    }
}

/**
 * Edit options for Imagen AI.
 */
readonly class ImagenEditOptions
{
    public function __construct(
        public bool $crop = false,
        public bool $windowPull = true,
        public bool $perspectiveCorrection = false,
        public bool $hdrMerge = false,
        public ?ImagenPhotographyType $photographyType = null,
    ) {}
}

/**
 * Photography type optimization.
 */
enum ImagenPhotographyType: string
{
    case REAL_ESTATE = 'REAL_ESTATE';
    case WEDDING = 'WEDDING';
    case PORTRAIT = 'PORTRAIT';
    case PRODUCT = 'PRODUCT';
    case LANDSCAPE = 'LANDSCAPE';
    case EVENT = 'EVENT';

    public function label(): string
    {
        return match($this) {
            self::REAL_ESTATE => 'Real Estate',
            self::WEDDING => 'Wedding',
            self::PORTRAIT => 'Portrait',
            self::PRODUCT => 'Product',
            self::LANDSCAPE => 'Landscape',
            self::EVENT => 'Event',
        };
    }
}
