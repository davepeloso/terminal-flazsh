<?php

namespace Tests\Unit;

use App\Enums\ImageClassificationStrategy;
use App\Enums\ImageType;
use App\Services\Flambient\ExifService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ExifServiceTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesPath = base_path('tests/Fixtures/exif');
    }

    /** @test */
    public function it_extracts_exif_metadata_from_images(): void
    {
        // Mock exiftool output with sample CSV data
        Process::fake([
            '*exiftool*' => Process::result(
                output: $this->getSampleExifCsv(),
                errorOutput: '',
                exitCode: 0
            ),
        ]);

        $service = new ExifService();
        $metadata = $service->extractMetadata('/fake/directory');

        $this->assertCount(3, $metadata);
        $this->assertEquals('image_001.jpg', $metadata->first()['filename']);
        $this->assertEquals(ImageType::Ambient, $metadata->first()['type']);
    }

    /** @test */
    public function it_classifies_images_using_flash_strategy(): void
    {
        Process::fake([
            '*exiftool*' => Process::result(output: $this->getSampleExifCsv()),
        ]);

        $service = new ExifService(
            strategy: ImageClassificationStrategy::Flash,
            ambientValue: 16
        );

        $metadata = $service->extractMetadata('/fake/directory');

        // First image has Flash=16 (No Flash) = Ambient
        $this->assertEquals(ImageType::Ambient, $metadata[0]['type']);
        // Second image has Flash=0 (Flash Fired) = Flash
        $this->assertEquals(ImageType::Flash, $metadata[1]['type']);
    }

    /** @test */
    public function it_classifies_images_using_exposure_program_strategy(): void
    {
        Process::fake([
            '*exiftool*' => Process::result(output: $this->getSampleExifCsv()),
        ]);

        $service = new ExifService(
            strategy: ImageClassificationStrategy::ExposureProgram,
            ambientValue: 1 // Manual
        );

        $metadata = $service->extractMetadata('/fake/directory');

        // First image has ExposureProgram=1 (Manual) = Ambient
        $this->assertEquals(ImageType::Ambient, $metadata[0]['type']);
        // Second image has ExposureProgram=2 (Program AE) = Flash
        $this->assertEquals(ImageType::Flash, $metadata[1]['type']);
    }

    /** @test */
    public function it_classifies_images_using_exposure_mode_strategy(): void
    {
        Process::fake([
            '*exiftool*' => Process::result(output: $this->getSampleExifCsv()),
        ]);

        $service = new ExifService(
            strategy: ImageClassificationStrategy::ExposureMode,
            ambientValue: 0 // Auto
        );

        $metadata = $service->extractMetadata('/fake/directory');

        // First image has ExposureMode=0 (Auto) = Ambient
        $this->assertEquals(ImageType::Ambient, $metadata[0]['type']);
        // Second image has ExposureMode=1 (Manual) = Flash
        $this->assertEquals(ImageType::Flash, $metadata[1]['type']);
    }

    /** @test */
    public function it_classifies_images_using_white_balance_strategy(): void
    {
        Process::fake([
            '*exiftool*' => Process::result(output: $this->getSampleExifCsv()),
        ]);

        $service = new ExifService(
            strategy: ImageClassificationStrategy::WhiteBalance,
            ambientValue: 0 // Auto
        );

        $metadata = $service->extractMetadata('/fake/directory');

        // First image has WhiteBalance=0 (Auto) = Ambient
        $this->assertEquals(ImageType::Ambient, $metadata[0]['type']);
        // Second image has WhiteBalance=1 (Manual) = Flash
        $this->assertEquals(ImageType::Flash, $metadata[1]['type']);
    }

    /** @test */
    public function it_groups_images_by_timestamp(): void
    {
        Process::fake([
            '*exiftool*' => Process::result(output: $this->getSampleExifCsv()),
        ]);

        $service = new ExifService();
        $metadata = $service->extractMetadata('/fake/directory');
        $groups = $service->groupImagesByTimestamp($metadata);

        $this->assertCount(2, $groups);

        // First group should have 2 images (same timestamp)
        $this->assertCount(2, $groups->first()['images']);
        $this->assertArrayHasKey('ambient', $groups->first());
        $this->assertArrayHasKey('flash', $groups->first());
    }

    /** @test */
    public function it_validates_groups_have_both_ambient_and_flash(): void
    {
        Process::fake([
            '*exiftool*' => Process::result(output: $this->getSampleExifCsv()),
        ]);

        $service = new ExifService();
        $metadata = $service->extractMetadata('/fake/directory');
        $groups = $service->groupImagesByTimestamp($metadata);

        foreach ($groups as $group) {
            $this->assertNotEmpty($group['ambient'], 'Group should have ambient images');
            $this->assertNotEmpty($group['flash'], 'Group should have flash images');
        }
    }

    /** @test */
    public function it_gets_sample_exif_values(): void
    {
        Process::fake([
            '*exiftool*' => Process::result(output: $this->getSampleExifCsv()),
        ]);

        $service = new ExifService();
        $samples = $service->getSampleExifValues('/fake/directory', 2);

        $this->assertCount(2, $samples);
        $this->assertArrayHasKey('filename', $samples[0]);
        $this->assertArrayHasKey('flash', $samples[0]);
        $this->assertArrayHasKey('exposure_program', $samples[0]);
        $this->assertArrayHasKey('exposure_mode', $samples[0]);
    }

    /** @test */
    public function it_handles_custom_exif_field(): void
    {
        Process::fake([
            '*exiftool*' => Process::result(output: $this->getSampleExifCsv()),
        ]);

        $service = new ExifService(customField: 'CustomField');

        // Verify the command includes the custom field
        $service->extractMetadata('/fake/directory');

        Process::assertRan(function ($process) {
            return str_contains($process, '-CustomField');
        });
    }

    private function getSampleExifCsv(): string
    {
        return <<<CSV
SourceFile,Filename,DateTimeOriginal,MeteringMode,ShutterSpeed,ApertureValue,ISO,Flash,WhiteBalance,ExposureProgram,ExposureMode,FNumber
/fake/image_001.jpg,image_001.jpg,2024:01:15 14:30:00,5,0.008,2.8,100,16,0,1,0,2.8
/fake/image_002.jpg,image_002.jpg,2024:01:15 14:30:00,5,0.004,2.8,200,0,1,2,1,2.8
/fake/image_003.jpg,image_003.jpg,2024:01:15 14:35:00,5,0.008,2.8,100,16,0,1,0,2.8
CSV;
    }
}
