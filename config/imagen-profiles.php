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
        223855 => 'Real Estate - Bright & Airy',
        254508 => 'Real Estate - Warm & Natural',
        279309 => 'Real Estate - Dramatic HDR',
    ],

    /*
    |--------------------------------------------------------------------------
    | All Available Profiles
    |--------------------------------------------------------------------------
    |
    | Complete list of all Imagen AI profiles. You can add more profiles here
    | or import them from select_imagen_profile.html using:
    |   php artisan imagen:import-profiles /path/to/select_imagen_profile.html
    |
    */
    'all' => [
        // Favorites (duplicated for "view all" option)
        223855 => 'Real Estate - Bright & Airy',
        254508 => 'Real Estate - Warm & Natural',
        279309 => 'Real Estate - Dramatic HDR',

        // Other profiles
        309406 => 'Real Estate - Standard',

        // Add more profiles here as needed
        // Format: profile_key => 'Description',
    ],

    /*
    |--------------------------------------------------------------------------
    | Profile Categories (Optional)
    |--------------------------------------------------------------------------
    |
    | Organize profiles by category for easier browsing.
    |
    */
    'categories' => [
        'real_estate' => [
            'label' => 'Real Estate',
            'profiles' => [223855, 254508, 279309, 309406],
        ],
        // Add more categories as needed
        // 'wedding' => [...],
        // 'portrait' => [...],
    ],
];
