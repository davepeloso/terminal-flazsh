<?php

namespace Tests\Unit;

use App\DTOs\WorkflowConfig;
use App\Enums\ImageType;
use App\Services\Flambient\ImageMagickService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ImageMagickServiceTest extends TestCase
{
    private ImageMagickService $service;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImageMagickService();
        $this->tempDir = sys_get_temp_dir() . '/flambient_test_' . uniqid();
        File::makeDirectory($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (File::exists($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    /** @test */
    public function it_generates_imagemagick_scripts_for_groups(): void
    {
        $config = new WorkflowConfig(
            projectName: 'test-project',
            imageDirectory: '/fake/input',
            outputDirectory: $this->tempDir,
            processOnly: true,
            apiKey: null,
            profileKey: 309406,
            levelLow: '40%',
            levelHigh: '140%',
            gamma: '1.0',
            outputPrefix: 'flambient',
        );

        $groups = $this->getSampleGroups();

        $result = $this->service->generateScripts($groups, $config);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['scripts']);
        $this->assertFileExists($result['master_script']);

        // Verify individual scripts were created
        foreach ($result['scripts'] as $script) {
            $this->assertFileExists($script);
            $this->assertStringContainsString('.mgk', $script);
        }

        // Verify master script contains all individual scripts
        $masterContent = File::get($result['master_script']);
        $this->assertStringContainsString('#!/bin/bash', $masterContent);
        $this->assertStringContainsString('magick -script', $masterContent);
    }

    /** @test */
    public function it_generates_correct_mgk_script_content(): void
    {
        $config = new WorkflowConfig(
            projectName: 'test-project',
            imageDirectory: '/fake/input',
            outputDirectory: $this->tempDir,
            processOnly: true,
            apiKey: null,
            profileKey: 309406,
            levelLow: '40%',
            levelHigh: '140%',
            gamma: '1.0',
            outputPrefix: 'flambient',
        );

        $groups = $this->getSampleGroups()->take(1);
        $result = $this->service->generateScripts($groups, $config);

        $scriptContent = File::get($result['scripts'][0]);

        // Verify essential ImageMagick operations are present
        $this->assertStringContainsString('# Flambient Processing Script', $scriptContent);
        $this->assertStringContainsString('-read', $scriptContent); // Reading images
        $this->assertStringContainsString('-clone', $scriptContent); // Cloning for processing
        $this->assertStringContainsString('-evaluate-sequence', $scriptContent); // Median merge
        $this->assertStringContainsString('-compose', $scriptContent); // Compose operations
        $this->assertStringContainsString('-write', $scriptContent); // Writing output
    }

    /** @test */
    public function it_includes_ambient_and_flash_images_in_script(): void
    {
        $config = new WorkflowConfig(
            projectName: 'test-project',
            imageDirectory: '/fake/input',
            outputDirectory: $this->tempDir,
            processOnly: true,
            apiKey: null,
            profileKey: 309406,
            levelLow: '40%',
            levelHigh: '140%',
            gamma: '1.0',
            outputPrefix: 'flambient',
        );

        $groups = $this->getSampleGroups()->take(1);
        $result = $this->service->generateScripts($groups, $config);

        $scriptContent = File::get($result['scripts'][0]);

        // Verify ambient images are included
        $this->assertStringContainsString('ambient_001.jpg', $scriptContent);
        $this->assertStringContainsString('ambient_002.jpg', $scriptContent);

        // Verify flash images are included
        $this->assertStringContainsString('flash_001.jpg', $scriptContent);
        $this->assertStringContainsString('flash_002.jpg', $scriptContent);
    }

    /** @test */
    public function it_executes_all_scripts_successfully(): void
    {
        Process::fake([
            'bash *run_all_scripts.sh*' => Process::result(
                output: "Processing group 1...\nProcessing group 2...\nComplete!",
                errorOutput: '',
                exitCode: 0
            ),
        ]);

        $result = $this->service->executeAllScripts($this->tempDir);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Complete!', $result['output']);
        Process::assertRan('bash *run_all_scripts.sh*');
    }

    /** @test */
    public function it_handles_script_execution_failure(): void
    {
        Process::fake([
            'bash *run_all_scripts.sh*' => Process::result(
                output: '',
                errorOutput: 'Error: Invalid image format',
                exitCode: 1
            ),
        ]);

        $result = $this->service->executeAllScripts($this->tempDir);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid image format', $result['error']);
    }

    /** @test */
    public function it_uses_custom_imagemagick_settings(): void
    {
        config([
            'flambient.imagemagick.level_low' => '30%',
            'flambient.imagemagick.level_high' => '150%',
            'flambient.imagemagick.gamma' => '1.2',
        ]);

        $config = new WorkflowConfig(
            projectName: 'test-project',
            imageDirectory: '/fake/input',
            outputDirectory: $this->tempDir,
            processOnly: true,
            apiKey: null,
            profileKey: 309406,
            levelLow: '40%',
            levelHigh: '140%',
            gamma: '1.0',
            outputPrefix: 'flambient',
        );

        $groups = $this->getSampleGroups()->take(1);
        $result = $this->service->generateScripts($groups, $config);

        $scriptContent = File::get($result['scripts'][0]);

        $this->assertStringContainsString('30%', $scriptContent);
        $this->assertStringContainsString('150%', $scriptContent);
        $this->assertStringContainsString('1.2', $scriptContent);
    }

    /** @test */
    public function it_creates_master_script_with_all_groups(): void
    {
        $config = new WorkflowConfig(
            projectName: 'test-project',
            imageDirectory: '/fake/input',
            outputDirectory: $this->tempDir,
            processOnly: true,
            apiKey: null,
            profileKey: 309406,
            levelLow: '40%',
            levelHigh: '140%',
            gamma: '1.0',
            outputPrefix: 'flambient',
        );

        $groups = $this->getSampleGroups();
        $result = $this->service->generateScripts($groups, $config);

        $masterContent = File::get($result['master_script']);

        // Should have 2 magick commands (one for each group)
        $this->assertEquals(2, substr_count($masterContent, 'magick -script'));
        $this->assertStringContainsString('flambient_01.mgk', $masterContent);
        $this->assertStringContainsString('flambient_02.mgk', $masterContent);
    }

    private function getSampleGroups(): Collection
    {
        return collect([
            [
                'group_number' => 1,
                'timestamp' => '2024:01:15 14:30:00',
                'ambient' => collect([
                    ['filename' => 'ambient_001.jpg', 'type' => ImageType::Ambient],
                    ['filename' => 'ambient_002.jpg', 'type' => ImageType::Ambient],
                ]),
                'flash' => collect([
                    ['filename' => 'flash_001.jpg', 'type' => ImageType::Flash],
                    ['filename' => 'flash_002.jpg', 'type' => ImageType::Flash],
                ]),
                'images' => collect([
                    ['filename' => 'ambient_001.jpg', 'type' => ImageType::Ambient],
                    ['filename' => 'flash_001.jpg', 'type' => ImageType::Flash],
                    ['filename' => 'ambient_002.jpg', 'type' => ImageType::Ambient],
                    ['filename' => 'flash_002.jpg', 'type' => ImageType::Flash],
                ]),
            ],
            [
                'group_number' => 2,
                'timestamp' => '2024:01:15 14:35:00',
                'ambient' => collect([
                    ['filename' => 'ambient_003.jpg', 'type' => ImageType::Ambient],
                ]),
                'flash' => collect([
                    ['filename' => 'flash_003.jpg', 'type' => ImageType::Flash],
                ]),
                'images' => collect([
                    ['filename' => 'ambient_003.jpg', 'type' => ImageType::Ambient],
                    ['filename' => 'flash_003.jpg', 'type' => ImageType::Flash],
                ]),
            ],
        ]);
    }
}
