# 🏗️ FLAMBIENT WORKFLOW ARCHITECTURE REDESIGN

## Executive Summary

This document presents a complete architectural redesign of the flambient photography processing workflow, transitioning from fragile shell scripts to a robust Laravel-native CLI application with explicit state management, comprehensive error handling, and superior user experience through Laravel Prompts.

## Installation and Setup

```bash
# 1. Install dependencies (if needed)
composer install

# 2. Set up database
touch database/database.sqlite
php artisan migrate

# 3. Run the command (MVP simulation)
php artisan flambient:process --local

# 4. Explore the database
php artisan tinker
>>> WorkflowRun::with(['steps', 'files'])->get()
>>> WorkflowRun::first()->toArray()

## Part 1: Critical Workflow Review

### Current State Analysis

The existing workflow exhibits several **critical architectural deficiencies**:

#### 1. **Breakpoints Identified**

| Stage | Current Implementation | Failure Mode | Impact |
|-------|------------------------|--------------|--------|
| **Input Validation** | None - assumes valid JPGs exist | Silent failure if no images or wrong format | Wasted processing time |
| **EXIF Extraction** | exiftool with CSV output, no validation | Missing Flash# field → classification fails | Incorrect grouping |
| **ImageMagick Processing** | Generated .mgk scripts, success = file exists | Script execution fails but file exists from previous run | False positive success |
| **UUID Extraction** | Regex parsing of log files (4 fallback patterns) | Log format change breaks entire workflow | Cannot proceed to polling |
| **Cloud Upload** | Sequential curl calls, weak error checking | Network failure mid-upload | Partial upload, unclear state |
| **Status Polling** | Fixed 60s interval, 120 max attempts | API rate limits or slow processing | Hard timeout, no resume |
| **Result Download** | Sequential download, no integrity checks | Corrupted download or network interruption | Incomplete results |
| **Cleanup** | No cleanup on failure | Temp files accumulate | Disk space issues |

#### 2. **Fragile State Passing**

**Problem Areas:**

```bash
# UUID Extraction (master_workflow.zsh:183-226)
# FRAGILE: 4 different regex patterns as fallbacks
project_uuid=$(grep -oE 'project_uuid":\s*"[0-9a-f-]+"' "$upload_log" | head -n 1 | grep -oE '[0-9a-f-]{8}-[0-9a-f-]{4}-[0-9a-f-]{4}-[0-9a-f-]{4}-[0-9a-f-]{12}')

# FRAGILE: Hard-coded directory coupling
flambient_dir="${image_dir}/flambient"  # Upload script MUST use this exact path

# FRAGILE: Log-based state reconstruction
# Process-only mode determined by ABSENCE of upload step in JSON log
```

**Why This Is Dangerous:**
- Any change to log format breaks UUID extraction
- No validation that UUID is actually valid
- Directory names create hidden coupling between scripts
- Cannot resume from failure (no persistent state)

#### 3. **Shell Scripts Doing Application Work**

**AWK-Generated ImageMagick Scripts** (001_imageMagick.zsh:200-400)
- **Problem**: Business logic (grouping, blending) embedded in AWK templates
- **Issue**: Cannot unit test, difficult to debug, version control is opaque
- **Should Be**: PHP classes with testable grouping logic

**Manual JSON Construction** (master_workflow.zsh:append_to_json)
- **Problem**: String concatenation for JSON in shell
- **Issue**: Quote escaping failures, malformed JSON, no schema validation
- **Should Be**: Laravel's built-in JSON serialization

**Polling Loop** (master_workflow.zsh:270-310)
- **Problem**: Bash while loop with sleep
- **Issue**: Cannot interrupt, fixed interval, no backoff
- **Should Be**: Laravel Command with progress bar and exponential backoff

#### 4. **Synchronous vs Asynchronous Decisions**

**Should Be Synchronous** (User Must Wait):
- ✅ Input validation and EXIF extraction (fast, critical for UX)
- ✅ Configuration validation (prevents wasted work)
- ✅ Project creation API call (need UUID immediately)

**Should Be Asynchronous** (Can Background):
- ❌ ImageMagick processing (CPU-intensive, long-running)
- ❌ File uploads (network I/O, variable duration)
- ❌ Status polling (long-running, can notify on completion)

**Current Implementation**: Everything is synchronous shell scripts

**Recommendation**:
- CLI remains synchronous for MVP (simpler, matches current UX)
- Design for future async: Use Jobs/Events, but dispatch synchronously for now
- Add `--async` flag later when queue workers are set up

#### 5. **State: Persistent vs Ephemeral**

**Must Be Persistent** (Survive Process Restart):
```
✓ Project UUID
✓ Workflow stage (which step completed)
✓ Configuration snapshot (API key, profile, parameters)
✓ Image file manifest (what was processed)
✓ API response history (for audit trail)
✓ Error state (for recovery logic)
```

**Can Be Ephemeral** (Process Lifetime Only):
```
✓ Progress counters (rebuild from state)
✓ Polling iteration count (recalculate from timestamps)
✓ Temporary file paths (regenerate on resume)
```

**Current Implementation**: All state is ephemeral (process memory + logs)

**Recommendation**: SQLite database with `workflow_runs` table

---

## Part 2: New High-Level Architecture

### Overview

```
┌─────────────────────────────────────────────────────────────┐
│                   Artisan Command Layer                      │
│  (FlambientProcessCommand, FlambientStatusCommand, etc.)    │
└────────────────┬────────────────────────────────────────────┘
                 │
┌────────────────▼────────────────────────────────────────────┐
│                  Orchestration Service                       │
│              (WorkflowOrchestrator)                          │
│  - State machine management                                  │
│  - Step coordination                                         │
│  - Recovery logic                                            │
└────────────────┬────────────────────────────────────────────┘
                 │
         ┌───────┴───────┐
         │               │
┌────────▼──────┐  ┌────▼──────────┐
│  Step Actions │  │  State Store  │
│               │  │   (Database)  │
│ - Prepare     │  │               │
│ - Analyze     │  │ workflow_runs │
│ - Process     │  │ workflow_steps│
│ - Upload      │  │ workflow_files│
│ - Monitor     │  └───────────────┘
│ - Download    │
│ - Finalize    │
└───────┬───────┘
        │
