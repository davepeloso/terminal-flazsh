# 🎨 Flambient Photography Processor

A **Laravel 11 CLI application** for processing flambient photography workflows with ImageMagick and optional Imagen AI cloud enhancement.

## What is Flambient Photography?

Flambient is a real estate photography technique that combines:
- **Ambient** exposures (natural/available light)
- **Flash** exposures (artificial light)

Using ImageMagick blending techniques to create perfectly balanced, natural-looking interior photos.

---

## 🏗️ Architecture

This application represents a **complete architectural redesign** from fragile shell scripts to a robust Laravel-native system featuring:

✅ **Explicit State Management** - SQLite database tracks all workflow execution
✅ **Step-Based Orchestration** - 7 explicit steps with failure recovery
✅ **Laravel Prompts UX** - Interactive CLI with validation and progress feedback
✅ **Resume Capability** - Pick up where you left off after failures
✅ **Database Audit Trail** - Every step logged with timing and results
✅ **Type-Safe Architecture** - PHP 8.2+ Enums and readonly DTOs

### Database Schema

```
workflow_runs
├── id (UUID)
├── project_name
├── config (JSON snapshot)
├── status (pending|running|completed|failed|paused)
├── current_step
└── timestamps

workflow_steps
├── workflow_run_id
├── step_name (prepare|analyze|process|upload|monitor|download|finalize)
├── status
├── input_data / output_data (JSON)
└── timing metrics

workflow_files
├── workflow_run_id
├── original_path
├── processed_path
├── file_type (ambient|flash|blended)
└── exif_data (JSON)
```

---

## 🚀 Installation

### Prerequisites

- PHP 8.2+ with SQLite extension
- Composer
- ImageMagick (`magick` command)
- exiftool (for EXIF metadata extraction)
- Optional: Imagen AI API key (for cloud enhancement)

### Setup

```bash
# 1. Clone repository
git clone <repository-url>
cd terminal-flazsh

# 2. Install dependencies
composer install

# 3. Configure environment
cp .env.example .env
php artisan key:generate

# 4. Create database
touch database/database.sqlite

# 5. Run migrations
php artisan migrate

# 6. (Optional) Add Imagen AI API key
# Edit .env and set: IMAGEN_AI_API_KEY=your_key_here
```

---

## 📖 Usage

### Basic Workflow

```bash
php artisan flambient:process
```

This launches an **interactive CLI** with Laravel Prompts that will:

1. ✅ **Validate project name** (unique, valid characters)
2. ✅ **Validate image directory** (exists, contains JPGs)
3. ✅ **Choose processing mode** (local-only or full cloud)
4. ✅ **Configure ImageMagick parameters** (optional customization)
5. ✅ **Confirm before starting** (show summary)
6. ✅ **Execute 7-step workflow** with progress indicators
7. ✅ **Display results summary** with database tracking

### Command Options

```bash
# Specify project name and directory upfront
php artisan flambient:process --project=my-project --dir=/path/to/images

# Local-only mode (skip cloud upload)
php artisan flambient:process --local

# Combined
php artisan flambient:process --project=test --dir=./images --local
```

### Workflow Steps

The processor executes **7 explicit steps**:

| Step | Name | Purpose | Can Skip |
|------|------|---------|----------|
| 1 | **Prepare** | Validate inputs, create workspace | Never |
| 2 | **Analyze** | Extract EXIF, classify & group images | Never |
| 3 | **Process** | Generate & execute ImageMagick blend scripts | Never |
| 4 | **Upload** | Upload blended images to Imagen AI | If `--local` |
| 5 | **Monitor** | Poll cloud processing status | If `--local` |
| 6 | **Download** | Retrieve enhanced images | If `--local` |
| 7 | **Finalize** | Cleanup, generate summary | Never |

---

## 🎯 Laravel Prompts Features

The CLI demonstrates **best-in-class UX** with Laravel Prompts:

### Input Validation

```php
text(
    label: 'Project name',
    validate: fn($value) => match(true) {
        strlen($value) < 3 => 'Must be at least 3 characters',
        !preg_match('/^[a-zA-Z0-9_-]+$/', $value) => 'Invalid characters',
        WorkflowRun::where('project_name', $value)->exists() => 'Already exists',
        default => null,
    }
)
```

### Directory Validation

```php
text(
    label: 'Image directory',
    validate: function($value) {
        if (!is_dir($value)) return "Directory does not exist";
        if (count(glob("{$value}/*.jpg")) === 0) return "No JPG files found";
        return null;
    }
)
```

### Mode Selection

