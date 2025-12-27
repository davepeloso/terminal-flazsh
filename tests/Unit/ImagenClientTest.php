<?php

namespace Tests\Unit;

use App\Services\ImagenAI\ImagenClient;
use App\Services\ImagenAI\ImagenEditOptions;
use App\Services\ImagenAI\ImagenException;
use App\Services\ImagenAI\ImagenPhotographyType;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImagenClientTest extends TestCase
{
    private ImagenClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['flambient.imagen.api_key' => 'test-api-key']);
        $this->client = new ImagenClient();
    }

    /** @test */
    public function it_throws_exception_when_api_key_not_configured(): void
    {
        config(['flambient.imagen.api_key' => null]);

        $this->expectException(ImagenException::class);
        $this->expectExceptionMessage('Imagen API key not configured');

        new ImagenClient();
    }

    /** @test */
    public function it_creates_a_project(): void
    {
        Http::fake([
            '*/projects/' => Http::response([
                'data' => [
                    'project_uuid' => 'test-uuid-123',
                    'name' => 'Test Project',
                ],
            ]),
        ]);

        $project = $this->client->createProject('Test Project');

        $this->assertEquals('test-uuid-123', $project->uuid);
        $this->assertEquals('Test Project', $project->name);
        $this->assertNotNull($project->createdAt);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api-beta.imagen-ai.com/v1/projects/'
                && $request->hasHeader('x-api-key', 'test-api-key');
        });
    }

    /** @test */
    public function it_handles_create_project_failure(): void
    {
        Http::fake([
            '*/projects/' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->expectException(ImagenException::class);
        $this->expectExceptionMessage('Failed to create project');

        $this->client->createProject();
    }

    /** @test */
    public function it_gets_available_profiles(): void
    {
        Http::fake([
            '*/profiles' => Http::response([
                'data' => [
                    'profiles' => [
                        [
                            'profile_key' => '5700',
                            'profile_name' => 'Real Estate Pro',
                            'photography_type' => 'REAL_ESTATE',
                        ],
                        [
                            'profile_key' => '5701',
                            'profile_name' => 'Wedding Magic',
                            'photography_type' => 'WEDDING',
                        ],
                    ],
                ],
            ]),
        ]);

        $profiles = $this->client->getProfiles();

        $this->assertCount(2, $profiles);
        $this->assertEquals('5700', $profiles->first()->key);
        $this->assertEquals('Real Estate Pro', $profiles->first()->name);
        $this->assertEquals('REAL_ESTATE', $profiles->first()->photographyType);
    }

    /** @test */
    public function it_gets_upload_links(): void
    {
        Http::fake([
            '*/get_temporary_upload_links' => Http::response([
                'data' => [
                    'files_list' => [
                        [
                            'file_name' => 'image1.jpg',
                            'upload_link' => 'https://s3.amazonaws.com/upload1',
                        ],
                        [
                            'file_name' => 'image2.jpg',
                            'upload_link' => 'https://s3.amazonaws.com/upload2',
                        ],
                    ],
                ],
            ]),
        ]);

        $links = $this->client->getUploadLinks('test-uuid', ['image1.jpg', 'image2.jpg']);

        $this->assertCount(2, $links);
        $this->assertEquals('image1.jpg', $links->first()->filename);
        $this->assertEquals('https://s3.amazonaws.com/upload1', $links->first()->uploadUrl);
    }

    /** @test */
    public function it_starts_editing_with_options(): void
    {
        Http::fake([
            '*/edit' => Http::response([
                'message' => 'Project submitted for editing',
            ]),
        ]);

        $options = new ImagenEditOptions(
            crop: true,
            windowPull: false,
            perspectiveCorrection: true,
            hdrMerge: false,
            photographyType: ImagenPhotographyType::REAL_ESTATE
        );

        $response = $this->client->startEditing('test-uuid', '5700', $options);

        $this->assertEquals('test-uuid', $response->projectUuid);
        $this->assertEquals('submitted', $response->status);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['crop'] === true
                && $body['window_pull'] === false
                && $body['perspective_correction'] === true
                && $body['profile_key'] === '5700'
                && $body['photography_type'] === 'REAL_ESTATE';
        });
    }

    /** @test */
    public function it_checks_edit_status(): void
    {
        Http::fake([
            '*/edit/status' => Http::response([
                'data' => [
                    'status' => 'processing',
                    'progress' => 45,
                    'message' => 'Editing in progress',
                ],
            ]),
        ]);

        $status = $this->client->getEditStatus('test-uuid');

        $this->assertEquals('processing', $status->status);
        $this->assertEquals(45, $status->progress);
        $this->assertFalse($status->isComplete);
        $this->assertFalse($status->isFailed);
    }

    /** @test */
    public function it_detects_completed_status(): void
    {
        Http::fake([
            '*/edit/status' => Http::response([
                'data' => [
                    'status' => 'completed',
                    'progress' => 100,
                    'message' => 'Editing complete',
                ],
            ]),
        ]);

        $status = $this->client->getEditStatus('test-uuid');

        $this->assertTrue($status->isComplete);
        $this->assertFalse($status->isFailed);
    }

    /** @test */
    public function it_detects_failed_status(): void
    {
        Http::fake([
            '*/edit/status' => Http::response([
                'data' => [
                    'status' => 'failed',
                    'progress' => 0,
                    'message' => 'Processing error',
                ],
            ]),
        ]);

        $status = $this->client->getEditStatus('test-uuid');

        $this->assertFalse($status->isComplete);
        $this->assertTrue($status->isFailed);
    }

    /** @test */
    public function it_exports_project_to_jpeg(): void
    {
        Http::fake([
            '*/export' => Http::response([
                'message' => 'Export initiated',
            ]),
        ]);

        $response = $this->client->exportProject('test-uuid');

        $this->assertEquals('test-uuid', $response->projectUuid);
        $this->assertEquals('exporting', $response->status);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/export')
                && $request->method() === 'POST';
        });
    }

    /** @test */
    public function it_gets_download_links_for_xmp_files(): void
    {
        Http::fake([
            '*/download' => Http::response([
                'data' => [
                    'files_list' => [
                        [
                            'file_name' => 'image1.xmp',
                            'download_link' => 'https://s3.amazonaws.com/download1.xmp',
                        ],
                    ],
                ],
            ]),
        ]);

        $links = $this->client->getDownloadLinks('test-uuid');

        $this->assertCount(1, $links);
        $this->assertEquals('image1.xmp', $links->first()->filename);
        $this->assertEquals('xmp', $links->first()->fileType);
    }

    /** @test */
    public function it_gets_export_links_for_jpeg_files(): void
    {
        Http::fake([
            '*/export/download' => Http::response([
                'data' => [
                    'files_list' => [
                        [
                            'file_name' => 'image1.jpg',
                            'download_link' => 'https://s3.amazonaws.com/download1.jpg',
                        ],
                    ],
                ],
            ]),
        ]);

        $links = $this->client->getExportLinks('test-uuid');

        $this->assertCount(1, $links);
        $this->assertEquals('image1.jpg', $links->first()->filename);
        $this->assertEquals('jpeg', $links->first()->fileType);
    }

    /** @test */
    public function it_calculates_upload_success_rate(): void
    {
        Http::fake();

        $result = new \App\Services\ImagenAI\ImagenUploadResult(
            projectUuid: 'test-uuid',
            totalFiles: 10,
            succeeded: ['file1.jpg', 'file2.jpg', 'file3.jpg'],
            failed: ['file4.jpg']
        );

        $this->assertEquals(30.0, $result->getSuccessRate());
        $this->assertFalse($result->isFullySuccessful());
    }

    /** @test */
    public function it_handles_fully_successful_upload(): void
    {
        Http::fake();

        $result = new \App\Services\ImagenAI\ImagenUploadResult(
            projectUuid: 'test-uuid',
            totalFiles: 3,
            succeeded: ['file1.jpg', 'file2.jpg', 'file3.jpg'],
            failed: []
        );

        $this->assertEquals(100.0, $result->getSuccessRate());
        $this->assertTrue($result->isFullySuccessful());
    }

    /** @test */
    public function it_uses_custom_api_key_and_base_url(): void
    {
        Http::fake([
            'https://custom-api.example.com/v2/projects/' => Http::response([
                'data' => ['project_uuid' => 'custom-uuid'],
            ]),
        ]);

        $client = new ImagenClient('custom-key', 'https://custom-api.example.com/v2');
        $project = $client->createProject();

        $this->assertEquals('custom-uuid', $project->uuid);

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', 'custom-key')
                && str_contains($request->url(), 'custom-api.example.com');
        });
    }
}
