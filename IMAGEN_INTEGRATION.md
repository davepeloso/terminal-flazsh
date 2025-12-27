# Imagen AI Integration Guide

## Overview

This Laravel application now includes a native PHP client for the Imagen AI API, providing a complete workflow for automated photo editing:

**Upload → Edit → Poll → Export → Download**

## Architecture Comparison

### ❌ Old Approach (Shell Script)

```bash
# 002_imagenUpload.zsh
- Direct curl commands
- Manual JSON construction
- No polling for completion
- No download capability
- Hard to integrate with Laravel
- No retry logic
- No type safety
```

### ✅ New Approach (Laravel Service)

```php
// app/Services/ImagenAI/ImagenClient.php
- Laravel HTTP client with retries
- Type-safe DTOs and enums
- Complete workflow support
- Progress tracking
- Database state management
- Easy to test with Http::fake()
- Integrates with workflow orchestration
```

## Quick Start

### 1. Configuration

Add to your `.env`:

```env
IMAGEN_AI_API_KEY=your_api_key_here
IMAGEN_PROFILE_KEY=309406
IMAGEN_API_BASE_URL=https://api-beta.imagen-ai.com/v1
IMAGEN_TIMEOUT=30
IMAGEN_POLL_INTERVAL=30
IMAGEN_POLL_MAX_ATTEMPTS=240
```

### 2. Basic Usage

```php
use App\Services\ImagenAI\ImagenClient;
use App\Services\ImagenAI\ImagenEditOptions;
use App\Services\ImagenAI\ImagenPhotographyType;

$client = new ImagenClient();

// Option 1: Quick Edit (all-in-one)
$result = $client->quickEdit(
    filePaths: ['/path/to/image1.jpg', '/path/to/image2.jpg'],
    profileKey: '309406',
    outputDirectory: '/path/to/output',
    editOptions: new ImagenEditOptions(
        crop: true,
        windowPull: true,
        photographyType: ImagenPhotographyType::REAL_ESTATE
    ),
    progressCallback: function ($current, $total, $filename) {
        echo "Processing {$current}/{$total}: {$filename}\n";
    }
);

echo "Downloaded files: " . count($result->downloadResult->succeeded);
```

### 3. Step-by-Step Workflow

```php
use App\Services\ImagenAI\ImagenClient;

$client = new ImagenClient();

// Step 1: Create Project
$project = $client->createProject('My Real Estate Shoot');
echo "Project UUID: {$project->uuid}\n";

// Step 2: Upload Images
$uploadResult = $client->uploadImages(
    projectUuid: $project->uuid,
    filePaths: ['/path/to/flambient_01.jpg', '/path/to/flambient_02.jpg'],
    progressCallback: fn($current, $total, $file) => echo "Upload {$current}/{$total}: {$file}\n"
);

if (!$uploadResult->isFullySuccessful()) {
    echo "Some uploads failed: " . implode(', ', $uploadResult->failed);
}

// Step 3: Start Editing
$client->startEditing(
    projectUuid: $project->uuid,
    profileKey: config('flambient.imagen.profile_key'),
    options: new ImagenEditOptions(
        crop: true,
        windowPull: true
    )
);

// Step 4: Poll for Completion
$editStatus = $client->pollEditStatus(
    projectUuid: $project->uuid,
    maxAttempts: 240,
    intervalSeconds: 30,
    progressCallback: function ($status) {
        echo "Edit status: {$status->status} ({$status->progress}%)\n";
    }
);

if ($editStatus->isComplete) {
    echo "Editing completed!\n";
}

// Step 5: Export to JPEG
$client->exportProject($project->uuid);
sleep(10); // Give export time to start

// Step 6: Download Edited Files
$exportLinks = $client->getExportLinks($project->uuid);
$downloadResult = $client->downloadFiles(
    downloadLinks: $exportLinks,
    outputDirectory: '/path/to/output',
    progressCallback: fn($current, $total, $file) => echo "Download {$current}/{$total}: {$file}\n"
);

echo "Downloaded {$downloadResult->totalFiles} files to output directory\n";
```

## Integration with FlambientProcessCommand

Update your workflow command to include Imagen processing:

```php
// In app/Console/Commands/FlambientProcessCommand.php

use App\Services\ImagenAI\ImagenClient;
use App\Services\ImagenAI\ImagenEditOptions;

protected function handle()
{
    // ... existing EXIF and ImageMagick processing ...

    // After local ImageMagick processing completes
    if (!$processOnly) {
        $this->newLine();
        info('Step 4: Upload to Imagen AI');

        $imagenClient = new ImagenClient();

        // Create project
        $project = spin(
            fn() => $imagenClient->createProject($config->projectName),
            'Creating Imagen AI project...'
        );

        note("✓ Project created: {$project->uuid}");

        // Get list of blended images
        $blendedImages = glob("{$config->outputDirectory}/flambient/*.jpg");

        // Upload images
        $uploadResult = $imagenClient->uploadImages(
            projectUuid: $project->uuid,
            filePaths: $blendedImages,
            progressCallback: function ($current, $total, $filename) {
                info("Uploading {$current}/{$total}: {$filename}");
            }
        );

        note("✓ Uploaded {$uploadResult->totalFiles} images");

        // Start editing
        $imagenClient->startEditing(
            projectUuid: $project->uuid,
            profileKey: config('flambient.imagen.profile_key'),
            options: new ImagenEditOptions(
                crop: false,
                windowPull: true
            )
        );

        info('Step 5: Waiting for Imagen AI processing...');

        // Poll for completion
        $editStatus = $imagenClient->pollEditStatus(
            projectUuid: $project->uuid,
            progressCallback: function ($status) use (&$lastProgress) {
                if ($status->progress !== $lastProgress) {
                    info("Progress: {$status->progress}%");
                    $lastProgress = $status->progress;
                }
            }
        );

        note('✓ Imagen AI editing complete');

        info('Step 6: Exporting and downloading...');

        // Export and download
        $imagenClient->exportProject($project->uuid);
        sleep(10);

        $exportLinks = $imagenClient->getExportLinks($project->uuid);
        $downloadResult = $imagenClient->downloadFiles(
            downloadLinks: $exportLinks,
            outputDirectory: "{$config->outputDirectory}/edited"
        );

        note("✓ Downloaded {$downloadResult->totalFiles} edited images");

        // Store project UUID in workflow
        $run->update(['imagen_project_uuid' => $project->uuid]);
    }
}
```