```php
select(
    label: 'Processing mode',
    options: [
        'full' => 'ImageMagick + Imagen AI cloud enhancement',
        'local' => 'Local only (no cloud upload)',
    ],
    hint: 'Cloud processing requires API key and costs money'
)
```

### Confirmation Gates

```php
confirm('Start processing?', default: true)
confirm('Proceed with upload?', default: true)
confirm('Customize ImageMagick parameters?', default: false)
```

### Progress Indicators

```php
spin(
    callback: fn() => $orchestrator->executeStep(StepName::Prepare),
    message: 'Validating inputs and creating directories...'
)
```

### Status Tables

```php
table(
    ['Type', 'Count'],
    [
        ['Ambient images', 15],
        ['Flash images', 10],
        ['Groups created', 5],
    ]
)
```

---

## 🗂️ Project Structure

```
app/
├── Console/Commands/
│   └── FlambientProcessCommand.php      # Main interactive CLI
├── DataObjects/
│   ├── WorkflowConfig.php               # Immutable configuration DTO
│   └── ProcessingResult.php             # Step result DTO
├── Enums/
│   ├── WorkflowStatus.php               # pending|running|completed|failed|paused
│   ├── StepName.php                     # prepare|analyze|process|etc
│   └── ImageType.php                    # ambient|flash|blended
├── Models/
│   ├── WorkflowRun.php                  # Main workflow record
│   ├── WorkflowStep.php                 # Individual step tracking
│   └── WorkflowFile.php                 # Processed file tracking
└── Services/Flambient/                  # (To be implemented)
    ├── WorkflowOrchestrator.php
    ├── ExifService.php
    ├── ImageMagickService.php
    └── ImagenApiClient.php

config/
└── flambient.php                        # Centralized configuration

database/
├── database.sqlite                      # SQLite database
└── migrations/
    ├── *_create_workflow_runs_table.php
    ├── *_create_workflow_steps_table.php
    └── *_create_workflow_files_table.php
```

---

## 🔧 Configuration

All configuration is managed through `config/flambient.php` and environment variables:

### Imagen AI

```env
IMAGEN_AI_API_KEY=your_api_key_here
IMAGEN_API_BASE_URL=https://api-beta.imagen-ai.com/v1
IMAGEN_PROFILE_KEY=309406
IMAGEN_TIMEOUT=30
IMAGEN_RETRY_TIMES=3
```

### ImageMagick

```env
IMAGEMAGICK_BINARY=magick
IMAGEMAGICK_LEVEL_LOW=40%           # Ambient mask threshold
IMAGEMAGICK_LEVEL_HIGH=140%         # Ambient mask upper bound
IMAGEMAGICK_GAMMA=1.0               # Gamma correction
IMAGEMAGICK_OUTPUT_PREFIX=flambient
IMAGEMAGICK_DARKEN_EXPORT=true
IMAGEMAGICK_DARKEN_SUFFIX=_tmp
```

### Workflow

```env
FLAMBIENT_STORAGE_PATH=             # Defaults to storage/flambient
FLAMBIENT_KEEP_TEMP=false           # Keep temporary files
FLAMBIENT_PARALLEL_UPLOADS=5        # Concurrent uploads
FLAMBIENT_PARALLEL_DOWNLOADS=5      # Concurrent downloads
```

---

## 📊 Querying Workflow Data

Since all state is persisted to SQLite, you can query workflow history:

```bash
# Enter tinker shell
php artisan tinker

# Get all workflows
WorkflowRun::all()

# Get a specific workflow
WorkflowRun::find('uuid-here')

# Get completed workflows
WorkflowRun::where('status', 'completed')->get()

# Get workflow with steps
$run = WorkflowRun::with('steps')->find('uuid');
$run->steps;

# Get workflow files
$run->files;
```

---

## 🎬 Example Session

