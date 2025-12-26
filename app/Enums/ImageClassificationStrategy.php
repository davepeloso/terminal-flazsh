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
            self::Flash => 'Flash',
            self::ExposureProgram => 'Exposure Program',
            self::ExposureMode => 'Exposure Mode',
            self::WhiteBalance => 'White Balance',
            self::ISO => 'ISO',
            self::ShutterSpeed => 'Shutter Speed',
            self::Custom => 'Custom Field',
        };
    }

    /**
     * Get help text showing common values.
     */
    public function helpText(): string
    {
        return match($this) {
            self::Flash => '16=No Flash, 0=Flash Fired',
            self::ExposureProgram => '0=Not Defined, 1=Manual, 2=Program AE, 3=Aperture-priority',
            self::ExposureMode => '0=Auto, 1=Manual, 2=Auto Bracket',
            self::WhiteBalance => '0=Auto, 1=Manual',
            self::ISO => 'e.g., 100, 125, 400, 640, 1000',
            self::ShutterSpeed => 'e.g., 1/50, 1/100, 1/1000',
            self::Custom => '',
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
