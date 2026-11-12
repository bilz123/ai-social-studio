<?php

namespace App\Support;

/** The limits the SPA needs to validate files and text before sending them. */
final class UploadLimits
{
    public const MAX_TITLE_LENGTH = 160;

    public const MAX_DESCRIPTION_LENGTH = 2000;

    /** @return array<string, mixed> */
    public static function toArray(): array
    {
        return [
            'max_bytes' => (int) config('uploads.max_megabytes') * 1024 * 1024,
            'max_images' => (int) config('uploads.max_images_per_project'),
            'formats' => array_keys((array) config('uploads.formats')),
            'min_dimension' => (int) config('uploads.min_dimension'),
            'max_dimension' => (int) config('uploads.max_dimension'),
            'max_title_length' => self::MAX_TITLE_LENGTH,
            'max_description_length' => self::MAX_DESCRIPTION_LENGTH,
        ];
    }
}
