<?php

namespace App\Services\Flambient;

use App\Enums\ImageType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

class ExifService
{
    /**
     * Extract EXIF metadata from all JPG images in a directory.
     */
    public function extractMetadata(string $directory): Collection
    {
        $result = Process::run(
            "exiftool -q -csv -ext jpg -ext JPG " .
            "-Filename -DateTimeOriginal -MeteringMode -ShutterSpeed -ApertureValue -ISO -Flash# -WhiteBalance " .
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
                'iso' => $row['ISO'] ?? '',
                'flash' => (int)($row['Flash#'] ?? 16),
                'white_balance' => $row['WhiteBalance'] ?? '',
                'type' => $this->classifyImageType((int)($row['Flash#'] ?? 16)),
            ];
        }

        // Sort by datetime
        usort($data, function ($a, $b) {
            return strcmp($a['datetime_original'], $b['datetime_original']);
        });

        return collect($data);
    }

    /**
     * Classify image type based on Flash# EXIF field.
     */
    private function classifyImageType(int $flashValue): ImageType
    {
        return ImageType::fromFlashField($flashValue);
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
}
