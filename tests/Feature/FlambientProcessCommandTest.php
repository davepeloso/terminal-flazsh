<?php

namespace Tests\Feature;

use App\Enums\WorkflowStatus;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class FlambientProcessCommandTest extends TestCase
{
    private string $tempImageDir;
    private string $tempOutputDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempImageDir = sys_get_temp_dir() . '/flambient_images_' . uniqid();
        $this->tempOutputDir = sys_get_temp_dir() . '/flambient_output_' . uniqid();

        File::makeDirectory($this->tempImageDir, 0755, true);
        File::makeDirectory($this->tempOutputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (File::exists($this->tempImageDir)) {
            File::deleteDirectory($this->tempImageDir);
        }
        if (File::exists($this->tempOutputDir)) {
            File::deleteDirectory($this->tempOutputDir);
        }
        parent::tearDown();
    }

    /** @test */
    public function it_processes_flambient_workflow_end_to_end(): void
    {
        // Mock exiftool and ImageMagick processes
        Process::fake([
            '*exiftool*' => Process::result(output: $this->getSampleExifCsv()),
            'bash *run_all_scripts.sh*' => Process::result(output: 'Success!'),
        ]);

        $this->artisan('flambient:process', [
            '--project' => 'test-workflow',
            '--dir' => $this->tempImageDir,
            '--local' => true,
        ])
        ->expectsQuestion('Show sample EXIF values from your images?', false)
        ->expectsQuestion('Which EXIF field should identify Ambient images?', 'flash')
        ->expectsQuestion('What Flash value indicates an AMBIENT image?', '16')
        ->expectsConfirmation('Process 3 images in 2 groups?', 'yes')
        ->assertExitCode(0);

        // Verify workflow was recorded in database
        $workflow = WorkflowRun::where('project_name', 'test-workflow')->first();
        $this->assertNotNull($workflow);
        $this->assertEquals(WorkflowStatus::Completed->value, $workflow->status);
        $this->assertEquals(3, $workflow->total_images_processed);
        $this->assertEquals(2, $workflow->total_groups_created);
    }

    /** @test */
    public function it_allows_selecting_different_classification_strategies(): void
    {
        Process::fake([
            '*exiftool*' => Process::result(output: $this->getSampleExifCsv()),
            'bash *run_all_scripts.sh*' => Process::result(output: 'Success!'),
        ]);

        $this->artisan('flambient:process', [
            '--project' => 'exposure-mode-test',
            '--dir' => $this->tempImageDir,
            '--local' => true,
        ])
        ->expectsQuestion('Show sample EXIF values from your images?', false)
        ->expectsQuestion('Which EXIF field should identify Ambient images?', 'exposure_mode')
        ->expectsQuestion('What Exposure Mode value indicates an AMBIENT image?', '0')
        ->expectsConfirmation('Process 3 images in 2 groups?', 'yes')
        ->assertExitCode(0);

        $workflow = WorkflowRun::where('project_name', 'exposure-mode-test')->first();
        $this->assertNotNull($workflow);
        $this->assertEquals('exposure_mode', $workflow->config['classification_strategy']);
    }

    /** @test */
    public function it_shows_sample_exif_values_when_requested(): void
    {
        Process::fake([
            '*exiftool*' => Process::result(output: $this->getSampleExifCsv()),
            'bash *run_all_scripts.sh*' => Process::result(output: 'Success!'),
        ]);

        $this->artisan('flambient:process', [
            '--project' => 'with-samples',
            '--dir' => $this->tempImageDir,
            '--local' => true,
        ])
        ->expectsQuestion('Show sample EXIF values from your images?', true)
        ->expectsQuestion('Which EXIF field should identify Ambient images?', 'flash')
        ->expectsQuestion('What Flash value indicates an AMBIENT image?', '16')
        ->expectsConfirmation('Process 3 images in 2 groups?', 'yes')
        ->expectsOutputToContain('image_001.jpg')
        ->assertExitCode(0);
    }

    /** @test */
    public function it_handles_workflow_failure_gracefully(): void
    {
        Process::fake([
            '*exiftool*' => Process::result(output: $this->getSampleExifCsv()),
            'bash *run_all_scripts.sh*' => Process::result(
                output: '',
                errorOutput: 'ImageMagick error',
                exitCode: 1
            ),
        ]);

        $this->artisan('flambient:process', [
            '--project' => 'failing-workflow',
            '--dir' => $this->tempImageDir,
            '--local' => true,
        ])
        ->expectsQuestion('Show sample EXIF values from your images?', false)
        ->expectsQuestion('Which EXIF field should identify Ambient images?', 'flash')
        ->expectsQuestion('What Flash value indicates an AMBIENT image?', '16')
        ->expectsConfirmation('Process 3 images in 2 groups?', 'yes');

        // Verify workflow was marked as failed
        $workflow = WorkflowRun::where('project_name', 'failing-workflow')->first();
        $this->assertNotNull($workflow);
        $this->assertEquals(WorkflowStatus::Failed->value, $workflow->status);
        $this->assertNotNull($workflow->error_message);
    }

    /** @test */
    public function it_records_all_workflow_steps(): void
    {
        Process::fake([
            '*exiftool*' => Process::result(output: $this->getSampleExifCsv()),
            'bash *run_all_scripts.sh*' => Process::result(output: 'Success!'),
        ]);

        $this->artisan('flambient:process', [
            '--project' => 'step-tracking',
            '--dir' => $this->tempImageDir,
            '--local' => true,
        ])
        ->expectsQuestion('Show sample EXIF values from your images?', false)
        ->expectsQuestion('Which EXIF field should identify Ambient images?', 'flash')
        ->expectsQuestion('What Flash value indicates an AMBIENT image?', '16')
        ->expectsConfirmation('Process 3 images in 2 groups?', 'yes')
        ->assertExitCode(0);

        $workflow = WorkflowRun::where('project_name', 'step-tracking')->first();

        // Verify all major steps were recorded
        $steps = $workflow->steps()->pluck('step_name')->toArray();
        $this->assertContains('analyze', $steps);
        $this->assertContains('process', $steps);
    }

    /** @test */
    public function it_validates_image_directory_exists(): void
    {
        $this->artisan('flambient:process', [
            '--project' => 'invalid-dir',
            '--dir' => '/nonexistent/directory',
            '--local' => true,
        ])
        ->assertExitCode(1);
    }

    /** @test */
    public function it_creates_output_directory_structure(): void
    {
        Process::fake([
            '*exiftool*' => Process::result(output: $this->getSampleExifCsv()),
            'bash *run_all_scripts.sh*' => Process::result(output: 'Success!'),
        ]);

        $customOutput = sys_get_temp_dir() . '/custom_output_' . uniqid();

        $this->artisan('flambient:process', [
            '--project' => 'custom-output',
            '--dir' => $this->tempImageDir,
            '--output' => $customOutput,
            '--local' => true,
        ])
        ->expectsQuestion('Show sample EXIF values from your images?', false)
        ->expectsQuestion('Which EXIF field should identify Ambient images?', 'flash')
        ->expectsQuestion('What Flash value indicates an AMBIENT image?', '16')
        ->expectsConfirmation('Process 3 images in 2 groups?', 'yes')
        ->assertExitCode(0);

        $this->assertDirectoryExists($customOutput);
        $this->assertDirectoryExists("{$customOutput}/scripts");
        $this->assertDirectoryExists("{$customOutput}/flambient");

        File::deleteDirectory($customOutput);
    }

    private function getSampleExifCsv(): string
    {
        return <<<CSV
SourceFile,Filename,DateTimeOriginal,MeteringMode,ShutterSpeed,ApertureValue,ISO,Flash,WhiteBalance,ExposureProgram,ExposureMode,FNumber
{$this->tempImageDir}/image_001.jpg,image_001.jpg,2024:01:15 14:30:00,5,0.008,2.8,100,16,0,1,0,2.8
{$this->tempImageDir}/image_002.jpg,image_002.jpg,2024:01:15 14:30:00,5,0.004,2.8,200,0,1,2,1,2.8
{$this->tempImageDir}/image_003.jpg,image_003.jpg,2024:01:15 14:35:00,5,0.008,2.8,100,16,0,1,0,2.8
CSV;
    }
}
