<?php

namespace App\Services\Flambient;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class ImageMagickService
{
    public function __construct(
        private readonly string $binary = 'magick',
        private readonly string $levelLow = '40%',
        private readonly string $levelHigh = '140%',
        private readonly string $gamma = '1.0',
        private readonly string $outputPrefix = 'flambient',
        private readonly bool $enableDarkenExport = true,
        private readonly string $darkenSuffix = '_tmp',
    ) {}

    /**
     * Generate .mgk scripts for all groups.
     */
    public function generateScripts(
        array $groups,
        string $imageDirectory,
        string $scriptsDirectory,
        string $flambientDirectory
    ): array {
        File::ensureDirectoryExists($scriptsDirectory);
        File::ensureDirectoryExists($flambientDirectory);

        $generatedScripts = [];

        foreach ($groups as $groupNumber => $group) {
            $scriptPath = $this->generateGroupScript(
                $groupNumber,
                $group['ambient'] ?? [],
                $group['flash'] ?? [],
                $imageDirectory,
                $scriptsDirectory,
                $flambientDirectory
            );

            if ($scriptPath) {
                $generatedScripts[] = $scriptPath;
            }
        }

        // Generate master run_all_scripts.sh
        if (!empty($generatedScripts)) {
            $this->generateMasterScript($scriptsDirectory, $generatedScripts);
        }

        return $generatedScripts;
    }

    /**
     * Generate a single .mgk script for a group.
     */
    private function generateGroupScript(
        int $groupNumber,
        array $ambientFiles,
        array $flashFiles,
        string $imageDirectory,
        string $scriptsDirectory,
        string $flambientDirectory
    ): ?string {
        $paddedGroup = str_pad($groupNumber, 2, '0', STR_PAD_LEFT);
        $scriptPath = "{$scriptsDirectory}/group_{$paddedGroup}_script.mgk";

        $script = [];
        $script[] = "# ImageMagick script for Group {$paddedGroup}";
        $script[] = "# Ambient files: " . (!empty($ambientFiles) ? implode(', ', $ambientFiles) : 'None');
        $script[] = "# Flash files:   " . (!empty($flashFiles) ? implode(', ', $flashFiles) : 'None');
        $script[] = "";

        $numAmbient = count($ambientFiles);
        $numFlash = count($flashFiles);

        // 1. Create Ambient Merge
        if ($numAmbient > 0) {
            $cmd = $this->buildMergeCommand($ambientFiles, $imageDirectory);
            $script[] = "{$cmd} -write mpr:ambient_merge +delete";
        } else {
            $script[] = "# No ambient images for Group {$paddedGroup}";
        }

        // 2. Create Flash Merge
        if ($numFlash > 0) {
            $cmd = $this->buildMergeCommand($flashFiles, $imageDirectory);
            $script[] = "{$cmd} -write mpr:flash_merge +delete";

            // Optional: Dark Export
            if ($this->enableDarkenExport) {
                $script[] = "";
                $script[] = "# --- Dark Export: Create darkened flash composite ---";
                $darkenCmd = $this->buildDarkenCommand($flashFiles, $imageDirectory);
                $darkOutput = "{$flambientDirectory}/{$this->outputPrefix}_{$paddedGroup}{$this->darkenSuffix}.jpg";
                $script[] = "{$darkenCmd} -write \"{$darkOutput}\" +delete";
                $script[] = "# Darkened flash composite saved to: {$darkOutput}";
            }
        } else {
            $script[] = "# No flash images for Group {$paddedGroup}";
        }

        // 3. Flambient Blending (only if both ambient and flash exist)
        if ($numAmbient > 0 && $numFlash > 0) {
            $script[] = "";
            $script[] = "# --- Flambient Blending Steps ---";

            // Step 1: Create ambient mask alpha
            $script[] = "mpr:ambient_merge -channel B -level {$this->levelLow},{$this->levelHigh},{$this->gamma} +channel -write mpr:ambient_mask_alpha +delete";

            // Step 2: Apply mask to flash
            $script[] = "mpr:flash_merge mpr:ambient_mask_alpha -compose CopyOpacity -composite -write mpr:flash_mask +delete";

            // Step 3: Luminize blend
            $script[] = "mpr:flash_merge mpr:ambient_merge -compose Luminize -composite -write mpr:luminize_flambient +delete";

            // Step 4: Over composite
            $script[] = "mpr:luminize_flambient mpr:flash_mask -compose Over -composite -write mpr:ungraded_flambient +delete";

            // Step 5: Final colorize and output
            $outputPath = "{$flambientDirectory}/{$this->outputPrefix}_{$paddedGroup}.jpg";
            $script[] = "mpr:ungraded_flambient mpr:flash_merge -compose Colorize -composite -write \"{$outputPath}\"";
            $script[] = "# Final output path: {$outputPath}";
        } else {
            $script[] = "";
            $script[] = "# Warning: Group {$paddedGroup} lacks either ambient or flash images. Skipping blend operations.";
        }

        File::put($scriptPath, implode("\n", $script));

        return $scriptPath;
    }