┌───────▼────────────────────────────────────────────────────┐
│                    Service Layer                            │
│                                                             │
│ ImageMagickService  │  ImagenApiClient  │  ExifService    │
│ FileManager         │  ConfigValidator  │  StateSerializer│
└─────────────────────────────────────────────────────────────┘
```

### Directory Structure

```
app/
├── Console/
│   └── Commands/
│       ├── FlambientProcessCommand.php      # Main workflow command
│       ├── FlambientStatusCommand.php       # Check project status
│       ├── FlambientResumeCommand.php       # Resume failed workflow
│       ├── FlambientListCommand.php         # List all workflows
│       └── FlambientCleanupCommand.php      # Clean temp files
│
├── Services/
│   ├── Flambient/
│   │   ├── WorkflowOrchestrator.php         # State machine coordinator
│   │   ├── ImageMagickService.php           # ImageMagick operations
│   │   ├── ImagenApiClient.php              # API wrapper
│   │   ├── ExifService.php                  # Metadata extraction
│   │   ├── FileManager.php                  # File operations
│   │   └── ConfigValidator.php              # Configuration validation
│   │
│   └── Flambient/Steps/                     # Step implementations
│       ├── AbstractStep.php                 # Base class
│       ├── PrepareStep.php                  # Validate inputs
│       ├── AnalyzeStep.php                  # EXIF extraction & grouping
│       ├── ProcessStep.php                  # ImageMagick blending
│       ├── UploadStep.php                   # Cloud upload
│       ├── MonitorStep.php                  # Status polling
│       ├── DownloadStep.php                 # Result retrieval
│       └── FinalizeStep.php                 # Cleanup & summary
│
├── DataObjects/
│   ├── WorkflowConfig.php                   # Immutable config DTO
│   ├── ImageGroup.php                       # Image grouping DTO
│   ├── ProcessingResult.php                 # Step result DTO
│   └── ApiResponse.php                      # API response DTO
│
├── Enums/
│   ├── WorkflowStatus.php                   # Pending, Running, Completed, Failed
│   ├── StepName.php                         # Prepare, Analyze, Process, etc.
│   └── ImageType.php                        # Ambient, Flash
│
└── Models/
    ├── WorkflowRun.php                      # Workflow execution record
    ├── WorkflowStep.php                     # Individual step record
    └── WorkflowFile.php                     # Processed file record

database/
└── migrations/
    ├── 2024_01_01_000001_create_workflow_runs_table.php
    ├── 2024_01_02_000002_create_workflow_steps_table.php
    └── 2024_01_03_000003_create_workflow_files_table.php

config/
└── flambient.php                            # Application configuration

storage/
└── flambient/
    ├── {run_id}/
    │   ├── metadata/                        # CSV files
    │   ├── scripts/                         # Generated .mgk scripts
    │   ├── temp/                            # Intermediate files
    │   └── output/                          # Final results
    └── logs/
        └── workflow-{run_id}.log            # Structured logs

tests/
├── Unit/
│   ├── Services/
│   │   ├── ExifServiceTest.php
│   │   └── ImageGroupingTest.php
│   └── DataObjects/
│       └── WorkflowConfigTest.php
└── Feature/
    ├── WorkflowOrchestrationTest.php
    └── Commands/
        └── FlambientProcessCommandTest.php
```

### Database Schema

```sql
-- workflow_runs table
CREATE TABLE workflow_runs (
    id CHAR(36) PRIMARY KEY,              -- UUID
    project_name VARCHAR(255) NOT NULL,
    image_directory TEXT NOT NULL,
    output_directory TEXT,

    -- Configuration snapshot
    config JSON NOT NULL,                 -- WorkflowConfig as JSON

    -- State tracking
    status VARCHAR(50) NOT NULL,          -- pending, running, completed, failed, paused
    current_step VARCHAR(50),             -- prepare, analyze, process, etc.

    -- Cloud tracking
    imagen_project_uuid CHAR(36) NULL,
    process_only BOOLEAN DEFAULT FALSE,

    -- Timing
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,

    -- Results
    total_images_processed INT DEFAULT 0,
    total_groups_created INT DEFAULT 0,
    error_message TEXT NULL,
    error_trace TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- workflow_steps table
CREATE TABLE workflow_steps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_run_id CHAR(36) NOT NULL,

    step_name VARCHAR(50) NOT NULL,       -- prepare, analyze, process, etc.
    status VARCHAR(50) NOT NULL,          -- pending, running, completed, failed, skipped

    -- Step data
    input_data JSON NULL,                 -- Input parameters
    output_data JSON NULL,                -- Output results
    metadata JSON NULL,                   -- Additional context

    -- Timing
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    duration_seconds INT NULL,

    -- Error tracking
    error_message TEXT NULL,
    retry_count INT DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (workflow_run_id) REFERENCES workflow_runs(id) ON DELETE CASCADE
);

CREATE INDEX idx_workflow_steps_run_id ON workflow_steps(workflow_run_id);
CREATE INDEX idx_workflow_steps_status ON workflow_steps(status);

-- workflow_files table
CREATE TABLE workflow_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_run_id CHAR(36) NOT NULL,

    -- File info
    original_path TEXT NOT NULL,
    processed_path TEXT NULL,
    file_type VARCHAR(50) NOT NULL,       -- ambient, flash, blended
    group_number INT NULL,

    -- EXIF metadata
    exif_data JSON NULL,

    -- Processing
    processed_at TIMESTAMP NULL,
    uploaded_at TIMESTAMP NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (workflow_run_id) REFERENCES workflow_runs(id) ON DELETE CASCADE
);

CREATE INDEX idx_workflow_files_run_id ON workflow_files(workflow_run_id);
CREATE INDEX idx_workflow_files_type ON workflow_files(file_type);
```

### Key Design Principles

#### 1. **Separation of Concerns**

**Commands** (CLI interface)
- Handle user interaction
- Validate input prompts
- Display progress and results
- Delegate to orchestrator

**Orchestrator** (State machine)
- Coordinate step execution
- Manage state transitions
- Handle recovery logic
- Persist state to database

**Steps** (Business logic)
- Execute single responsibility
- Return structured results
- Throw domain-specific exceptions
- Idempotent where possible

**Services** (Infrastructure)
- Wrap external dependencies (ImageMagick, API)
- Handle retries and timeouts
- Provide clean interfaces

#### 2. **State Machine Architecture**

```php
enum WorkflowStatus: string {
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Paused = 'paused';
}

