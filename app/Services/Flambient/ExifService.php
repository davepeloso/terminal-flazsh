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
     * Uses dual extraction: numeric (-n) for logic, pretty for UI labels.
     */
    public function extractMetadata(string $directory): Collection
    {
        // Extract numeric values for classification logic
        $numericResult = Process::run(
            "exiftool -n -q -csv -ext jpg -ext JPG " .
            "-Filename -DateTimeOriginal -MeteringMode -ShutterSpeed -ApertureValue " .
            "-ISO -Flash -WhiteBalance -ExposureProgram -ExposureMode -FNumber " .
            ($this->customField ? "-{$this->customField} " : "") .
            "\"{$directory}\""
        );

        if (!$numericResult->successful()) {
            throw new \RuntimeException("exiftool numeric extraction failed: " . $numericResult->errorOutput());
        }

        // Extract human-readable labels for display
        $prettyResult = Process::run(
            "exiftool -q -csv -ext jpg -ext JPG " .
            "-Filename -DateTimeOriginal -MeteringMode -ShutterSpeed -ApertureValue " .
            "-ISO -Flash -WhiteBalance -ExposureProgram -ExposureMode -FNumber " .
            ($this->customField ? "-{$this->customField} " : "") .
            "\"{$directory}\""
        );

        if (!$prettyResult->successful()) {
            throw new \RuntimeException("exiftool pretty extraction failed: " . $prettyResult->errorOutput());
        }

        return $this->parseExifCsv($numericResult->output(), $prettyResult->output());
    }

    /**
     * Parse exiftool CSV output - both numeric and pretty versions.
     * Merges numeric (for logic) with human-readable labels (for display).
     */
    private function parseExifCsv(string $numericCsv, string $prettyCsv): Collection
    {
        // Parse numeric CSV
        $numericLines = explode("\n", trim($numericCsv));
        if (count($numericLines) < 2) {
            return collect([]);
        }
        $numericHeaders = str_getcsv(array_shift($numericLines));

        // Parse pretty CSV
        $prettyLines = explode("\n", trim($prettyCsv));
        if (count($prettyLines) < 2) {
            return collect([]);
        }
        $prettyHeaders = str_getcsv(array_shift($prettyLines));

        $data = [];

        // Process each image (assumes both CSVs have same rows in same order)
        foreach ($numericLines as $index => $numericLine) {
            if (empty(trim($numericLine))) {
                continue;
            }

            $numericValues = str_getcsv($numericLine);
            $numericRow = array_combine($numericHeaders, $numericValues);

            // Get corresponding pretty line
            $prettyValues = isset($prettyLines[$index]) ? str_getcsv($prettyLines[$index]) : [];
            $prettyRow = !empty($prettyValues) ? array_combine($prettyHeaders, $prettyValues) : [];

            // Build merged data structure with both raw and label
            $data[] = [
                'source_file' => $numericRow['SourceFile'] ?? '',
                'filename' => basename($numericRow['SourceFile'] ?? ''),
                'datetime_original' => $numericRow['DateTimeOriginal'] ?? '',
                'metering_mode' => $numericRow['MeteringMode'] ?? '',
                'shutter_speed' => $numericRow['ShutterSpeed'] ?? '',
                'aperture' => $numericRow['ApertureValue'] ?? '',
                'f_number' => $numericRow['FNumber'] ?? '',
                'iso' => [
                    'raw' => (int)($numericRow['ISO'] ?? 0),
                    'label' => $prettyRow['ISO'] ?? (string)($numericRow['ISO'] ?? 0),
                ],
                'flash' => [
                    'raw' => (int)($numericRow['Flash'] ?? 16),
                    'label' => $prettyRow['Flash'] ?? (string)($numericRow['Flash'] ?? 16),
                ],
                'white_balance' => [
                    'raw' => (int)($numericRow['WhiteBalance'] ?? 0),
                    'label' => $prettyRow['WhiteBalance'] ?? (string)($numericRow['WhiteBalance'] ?? 0),
                ],
                'exposure_program' => [
                    'raw' => (int)($numericRow['ExposureProgram'] ?? 0),
                    'label' => $prettyRow['ExposureProgram'] ?? (string)($numericRow['ExposureProgram'] ?? 0),
                ],
                'exposure_mode' => [
                    'raw' => (int)($numericRow['ExposureMode'] ?? 0),
                    'label' => $prettyRow['ExposureMode'] ?? (string)($numericRow['ExposureMode'] ?? 0),
                ],
                'custom_field' => $this->customField ? ($numericRow[$this->customField] ?? '') : '',
                'type' => $this->classifyImageType($numericRow),
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
            ImageClassificationStrategy::Flash => (int)($exifData['Flash'] ?? 16),
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
     * Returns both raw numeric values and human-readable labels.
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