    /**
     * Build merge command for lighten composite.
     */
    private function buildMergeCommand(array $files, string $imageDirectory): string
    {
        $parts = [];
        foreach ($files as $index => $file) {
            $filepath = "\"{$imageDirectory}/{$file}\"";
            if ($index === 0) {
                $parts[] = $filepath;
            } else {
                $parts[] = "{$filepath} -compose lighten -composite";
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Build darken command for darkened export.
     */
    private function buildDarkenCommand(array $files, string $imageDirectory): string
    {
        $parts = [];
        foreach ($files as $index => $file) {
            $filepath = "\"{$imageDirectory}/{$file}\"";
            if ($index === 0) {
                $parts[] = $filepath;
            } else {
                $parts[] = "{$filepath} -compose darken -composite";
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Generate master run_all_scripts.sh.
     */
    private function generateMasterScript(string $scriptsDirectory, array $scriptPaths): void
    {
        $masterScript = [];
        $masterScript[] = "#!/bin/bash";
        $masterScript[] = "";
        $masterScript[] = "# Master script to run all generated ImageMagick group scripts.";
        $masterScript[] = "# Generated by Flambient Laravel Application";
        $masterScript[] = "";
        $masterScript[] = "echo \"Starting Flambient processing for all groups...\"";
        $masterScript[] = "";

        foreach ($scriptPaths as $index => $scriptPath) {
            $groupNum = $index + 1;
            $basename = basename($scriptPath);
            $masterScript[] = "echo \"Processing Group {$groupNum}...\"";
            $masterScript[] = "{$this->binary} -script \"{$scriptPath}\"";
            $masterScript[] = "if [ \$? -eq 0 ]; then";
            $masterScript[] = "    echo \"  ✓ Group {$groupNum} completed successfully.\"";
            $masterScript[] = "else";
            $masterScript[] = "    echo \"  ✗ Group {$groupNum} failed!\" >&2";
            $masterScript[] = "fi";
            $masterScript[] = "";
        }

        $masterScript[] = "echo \"All groups processed.\"";

        $masterScriptPath = "{$scriptsDirectory}/run_all_scripts.sh";
        File::put($masterScriptPath, implode("\n", $masterScript));
        chmod($masterScriptPath, 0755);
    }

    /**
     * Execute a single .mgk script.
     */
    public function executeScript(string $scriptPath): array
    {
        $result = Process::run("{$this->binary} -script \"{$scriptPath}\"");

        return [
            'success' => $result->successful(),
            'output' => $result->output(),
            'error' => $result->errorOutput(),
            'exit_code' => $result->exitCode(),
        ];
    }

    /**
     * Execute all scripts in a directory.
     */
    public function executeAllScripts(string $scriptsDirectory): array
    {
        $masterScript = "{$scriptsDirectory}/run_all_scripts.sh";

        if (!file_exists($masterScript)) {
            return [
                'success' => false,
                'error' => 'Master script not found',
                'results' => [],
            ];
        }

        // Set timeout to 30 minutes (1800 seconds) for large image sets
        $result = Process::timeout(1800)->run("bash \"{$masterScript}\"");

        return [
            'success' => $result->successful(),
            'output' => $result->output(),
            'error' => $result->errorOutput(),
            'exit_code' => $result->exitCode(),
        ];
    }
}