enum StepName: string {
    case Prepare = 'prepare';
    case Analyze = 'analyze';
    case Process = 'process';
    case Upload = 'upload';
    case Monitor = 'monitor';
    case Download = 'download';
    case Finalize = 'finalize';
}

// State transitions
class WorkflowOrchestrator {
    public function executeStep(StepName $step): ProcessingResult {
        // 1. Check if step already completed (idempotency)
        if ($this->isStepCompleted($step)) {
            return $this->getStepResult($step);
        }

        // 2. Mark step as running
        $this->recordStepStart($step);

        try {
            // 3. Execute step
            $result = $this->getStepInstance($step)->execute(
                $this->run,
                $this->getPreviousStepOutput()
            );

            // 4. Record success
            $this->recordStepComplete($step, $result);

            return $result;

        } catch (\Throwable $e) {
            // 5. Record failure
            $this->recordStepFailure($step, $e);

            throw $e;
        }
    }
}
```

#### 3. **Immutable Configuration**

```php
readonly class WorkflowConfig {
    public function __construct(
        public string $imageDirectory,
        public string $outputDirectory,
        public bool $processOnly,
        public ?string $apiKey,
        public int $profileKey,
        public ImageMagickConfig $imageMagick,
        public ImagenEditConfig $imagenEdit,
    ) {}

    // Snapshot to database
    public function toArray(): array {
        return [
            'image_directory' => $this->imageDirectory,
            'output_directory' => $this->outputDirectory,
            'process_only' => $this->processOnly,
            'api_key' => $this->apiKey ? '***REDACTED***' : null,
            'profile_key' => $this->profileKey,
            'imagemagick' => $this->imageMagick->toArray(),
            'imagen_edit' => $this->imagenEdit->toArray(),
        ];
    }
}
```

#### 4. **Logging Strategy**

**Structured Logging**
```php
// Every step logs structured data
Log::info('Step started', [
    'workflow_run_id' => $this->run->id,
    'step' => StepName::Analyze->value,
    'image_directory' => $this->config->imageDirectory,
]);

Log::info('EXIF extraction completed', [
    'workflow_run_id' => $this->run->id,
    'step' => StepName::Analyze->value,
    'images_found' => $metadata->count(),
    'groups_created' => $groups->count(),
    'ambient_count' => $metadata->where('type', ImageType::Ambient)->count(),
    'flash_count' => $metadata->where('type', ImageType::Flash)->count(),
]);
```

**Database Events**
```php
// WorkflowRun model
protected $dispatchesEvents = [
    'updated' => WorkflowRunUpdated::class,
];

// Listen for state changes
class WorkflowRunUpdated {
    public function handle(WorkflowRunUpdated $event): void {
        // Log state transitions
        if ($event->run->wasChanged('status')) {
            Log::info('Workflow status changed', [
                'run_id' => $event->run->id,
                'from' => $event->run->getOriginal('status'),
                'to' => $event->run->status,
            ]);
        }
    }
}
```

**File Logging**
- Single JSON Lines file per workflow run
- `storage/flambient/logs/workflow-{run_id}.log`
- One JSON object per line (parseable, grep-friendly)

#### 5. **Retry and Resume**

**Idempotent Steps**
```php
abstract class AbstractStep {
    // Each step checks if already completed
    public function execute(WorkflowRun $run, mixed $previousOutput): ProcessingResult {
        if ($this->isCompleted($run)) {
            return $this->getStoredResult($run);
        }

        return $this->perform($run, $previousOutput);
    }

    abstract protected function perform(WorkflowRun $run, mixed $previousOutput): ProcessingResult;
}
```

**Resume Capability**
```php
// Resume from last successful step
class WorkflowOrchestrator {
    public function resume(WorkflowRun $run): void {
        // Get last completed step
        $lastCompletedStep = $run->steps()
            ->where('status', 'completed')
            ->orderBy('id', 'desc')
            ->first();

        // Start from next step
        $nextStep = $this->getNextStep($lastCompletedStep?->step_name);

        // Continue execution
        $this->executeFromStep($nextStep);
    }
}
```

**API Retry Logic**
```php
class ImagenApiClient {
    public function createProject(string $projectName): string {
        return retry(
            times: 3,
            callback: fn() => $this->doCreateProject($projectName),
            sleepMilliseconds: function (int $attempt) {
                // Exponential backoff: 1s, 2s, 4s
                return 1000 * (2 ** ($attempt - 1));
            },
            when: fn(\Throwable $e) => $e instanceof NetworkException
        );
    }
}
```

---

## Part 3: CLI Experience with Laravel Prompts

### Design Philosophy

**Every prompt has a PURPOSE:**
1. **Validation** - Prevent invalid input early
2. **Confirmation** - Give users control at critical points
3. **Feedback** - Show progress and results clearly
4. **Recovery** - Offer choices when failures occur

### Command Flow: `flambient:process`

```php
use function Laravel\Prompts\text;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

