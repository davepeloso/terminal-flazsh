<?php

namespace App\Console\Commands;

use App\Services\ImagenAI\ImagenClient;
use App\Services\ImagenAI\ImagenException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class ImagenFetchProfilesCommand extends Command
{
    protected $signature = 'imagen:fetch-profiles
                            {--merge : Merge with existing profiles instead of replacing}
                            {--update-favorites : Interactively select new favorites}';

    protected $description = 'Fetch Imagen AI profiles directly from the API and update config';

    public function handle(): int
    {
        info('Fetching Imagen AI Profiles from API');
        $this->newLine();

        try {
            // Fetch profiles from API
            $client = new ImagenClient();

            $profiles = spin(
                callback: fn() => $client->getProfiles(),
                message: 'Fetching profiles from Imagen AI API...'
            );

            if ($profiles->isEmpty()) {
                $this->error('No profiles found');
                return self::FAILURE;
            }

            $this->components->info("✓ Found {$profiles->count()} profiles");
            $this->newLine();

            // Convert to config format
            $profilesArray = $profiles->mapWithKeys(fn($profile) => [
                $profile->key => $this->formatProfileName($profile)
            ])->toArray();

            // Show sample
            $this->table(
                ['Profile Key', 'Name'],
                collect($profilesArray)->take(10)->map(fn($name, $key) => [$key, $name])->values()->toArray()
            );

            if (count($profilesArray) > 10) {
                note("... and " . (count($profilesArray) - 10) . " more");
            }
            $this->newLine();

            // Confirm import
            if (!confirm('Update config/imagen-profiles.php with these profiles?', default: true)) {
                info('Import cancelled');
                return self::SUCCESS;
            }

            // Generate config file
            $configPath = config_path('imagen-profiles.php');
            $merge = $this->option('merge');

            if ($merge && File::exists($configPath)) {
                $existingProfiles = config('imagen-profiles.all', []);
                $profilesArray = array_merge($existingProfiles, $profilesArray);
                note('Merging with existing profiles');
            }

            // Get current favorites or defaults
            $favorites = config('imagen-profiles.favorites', [
                223855 => 'Real Estate - Bright & Airy',
                254508 => 'Real Estate - Warm & Natural',
                279309 => 'Real Estate - Dramatic HDR',
            ]);

            $this->generateConfigFile($profilesArray, $favorites, $configPath);

            $this->newLine();
            $this->components->info("✓ Successfully imported {$profiles->count()} profiles");
            note("Config updated: {$configPath}");
            $this->newLine();

            info('Next steps:');
            info('1. Review config/imagen-profiles.php');
            info('2. Update the "favorites" array with your preferred profiles');
            info('3. Run: php artisan config:clear');

            return self::SUCCESS;

        } catch (ImagenException $e) {
            $this->error("Imagen API error: {$e->getMessage()}");
            $this->newLine();
            warning('Make sure your IMAGEN_AI_API_KEY is set in .env');
            return self::FAILURE;
        }
    }

    /**
     * Format profile name from API response with full details.
     */
    private function formatProfileName($profile): string
    {
        $parts = [];

        // Start with the profile name
        $parts[] = $profile->name;

        // Add image type (RAW/JPEG info) if available
        if (!empty($profile->imageType)) {
            $parts[] = "[{$profile->imageType}]";
        }

        // Add profile type if available
        if (!empty($profile->profileType)) {
            $parts[] = "({$profile->profileType})";
        }

        return implode(' ', $parts);
    }

    /**
     * Generate config file content.
     */
    private function generateConfigFile(array $profiles, array $favorites, string $configPath): void
    {
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
    | Fetched from Imagen AI API on:
PHP;

        $content .= date('Y-m-d H:i:s') . "\n";
        $content .= <<<'PHP'
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
    | To find your favorites, run the workflow and note which profiles
    | you use most often, then update this list.
    |
    */
    'favorites' => [
PHP;

        // Add favorites
        foreach ($favorites as $key => $name) {
            $content .= "\n        {$key} => '{$this->escapeString($name)}',";
        }

        $content .= "\n    ],\n\n";
        $content .= <<<'PHP'
    /*
    |--------------------------------------------------------------------------
    | All Available Profiles
    |--------------------------------------------------------------------------
    |
    | Complete list of all Imagen AI profiles fetched from the API.
    | To update this list, run: php artisan imagen:fetch-profiles
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
