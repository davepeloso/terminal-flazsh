<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class ImagenImportProfilesCommand extends Command
{
    protected $signature = 'imagen:import-profiles
                            {file? : Path to select_imagen_profile.html}
                            {--merge : Merge with existing profiles instead of replacing}';

    protected $description = 'Import Imagen AI profiles from HTML file to config/imagen-profiles.php';

    public function handle(): int
    {
        $filePath = $this->argument('file') ?? base_path('select_imagen_profile.html');

        // Validate file exists
        if (!File::exists($filePath)) {
            $this->error("File not found: {$filePath}");
            $this->newLine();
            info('Expected file: select_imagen_profile.html in project root');
            info('Or specify path: php artisan imagen:import-profiles /path/to/file.html');
            return self::FAILURE;
        }

        info('Importing Imagen AI profiles from HTML file');
        note("Reading: {$filePath}");
        $this->newLine();

        // Read and parse HTML file
        $html = File::get($filePath);
        $profiles = $this->parseHtmlProfiles($html);

        if (empty($profiles)) {
            $this->error('No profiles found in HTML file');
            $this->newLine();
            warning('Expected HTML with <option value="profile_key">Profile Name</option>');
            return self::FAILURE;
        }

        $this->components->info("Found " . count($profiles) . " profiles");
        $this->newLine();

        // Show sample
        $this->table(
            ['Profile Key', 'Name'],
            collect($profiles)->take(5)->map(fn($name, $key) => [$key, $name])->toArray()
        );
        if (count($profiles) > 5) {
            note("... and " . (count($profiles) - 5) . " more");
        }
        $this->newLine();

        // Confirm import
        if (!confirm('Import these profiles to config/imagen-profiles.php?', default: true)) {
            info('Import cancelled');
            return self::SUCCESS;
        }

        // Generate new config content
        $configPath = config_path('imagen-profiles.php');
        $merge = $this->option('merge');

        if ($merge && File::exists($configPath)) {
            // Merge with existing
            $existingProfiles = config('imagen-profiles.all', []);
            $profiles = array_merge($existingProfiles, $profiles);
            note('Merging with existing profiles');
        }

        $this->generateConfigFile($profiles, $configPath);

        $this->newLine();
        $this->components->info('✓ Successfully imported ' . count($profiles) . ' profiles');
        note("Config updated: {$configPath}");
        $this->newLine();
        info('Next steps:');
        info('1. Review config/imagen-profiles.php');
        info('2. Update the "favorites" array with your preferred profiles');
        info('3. Run: php artisan config:clear');

        return self::SUCCESS;
    }

    /**
     * Parse HTML file to extract profile options.
     */
    private function parseHtmlProfiles(string $html): array
    {
        $profiles = [];

        // Match <option value="123456">Profile Name</option>
        preg_match_all('/<option\s+value=["\'](\d+)["\'][^>]*>(.*?)<\/option>/i', $html, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $index => $profileKey) {
                $profileName = trim(strip_tags($matches[2][$index]));
                if (!empty($profileName)) {
                    $profiles[$profileKey] = $profileName;
                }
            }
        }

        // Sort by profile key
        ksort($profiles);

        return $profiles;
    }

    /**
     * Generate config file content.
     */
    private function generateConfigFile(array $profiles, string $configPath): void
    {
        // Get current favorites (preserve them if merging)
        $currentFavorites = config('imagen-profiles.favorites', [
            223855 => 'Real Estate - Bright & Airy',
            254508 => 'Real Estate - Warm & Natural',
            279309 => 'Real Estate - Dramatic HDR',
        ]);

        $content = <<<'PHP'
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Imagen AI Editing Profiles
    |--------------------------------------------------------------------------
    |
    | Profile keys determine which AI editing style Imagen applies to your
    | images. Each profile has been trained on specific photography styles
    | and produces different results.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Default Profile
    |--------------------------------------------------------------------------
    |
    | This profile is used when no selection is made or when running in
    | non-interactive mode.
    |
    */
    'default' => env('IMAGEN_PROFILE_KEY', 309406),

    /*
    |--------------------------------------------------------------------------
    | Favorite Profiles (Quick Select)
    |--------------------------------------------------------------------------
    |
    | Your most-used profiles for quick selection. These appear first in
    | the selection prompt. Edit this array to customize your shortcuts.
    |
    */
    'favorites' => [
PHP;

        // Add favorites
        foreach ($currentFavorites as $key => $name) {
            $content .= "\n        {$key} => '{$this->escapeString($name)}',";
        }

        $content .= "\n    ],\n\n";
        $content .= <<<'PHP'
    /*
    |--------------------------------------------------------------------------
    | All Available Profiles
    |--------------------------------------------------------------------------
    |
    | Complete list of all Imagen AI profiles imported from
    | select_imagen_profile.html
    |
    */
    'all' => [
PHP;

        // Add all profiles
        foreach ($profiles as $key => $name) {
            $content .= "\n        {$key} => '{$this->escapeString($name)}',";
        }

        $content .= "\n    ],\n];\n";

        File::put($configPath, $content);
    }

    /**
     * Escape string for PHP config file.
     */
    private function escapeString(string $value): string
    {
        return addslashes($value);
    }
}
