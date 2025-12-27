<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Imagen AI Configuration
    |--------------------------------------------------------------------------
    */
    'imagen' => [
        'api_key' => env('IMAGEN_AI_API_KEY'),
        'base_url' => env('IMAGEN_API_BASE_URL', 'https://api-beta.imagen-ai.com/v1'),
        'profile_key' => env('IMAGEN_PROFILE_KEY', 309406),
        'timeout' => env('IMAGEN_TIMEOUT', 30),
        'retry_times' => env('IMAGEN_RETRY_TIMES', 3),
        'poll_interval' => env('IMAGEN_POLL_INTERVAL', 30), // seconds
        'poll_max_attempts' => env('IMAGEN_POLL_MAX_ATTEMPTS', 240), // 2 hours at 30s intervals
    ],

    /*
    |--------------------------------------------------------------------------
    | ImageMagick Configuration
    |--------------------------------------------------------------------------
    */
    'imagemagick' => [
        'binary' => env('IMAGEMAGICK_BINARY', 'magick'),
        'level_low' => env('IMAGEMAGICK_LEVEL_LOW', '40%'),
        'level_high' => env('IMAGEMAGICK_LEVEL_HIGH', '140%'),
        'gamma' => env('IMAGEMAGICK_GAMMA', '1.0'),
        'output_prefix' => env('IMAGEMAGICK_OUTPUT_PREFIX', 'flambient'),
        'enable_darken_export' => env('IMAGEMAGICK_DARKEN_EXPORT', true),
        'darken_suffix' => env('IMAGEMAGICK_DARKEN_SUFFIX', '_tmp'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow Configuration
    |--------------------------------------------------------------------------
    */
    'workflow' => [
        'storage_path' => env('FLAMBIENT_STORAGE_PATH', storage_path('flambient')),
        'keep_temp_files' => env('FLAMBIENT_KEEP_TEMP', false),
        'parallel_uploads' => env('FLAMBIENT_PARALLEL_UPLOADS', 5),
        'parallel_downloads' => env('FLAMBIENT_PARALLEL_DOWNLOADS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Polling Configuration
    |--------------------------------------------------------------------------
    */
    'polling' => [
        'initial_interval' => 15,  // seconds
        'max_interval' => 60,
        'timeout_minutes' => 120,
    ],
];