## Error Handling

```php
use App\Services\ImagenAI\ImagenException;

try {
    $client = new ImagenClient();
    $result = $client->quickEdit(...);
} catch (ImagenException $e) {
    Log::error("Imagen AI error: {$e->getMessage()}");

    // Retry logic
    if ($e->getCode() === 429) { // Rate limit
        sleep(60);
        // Retry...
    }
}
```

## Testing

```php
use Illuminate\Support\Facades\Http;

public function test_imagen_workflow()
{
    Http::fake([
        '*/projects/' => Http::response(['data' => ['project_uuid' => 'test-uuid']]),
        '*/get_temporary_upload_links' => Http::response(['data' => ['files_list' => []]]),
        '*/edit' => Http::response(['message' => 'Success']),
        '*/edit/status' => Http::response(['data' => ['status' => 'completed', 'progress' => 100]]),
    ]);

    $client = new ImagenClient();
    $project = $client->createProject();

    $this->assertEquals('test-uuid', $project->uuid);
}
```

## Available Methods

### ImagenClient

| Method | Purpose |
|--------|---------|
| `createProject(?string $name)` | Create new project |
| `getProfiles()` | List available editing profiles |
| `getUploadLinks(string $projectUuid, array $filenames)` | Get S3 presigned upload URLs |
| `uploadFile(ImagenUploadLink $link, string $path)` | Upload single file |
| `uploadImages(string $projectUuid, array $paths, ?callable $callback)` | Upload multiple files with progress |
| `startEditing(string $projectUuid, string\|int $profileKey, ?ImagenEditOptions $options)` | Start AI editing |
| `getEditStatus(string $projectUuid)` | Check current edit status |
| `pollEditStatus(string $projectUuid, int $maxAttempts, int $intervalSeconds, ?callable $callback)` | Poll until complete |
| `exportProject(string $projectUuid)` | Export to JPEG format |
| `getDownloadLinks(string $projectUuid)` | Get XMP download links |
| `getExportLinks(string $projectUuid)` | Get JPEG download links |
| `downloadFile(ImagenDownloadLink $link, string $outputDir)` | Download single file |
| `downloadFiles(Collection $links, string $outputDir, ?callable $callback)` | Download multiple files |
| `quickEdit(array $paths, string\|int $profileKey, string $outputDir, ?ImagenEditOptions $options, ?callable $callback)` | Complete workflow |

## DTOs and Value Objects

### ImagenEditOptions

```php
new ImagenEditOptions(
    crop: bool = false,
    windowPull: bool = true,
    perspectiveCorrection: bool = false,
    hdrMerge: bool = false,
    photographyType: ?ImagenPhotographyType = null
)
```

### ImagenPhotographyType

```php
ImagenPhotographyType::REAL_ESTATE
ImagenPhotographyType::WEDDING
ImagenPhotographyType::PORTRAIT
ImagenPhotographyType::PRODUCT
ImagenPhotographyType::LANDSCAPE
ImagenPhotographyType::EVENT
```

## Comparison with Official Python SDK

| Feature | Python SDK | Our PHP Client |
|---------|-----------|----------------|
| Authentication | ✅ API key | ✅ API key |
| Create project | ✅ | ✅ |
| Upload images | ✅ Async | ✅ Sequential with progress |
| Start editing | ✅ | ✅ |
| Poll status | ✅ | ✅ |
| Export to JPEG | ✅ | ✅ |
| Download files | ✅ | ✅ |
| Progress callbacks | ✅ | ✅ |
| Error handling | ✅ Custom exceptions | ✅ ImagenException |
| Quick edit function | ✅ | ✅ |
| Type safety | ✅ Pydantic | ✅ PHP 8.2+ readonly classes |
| Testing | ✅ | ✅ Http::fake() |

## Migration from Shell Script

**Before (002_imagenUpload.zsh):**
```bash
./002_imagenUpload.zsh -d /path/to/images -P 309406 -k $API_KEY
# Manual polling required
# No download capability
```

**After (Laravel):**
```php
$client = new ImagenClient();
$result = $client->quickEdit(
    filePaths: glob('/path/to/images/*.jpg'),
    profileKey: '309406',
    outputDirectory: '/path/to/output'
);
// Automatically polls and downloads!
```

## Next Steps

1. ✅ **Replace** `002_imagenUpload.zsh` with `ImagenClient` in your workflow
2. ✅ **Integrate** with `FlambientProcessCommand` for end-to-end automation
3. ✅ **Add** workflow state tracking to database
4. ✅ **Implement** retry logic for network failures
5. ✅ **Create** dashboard to view project status

## Benefits

- **Type-safe** - PHP 8.2+ readonly classes and enums
- **Testable** - Easy mocking with Laravel's Http::fake()
- **Integrated** - Works seamlessly with existing workflow
- **Resumable** - Store project UUID in database, resume later
- **Observable** - Progress callbacks for UI feedback
- **Maintainable** - Clean service architecture vs shell scripts
- **Complete** - Full workflow from upload to download
