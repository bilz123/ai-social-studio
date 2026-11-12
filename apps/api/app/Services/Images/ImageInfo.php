<?php

namespace App\Services\Images;

/** What was learned about an upload by reading the file itself (never the client's claims). */
final readonly class ImageInfo
{
    public function __construct(
        public string $mime,
        public string $extension,
        public int $width,
        public int $height,
        public int $bytes,
        public string $sha256,
        /** EXIF orientation (1 = upright). Only JPEG files carry one. */
        public int $orientation = 1,
    ) {}
}
