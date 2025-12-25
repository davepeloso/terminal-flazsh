<?php

namespace App\Enums;

enum ImageType: string
{
    case Ambient = 'ambient';
    case Flash = 'flash';
    case Blended = 'blended';

    /**
     * Determine image type from EXIF flash field value.
     */
    public static function fromFlashField(int $flashValue): self
    {
        // Flash# == 16 means "no flash fired"
        return $flashValue === 16 ? self::Ambient : self::Flash;
    }

    /**
     * Check if this is a source image type (not blended).
     */
    public function isSource(): bool
    {
        return in_array($this, [self::Ambient, self::Flash]);
    }
}
