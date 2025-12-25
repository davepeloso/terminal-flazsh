<?php

namespace App\Services\Flambient;

use App\Enums\ImageClassificationStrategy;
use App\Enums\ImageType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

class ExifService
{
    public function __construct(
        private ImageClassificationStrategy $strategy = ImageClassificationStrategy::Flash,
        private mixed $ambientValue = 16,
        private ?string $customField = null,
    ) {}

    /**
     * Extract EXIF metadata from all JPG images in a directory.
     */
    public function extractMetadata(string $directory): Collection
    {
        $result = Process::run(
            "exiftool -q -csv -ext jpg -ext JPG " .
            "-Filename -DateTimeOriginal -MeteringMode -ShutterSpeed -ApertureValue " .
            "-ISO -Flash# -WhiteBalance -ExposureProgram -ExposureMode -FNumber " .
            ($this->customField ? "-{$this->customField} " : "") .
            "\"{$directory}\""
        );

        if (!$result->successful()) {
            throw new \RuntimeException("exiftool failed: " . $result->errorOutput());
        }

        return $this->parseExifCsv($result->output());
    }

    /**
     * Parse exiftool CSV output.
     */
    private function parseExifCsv(string $csv): Collection
    {
        $lines = explode("\n", trim($csv));
        if (count($lines) < 2) {
            return collect([]);
        }

        $headers = str_getcsv(array_shift($lines));
        $data = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $values = str_getcsv($line);
            $row = array_combine($headers, $values);

            $data[] = [
                'source_file' => $row['SourceFile'] ?? '',
                'filename' => basename($row['SourceFile'] ?? ''),
                'datetime_original' => $row['DateTimeOriginal'] ?? '',
                'metering_mode' => $row['MeteringMode'] ?? '',
                'shutter_speed' => $row['ShutterSpeed'] ?? '',
                'aperture' => $row['ApertureValue'] ?? '',
                'f_number' => $row['FNumber'] ?? '',
                'iso' => $row['ISO'] ?? '',
                'flash' => $row['Flash#'] ?? '',
                'white_balance' => $row['WhiteBalance'] ?? '',
                'exposure_program' => $row['ExposureProgram'] ?? '',
                'exposure_mode' => $row['ExposureMode'] ?? '',
                'custom_field' => $this->customField ? ($row[$this->customField] ?? '') : '',
                'type' => $this->classifyImageType($row),
            ];
        }

        // Sort by datetime
        usort($data, function ($a, $b) {
            return strcmp($a['datetime_original'], $b['datetime_original']);
        });

        return collect($data);
    }

    /**
     * Classify image type based on selected strategy.
     */
    private function classifyImageType(array $exifData): ImageType
    {
        $fieldValue = match($this->strategy) {
            ImageClassificationStrategy::Flash => (int)($exifData['Flash#'] ?? 16),
            ImageClassificationStrategy::ExposureProgram => (int)($exifData['ExposureProgram'] ?? 0),
            ImageClassificationStrategy::ExposureMode => (int)($exifData['ExposureMode'] ?? 0),
            ImageClassificationStrategy::WhiteBalance => (int)($exifData['WhiteBalance'] ?? 0),
            ImageClassificationStrategy::ISO => (int)($exifData['ISO'] ?? 0),
            ImageClassificationStrategy::ShutterSpeed => $exifData['ShutterSpeed'] ?? '',
            ImageClassificationStrategy::Custom => $this->customField ? ($exifData[$this->customField] ?? '') : '',
        };

        // Compare field value with ambient value
        // If they match, it's ambient; otherwise it's flash
        return $fieldValue == $this->ambientValue ? ImageType::Ambient : ImageType::Flash;
    }

    /**
     * Group images by consecutive ambient/flash sequences.
     *
     * Logic from original AWK script:
     * - Consecutive ambient images form a group
     * - Flash images following ambient append to that group
     * - New ambient sequence starts a new group
     */
    public function groupImages(Collection $metadata): array
    {
        $groups = [];
        $currentGroup = 0;
        $lastType = null;

        foreach ($metadata as $image) {
            $currentType = $image['type'];

            // Start new group if:
            // 1. This is the first image
            // 2. We transitioned from Flash to Ambient
            if ($lastType === null || ($lastType === ImageType::Flash && $currentType === ImageType::Ambient)) {
                $currentGroup++;
                $groups[$currentGroup] = [
                    'ambient' => [],
                    'flash' => [],
                ];
            }

            // Add image to current group
            if ($currentType === ImageType::Ambient) {
                $groups[$currentGroup]['ambient'][] = $image['filename'];
            } else {
                $groups[$currentGroup]['flash'][] = $image['filename'];
            }

            $lastType = $currentType;
        }

        return $groups;
    }

    /**
     * Get group statistics.
     */
    public function getGroupStatistics(array $groups): array
    {
        $stats = [
            'total_groups' => count($groups),
            'total_ambient' => 0,
            'total_flash' => 0,
            'groups_with_both' => 0,
            'groups_ambient_only' => 0,
            'groups_flash_only' => 0,
        ];

        foreach ($groups as $group) {
            $ambientCount = count($group['ambient']);
            $flashCount = count($group['flash']);

            $stats['total_ambient'] += $ambientCount;
            $stats['total_flash'] += $flashCount;

            if ($ambientCount > 0 && $flashCount > 0) {
                $stats['groups_with_both']++;
            } elseif ($ambientCount > 0) {
                $stats['groups_ambient_only']++;
            } else {
                $stats['groups_flash_only']++;
            }
        }

        return $stats;
    }

    /**
     * Display sample EXIF values to help user choose classification strategy.
     */
    public function getSampleExifValues(string $directory, int $sampleSize = 5): array
    {
        $metadata = $this->extractMetadata($directory);
        $sample = $metadata->take($sampleSize);

        return $sample->map(fn($img) => [
            'filename' => $img['filename'],
            'flash' => $img['flash'],
            'exposure_program' => $img['exposure_program'],
            'exposure_mode' => $img['exposure_mode'],
            'white_balance' => $img['white_balance'],
            'iso' => $img['iso'],
            'shutter_speed' => $img['shutter_speed'],
        ])->toArray();
    }
}
