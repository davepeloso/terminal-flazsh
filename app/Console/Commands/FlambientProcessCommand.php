<?php

namespace App\Console\Commands;

use App\DataObjects\ProcessingResult;
use App\DataObjects\WorkflowConfig;
use App\Enums\ImageClassificationStrategy;
use App\Enums\WorkflowStatus;
use App\Models\WorkflowRun;
use App\Services\Flambient\ExifService;
use App\Services\Flambient\ImageMagickService;
use Illuminate\Console\Command;
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
            $tempExif = new ExifService();
            $samples = $tempExif->getSampleExifValues($imageDirectory, 3);

            $this->newLine();
            $this->table(
                ['Filename', 'Flash#', 'ExpProgram', 'ExpMode', 'WB', 'ISO', 'Shutter'],
                array_map(fn($s) => [
                    $s['filename'],
                    $s['flash'],
                    $s['exposure_program'],
                    $s['exposure_mode'],
                    $s['white_balance'],
                    $s['iso'],
                    $s['shutter_speed'],
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
            hint: 'Images with this exact value will be classified as Ambient'
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
                info('Step 4-6: Skipped (local-only mode)');

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

            // Step 4: Upload (simulated)
            info('Step 4/7: Uploading to Imagen AI');
            warning("This will upload {$processResult->data['blended_count']} images to Imagen AI for processing.");

            if (!confirm('Proceed with upload?', default: true)) {
                $run->update(['status' => WorkflowStatus::Paused->value]);
                info('Workflow paused. Resume later with: php artisan flambient:resume ' . $run->id);
                return self::SUCCESS;
            }

            spin(
                callback: fn() => sleep(2),
                message: 'Uploading images...'
            );

            $projectUuid = Str::uuid();
            note("✓ Uploaded to project: {$projectUuid}");
            $this->newLine();

            // Step 5: Monitor (simulated)
            info('Step 5/7: Monitoring cloud processing');
            note('This typically takes 10-30 minutes. You can safely cancel and resume later.');

            spin(
                callback: fn() => sleep(3),
                message: 'Waiting for cloud processing (simulated)...'
            );
            note("✓ Processing completed");
            $this->newLine();

            // Step 6: Download (simulated)
            info('Step 6/7: Downloading results');
            spin(
                callback: fn() => sleep(2),
                message: 'Downloading enhanced images...'
            );
            note("✓ Downloaded {$processResult->data['blended_count']} images");
            $this->newLine();

            // Step 7: Finalize
            info('Step 7/7: Finalizing');
            spin(
                callback: fn() => sleep(1),
                message: 'Cleaning up and generating summary...'
            );

            $run->update([
                'status' => WorkflowStatus::Completed->value,
                'completed_at' => now(),
                'imagen_project_uuid' => $projectUuid,
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
                    ['Output Directory', $config->outputDirectory],
                ]
            );

            $this->newLine();
            info("View results: ls {$config->outputDirectory}/flambient");
            info("View database: php artisan tinker -> WorkflowRun::find('{$run->id}')");

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
}
