# 🎨 Terminal Flazsh - Flambient Photography Processor

A **production-ready Laravel 11 CLI application** for automated flambient photography processing with ImageMagick local blending and Imagen AI cloud enhancement.

> **Flambient Photography**: A real estate photography technique combining ambient (natural light) and flash (artificial light) exposures using advanced blending to create perfectly balanced, professional interior photos.

[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat&logo=php)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11-FF2D20?style=flat&logo=laravel)](https://laravel.com)
[![Tests](https://img.shields.io/badge/Tests-46%20Passing-00D66A?style=flat)]()
[![License](https://img.shields.io/badge/License-MIT-green?style=flat)]()

---

## 📖 Table of Contents

- [Features](#-features)
- [Architecture](#-architecture)
- [Installation](#-installation)
- [Quick Start](#-quick-start)
- [Usage](#-usage)
- [Configuration](#-configuration)
- [Testing](#-testing)
- [Project Structure](#-project-structure)
- [API Documentation](#-api-documentation)
- [Future Improvements](#-future-improvements)
- [Contributing](#-contributing)

---

## ✨ Features

### **Complete End-to-End Workflow**

**Local Processing:**
- ✅ **Flexible EXIF Classification** - Choose from 6+ fields (Flash, Exposure Mode, White Balance, ISO, etc.)
- ✅ **Dual EXIF Extraction** - Numeric values for logic + human-readable labels for display
- ✅ **Smart Image Grouping** - Automatic pairing of ambient/flash sequences by timestamp
- ✅ **ImageMagick Blending** - 7-step flambient algorithm with customizable parameters
- ✅ **Batch Processing** - Handle hundreds of images efficiently

**Cloud Enhancement (Optional):**
- ✅ **Imagen AI Integration** - Native PHP client for automated cloud enhancement
- ✅ **Progress Tracking** - Real-time upload/processing/download progress with emojis
- ✅ **Automatic Polling** - Monitor AI processing until completion (up to 2 hours)
- ✅ **JPEG Export** - Automatic conversion and download of enhanced images
- ✅ **Resume Capability** - Save project UUID to database for later access

### **Developer Experience**

- ✅ **Laravel Prompts** - Beautiful interactive CLI with validation
- ✅ **Type Safety** - PHP 8.2+ readonly classes, backed enums
- ✅ **Database State** - SQLite tracks all workflow execution
- ✅ **Comprehensive Testing** - 46 PHPUnit tests with 100% Http::fake() coverage
- ✅ **Error Handling** - Graceful failures with actionable messages
- ✅ **Configurable** - Environment-based settings with sane defaults

### **Production Ready**

- ✅ **State Machine** - 8-step workflow with explicit transitions
- ✅ **Audit Trail** - Database logging of all operations
- ✅ **Progress Indicators** - Spinners, tables, emoji status updates
- ✅ **Resumable** - Pause/resume long-running operations
- ✅ **Testable** - Mock all external dependencies (exiftool, ImageMagick, Imagen API)

---

## 🏗️ Architecture

This application represents a **complete architectural redesign** from fragile shell scripts to a robust, Laravel-native system.

### **Key Design Principles**

1. **Explicit State Management** - All workflow state persisted to SQLite
2. **Type Safety** - Enums, DTOs, readonly classes throughout
3. **Separation of Concerns** - Services for EXIF, ImageMagick, Imagen AI
4. **Laravel-Native** - HTTP client, Prompts, Collections, Eloquent
5. **Testability** - All external dependencies mockable

### **Workflow Steps**

| Step | Name | Purpose | Skippable |
|------|------|---------|-----------|
| 1 | **Prepare** | Validate inputs, create workspace | ❌ |
| 2 | **Analyze** | Extract EXIF, classify & group images | ❌ |
| 3 | **Process** | Generate & execute ImageMagick scripts | ❌ |
| 4 | **Upload** | Upload blended images to Imagen AI | ✅ `--local` |
| 5 | **Edit** | Submit for AI enhancement | ✅ `--local` |
| 6 | **Monitor** | Poll processing status | ✅ `--local` |
| 7 | **Export** | Convert to JPEG format | ✅ `--local` |
| 8 | **Download** | Retrieve enhanced images | ✅ `--local` |

### **Database Schema**

```sql
workflow_runs
├── id (UUID primary key)
├── project_name (string, unique)
├── config (JSON snapshot)
├── status (pending|running|completed|failed|paused)
├── imagen_project_uuid (nullable)
└── total_images_processed, total_groups_created

workflow_steps
├── workflow_run_id (foreign key)
├── step_name (prepare|analyze|process|upload|monitor|export|download|finalize)
├── status, input_data, output_data (JSON)
└── duration_seconds, retry_count

workflow_files
├── workflow_run_id (foreign key)
├── original_path, processed_path
├── file_type (ambient|flash|blended)
└── exif_data (JSON)
```

---

## 📦 Installation

### **Prerequisites**

- **PHP 8.2+** with SQLite extension
- **Composer** for dependency management
- **ImageMagick** (`magick` command available)
- **exiftool** for EXIF metadata extraction
- **Imagen AI API key** (optional, for cloud enhancement)

### **Setup**

```bash
# 1. Clone repository
git clone https://github.com/yourusername/terminal-flazsh.git
cd terminal-flazsh

# 2. Install dependencies
composer install

# 3. Configure environment
cp .env.example .env
php artisan key:generate

# 4. Set up database
touch database/database.sqlite
php artisan migrate

# 5. (Optional) Configure Imagen AI
# Edit .env and add:
# IMAGEN_AI_API_KEY=your_key_here
# IMAGEN_PROFILE_KEY=309406
```

### **Verify Installation**

```bash
# Check PHP version
php -v  # Should be 8.2+

# Check ImageMagick
magick -version

# Check exiftool
exiftool -ver

# Run tests
php artisan test
```

---

## 🚀 Quick Start

### **Local-Only Processing (No Cloud)**

```bash
php artisan flambient:process \
  --project="my-first-shoot" \
  --dir="/path/to/images" \
  --local
```

This will:
1. Extract EXIF metadata from your images
2. Classify images as Ambient or Flash based on EXIF fields
3. Group images by timestamp
4. Generate ImageMagick blend scripts
5. Create blended flambient images in `storage/flambient/my-first-shoot/flambient/`

### **Full Workflow (With Imagen AI)**

```bash
php artisan flambient:process \
  --project="real-estate-shoot" \
  --dir="/path/to/images"
```

This will:
1. Perform local ImageMagick blending (steps 1-3)
2. Upload blended images to Imagen AI (step 4)
3. Monitor AI processing with real-time progress (step 5-6)
4. Export and download enhanced images (steps 7-8)
5. Save both blended and enhanced versions locally

### **Interactive Mode**

Simply run without arguments for a guided experience:

```bash
php artisan flambient:process
```

The CLI will interactively prompt for:
- Project name
- Image directory
- EXIF classification strategy
- Processing mode (local vs. cloud)

---

## 📖 Usage

### **Command Options**

```bash
php artisan flambient:process [options]

Options:
  --project=NAME      Project name (unique identifier)
  --dir=PATH          Directory containing JPG images
  --output=PATH       Output directory (default: storage/flambient/{project})
  --local             Skip cloud processing (ImageMagick only)
```

### **EXIF Classification**

During analysis, you'll choose which EXIF field to use for classifying Ambient vs. Flash:

```
┌ Which EXIF field should identify Ambient images? ────┐
│ ● Flash (Flash = 16 for Ambient)                      │
│ ○ Exposure Program                                    │
│ ○ Exposure Mode                                       │
│ ○ White Balance                                       │
│ ○ ISO Value                                           │
│ ○ Shutter Speed                                       │
└────────────────────────────────────────────────────────┘
  Choose the field that differs between your ambient and flash shots
```

You can optionally view sample EXIF data first:

```
┌ How many sample images to display? ───────────────────┐
│ ● 3 samples                                            │
│ ○ 5 samples                                            │
│ ○ 10 samples                                           │
│ ○ 25 samples                                           │
└────────────────────────────────────────────────────────┘

┌──────────────┬──────────────────┬──────────────────┬──────────────┐
│ Filename     │ Flash            │ ExposureProgram  │ ExposureMode │
├──────────────┼──────────────────┼──────────────────┼──────────────┤
│ IMG_001.jpg  │ 16 (No Flash)    │ 1 (Manual)       │ 0 (Auto)     │
│ IMG_002.jpg  │ 0 (Flash Fired)  │ 2 (Program AE)   │ 1 (Manual)   │
│ IMG_003.jpg  │ 16 (No Flash)    │ 1 (Manual)       │ 0 (Auto)     │
└──────────────┴──────────────────┴──────────────────┴──────────────┘
```

### **Example Output**

```
Step 1/3: Preparing workspace
⠧ Validating inputs and creating directories...
✓ Workspace created: /storage/flambient/my-shoot

Step 2/3: Analyzing images
⠧ Extracting EXIF metadata and grouping images...
✓ Classified 54 images into 27 groups (9 Ambient, 18 Flash)

Step 3/3: Processing with ImageMagick
⠧ Generating and executing ImageMagick scripts...
✓ Created 27 blended images in 8.5s

Steps 4-8: Skipped (local-only mode - Imagen AI processing disabled)

✓ Processing complete!
  Output: /storage/flambient/my-shoot/flambient/
  Images: 27
```

### **With Imagen AI**

```
Step 4/8: Upload to Imagen AI
⚠ This will upload 27 blended images to Imagen AI for enhancement.

┌ Proceed with Imagen AI upload and processing? ────┐
│ ● Yes / ○ No                                       │
└────────────────────────────────────────────────────┘

⠧ Creating Imagen AI project...
✓ Project created: abc-123-def-456
  View at: https://app.imagen-ai.com/projects/abc-123-def-456

Uploading 27 images to Imagen AI...
  Uploaded 5/27: flambient_01.jpg
  Uploaded 10/27: flambient_05.jpg
✓ Upload complete: 27/27 files (100% success)

Step 6/8: Monitoring AI processing
This typically takes 10-30 minutes depending on image count.

⏳ Processing: 0% - queued
🔄 Processing: 25% - editing
⚡ Processing: 50% - editing
🚀 Processing: 90% - editing
🎉 Processing: 100% - completed
✓ AI editing complete!

Step 8/8: Downloading enhanced images
  Downloaded 5/27: edited_01.jpg
  Downloaded 10/27: edited_05.jpg
✓ Download complete: 27/27 files (100% success)
  Output: /storage/flambient/my-shoot/edited/

🎉 Workflow Complete                           SUCCESS

┌─────────────────┬───────────────────────────────┐
│ Metric          │ Value                         │
├─────────────────┼───────────────────────────────┤
│ Project Name    │ my-shoot                      │
│ Total Duration  │ 25 minutes                    │
│ Images Processed│ 54                            │
│ Groups Created  │ 27                            │
│ Blended Output  │ /storage/.../flambient/       │
│ Enhanced Output │ /storage/.../edited/          │
│ Imagen Project  │ abc-123-def-456               │
└─────────────────┴───────────────────────────────┘

🎨 Blended images: /storage/flambient/my-shoot/flambient/
✨ Enhanced images: /storage/flambient/my-shoot/edited/
🌐 Imagen project: https://app.imagen-ai.com/projects/abc-123-def-456
```

---

## ⚙️ Configuration

### **Environment Variables**

Edit `.env` to configure:

#### **Imagen AI Settings**

```env
IMAGEN_AI_API_KEY=your_api_key_here
IMAGEN_API_BASE_URL=https://api-beta.imagen-ai.com/v1
IMAGEN_PROFILE_KEY=309406
IMAGEN_TIMEOUT=30
IMAGEN_POLL_INTERVAL=30              # Seconds between status checks
IMAGEN_POLL_MAX_ATTEMPTS=240         # Max attempts (240 × 30s = 2 hours)
```

#### **ImageMagick Settings**

```env
IMAGEMAGICK_BINARY=magick
IMAGEMAGICK_LEVEL_LOW=40%            # Ambient mask threshold
IMAGEMAGICK_LEVEL_HIGH=140%          # Ambient mask upper bound
IMAGEMAGICK_GAMMA=1.0                # Gamma correction
IMAGEMAGICK_OUTPUT_PREFIX=flambient
IMAGEMAGICK_DARKEN_EXPORT=false      # Export darkened flash composite
IMAGEMAGICK_DARKEN_SUFFIX=_tmp
```

#### **Workflow Settings**

```env
FLAMBIENT_STORAGE_PATH=              # Default: storage/flambient
FLAMBIENT_KEEP_TEMP=false            # Keep temporary files
FLAMBIENT_PARALLEL_UPLOADS=5         # Concurrent uploads
FLAMBIENT_PARALLEL_DOWNLOADS=5       # Concurrent downloads
```

### **Configuration File**

Advanced settings in `config/flambient.php`:

```php
return [
    'imagen' => [
        'api_key' => env('IMAGEN_AI_API_KEY'),
        'base_url' => env('IMAGEN_API_BASE_URL', 'https://api-beta.imagen-ai.com/v1'),
        'profile_key' => env('IMAGEN_PROFILE_KEY', 309406),
        'timeout' => env('IMAGEN_TIMEOUT', 30),
        'poll_interval' => env('IMAGEN_POLL_INTERVAL', 30),
        'poll_max_attempts' => env('IMAGEN_POLL_MAX_ATTEMPTS', 240),
    ],

    'imagemagick' => [
        'level_low' => env('IMAGEMAGICK_LEVEL_LOW', '40%'),
        'level_high' => env('IMAGEMAGICK_LEVEL_HIGH', '140%'),
        'gamma' => env('IMAGEMAGICK_GAMMA', '1.0'),
    ],
];
```

---

## 🧪 Testing

### **Run All Tests**

```bash
php artisan test
```

Expected output:
```
PASS  Tests\Unit\ExifServiceTest
✓ it extracts exif metadata from images
✓ it classifies images using flash strategy
✓ it classifies images using exposure program strategy
... (11 tests)

PASS  Tests\Unit\ImageMagickServiceTest
✓ it generates imagemagick scripts for groups
✓ it generates correct mgk script content
... (9 tests)

PASS  Tests\Unit\ImagenClientTest
✓ it creates a project
✓ it handles create project failure
... (18 tests)

PASS  Tests\Feature\FlambientProcessCommandTest
✓ it processes flambient workflow end to end
✓ it allows selecting different classification strategies
... (8 tests)

Tests:    46 passed (117 assertions)
Duration: 2.34s
```

### **Run Specific Test Suites**

```bash
# EXIF extraction and classification
php artisan test --filter=ExifServiceTest

# ImageMagick script generation
php artisan test --filter=ImageMagickServiceTest

# Imagen AI client
php artisan test --filter=ImagenClientTest

# End-to-end workflow
php artisan test --filter=FlambientProcessCommandTest
```

### **Test Coverage**

The test suite covers:

- ✅ EXIF extraction (numeric + pretty)
- ✅ Image classification (6 strategies)
- ✅ Image grouping by timestamp
- ✅ ImageMagick script generation
- ✅ Imagen AI client (all endpoints)
- ✅ Upload/download with progress
- ✅ Polling with status tracking
- ✅ End-to-end workflow command
- ✅ Error handling and retries
- ✅ Success rate calculations

All external dependencies (exiftool, ImageMagick, Imagen API) are mocked using `Process::fake()` and `Http::fake()`.

---

## 📁 Project Structure

```
terminal-flazsh/
├── app/
│   ├── Console/Commands/
│   │   └── FlambientProcessCommand.php       # Main interactive CLI
│   │
│   ├── DataObjects/
│   │   ├── WorkflowConfig.php                # Immutable configuration DTO
│   │   └── ProcessingResult.php              # Step result DTO
│   │
│   ├── Enums/
│   │   ├── WorkflowStatus.php                # Workflow states
│   │   ├── StepName.php                      # Workflow steps
│   │   ├── ImageType.php                     # ambient|flash|blended
│   │   └── ImageClassificationStrategy.php   # EXIF field selection
│   │
│   ├── Models/
│   │   ├── WorkflowRun.php                   # Main workflow record
│   │   ├── WorkflowStep.php                  # Individual step tracking
│   │   └── WorkflowFile.php                  # Processed file tracking
│   │
│   ├── Services/
│   │   ├── Flambient/
│   │   │   ├── ExifService.php               # EXIF extraction & classification
│   │   │   └── ImageMagickService.php        # .mgk script generation
│   │   │
│   │   └── ImagenAI/
│   │       ├── ImagenClient.php              # Complete API client
│   │       ├── ImagenException.php           # Custom exception
│   │       └── DTOs.php                      # All Imagen DTOs & enums
│   │
│   └── ...
│
├── config/
│   └── flambient.php                         # Centralized configuration
│
├── database/
│   ├── database.sqlite                       # SQLite database
│   └── migrations/
│       ├── *_create_workflow_runs_table.php
│       ├── *_create_workflow_steps_table.php
│       └── *_create_workflow_files_table.php
│
├── tests/
│   ├── Unit/
│   │   ├── ExifServiceTest.php              # 11 tests
│   │   ├── ImageMagickServiceTest.php       # 9 tests
│   │   └── ImagenClientTest.php             # 18 tests
│   │
│   └── Feature/
│       └── FlambientProcessCommandTest.php  # 8 tests
│
├── ARCHITECTURE_REDESIGN.md                 # Original architectural analysis
├── IMAGEN_INTEGRATION.md                    # Imagen AI integration guide
└── README.md                                # This file
```

---

## 📚 API Documentation

### **ExifService**

```php
use App\Services\Flambient\ExifService;
use App\Enums\ImageClassificationStrategy;

$service = new ExifService(
    strategy: ImageClassificationStrategy::ExposureMode,
    ambientValue: 0 // Auto = Ambient
);

// Extract EXIF with both numeric and human-readable values
$metadata = $service->extractMetadata('/path/to/images');

// Get sample values for user preview
$samples = $service->getSampleExifValues('/path/to/images', count: 10);

// Group images by timestamp
$groups = $service->groupImagesByTimestamp($metadata);
```

### **ImageMagickService**

```php
use App\Services\Flambient\ImageMagickService;

$service = new ImageMagickService();

// Generate .mgk scripts for all groups
$result = $service->generateScripts($groups, $config);
// Returns: ['scripts' => [...], 'master_script' => '...']

// Execute all scripts
$result = $service->executeAllScripts($scriptsDirectory);
// Returns: ['success' => true, 'output' => '...']
```

### **ImagenClient**

```php
use App\Services\ImagenAI\ImagenClient;
use App\Services\ImagenAI\ImagenEditOptions;
use App\Services\ImagenAI\ImagenPhotographyType;

$client = new ImagenClient();

// Quick workflow (all-in-one)
$result = $client->quickEdit(
    filePaths: ['/path/to/flambient_01.jpg', ...],
    profileKey: '309406',
    outputDirectory: '/path/to/output',
    editOptions: new ImagenEditOptions(
        crop: true,
        windowPull: true,
        photographyType: ImagenPhotographyType::REAL_ESTATE
    )
);

// Or step-by-step:
$project = $client->createProject('My Shoot');
$uploadResult = $client->uploadImages($project->uuid, $filePaths);
$client->startEditing($project->uuid, '309406');
$editStatus = $client->pollEditStatus($project->uuid);
$client->exportProject($project->uuid);
$exportLinks = $client->getExportLinks($project->uuid);
$downloadResult = $client->downloadFiles($exportLinks, '/output');
```

For complete API reference, see:
- **[IMAGEN_INTEGRATION.md](./IMAGEN_INTEGRATION.md)** - Imagen AI client guide
- **[ARCHITECTURE_REDESIGN.md](./ARCHITECTURE_REDESIGN.md)** - Architectural overview

---

## 🔮 Future Improvements

### **1. Multiple ImageMagick Processing Scripts**

**Current:** Single flambient blend algorithm
**Future:** Multiple processing profiles to choose from

```bash
php artisan flambient:process --profile=hdr-merge
php artisan flambient:process --profile=window-pull
php artisan flambient:process --profile=flash-balance
php artisan flambient:process --profile=custom
```

**Potential Profiles:**
- `flambient` (default) - Standard ambient/flash blend
- `hdr-merge` - HDR tone mapping with multiple exposures
- `window-pull` - Specialized window light enhancement
- `flash-balance` - Auto-balance flash intensity
- `perspective-fix` - Vertical/horizontal correction
- `custom` - User-defined .mgk script templates

**Implementation Plan:**
```php
// app/Enums/ProcessingProfile.php
enum ProcessingProfile: string {
    case Flambient = 'flambient';
    case HdrMerge = 'hdr-merge';
    case WindowPull = 'window-pull';
    case FlashBalance = 'flash-balance';

    public function getScriptTemplate(): string;
    public function getDefaultParams(): array;
}

// app/Services/Flambient/ScriptGenerator.php
class ScriptGenerator {
    public function generate(ProcessingProfile $profile, array $params): string;
}
```

### **2. Capture One Integration**

**Current:** Post-processing of existing JPGs
**Future:** Live ingestion during wireless camera tethering

```bash
# Watch mode - monitor Capture One output folder
php artisan flambient:watch \
  --capture-one-session=/path/to/session \
  --auto-process \
  --interval=30s

# Output:
⠧ Watching for new images...
✓ Detected IMG_001.jpg (Ambient)
✓ Detected IMG_002.jpg (Flash)
⚡ Auto-processing group 1 (2 images)
✓ Blended: flambient_01.jpg
```

**Features:**
- **File watcher** - Monitor Capture One Capture folder
- **Real-time classification** - Instant EXIF analysis on import
- **Auto-grouping** - Smart pairing based on shoot sequence
- **Progressive processing** - Process groups as they complete
- **Live preview** - Optional web server for client viewing
- **Pause/resume** - Control processing during shoot
- **Backup strategy** - Copy originals before processing

**Implementation Plan:**
```php
// app/Console/Commands/FlambientWatchCommand.php
class FlambientWatchCommand extends Command {
    protected $signature = 'flambient:watch
        {--capture-one-session= : Capture One session folder}
        {--auto-process : Auto-process complete groups}
        {--interval=30s : Check interval}';

    public function handle() {
        $watcher = new CaptureOneWatcher(
            sessionPath: $this->option('capture-one-session'),
            autoProcess: $this->option('auto-process')
        );

        $watcher->watch();
    }
}

// app/Services/CaptureOne/CaptureOneWatcher.php
class CaptureOneWatcher {
    public function watch(): void {
        while (true) {
            $newFiles = $this->detectNewFiles();
            $completeGroups = $this->findCompleteGroups($newFiles);

            if ($this->autoProcess && !empty($completeGroups)) {
                $this->processGroups($completeGroups);
            }

            sleep($this->interval);
        }
    }

    private function detectNewFiles(): array;
    private function findCompleteGroups(array $files): array;
    private function processGroups(array $groups): void;
}

// app/Services/CaptureOne/SessionParser.php
class SessionParser {
    public function parseSession(string $path): CaptureOneSession;
    public function getCaptureFolder(): string;
    public function getOutputSettings(): array;
}
```

**Capture One Session Structure:**
```
CaptureOneSession/
├── Capture/               # New images appear here
│   ├── IMG_001.jpg
│   ├── IMG_002.jpg
│   └── ...
├── Output/                # Processed images (optional)
├── Cache/                 # Capture One metadata
└── Settings/              # Session settings
```

**Benefits:**
- ✅ Faster turnaround - Process during shoot, not after
- ✅ Immediate feedback - See blended results in real-time
- ✅ Client preview - Show progress to clients on-site
- ✅ Reduce post-processing - Images ready when shoot ends
- ✅ Better organization - Auto-naming and grouping

---

## 🤝 Contributing

### **Development Workflow**

```bash
# 1. Create feature branch
git checkout -b feature/my-feature

# 2. Make changes and add tests
# Edit app/Services/...
# Edit tests/Unit/...

# 3. Run tests
php artisan test

# 4. Format code
./vendor/bin/pint

# 5. Commit and push
git add .
git commit -m "feat: add my feature"
git push origin feature/my-feature

# 6. Create pull request
```

### **Code Standards**

- **PSR-12** coding style (enforced by Laravel Pint)
- **Type safety** - Use type hints, return types, readonly classes
- **Test coverage** - Add tests for all new features
- **Documentation** - Update README and inline docs
- **Laravel conventions** - Follow Laravel best practices

### **Testing Requirements**

All new features must include:
- ✅ Unit tests for services/classes
- ✅ Feature tests for commands
- ✅ Mock external dependencies (Http::fake(), Process::fake())
- ✅ Assertions for success and failure cases

---

## 📝 License

MIT License - see [LICENSE](LICENSE) for details

---

## 🙏 Acknowledgments

- **[Laravel Framework](https://laravel.com)** - Elegant PHP framework
- **[Laravel Prompts](https://laravel.com/docs/prompts)** - Beautiful CLI interactions
- **[ImageMagick](https://imagemagick.org)** - Image processing powerhouse
- **[Imagen AI](https://imagen-ai.com)** - Cloud-based photo enhancement
- **[ExifTool](https://exiftool.org)** - Comprehensive EXIF metadata extraction

---

## 📞 Support

For issues, questions, or feature requests:

- **GitHub Issues**: [Create an issue](https://github.com/yourusername/terminal-flazsh/issues)
- **Documentation**: See `/docs` folder for detailed guides
- **Architecture**: Read [ARCHITECTURE_REDESIGN.md](./ARCHITECTURE_REDESIGN.md)
- **Imagen Integration**: Read [IMAGEN_INTEGRATION.md](./IMAGEN_INTEGRATION.md)

---

**Built with ❤️ using Laravel 11, PHP 8.2, and modern development practices**

**Production-ready flambient photography automation for professional photographers**