```bash
$ php artisan flambient:process

🎨 Flambient Photography Processor

This workflow will process flambient images using ImageMagick
and optionally enhance them with Imagen AI.

┌ Project name ────────────────────────────────────────────┐
│ my-property-shoot                                        │
└──────────────────────────────────────────────────────────┘

┌ Image directory ─────────────────────────────────────────┐
│ /Users/me/photos/shoot-2024-01                           │
└──────────────────────────────────────────────────────────┘
Directory containing your ambient and flash images

┌ Processing mode ─────────────────────────────────────────┐
│ ● Full workflow (ImageMagick + Imagen AI)               │
│ ○ Local only (ImageMagick blending)                     │
└──────────────────────────────────────────────────────────┘
Cloud processing requires API key and costs money

┌ Customize ImageMagick blending parameters? ──────────────┐
│ ○ Yes / ● No                                             │
└──────────────────────────────────────────────────────────┘

Configuration summary:
  Project: my-property-shoot
  Images: /Users/me/photos/shoot-2024-01
  Output: /app/storage/flambient/my-property-shoot
  Mode: Full (with cloud)
  Blending: 40%/140%/γ1.0

┌ Start processing? ───────────────────────────────────────┐
│ ● Yes / ○ No                                             │
└──────────────────────────────────────────────────────────┘

Workflow created: 9c8f3a42-...
You can check status with: php artisan flambient:status 9c8f3a42-...

Step 1/7: Preparing workspace
⠹ Validating inputs and creating directories...
✓ Found 25 images

Step 2/7: Analyzing images
⠹ Extracting EXIF metadata and grouping images...

┌────────────────┬───────┐
│ Type           │ Count │
├────────────────┼───────┤
│ Ambient images │ 15    │
│ Flash images   │ 10    │
│ Groups created │ 5     │
└────────────────┴───────┘

... (continues through all 7 steps) ...

🎉 Workflow Complete                                 SUCCESS

┌──────────────────┬────────────────────────────────────────┐
│ Metric           │ Value                                  │
├──────────────────┼────────────────────────────────────────┤
│ Project Name     │ my-property-shoot                      │
│ Total Duration   │ 2 minutes                              │
│ Images Processed │ 25                                     │
│ Groups Created   │ 5                                      │
│ Output Directory │ /app/storage/flambient/my-property-... │
└──────────────────┴────────────────────────────────────────┘

View results: ls /app/storage/flambient/my-property-shoot/flambient
View database: php artisan tinker -> WorkflowRun::find('9c8f3a42-...')
```

---

## 🔄 Migration from Shell Scripts

### Old Workflow (Shell Scripts)

```bash
./master_workflow.zsh my-project /path/to/images
# - Fragile UUID extraction via regex
# - Continues on failures
# - No resume capability
# - JSON logs manually constructed
# - Hard-coded directory coupling
```

### New Workflow (Laravel)

```bash
php artisan flambient:process
# ✅ Interactive prompts with validation
# ✅ Database-backed state
# ✅ Resume from any failure point
# ✅ Structured logging
# ✅ Type-safe configuration
```

---

## 🚧 Current Status (MVP)

### ✅ Implemented

- Complete Laravel 11 setup with SQLite
- Database migrations (workflow_runs, workflow_steps, workflow_files)
- Eloquent models with relationships
- Type-safe Enums (WorkflowStatus, StepName, ImageType)
- Configuration system (config/flambient.php)
- Data Transfer Objects (WorkflowConfig, ProcessingResult)
- **FlambientProcessCommand** with full Laravel Prompts integration
- Workflow simulation demonstrating all 7 steps

### 🔨 To Be Implemented (Future)

- **ExifService** - Port EXIF extraction from shell script
- **ImageMagickService** - Port .mgk script generation from AWK
- **ImagenApiClient** - API wrapper with retry logic
- **WorkflowOrchestrator** - State machine coordinator
- All 7 Step classes (Prepare, Analyze, Process, Upload, Monitor, Download, Finalize)
- Additional commands:
  - `flambient:status {run}` - Check workflow status
  - `flambient:resume {run}` - Resume failed workflow
  - `flambient:list` - List all workflows
  - `flambient:cleanup` - Clean temp files

---

## 📚 Architecture Document

For a comprehensive breakdown of the architectural decisions, design patterns, and implementation phases, see:

**[ARCHITECTURE_REDESIGN.md](./ARCHITECTURE_REDESIGN.md)**

This document includes:
- Critical workflow review with breakpoint analysis
- Complete Laravel-native architecture design
- CLI experience redesign using Laravel Prompts
- Step-based orchestration model with state machine
- Database schema rationale
- Migration path from shell scripts

---

## 🤝 Contributing

This is a proof-of-concept MVP demonstrating the architectural transformation.

To extend the implementation:

1. Implement service classes in `app/Services/Flambient/`
2. Create step classes in `app/Services/Flambient/Steps/`
3. Build the `WorkflowOrchestrator` to coordinate execution
4. Add unit tests in `tests/Unit/Services/`
5. Add feature tests in `tests/Feature/`

---

## 📝 License

MIT

---

## 🙏 Acknowledgments

- **Laravel Framework** - Elegant PHP framework
- **Laravel Prompts** - Beautiful CLI interactions
- **ImageMagick** - Image processing powerhouse
- **Imagen AI** - Cloud-based photo enhancement

---

**Built with ❤️ using Laravel 11 and PHP 8.2**