class FlambientProcessCommand extends Command {
    public function handle(): int {
        // ═══════════════════════════════════════════════════════
        // 1. PROJECT SETUP
        // ═══════════════════════════════════════════════════════

        info('🎨 Flambient Photography Processor');
        note('This workflow will process flambient images using ImageMagick and optionally enhance them with Imagen AI.');

        // WHY: Prevent empty or invalid project names
        $projectName = text(
            label: 'Project name',
            placeholder: 'client-property-address',
            required: true,
            validate: fn($value) => match(true) {
                strlen($value) < 3 => 'Project name must be at least 3 characters',
                !preg_match('/^[a-zA-Z0-9_-]+$/', $value) => 'Only letters, numbers, dashes, and underscores allowed',
                WorkflowRun::where('project_name', $value)->exists() => 'Project name already exists. Use a unique name or resume existing workflow.',
                default => null,
            }
        );

        // WHY: Validate directory exists and contains images BEFORE processing
        $imageDirectory = text(
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

        // WHY: Let user choose between local-only or cloud processing
        $processOnly = select(
            label: 'Processing mode',
            options: [
                'full' => 'Full workflow (ImageMagick + Imagen AI cloud enhancement)',
                'local' => 'Local only (ImageMagick blending without cloud upload)',
            ],
            default: 'full',
            hint: 'Cloud processing requires API key and costs money'
        ) === 'local';

        // ═══════════════════════════════════════════════════════
        // 2. CONFIGURATION
        // ═══════════════════════════════════════════════════════

        // WHY: Load and validate API configuration EARLY to prevent failures later
        if (!$processOnly) {
            $apiKey = $this->getApiKey();

            if (!$apiKey) {
                warning('No API key configured. Switching to local-only mode.');
                $processOnly = true;
            } else {
                // WHY: Validate API key works BEFORE processing images
                $apiValid = spin(
                    callback: fn() => app(ImagenApiClient::class)->validateApiKey($apiKey),
                    message: 'Validating Imagen AI API key...'
                );

                if (!$apiValid) {
                    error('API key validation failed. Please check your credentials.');

                    if (confirm('Continue in local-only mode?', default: true)) {
                        $processOnly = true;
                    } else {
                        return self::FAILURE;
                    }
                }
            }
        }

        // WHY: Let user customize ImageMagick parameters (advanced users)
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
        } else {
            $levelLow = '40%';
            $levelHigh = '140%';
            $gamma = '1.0';
        }

        // WHY: Show what will happen BEFORE starting (transparency)
        note("Configuration summary:\n" .
             "  Project: {$projectName}\n" .
             "  Images: {$imageDirectory}\n" .
             "  Mode: " . ($processOnly ? 'Local only' : 'Full (with cloud)') . "\n" .
             "  Blending: {$levelLow}/{$levelHigh}/γ{$gamma}"
        );

        // WHY: Final confirmation before expensive/long operation
        if (!confirm('Start processing?', default: true)) {
            info('Operation cancelled.');
            return self::SUCCESS;
        }

        // ═══════════════════════════════════════════════════════
        // 3. WORKFLOW EXECUTION
        // ═══════════════════════════════════════════════════════

        // Create workflow run
        $run = WorkflowRun::create([
            'id' => Str::uuid(),
            'project_name' => $projectName,
            'image_directory' => $imageDirectory,
            'config' => $config->toArray(),
            'status' => WorkflowStatus::Running,
            'process_only' => $processOnly,
            'started_at' => now(),
        ]);

        $orchestrator = new WorkflowOrchestrator($run, $config);

        try {
            // ───────────────────────────────────────────────────
            // STEP 1: PREPARE
            // ───────────────────────────────────────────────────
            // WHY: Validate all prerequisites before heavy work

            info('Step 1/7: Preparing workspace');

            $prepareResult = spin(
                callback: fn() => $orchestrator->executeStep(StepName::Prepare),
                message: 'Validating inputs and creating directories...'
            );

            note("✓ Found {$prepareResult->imageCount} images");

            // ───────────────────────────────────────────────────
            // STEP 2: ANALYZE
            // ───────────────────────────────────────────────────
            // WHY: Show EXIF extraction progress (can be slow for many images)

            info('Step 2/7: Analyzing images');

            $analyzeResult = spin(
                callback: fn() => $orchestrator->executeStep(StepName::Analyze),
                message: 'Extracting EXIF metadata and grouping images...'
            );

            // WHY: Show grouping results for user validation
            table(
                headers: ['Type', 'Count'],
                rows: [
                    ['Ambient images', $analyzeResult->ambientCount],
                    ['Flash images', $analyzeResult->flashCount],
                    ['Groups created', $analyzeResult->groupCount],
                ]
            );

            // WHY: Warn if grouping seems wrong (user can abort)
            if ($analyzeResult->groupCount === 0) {
                warning('No groups created. This may indicate EXIF data issues.');

                if (!confirm('Continue anyway?', default: false)) {
                    $run->update(['status' => WorkflowStatus::Failed, 'error_message' => 'User aborted: no groups']);
                    return self::FAILURE;
                }
            }

            // ───────────────────────────────────────────────────
            // STEP 3: PROCESS
            // ───────────────────────────────────────────────────
            // WHY: Show progress for each group (ImageMagick can be slow)

            info('Step 3/7: Processing images with ImageMagick');

            $processResult = progress(
                label: 'Blending groups',
                steps: $analyzeResult->groupCount,
                callback: function ($progress) use ($orchestrator, $analyzeResult) {
                    return $orchestrator->executeStep(
                        StepName::Process,
                        onGroupComplete: fn($groupNum) => $progress->advance()
                    );
                },
                hint: 'This may take several minutes for large groups'
            );

            note("✓ Created {$processResult->blendedCount} blended images in {$processResult->durationSeconds}s");

            // ───────────────────────────────────────────────────
            // LOCAL-ONLY MODE: SKIP CLOUD STEPS
            // ───────────────────────────────────────────────────

            if ($processOnly) {
                info('Step 4-6: Skipped (local-only mode)');

                $orchestrator->executeStep(StepName::Finalize);

                note("✓ Processing complete!\n" .
                     "  Output: {$config->outputDirectory}/flambient/\n" .
                     "  Images: {$processResult->blendedCount}"
                );

                return self::SUCCESS;
            }

            // ───────────────────────────────────────────────────
            // STEP 4: UPLOAD
            // ───────────────────────────────────────────────────
            // WHY: Confirm before cloud upload (costs money, sends data)

            info('Step 4/7: Uploading to Imagen AI');

            warning("This will upload {$processResult->blendedCount} images to Imagen AI for processing.");
            warning("Estimated cost: \$" . number_format($processResult->blendedCount * 0.25, 2));

            if (!confirm('Proceed with upload?', default: true)) {
                $run->update(['status' => WorkflowStatus::Paused, 'current_step' => StepName::Upload->value]);
                info('Workflow paused. Resume later with: php artisan flambient:resume ' . $run->id);
                return self::SUCCESS;
            }

            // WHY: Show upload progress (network I/O can be slow/fail)
            $uploadResult = progress(
                label: 'Uploading images',
                steps: $processResult->blendedCount,
                callback: function ($progress) use ($orchestrator) {
                    return $orchestrator->executeStep(
                        StepName::Upload,
                        onFileUploaded: fn($filename) => $progress->advance()
                    );
                }
            );

            note("✓ Uploaded to project: {$uploadResult->projectUuid}");

            // ───────────────────────────────────────────────────
            // STEP 5: MONITOR
            // ───────────────────────────────────────────────────
            // WHY: Show polling progress (can take 10-30 minutes)

            info('Step 5/7: Monitoring cloud processing');

            note('Imagen AI is now processing your images. This typically takes 10-30 minutes.');
            note('You can safely cancel this command and resume later with: php artisan flambient:resume ' . $run->id);

            // WHY: Adaptive progress bar with status updates
            $monitorResult = $this->monitorWithProgress($orchestrator);

            if ($monitorResult->status === 'failed') {
                error('Cloud processing failed: ' . $monitorResult->errorMessage);

                if (confirm('Download partial results anyway?', default: true)) {
                    // Continue to download step
                } else {
                    return self::FAILURE;
                }
            } else {
                note("✓ Processing completed in {$monitorResult->durationMinutes} minutes");
            }

            // ───────────────────────────────────────────────────
            // STEP 6: DOWNLOAD
            // ───────────────────────────────────────────────────
            // WHY: Show download progress

            info('Step 6/7: Downloading results');

            $downloadResult = progress(
                label: 'Downloading enhanced images',
                steps: $uploadResult->fileCount,
                callback: function ($progress) use ($orchestrator) {
                    return $orchestrator->executeStep(
                        StepName::Download,
                        onFileDownloaded: fn($filename) => $progress->advance()
                    );
                }
            );

            note("✓ Downloaded {$downloadResult->fileCount} images");

            // ───────────────────────────────────────────────────
            // STEP 7: FINALIZE
            // ───────────────────────────────────────────────────

            info('Step 7/7: Finalizing');

            $finalizeResult = spin(
                callback: fn() => $orchestrator->executeStep(StepName::Finalize),
                message: 'Cleaning up and generating summary...'
            );

            // ═══════════════════════════════════════════════════════
            // SUCCESS SUMMARY
            // ═══════════════════════════════════════════════════════

            $this->displaySuccessSummary($run, $finalizeResult);

            return self::SUCCESS;

        } catch (\Throwable $e) {
            // ═══════════════════════════════════════════════════════
            // FAILURE RECOVERY
            // ═══════════════════════════════════════════════════════

            error('Workflow failed: ' . $e->getMessage());

            $run->update([
                'status' => WorkflowStatus::Failed,
                'failed_at' => now(),
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
            ]);

            // WHY: Give user recovery options
            $choice = select(
                label: 'What would you like to do?',
                options: [
                    'retry' => 'Retry from last successful step',
                    'logs' => 'View detailed logs',
                    'cleanup' => 'Clean up and exit',
                    'exit' => 'Exit without cleanup',
                ],
            );

            match($choice) {
                'retry' => $this->call('flambient:resume', ['run' => $run->id]),
                'logs' => $this->call('flambient:logs', ['run' => $run->id]),
                'cleanup' => $this->call('flambient:cleanup', ['run' => $run->id]),
                'exit' => null,
            };

            return self::FAILURE;
        }
    }

