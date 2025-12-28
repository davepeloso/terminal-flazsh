<?php

namespace App\Services\ImagenAI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Imagen AI API Client
 *
 * Wraps the Imagen AI REST API for automated photo editing.
 * Based on official Python SDK patterns but built for Laravel/PHP.
 *
 * @see https://github.com/imagenai/imagen-ai-sdk
 */
class ImagenClient
{
    private PendingRequest $http;
    private string $apiKey;
    private string $baseUrl;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null)
    {
        $this->apiKey = $apiKey ?? config('flambient.imagen.api_key');
        $this->baseUrl = $baseUrl ?? config('flambient.imagen.base_url', 'https://api-beta.imagen-ai.com/v1');

        if (empty($this->apiKey)) {
            throw new ImagenException('Imagen API key not configured. Set IMAGEN_AI_API_KEY in .env');
        }

        $this->http = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
        ->timeout(config('flambient.imagen.timeout', 30))
        ->retry(
            times: config('flambient.imagen.retry_times', 3),
            sleepMilliseconds: 1000,
            when: fn($exception) => $exception instanceof \Illuminate\Http\Client\ConnectionException
        );
    }

    /**
     * Create a new project.
     *
     * @param string|null $name Optional project name
     * @return ImagenProject
     * @throws ImagenException
     */
    public function createProject(?string $name = null): ImagenProject
    {
        $response = $this->http->post("{$this->baseUrl}/projects/", [
            'name' => $name,
        ]);

        if (!$response->successful()) {
            throw new ImagenException(
                "Failed to create project: {$response->body()}",
                $response->status()
            );
        }

        $data = $response->json('data');

        return new ImagenProject(
            uuid: $data['project_uuid'],
            name: $data['name'] ?? null,
            createdAt: now()
        );
    }

    /**
     * Get list of available editing profiles.
     *
     * @return Collection<ImagenProfile>
     * @throws ImagenException
     */
    public function getProfiles(): Collection
    {
        $response = $this->http->get("{$this->baseUrl}/profiles");

        if (!$response->successful()) {
            throw new ImagenException(
                "Failed to fetch profiles: {$response->body()}",
                $response->status()
            );
        }

        $profiles = $response->json('data.profiles', []);

        return collect($profiles)->map(fn($profile) => new ImagenProfile(
            key: $profile['profile_key'],
            name: $profile['profile_name'],
            profileType: $profile['profile_type'] ?? null,
            imageType: $profile['image_type'] ?? null,
            photographyType: $profile['photography_type'] ?? null
        ));
    }

    /**
     * Get temporary upload links for files.
     *
     * @param string $projectUuid
     * @param array<string> $filenames
     * @return Collection<ImagenUploadLink>
     * @throws ImagenException
     */
    public function getUploadLinks(string $projectUuid, array $filenames): Collection
    {
        $filesList = array_map(fn($name) => ['file_name' => $name], $filenames);

        $response = $this->http->post(
            "{$this->baseUrl}/projects/{$projectUuid}/get_temporary_upload_links",
            ['files_list' => $filesList]
        );

        if (!$response->successful()) {
            throw new ImagenException(
                "Failed to get upload links: {$response->body()}",
                $response->status()
            );
        }

        $files = $response->json('data.files_list', []);

        return collect($files)->map(fn($file) => new ImagenUploadLink(
            filename: $file['file_name'],
            uploadUrl: $file['upload_link']
        ));
    }

    /**
     * Upload a file to a temporary URL (S3 presigned URL).
     *
     * @param ImagenUploadLink $uploadLink
     * @param string $localFilePath
     * @return bool
     * @throws ImagenException
     */
    public function uploadFile(ImagenUploadLink $uploadLink, string $localFilePath): bool
    {
        if (!File::exists($localFilePath)) {
            throw new ImagenException("File not found: {$localFilePath}");
        }

        try {
            // Simple PUT request to S3 presigned URL (matches curl -T from shell script)
            // S3 expects raw file content in the body, not multipart/form-data
            $fileHandle = fopen($localFilePath, 'r');
            if ($fileHandle === false) {
                throw new ImagenException("Could not open file: {$localFilePath}");
            }

            $response = Http::withoutVerifying()  // S3 presigned URLs may have cert issues
                ->timeout(300)  // 5 minute timeout for large files
                ->withBody(stream_get_contents($fileHandle), mime_content_type($localFilePath))
                ->put($uploadLink->uploadUrl);

            fclose($fileHandle);

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error("Upload failed for {$uploadLink->filename}", [
                    'status' => $response->status(),
                    'body' => $errorBody,
                    'file_size' => filesize($localFilePath),
                ]);

                throw new ImagenException(
                    "Failed to upload {$uploadLink->filename}: HTTP {$response->status()}",
                    $response->status()
                );
            }

            return true;
        } catch (\Exception $e) {
            if ($e instanceof ImagenException) {
                throw $e;
            }

            Log::error("Exception during upload of {$uploadLink->filename}", [
                'error' => $e->getMessage(),
                'file' => $localFilePath,
            ]);

            throw new ImagenException(
                "Upload exception for {$uploadLink->filename}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Upload multiple files with progress tracking.
     *
     * @param string $projectUuid
     * @param array<string> $filePaths Absolute file paths
     * @param callable|null $progressCallback function(int $current, int $total, string $filename): void
     * @return ImagenUploadResult
     * @throws ImagenException
     */
    public function uploadImages(
        string $projectUuid,
        array $filePaths,
        ?callable $progressCallback = null
    ): ImagenUploadResult {
        $filenames = array_map(fn($path) => basename($path), $filePaths);
        $uploadLinks = $this->getUploadLinks($projectUuid, $filenames);

        $total = count($filePaths);
        $current = 0;
        $succeeded = [];
        $failed = [];

        foreach ($filePaths as $filePath) {
            $filename = basename($filePath);
            $uploadLink = $uploadLinks->firstWhere('filename', $filename);

            if (!$uploadLink) {
                $failed[] = $filename;
                Log::warning("No upload link for file: {$filename}");
                continue;
            }

            try {
                $this->uploadFile($uploadLink, $filePath);
                $succeeded[] = $filename;
                $current++;

                if ($progressCallback) {
                    $progressCallback($current, $total, $filename);
                }
            } catch (\Exception $e) {
                $failed[] = $filename;
                Log::error("Upload failed for {$filename}: {$e->getMessage()}");
            }
        }

        return new ImagenUploadResult(
            projectUuid: $projectUuid,
            totalFiles: $total,
            succeeded: $succeeded,
            failed: $failed
        );
    }

    /**
     * Start editing a project with specified profile.
     *
     * @param string $projectUuid
     * @param string|int $profileKey
     * @param ImagenEditOptions|null $options
     * @return ImagenEditResponse
     * @throws ImagenException
     */
    public function startEditing(
        string $projectUuid,
        string|int $profileKey,
        ?ImagenEditOptions $options = null
    ): ImagenEditResponse {
        $options = $options ?? new ImagenEditOptions();

        $payload = [
            'profile_key' => (string)$profileKey,
            'crop' => $options->crop,
            'window_pull' => $options->windowPull,
            'perspective_correction' => $options->perspectiveCorrection,
            'hdr_merge' => $options->hdrMerge,
            'photography_type' => $options->photographyType?->value,
        ];

        $response = $this->http->post(
            "{$this->baseUrl}/projects/{$projectUuid}/edit",
            $payload
        );

        if (!$response->successful()) {
            throw new ImagenException(
                "Failed to start editing: {$response->body()}",
                $response->status()
            );
        }

        return new ImagenEditResponse(
            projectUuid: $projectUuid,
            status: 'submitted',
            message: $response->json('message', 'Project submitted for editing')
        );
    }

    /**
     * Check edit status for a project.
     *
     * @param string $projectUuid
     * @return ImagenEditStatus
     * @throws ImagenException
     */
    public function getEditStatus(string $projectUuid): ImagenEditStatus
    {
        $response = $this->http->get("{$this->baseUrl}/projects/{$projectUuid}/edit/status");

        if (!$response->successful()) {
            throw new ImagenException(
                "Failed to get edit status: {$response->body()}",
                $response->status()
            );
        }

        $data = $response->json('data', []);

        return new ImagenEditStatus(
            status: $data['status'] ?? 'unknown',
            progress: $data['progress'] ?? 0,
            message: $data['message'] ?? null,
            isComplete: in_array($data['status'] ?? '', ['completed', 'done', 'finished']),
            isFailed: in_array($data['status'] ?? '', ['failed', 'error'])
        );
    }

    /**
     * Poll edit status until complete or timeout.
     *
     * @param string $projectUuid
     * @param int $maxAttempts
     * @param int $intervalSeconds
     * @param callable|null $progressCallback function(ImagenEditStatus $status): void
     * @return ImagenEditStatus
     * @throws ImagenException
     */
    public function pollEditStatus(
        string $projectUuid,
        int $maxAttempts = 240, // 2 hours at 30s intervals
        int $intervalSeconds = 30,
        ?callable $progressCallback = null
    ): ImagenEditStatus {
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            $status = $this->getEditStatus($projectUuid);

            if ($progressCallback) {
                $progressCallback($status);
            }

            if ($status->isComplete) {
                return $status;
            }

            if ($status->isFailed) {
                throw new ImagenException("Edit failed: {$status->message}");
            }

            sleep($intervalSeconds);
            $attempts++;
        }

        throw new ImagenException("Edit polling timeout after {$maxAttempts} attempts");
    }

    /**
     * Export project to JPEG format.
     *
     * @param string $projectUuid
     * @return ImagenExportResponse
     * @throws ImagenException
     */
    public function exportProject(string $projectUuid): ImagenExportResponse
    {
        $response = $this->http->post("{$this->baseUrl}/projects/{$projectUuid}/export");

        if (!$response->successful()) {
            throw new ImagenException(
                "Failed to export project: {$response->body()}",
                $response->status()
            );
        }

        return new ImagenExportResponse(
            projectUuid: $projectUuid,
            status: 'exporting',
            message: $response->json('message', 'Project export initiated')
        );
    }

    /**
     * Get download links for edited images (XMP sidecar files).
     *
     * @param string $projectUuid
     * @return Collection<ImagenDownloadLink>
     * @throws ImagenException
     */
    public function getDownloadLinks(string $projectUuid): Collection
    {
        $response = $this->http->get("{$this->baseUrl}/projects/{$projectUuid}/download");

        if (!$response->successful()) {
            throw new ImagenException(
                "Failed to get download links: {$response->body()}",
                $response->status()
            );
        }

        $files = $response->json('data.files_list', []);

        return collect($files)->map(fn($file) => new ImagenDownloadLink(
            filename: $file['file_name'],
            downloadUrl: $file['download_link'],
            fileType: 'xmp'
        ));
    }

    /**
     * Get export download links (JPEG files).
     *
     * @param string $projectUuid
     * @return Collection<ImagenDownloadLink>
     * @throws ImagenException
     */
    public function getExportLinks(string $projectUuid): Collection
    {
        $response = $this->http->get("{$this->baseUrl}/projects/{$projectUuid}/export/download");

        if (!$response->successful()) {
            throw new ImagenException(
                "Failed to get export links: {$response->body()}",
                $response->status()
            );
        }

        $files = $response->json('data.files_list', []);

        return collect($files)->map(fn($file) => new ImagenDownloadLink(
            filename: $file['file_name'],
            downloadUrl: $file['download_link'],
            fileType: 'jpeg'
        ));
    }

    /**
     * Download a file from a URL.
     *
     * @param ImagenDownloadLink $downloadLink
     * @param string $outputDirectory
     * @return string Path to downloaded file
     * @throws ImagenException
     */
    public function downloadFile(ImagenDownloadLink $downloadLink, string $outputDirectory): string
    {
        if (!File::isDirectory($outputDirectory)) {
            File::makeDirectory($outputDirectory, 0755, true);
        }

        $outputPath = $outputDirectory . '/' . $downloadLink->filename;

        $response = Http::timeout(300)->get($downloadLink->downloadUrl);

        if (!$response->successful()) {
            throw new ImagenException(
                "Failed to download {$downloadLink->filename}: {$response->body()}",
                $response->status()
            );
        }

        File::put($outputPath, $response->body());

        return $outputPath;
    }

    /**
     * Download multiple files with progress tracking.
     *
     * @param Collection<ImagenDownloadLink> $downloadLinks
     * @param string $outputDirectory
     * @param callable|null $progressCallback function(int $current, int $total, string $filename): void
     * @return ImagenDownloadResult
     */
    public function downloadFiles(
        Collection $downloadLinks,
        string $outputDirectory,
        ?callable $progressCallback = null
    ): ImagenDownloadResult {
        $total = $downloadLinks->count();
        $current = 0;
        $succeeded = [];
        $failed = [];

        foreach ($downloadLinks as $link) {
            try {
                $path = $this->downloadFile($link, $outputDirectory);
                $succeeded[] = $path;
                $current++;

                if ($progressCallback) {
                    $progressCallback($current, $total, $link->filename);
                }
            } catch (\Exception $e) {
                $failed[] = $link->filename;
                Log::error("Download failed for {$link->filename}: {$e->getMessage()}");
            }
        }

        return new ImagenDownloadResult(
            totalFiles: $total,
            succeeded: $succeeded,
            failed: $failed
        );
    }

    /**
     * Convenience method: Complete workflow (upload → edit → export → download).
     *
     * @param array<string> $filePaths
     * @param string|int $profileKey
     * @param string $outputDirectory
     * @param ImagenEditOptions|null $editOptions
     * @param callable|null $progressCallback
     * @return ImagenWorkflowResult
     * @throws ImagenException
     */
    public function quickEdit(
        array $filePaths,
        string|int $profileKey,
        string $outputDirectory,
        ?ImagenEditOptions $editOptions = null,
        ?callable $progressCallback = null
    ): ImagenWorkflowResult {
        // Create project
        $project = $this->createProject();

        // Upload images
        $uploadResult = $this->uploadImages($project->uuid, $filePaths, $progressCallback);

        if (!empty($uploadResult->failed)) {
            throw new ImagenException("Some files failed to upload: " . implode(', ', $uploadResult->failed));
        }

        // Start editing
        $this->startEditing($project->uuid, $profileKey, $editOptions);

        // Poll for completion
        $editStatus = $this->pollEditStatus($project->uuid, progressCallback: $progressCallback);

        // Export to JPEG
        $this->exportProject($project->uuid);

        // Wait for export to complete (poll again)
        sleep(10); // Give export a head start

        // Get download links and download
        $downloadLinks = $this->getExportLinks($project->uuid);
        $downloadResult = $this->downloadFiles($downloadLinks, $outputDirectory, $progressCallback);

        return new ImagenWorkflowResult(
            projectUuid: $project->uuid,
            uploadResult: $uploadResult,
            editStatus: $editStatus,
            downloadResult: $downloadResult
        );
    }
}
