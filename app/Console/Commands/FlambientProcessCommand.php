<?php

namespace App\Console\Commands;

use App\DataObjects\ProcessingResult;
use App\DataObjects\WorkflowConfig;
use App\Enums\ImageClassificationStrategy;
use App\Enums\WorkflowStatus;
use App\Models\WorkflowRun;
use App\Services\Flambient\ExifService;
use App\Services\Flambient\ImageMagickService;
use App\Services\ImagenAI\ImagenClient;
use App\Services\ImagenAI\ImagenEditOptions;
use App\Services\ImagenAI\ImagenException;
use App\Services\ImagenAI\ImagenPhotographyType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class FlambientProcessCommand extends Command
{
    protected $signature = 'flambient:process
                            {--project= : Project name}
                            {--dir= : Image directory}
                            {--local : Local-only mode (skip cloud processing)}';

    protected $description = 'Process flambient photography workflow with ImageMagick and optional Imagen AI enhancement';

    public function handle(): int
    {
        // Welcome Banner
        info('🎨 Flambient Photography Processor');
        note('This workflow will process flambient images using ImageMagick and optionally enhance them with Imagen AI.');
        $this->newLine();

        // ═══════════════════════════════════════════════════════
        // 1. PROJECT SETUP
        // ═══════════════════════════════════════════════════════

        $projectName = $this->option('project') ?: text(
            label: 'Project name',
            placeholder: 'client-property-address',
            required: true,
            validate: fn($value) => match(true) {
                strlen($value) < 3 => 'Project name must be at least 3 characters',
                !preg_match('/^[a-zA-Z0-9_-]+$/', $value) => 'Only letters, numbers, dashes, and underscores allowed',
                WorkflowRun::where('project_name', $value)->exists() => 'Project name already exists. Use a unique name.',
                default => null,
            }
        );

        $imageDirectory = $this->option('dir') ?: text(
            label: 'Image directory',
            placeholder: '/path/to/images',
            required: true,
            validate: function($value) {
                if (!is_dir($value)) {
                    return "Directory does not exist: {$value}";
                }

                $jpgCount = count(glob("{$value}/*.jpg")) + count(glob("{$value}/*.JPG"));
                if ($jpgCount === 0) {
                    return "No JPG files found in directory";
                }

                return null;
            },
            hint: 'Directory containing your ambient and flash images'
        );

        $processOnly = $this->option('local') ?: (select(
            label: 'Processing mode',
            options: [
                'full' => 'Full workflow (ImageMagick + Imagen AI cloud enhancement)',
                'local' => 'Local only (ImageMagick blending without cloud upload)',
            ],
            default: 'full',
            hint: 'Cloud processing requires API key and costs money'
        ) === 'local');

        // ═══════════════════════════════════════════════════════
        // 1.5. IMAGE CLASSIFICATION STRATEGY
        // ═══════════════════════════════════════════════════════

        $this->newLine();
        info('Image Classification Setup');
        note('Choose which EXIF field to use for distinguishing Ambient vs Flash images');

        // Show sample EXIF values to help user decide
        if (confirm('Show sample EXIF values from your images?', default: true)) {
            $sampleCount = (int)select(
                label: 'How many sample images to display?',
                options: [
                    '3' => '3 samples',
                    '5' => '5 samples',
                    '10' => '10 samples',
                    '25' => '25 samples',
                    '50' => '50 samples',
                    '75' => '75 samples',
                    '100' => '100 samples',
                ],
                default: '3',
                hint: 'More samples help identify patterns in your EXIF data'
            );

            $tempExif = new ExifService();
            $samples = $tempExif->getSampleExifValues($imageDirectory, $sampleCount);

            $this->newLine();
            $this->table(
                ['Filename', 'Flash', 'ExposureProgram', 'ExposureMode', 'WhiteBalance', 'ISO', 'Shutter'],
                array_map(fn($s) => [
                    $s['filename'],
                    $this->formatExifValue($s['flash']),
                    $this->formatExifValue($s['exposure_program']),
                    $this->formatExifValue($s['exposure_mode']),
                    $this->formatExifValue($s['white_balance']),
                    $this->formatExifValue($s['iso']),
                    $s['shutter_speed'], // Shutter speed is not an enum, keep as-is
                ], $samples)
            );
            $this->newLine();
        }

        $strategy = ImageClassificationStrategy::from(select(
            label: 'Which EXIF field should identify Ambient images?',
            options: [
                'flash' => 'Flash# (Flash = 16 for Ambient)',
                'exposure_program' => 'Exposure Program',
                'exposure_mode' => 'Exposure Mode',
                'white_balance' => 'White Balance',
                'iso' => 'ISO Value',
                'shutter_speed' => 'Shutter Speed',
            ],
            default: 'flash',
            hint: 'Choose the field that differs between your ambient and flash shots'
        ));

        $ambientValue = text(
            label: "What {$strategy->label()} value indicates an AMBIENT image?",
            default: (string)$strategy->commonAmbientValue(),
            required: true,
            hint: $strategy->helpText()
        );

        note("Classification: {$strategy->label()} = '{$ambientValue}' → Ambient, others → Flash");

        // ═══════════════════════════════════════════════════════
        // 2. CONFIGURATION
        // ═══════════════════════════════════════════════════════

        $apiKey = null;
        if (!$processOnly) {
            $apiKey = config('flambient.imagen.api_key');

            if (!$apiKey) {
                warning('No API key configured in .env file.');

                if (confirm('Switch to local-only mode?', default: true)) {
                    $processOnly = true;
                } else {
                    return self::FAILURE;
                }
            }
        }

        // ImageMagick parameters
        $levelLow = '40%';
        $levelHigh = '140%';
        $gamma = '1.0';

        if (confirm('Customize ImageMagick blending parameters?', default: false)) {
            $levelLow = text(
                label: 'Level low (ambient mask threshold)',
                default: '40%',
                validate: fn($v) => preg_match('/^\d+%?$/', $v) ? null : 'Must be a percentage or number'
            );

            $levelHigh = text(
                label: 'Level high (ambient mask upper bound)',
                default: '140%',
                validate: fn($v) => preg_match('/^\d+%?$/', $v) ? null : 'Must be a percentage or number'
            );

            $gamma = text(
                label: 'Gamma (ambient mask correction)',
                default: '1.0',
                validate: fn($v) => is_numeric($v) ? null : 'Must be a number'
            );
        }

        // Build output directory
        $outputDirectory = storage_path("flambient/{$projectName}");

        // Create configuration
        $config = new WorkflowConfig(
            projectName: $projectName,
            imageDirectory: $imageDirectory,
            outputDirectory: $outputDirectory,
            processOnly: $processOnly,
            apiKey: $apiKey,
            profileKey: config('flambient.imagen.profile_key'),
            levelLow: $levelLow,
            levelHigh: $levelHigh,
            gamma: $gamma,
            outputPrefix: config('flambient.imagemagick.output_prefix'),
        );

        // Show configuration summary
        $this->newLine();
        note("Configuration summary:\n" .
             "  Project: {$projectName}\n" .
             "  Images: {$imageDirectory}\n" .
             "  Output: {$outputDirectory}\n" .
             "  Mode: " . ($processOnly ? 'Local only' : 'Full (with cloud)') . "\n" .
             "  Blending: {$levelLow}/{$levelHigh}/γ{$gamma}"
        );

        if (!confirm('Start processing?', default: true)) {
            info('Operation cancelled.');
            return self::SUCCESS;
        }

        // ═══════════════════════════════════════════════════════
        // 3. CREATE WORKFLOW RUN
        // ═══════════════════════════════════════════════════════

        $run = WorkflowRun::create([
            'id' => Str::uuid(),
            'project_name' => $projectName,
            'image_directory' => $imageDirectory,
            'output_directory' => $outputDirectory,
            'config' => $config->toArray(),
            'status' => WorkflowStatus::Running->value,
            'process_only' => $processOnly,
            'started_at' => now(),
        ]);

        $this->newLine();
        info("Workflow created: {$run->id}");
        info("You can check status with: php artisan flambient:status {$run->id}");
        $this->newLine();

        // ═══════════════════════════════════════════════════════
        // 4. SIMULATE WORKFLOW EXECUTION (MVP Demo)
        // ═══════════════════════════════════════════════════════
        // In the full implementation, this would call WorkflowOrchestrator
        // For the MVP, we demonstrate the UI flow

        try {
            // Step 1: Prepare
            info('Step 1/7: Preparing workspace');
            $prepareResult = spin(
                callback: function() use ($run, $config) {
                    sleep(1); // Simulate work
                    // Create output directories
                    @mkdir($config->outputDirectory, 0755, true);
                    @mkdir("{$config->outputDirectory}/metadata", 0755, true);
                    @mkdir("{$config->outputDirectory}/scripts", 0755, true);
                    @mkdir("{$config->outputDirectory}/flambient", 0755, true);
                    @mkdir("{$config->outputDirectory}/temp", 0755, true);

                    return new ProcessingResult(true, 'Workspace prepared', [
                        'image_count' => count(glob("{$config->imageDirectory}/*.jpg")),
                    ]);
                },
                message: 'Validating inputs and creating directories...'
            );

            note("✓ Found {$prepareResult->data['image_count']} images");
            $this->newLine();

            // Step 2: Analyze
            info('Step 2/7: Analyzing images');

            $exifService = new ExifService(
                strategy: $strategy,
                ambientValue: $ambientValue
            );
            $analyzeResult = spin(
                callback: function() use ($exifService, $config) {
                    // Extract EXIF metadata
                    $metadata = $exifService->extractMetadata($config->imageDirectory);

                    // Group images by consecutive ambient/flash sequences
                    $groups = $exifService->groupImages($metadata);

                    // Get statistics
                    $stats = $exifService->getGroupStatistics($groups);

                    return new ProcessingResult(true, 'EXIF extracted', [
                        'metadata' => $metadata,
                        'groups' => $groups,
                        'ambient_count' => $stats['total_ambient'],
                        'flash_count' => $stats['total_flash'],
                        'group_count' => $stats['total_groups'],
                    ]);
                },
                message: 'Extracting EXIF metadata and grouping images...'
            );

            $this->table(
                ['Type', 'Count'],
                [
                    ['Ambient images', $analyzeResult->data['ambient_count']],
                    ['Flash images', $analyzeResult->data['flash_count']],
                    ['Groups created', $analyzeResult->data['group_count']],
                ]
            );
            $this->newLine();

            // Step 3: Process
            info('Step 3/7: Processing images with ImageMagick');
            note('This may take several minutes for large groups');

            $imageMagickService = new ImageMagickService(
                levelLow: $config->levelLow,
                levelHigh: $config->levelHigh,
                gamma: $config->gamma,
                outputPrefix: $config->outputPrefix,
                enableDarkenExport: false, // Disable _tmp files
            );

            $startTime = microtime(true);

            $processResult = spin(
                callback: function() use ($imageMagickService, $config, $analyzeResult) {
                    // Generate .mgk scripts for all groups
                    $scripts = $imageMagickService->generateScripts(
                        groups: $analyzeResult->data['groups'],
                        imageDirectory: $config->imageDirectory,
                        scriptsDirectory: "{$config->outputDirectory}/scripts",
                        flambientDirectory: "{$config->outputDirectory}/flambient"
                    );

                    // Execute all scripts via master script
                    $result = $imageMagickService->executeAllScripts("{$config->outputDirectory}/scripts");

                    if (!$result['success']) {
                        throw new \RuntimeException("ImageMagick execution failed: " . ($result['error'] ?? 'Unknown error'));
                    }

                    return new ProcessingResult(true, 'Blended', [
                        'scripts_generated' => count($scripts),
                        'blended_count' => $analyzeResult->data['group_count'],
                        'output' => $result['output'],
                    ]);
                },
                message: 'Generating and executing ImageMagick scripts...'
            );

            $duration = round(microtime(true) - $startTime, 2);
            note("✓ Created {$processResult->data['blended_count']} blended images in {$duration}s");
            $this->newLine();

            // Cloud steps (skip if local-only)
            if ($processOnly) {
                info('Steps 4-8: Skipped (local-only mode - Imagen AI processing disabled)');

                // Clean up any _tmp files (darkened exports)
                $tmpFiles = glob("{$config->outputDirectory}/flambient/*_tmp.jpg");
                if (!empty($tmpFiles)) {
                    foreach ($tmpFiles as $tmpFile) {
                        @unlink($tmpFile);
                    }
                    note("✓ Cleaned up " . count($tmpFiles) . " temporary files");
                }

                note("✓ Processing complete!\n" .
                     "  Output: {$config->outputDirectory}/flambient/\n" .
                     "  Images: {$processResult->data['blended_count']}"
                );

                $run->update([
                    'status' => WorkflowStatus::Completed->value,
                    'completed_at' => now(),
                    'total_images_processed' => $prepareResult->data['image_count'],
                    'total_groups_created' => $analyzeResult->data['group_count'],
                ]);

                return self::SUCCESS;
            }

            // ═══════════════════════════════════════════════════════
            // 4. UPLOAD TO IMAGEN AI
            // ═══════════════════════════════════════════════════════

            $this->newLine();
            info('Step 4/8: Upload to Imagen AI');
            warning("This will upload {$processResult->data['blended_count']} blended images to Imagen AI for enhancement.");

            if (!confirm('Proceed with Imagen AI upload and processing?', default: true)) {
                $run->update(['status' => WorkflowStatus::Paused->value]);
                info('Workflow paused. Resume later with: php artisan flambient:resume ' . $run->id);
                return self::SUCCESS;
            }

            try {
                $imagenClient = new ImagenClient();

                // Create Imagen project
                $imagenProject = spin(
                    callback: fn() => $imagenClient->createProject($config->projectName),
                    message: 'Creating Imagen AI project...'
                );

                note("✓ Project created: {$imagenProject->uuid}");
                note("  View at: https://app.imagen-ai.com/projects/{$imagenProject->uuid}");
                $this->newLine();

                // Get list of blended images to upload
                $blendedImages = File::glob("{$config->outputDirectory}/flambient/*.jpg");
                $blendedImages = array_filter($blendedImages, fn($file) => !str_contains($file, '_tmp.jpg'));

                info("Uploading {$processResult->data['blended_count']} images to Imagen AI...");

                // Upload images with progress tracking
                $uploadedCount = 0;
                $uploadResult = $imagenClient->uploadImages(
                    projectUuid: $imagenProject->uuid,
                    filePaths: $blendedImages,
                    progressCallback: function ($current, $total, $filename) use (&$uploadedCount) {
                        $uploadedCount = $current;
                        if ($current % 5 === 0 || $current === $total) {
                            $this->components->info("  Uploaded {$current}/{$total}: " . basename($filename));
                        }
                    }
                );

                if (!$uploadResult->isFullySuccessful()) {
                    $failedCount = count($uploadResult->failed);
                    warning("⚠ {$failedCount} files failed to upload: " . implode(', ', array_slice($uploadResult->failed, 0, 3)));
                }

                note("✓ Upload complete: {$uploadedCount}/{$uploadResult->totalFiles} files ({$uploadResult->getSuccessRate()}% success)");
                $this->newLine();

                // ═══════════════════════════════════════════════════════
                // 5. START IMAGEN AI EDITING
                // ═══════════════════════════════════════════════════════

                info('Step 5/8: Starting Imagen AI editing');

                // Select editing profile
                $profileKey = $this->selectImagenProfile();
                $this->newLine();

                // Get edit options
                $editOptions = new ImagenEditOptions(
                    crop: false,
                    windowPull: true,
                    perspectiveCorrection: false,
                    hdrMerge: false,
                    photographyType: ImagenPhotographyType::REAL_ESTATE
                );

                spin(
                    callback: fn() => $imagenClient->startEditing(
                        projectUuid: $imagenProject->uuid,
                        profileKey: $profileKey,
                        options: $editOptions
                    ),
                    message: 'Submitting to Imagen AI for enhancement...'
                );

                note("✓ Project submitted for AI editing (Profile: {$profileKey})");
                $this->newLine();

                // ═══════════════════════════════════════════════════════
                // 6. MONITOR PROCESSING
                // ═══════════════════════════════════════════════════════

                info('Step 6/8: Monitoring AI processing');
                note('This typically takes 10-30 minutes depending on image count.');
                note('Progress updates will appear below. You can safely cancel (Ctrl+C) and resume later.');
                $this->newLine();

                $lastProgress = -1;
                $editStatus = $imagenClient->pollEditStatus(
                    projectUuid: $imagenProject->uuid,
                    maxAttempts: config('flambient.imagen.poll_max_attempts', 240),
                    intervalSeconds: config('flambient.imagen.poll_interval', 30),
                    progressCallback: function ($status) use (&$lastProgress) {
                        if ($status->progress !== $lastProgress) {
                            $emoji = match(true) {
                                $status->progress === 100 => '🎉',
                                $status->progress >= 75 => '🚀',
                                $status->progress >= 50 => '⚡',
                                $status->progress >= 25 => '🔄',
                                default => '⏳'
                            };

                            $this->components->info("{$emoji} Processing: {$status->progress}% - {$status->status}");
                            $lastProgress = $status->progress;
                        }
                    }
                );

                note("✓ AI editing complete!");
                $this->newLine();

                // ═══════════════════════════════════════════════════════
                // 7. EXPORT TO JPEG
                // ═══════════════════════════════════════════════════════

                info('Step 7/8: Exporting to JPEG format');

                spin(
                    callback: function () use ($imagenClient, $imagenProject) {
                        $imagenClient->exportProject($imagenProject->uuid);
                        sleep(10); // Give export time to initialize
                    },
                    message: 'Initiating JPEG export...'
                );

                note("✓ Export initiated");
                $this->newLine();

                // ═══════════════════════════════════════════════════════
                // 8. DOWNLOAD RESULTS
                // ═══════════════════════════════════════════════════════

                info('Step 8/8: Downloading enhanced images');

                // Get export download links
                $exportLinks = spin(
                    callback: fn() => $imagenClient->getExportLinks($imagenProject->uuid),
                    message: 'Retrieving download links...'
                );

                note("✓ Found {$exportLinks->count()} files ready for download");

                // Create output directory for edited images
                $editedOutputDir = "{$config->outputDirectory}/edited";
                File::ensureDirectoryExists($editedOutputDir);

                // Download files with progress
                $downloadedCount = 0;
                $downloadResult = $imagenClient->downloadFiles(
                    downloadLinks: $exportLinks,
                    outputDirectory: $editedOutputDir,
                    progressCallback: function ($current, $total, $filename) use (&$downloadedCount) {
                        $downloadedCount = $current;
                        if ($current % 5 === 0 || $current === $total) {
                            $this->components->info("  Downloaded {$current}/{$total}: " . basename($filename));
                        }
                    }
                );

                if (!$downloadResult->isFullySuccessful()) {
                    $failedCount = count($downloadResult->failed);
                    warning("⚠ {$failedCount} files failed to download");
                }

                note("✓ Download complete: {$downloadedCount}/{$downloadResult->totalFiles} files ({$downloadResult->getSuccessRate()}% success)");
                note("  Output: {$editedOutputDir}/");
                $this->newLine();

                // Update workflow run with Imagen project UUID
                $run->update(['imagen_project_uuid' => $imagenProject->uuid]);

            } catch (ImagenException $e) {
                $this->error("Imagen AI error: {$e->getMessage()}");
                $run->update([
                    'status' => WorkflowStatus::Failed->value,
                    'failed_at' => now(),
                    'error_message' => "Imagen AI failed: {$e->getMessage()}",
                ]);
                return self::FAILURE;
            }

            // ═══════════════════════════════════════════════════════
            // 9. FINALIZE
            // ═══════════════════════════════════════════════════════

            $this->newLine();
            info('Finalizing workflow');

            $run->update([
                'status' => WorkflowStatus::Completed->value,
                'completed_at' => now(),
                'total_images_processed' => $prepareResult->data['image_count'],
                'total_groups_created' => $analyzeResult->data['group_count'],
            ]);

            // Success summary
            $this->newLine();
            $this->components->twoColumnDetail('🎉 Workflow Complete', '<fg=green>SUCCESS</>');
            $this->newLine();

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Project Name', $run->project_name],
                    ['Total Duration', $run->started_at->diffForHumans($run->completed_at, true)],
                    ['Images Processed', $run->total_images_processed],
                    ['Groups Created', $run->total_groups_created],
                    ['Blended Output', "{$config->outputDirectory}/flambient/"],
                    ['Enhanced Output', "{$editedOutputDir}/"],
                    ['Imagen Project', $run->imagen_project_uuid ?? 'N/A'],
                ]
            );

            $this->newLine();
            info("🎨 Blended images: {$config->outputDirectory}/flambient/");
            info("✨ Enhanced images: {$editedOutputDir}/");
            if ($run->imagen_project_uuid) {
                info("🌐 Imagen project: https://app.imagen-ai.com/projects/{$run->imagen_project_uuid}");
            }
            info("💾 Database: php artisan tinker -> WorkflowRun::find('{$run->id}')");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('Workflow failed: ' . $e->getMessage());

            $run->update([
                'status' => WorkflowStatus::Failed->value,
                'failed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }
    }

    /**
     * Format EXIF value to show both raw numeric and human-readable label.
     * Example: "1 (Manual)" or just "100" for non-enum values.
     */
    private function formatExifValue(mixed $value): string
    {
        if (is_array($value) && isset($value['raw'], $value['label'])) {
            // If raw and label are identical (numeric-only field), just show raw
            if ($value['raw'] == $value['label']) {
                return (string)$value['raw'];
            }
            // Otherwise show "raw (label)"
            return "{$value['raw']} ({$value['label']})";
        }

        // Fallback for non-dual-extraction values
        return (string)$value;
    }

    /**
     * Prompt user to select an Imagen AI editing profile.
     * Offers quick selection from favorites or browse all profiles.
     */
    private function selectImagenProfile(): string
    {
        $favorites = config('imagen-profiles.favorites', []);
        $allProfiles = config('imagen-profiles.all', []);
        $default = config('imagen-profiles.default');

        // If no favorites configured, just use default
        if (empty($favorites)) {
            return (string)$default;
        }

        $this->newLine();
        info('Select Imagen AI Editing Profile');
        note('Profiles determine the AI editing style applied to your images');

        // Ask if they want to select or use default
        $choice = select(
            label: 'How would you like to choose a profile?',
            options: [
                'favorites' => "⭐ Quick Select ({$this->formatCount(count($favorites))} favorites)",
                'all' => "📋 Browse All Profiles ({$this->formatCount(count($allProfiles))} available)",
                'default' => "✓ Use Default ({$default})",
            ],
            default: 'favorites',
            hint: 'Favorites are your most-used profiles for quick access'
        );

        // Use default
        if ($choice === 'default') {
            note("Using default profile: {$default}");
            return (string)$default;
        }

        // Select from favorites (quick select)
        if ($choice === 'favorites') {
            $selectedKey = select(
                label: 'Choose from your favorite profiles',
                options: $favorites,
                hint: 'These are your 3 most-used profiles'
            );

            $profileName = $favorites[$selectedKey] ?? "Profile {$selectedKey}";
            note("✓ Selected: {$profileName} (Profile: {$selectedKey})");
            return (string)$selectedKey;
        }

        // Browse all profiles
        $selectedKey = select(
            label: 'Choose from all available profiles',
            options: $allProfiles,
            scroll: 10, // Show 10 at a time for easier scrolling
            hint: 'Scroll through all available Imagen AI profiles'
        );

        $profileName = $allProfiles[$selectedKey] ?? "Profile {$selectedKey}";
        note("✓ Selected: {$profileName} (Profile: {$selectedKey})");
        return (string)$selectedKey;
    }

    /**
     * Format count for display (e.g., "3" not "3 profiles").
     */
    private function formatCount(int $count): string
    {
        return (string)$count;
    }
}