    // WHY: API key resolution with fallbacks
    private function getApiKey(): ?string {
        // 1. Check environment
        if ($key = config('flambient.imagen.api_key')) {
            return $key;
        }

        // 2. Prompt user
        return text(
            label: 'Imagen AI API Key',
            placeholder: 'Enter your API key',
            hint: 'Set IMAGEN_AI_API_KEY in .env to avoid this prompt',
            validate: fn($v) => strlen($v) > 10 ? null : 'API key seems too short'
        );
    }

    // WHY: Adaptive polling with visual feedback
    private function monitorWithProgress(WorkflowOrchestrator $orchestrator): ProcessingResult {
        $startTime = now();
        $iteration = 0;

        return spin(
            callback: function() use ($orchestrator, &$iteration, $startTime) {
                while (true) {
                    $result = $orchestrator->checkStatus();

                    $elapsed = $startTime->diffInMinutes(now());
                    $this->output->write("\r  Status: {$result->status} | Progress: {$result->progress}% | Elapsed: {$elapsed}m");

                    if (in_array($result->status, ['Completed', 'Failed'])) {
                        $this->newLine();
                        return $result;
                    }

                    sleep($this->calculatePollInterval($iteration++));
                }
            },
            message: 'Waiting for cloud processing...'
        );
    }

    // WHY: Exponential backoff to reduce API calls
    private function calculatePollInterval(int $iteration): int {
        return match(true) {
            $iteration < 5 => 15,    // First 5 checks: every 15s
            $iteration < 15 => 30,   // Next 10 checks: every 30s
            default => 60,           // Rest: every 60s
        };
    }

    // WHY: Clear success summary
    private function displaySuccessSummary(WorkflowRun $run, ProcessingResult $result): void {
        $this->newLine();
        $this->components->twoColumnDetail('🎉 Workflow Complete', '<fg=green>SUCCESS</>');
        $this->newLine();

        table(
            headers: ['Metric', 'Value'],
            rows: [
                ['Project Name', $run->project_name],
                ['Total Duration', gmdate('H:i:s', $run->started_at->diffInSeconds($run->completed_at))],
                ['Images Processed', $result->totalImages],
                ['Groups Created', $result->totalGroups],
                ['Output Directory', $result->outputDirectory],
            ]
        );

        $this->newLine();
        info('View results: ls ' . $result->outputDirectory);
        info('View logs: php artisan flambient:logs ' . $run->id);
    }
}
```

### Supporting Commands

#### `flambient:resume {run}`

```php
// WHY: Allow resuming failed/paused workflows
class FlambientResumeCommand extends Command {
    public function handle(): int {
        $run = WorkflowRun::findOrFail($this->argument('run'));

        if ($run->status === WorkflowStatus::Completed) {
            warning('This workflow already completed successfully.');
            return self::SUCCESS;
        }

        // WHY: Show resume context
        note("Resuming workflow: {$run->project_name}\n" .
             "  Last step: {$run->current_step}\n" .
             "  Started: {$run->started_at->diffForHumans()}"
        );

        if (!confirm('Resume from last successful step?', default: true)) {
            return self::SUCCESS;
        }

        // Continue from orchestrator
        $orchestrator = WorkflowOrchestrator::loadFromRun($run);
        $orchestrator->resume();

        return self::SUCCESS;
    }
}
```

#### `flambient:status {run?}`

```php
// WHY: Quick status check
class FlambientStatusCommand extends Command {
    public function handle(): int {
        if ($runId = $this->argument('run')) {
            $this->showRunStatus(WorkflowRun::findOrFail($runId));
        } else {
            $this->showAllRuns();
        }

        return self::SUCCESS;
    }

