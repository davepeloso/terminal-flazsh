<?php

namespace App\Enums;

enum ImageClassificationStrategy: string
{
    case Flash = 'flash';
    case ExposureProgram = 'exposure_program';
    case ExposureMode = 'exposure_mode';
    case WhiteBalance = 'white_balance';
    case ISO = 'iso';
    case ShutterSpeed = 'shutter_speed';
    case Custom = 'custom';

    /**
     * Get human-readable label for the strategy.
     */
    public function label(): string
    {
        return match($this) {
            self::Flash => 'Flash Field (Flash# = 16 for Ambient)',
            self::ExposureProgram => 'Exposure Program',
            self::ExposureMode => 'Exposure Mode',
            self::WhiteBalance => 'White Balance',
            self::ISO => 'ISO Value',
            self::ShutterSpeed => 'Shutter Speed',
            self::Custom => 'Custom Field',
        };
    }

    /**
     * Get the EXIF field name for this strategy.
     */
    public function exifField(): string
    {
        return match($this) {
            self::Flash => 'Flash#',
            self::ExposureProgram => 'ExposureProgram',
            self::ExposureMode => 'ExposureMode',
            self::WhiteBalance => 'WhiteBalance',
            self::ISO => 'ISO',
            self::ShutterSpeed => 'ShutterSpeed',
            self::Custom => '',
        };
    }

    /**
     * Get common values for ambient images.
     */
    public function commonAmbientValue(): mixed
    {
        return match($this) {
            self::Flash => 16,
            self::ExposureProgram => 1, // Manual
            self::ExposureMode => 1, // Manual
            self::WhiteBalance => 0, // Auto
            self::ISO => null, // User must specify
            self::ShutterSpeed => null, // User must specify
            self::Custom => null,
        };
    }
}