    private function showRunStatus(WorkflowRun $run): void {
        info("Workflow: {$run->project_name}");

        table(
            headers: ['Step', 'Status', 'Duration'],
            rows: $run->steps->map(fn($step) => [
                $step->step_name,
                match($step->status) {
                    'completed' => '<fg=green>✓ ' . $step->status . '</>',
                    'failed' => '<fg=red>✗ ' . $step->status . '</>',
                    'running' => '<fg=yellow>⟳ ' . $step->status . '</>',
                    default => $step->status,
                },
                $step->duration_seconds ? gmdate('i:s', $step->duration_seconds) : '-',
            ])
        );

        if ($run->status === WorkflowStatus::Running && $run->current_step === 'monitor') {
            // WHY: Real-time status for cloud processing
            spin(
                callback: fn() => app(ImagenApiClient::class)->getStatus($run->imagen_project_uuid),
                message: 'Fetching cloud status...'
            );
        }
    }
}
```

---

## Part 4: Step-Based Orchestration Model

### State Machine Definition

```
┌─────────────┐
│   PENDING   │
└──────┬──────┘
       │ start()
       ▼
┌─────────────┐
│   RUNNING   │◄────────┐
└──────┬──────┘         │
       │                │ resume()
       ├─► PREPARE      │
       ├─► ANALYZE      │
       ├─► PROCESS      │
       ├─► UPLOAD       │
       ├─► MONITOR      ├─┐
       ├─► DOWNLOAD     │ │ pause()
       └─► FINALIZE     │ │
           │            │ │
           ▼            │ │
     ┌───────────┐     │ │
     │ COMPLETED │     │ │
     └───────────┘     │ │
                       ▼ │
                  ┌────────┐
                  │ PAUSED │
                  └────────┘
                       │
                       ▼ exception
                  ┌────────┐
                  │ FAILED │
                  └────────┘
```

### Step Definitions

#### STEP 1: PREPARE

**Purpose**: Validate inputs and prepare workspace

**Inputs**:
```php
- WorkflowConfig $config
- string $imageDirectory
- string $outputDirectory
```

**Responsibilities**:
1. Validate image directory exists and is readable
2. Count JPG files (fail if 0)
3. Check exiftool availability
4. Check ImageMagick availability
5. Create output directory structure:
   - `{output}/metadata/`
   - `{output}/scripts/`
   - `{output}/flambient/`
   - `{output}/temp/`
6. Validate API key if not process-only mode

**Outputs**:
```php
class PrepareResult extends ProcessingResult {
    public int $imageCount;
    public array $imagePaths;
    public string $workspaceRoot;
    public bool $exiftoolAvailable;
    public bool $imageMagickAvailable;
}
```

**Failure Handling**:
- **No images found** → Fail immediately (cannot proceed)
- **Missing exiftool** → Fail (required for EXIF)
- **Missing ImageMagick** → Fail (required for blending)
- **Cannot create output dirs** → Fail (permission issue)

**Resume Strategy**: Re-run (fast, idempotent)

**Idempotency**: Check if output directories already exist, skip creation if present

---

#### STEP 2: ANALYZE

**Purpose**: Extract EXIF metadata and group images

**Inputs**:
```php
- PrepareResult $prepareResult
- WorkflowConfig $config
```

**Responsibilities**:
1. Run exiftool on all images:
   ```bash
   exiftool -q -csv -ext jpg -Filename -DateTimeOriginal -Flash# {directory}
   ```
2. Parse CSV output
3. Sort by DateTimeOriginal
4. Classify images:
   - `Flash# == 16` → Ambient
   - `Flash# != 16` → Flash
5. Group logic (from existing AWK):
   ```
   consecutive ambient images → group N
   flash images following ambient → append to group N
   new ambient after flash → new group N+1
   ```
6. Write `metadata/metadata_final.csv`
7. Create `ImageGroup` DTOs

**Outputs**:
```php
class AnalyzeResult extends ProcessingResult {
    public Collection $groups;        // Collection<ImageGroup>
    public int $ambientCount;
    public int $flashCount;
    public int $groupCount;
    public string $metadataPath;      // CSV file path
}

class ImageGroup {
    public int $groupNumber;
    public Collection $ambientImages; // Collection<ImageFile>
    public Collection $flashImages;   // Collection<ImageFile>
    public bool $hasAmbient;
    public bool $hasFlash;
}
```

**Failure Handling**:
- **exiftool fails** → Retry 3 times, then fail
- **No Flash# field** → Warning, classify all as ambient
- **No groups created** → Warning, prompt user to continue
- **CSV write fails** → Fail (needed for audit trail)

**Resume Strategy**: Re-run (relatively fast, <30s for 100 images)

**Idempotency**: Check if `metadata_final.csv` exists with valid content, parse and return

---

#### STEP 3: PROCESS

**Purpose**: Generate and execute ImageMagick blend scripts

**Inputs**:
```php
- AnalyzeResult $analyzeResult
- WorkflowConfig $config
```

**Responsibilities**:
1. For each group, generate `.mgk` script:
   ```
   IF group has only ambient:
     → Merge with -compose lighten → output

   IF group has only flash:
     → Merge with -compose lighten → output
     → Optionally create darkened version

   IF group has both ambient AND flash:
     → Execute full flambient blend (7-step process from current AWK)
   ```
2. Write scripts to `scripts/group_{N}.mgk`
3. Generate master script `scripts/run_all_scripts.sh`
4. Execute master script
5. Validate output files exist
6. Move original images to `exposures/`

**Outputs**:
```php
class ProcessResult extends ProcessingResult {
    public int $scriptsGenerated;
    public int $blendedCount;
    public int $darkenedCount;
    public array $outputPaths;        // Paths to flambient/*.jpg
    public int $durationSeconds;
}
```

**Failure Handling**:
- **Script generation fails** → Fail (logic error)
- **ImageMagick execution fails** → Parse stderr, identify group, log error, continue with next group
- **Output validation fails** → List missing files, prompt to continue
- **Disk space exhausted** → Fail with clear message

**Resume Strategy**:
- Check which groups already have output files
- Skip completed groups
- Re-run missing groups only

**Idempotency**: Check if `flambient/{prefix}_{N}.jpg` exists for each group

---

#### STEP 4: UPLOAD (Conditional - Skip if process_only = true)

**Purpose**: Upload blended images to Imagen AI

**Inputs**:
```php
- ProcessResult $processResult
- WorkflowConfig $config
```

**Responsibilities**:
1. Call `POST /projects/` → Extract `project_uuid`
2. Store UUID in database immediately
3. Call `POST /projects/{uuid}/get_temporary_upload_links`
   - Request N links for N files
4. Upload files in parallel (up to 5 concurrent):
   ```bash
   curl -X PUT {upload_link} --data-binary @{file}
   ```
5. Track upload progress
6. Call `POST /projects/{uuid}/edit` with profile parameters
7. Record all API interactions in database

**Outputs**:
```php
class UploadResult extends ProcessingResult {
    public string $projectUuid;
    public int $fileCount;
    public int $uploadedCount;
    public int $failedCount;
    public array $failedFiles;        // Files that failed to upload
    public int $durationSeconds;
}
```

**Failure Handling**:
- **Project creation fails** → Retry 3 times with exponential backoff, then fail
- **UUID extraction fails** → Fail (cannot proceed without UUID)
- **Upload link request fails** → Retry 3 times, then fail
- **File upload fails** → Retry failed file 3 times, then skip and continue (partial upload)
- **Edit submission fails** → Retry 3 times, then fail
- **Network timeout** → Log, retry with increased timeout

**Resume Strategy**:
- Check if `proyecto_uuid` exists in database
- If yes, skip project creation
- Get list of already-uploaded files from API
- Upload only missing files

**Idempotency**:
- Project creation: Check database for existing UUID
- Upload: API should handle duplicate uploads gracefully

---

#### STEP 5: MONITOR (Conditional - Skip if process_only = true)

**Purpose**: Poll cloud processing status until complete

**Inputs**:
```php
- UploadResult $uploadResult
- WorkflowConfig $config
```

**Responsibilities**:
1. Poll `GET /projects/{uuid}/edit/status` at intervals:
   - First 5 checks: every 15 seconds
   - Next 10 checks: every 30 seconds
   - Remaining: every 60 seconds
2. Parse response: `{"data": {"status": "...", "progress": N}}`
3. Update database with each poll
4. Exit conditions:
   - `status == "Completed"` → Success
   - `status == "Failed"` → Fail with error
   - Timeout (2 hours) → Fail with timeout message

**Outputs**:
```php
class MonitorResult extends ProcessingResult {
    public string $finalStatus;       // Completed, Failed
    public int $pollCount;
    public int $durationMinutes;
    public ?string $errorMessage;
    public array $pollHistory;        // [{timestamp, status, progress}]
}
```

**Failure Handling**:
- **API call fails** → Retry 3 times, continue polling (transient network issue)
- **Status = "Failed"** → Record error, prompt user for action (download partial results?)
- **Timeout reached** → Pause workflow, save state, allow resume later
- **Unexpected status** → Log warning, continue polling

**Resume Strategy**:
- Get last known status from database
- Continue polling from current state
- No need to re-check previous polls

**Idempotency**: Polling is inherently idempotent (read-only API)

---

#### STEP 6: DOWNLOAD (Conditional - Skip if process_only = true)

**Purpose**: Download enhanced images from Imagen AI

**Inputs**:
```php
- MonitorResult $monitorResult
- UploadResult $uploadResult
- WorkflowConfig $config
```

**Responsibilities**:
1. Call `GET /projects/{uuid}/edit/get_temporary_download_links`
2. Parse response: `{"data": {"files_list": [{file_name, download_link}]}}`
3. Download files in parallel (up to 5 concurrent):
   ```bash
   curl -o {output}/{file_name} {download_link}
   ```
4. Validate file sizes (should be > 0)
5. Optionally verify file integrity (JPEG magic bytes)

**Outputs**:
```php
class DownloadResult extends ProcessingResult {
    public int $fileCount;
    public int $downloadedCount;
    public int $failedCount;
    public array $downloadedPaths;
    public int $totalBytes;
    public int $durationSeconds;
}
```

**Failure Handling**:
- **Get links fails** → Retry 3 times, then fail
- **File download fails** → Retry file 3 times, then skip and continue (partial download)
- **Zero-byte file** → Delete, mark as failed, log error
- **Disk space exhausted** → Fail with clear message

**Resume Strategy**:
- Check which files already exist in output directory
- Download only missing files
- Re-download zero-byte files

**Idempotency**: Check if file exists with size > 0

---

#### STEP 7: FINALIZE

**Purpose**: Cleanup and summary generation

**Inputs**:
```php
- All previous step results
- WorkflowConfig $config
```

**Responsibilities**:
1. Update workflow run status to `completed`
2. Set `completed_at` timestamp
3. Calculate total duration
4. Generate summary report:
   - Total images processed
   - Total groups created
   - Total duration
   - Output paths
   - Errors encountered (if any)
5. Clean up temp files (unless `--keep-temp` flag)
6. Generate JSON summary file
7. Archive logs

**Outputs**:
```php
class FinalizeResult extends ProcessingResult {
    public string $summaryPath;       // JSON summary file
    public int $totalImages;
    public int $totalGroups;
    public int $totalDurationSeconds;
    public string $outputDirectory;
    public array $errors;
    public bool $tempCleaned;
}
```

**Failure Handling**:
- **Cleanup fails** → Log warning, continue (non-critical)
- **Summary generation fails** → Log error, continue (non-critical)
- **Always succeed** → This step should not fail workflow

**Resume Strategy**: Re-run (idempotent, fast)

**Idempotency**: Check if summary file exists, skip if present

---

### Step Execution Contract

```php
abstract class AbstractStep {
    // Every step must implement this
    abstract public function execute(
        WorkflowRun $run,
        mixed $previousStepOutput
    ): ProcessingResult;

    // Every step should define these
    abstract public function validate(WorkflowRun $run): bool;
    abstract public function canResume(WorkflowRun $run): bool;
    abstract public function isCompleted(WorkflowRun $run): bool;

    // Optional hooks
    public function beforeExecute(WorkflowRun $run): void {}
    public function afterExecute(WorkflowRun $run, ProcessingResult $result): void {}
    public function onFailure(WorkflowRun $run, \Throwable $exception): void {}
}
```

### Orchestration Flow

```php
class WorkflowOrchestrator {
    private const STEPS = [
        StepName::Prepare,
        StepName::Analyze,
        StepName::Process,
        StepName::Upload,    // Conditional
        StepName::Monitor,   // Conditional
        StepName::Download,  // Conditional
        StepName::Finalize,
    ];

    public function execute(): void {
        foreach (self::STEPS as $stepName) {
            // Skip cloud steps if process-only
            if ($this->shouldSkipStep($stepName)) {
                $this->recordStepSkipped($stepName);
                continue;
            }

            // Execute step
            $this->executeStep($stepName);
        }
    }

    public function executeStep(StepName $stepName): ProcessingResult {
        $step = $this->getStepInstance($stepName);

        // Check if already completed (resume case)
        if ($step->isCompleted($this->run)) {
            return $this->getStoredResult($stepName);
        }

        // Validate preconditions
        if (!$step->validate($this->run)) {
            throw new StepValidationException("Step {$stepName->value} validation failed");
        }

        // Record start
        $stepRecord = $this->recordStepStart($stepName);

        try {
            // Execute
            $step->beforeExecute($this->run);
            $result = $step->execute($this->run, $this->getLastStepOutput());
            $step->afterExecute($this->run, $result);

            // Record success
            $this->recordStepComplete($stepRecord, $result);

            return $result;

        } catch (\Throwable $e) {
            // Record failure
            $step->onFailure($this->run, $e);
            $this->recordStepFailure($stepRecord, $e);

            throw $e;
        }
    }
}
```

---

## Summary & Next Steps

### What This Architecture Provides

✅ **Explicit State** - Database-backed workflow tracking
✅ **Recoverability** - Resume from any step
✅ **Robust Error Handling** - Retries, fallbacks, and user prompts
✅ **Clear UX** - Laravel Prompts guide users through every decision
✅ **Testability** - Step classes can be unit tested
✅ **Auditability** - Every API call and step logged
✅ **Extensibility** - Easy to add new steps or modify existing ones
✅ **Future-Ready** - Designed for eventual async/queue migration

### Migration Path

**Phase 1: Foundation** (Week 1)
- Create Laravel application
- Set up database schema
- Implement Step classes (without ImageMagick/API logic)
- Write unit tests for step orchestration

**Phase 2: Core Logic** (Week 2)
- Port EXIF extraction to `ExifService`
- Port ImageMagick script generation to PHP
- Implement `ImageMagickService` wrapper
- Test blending logic against current scripts

**Phase 3: API Integration** (Week 3)
- Implement `ImagenApiClient`
- Add retry logic and error handling
- Test upload/poll/download flow
- Validate against current API behavior

**Phase 4: CLI Experience** (Week 4)
- Build `FlambientProcessCommand` with prompts
- Add supporting commands (resume, status, logs)
- Test full workflow end-to-end
- Migration guide for existing users

**Phase 5: Optimization** (Week 5+)
- Add parallel processing (ImageMagick groups, uploads)
- Implement background jobs (optional)
- Add webhooks for status notifications (optional)
- Performance tuning

---

## Configuration File

```php
// config/flambient.php
return [
    'imagen' => [
        'api_key' => env('IMAGEN_AI_API_KEY'),
        'api_base_url' => env('IMAGEN_API_BASE_URL', 'https://api-beta.imagen-ai.com/v1'),
        'profile_key' => env('IMAGEN_PROFILE_KEY', 309406),
        'timeout' => env('IMAGEN_TIMEOUT', 30),
        'retry_times' => env('IMAGEN_RETRY_TIMES', 3),
    ],

    'imagemagick' => [
        'binary' => env('IMAGEMAGICK_BINARY', 'magick'),
        'level_low' => env('IMAGEMAGICK_LEVEL_LOW', '40%'),
        'level_high' => env('IMAGEMAGICK_LEVEL_HIGH', '140%'),
        'gamma' => env('IMAGEMAGICK_GAMMA', '1.0'),
        'output_prefix' => env('IMAGEMAGICK_OUTPUT_PREFIX', 'flambient'),
        'enable_darken_export' => env('IMAGEMAGICK_DARKEN_EXPORT', true),
        'darken_suffix' => env('IMAGEMAGICK_DARKEN_SUFFIX', '_tmp'),
    ],

    'workflow' => [
        'storage_path' => env('FLAMBIENT_STORAGE_PATH', storage_path('flambient')),
        'keep_temp_files' => env('FLAMBIENT_KEEP_TEMP', false),
        'parallel_uploads' => env('FLAMBIENT_PARALLEL_UPLOADS', 5),
        'parallel_downloads' => env('FLAMBIENT_PARALLEL_DOWNLOADS', 5),
    ],

    'polling' => [
        'initial_interval' => 15,  // seconds
        'max_interval' => 60,
        'timeout_minutes' => 120,
    ],
];
```

---

This architecture transforms your fragile shell scripts into a **production-grade Laravel application** with clear separation of concerns, robust error handling, and excellent user experience. Every design decision prioritizes **maintainability**, **recoverability**, and **clarity**.
