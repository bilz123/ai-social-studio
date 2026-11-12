<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An uploaded file was refused. The message is written for the end user and is safe to show; it
 * never echoes file content or internal paths.
 */
class ImageRejectedException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $detail,
    ) {
        parent::__construct($detail);
    }

    public static function empty(): self
    {
        return new self('image_empty', 422, 'This file is empty.');
    }

    public static function tooLarge(int $maxMegabytes): self
    {
        return new self('image_too_large', 422, "This file is larger than {$maxMegabytes} MB.");
    }

    public static function unsupportedType(): self
    {
        return new self('image_unsupported_type', 422, 'Only JPEG, PNG and WebP images are accepted.');
    }

    public static function corrupt(): self
    {
        return new self('image_corrupt', 422, 'This file is not a valid image, or it is damaged.');
    }

    public static function tooSmall(int $minimum): self
    {
        return new self('image_too_small', 422, "Images must be at least {$minimum} pixels on each side.");
    }

    public static function tooManyPixels(): self
    {
        return new self('image_dimensions_too_large', 422, 'This image has too many pixels to process. Resize it and upload it again.');
    }

    public static function duplicate(): self
    {
        return new self('image_duplicate', 422, 'This image is already in the project.');
    }

    public static function limitReached(int $limit): self
    {
        return new self('image_limit_reached', 422, "A project can hold up to {$limit} images. Remove one to add another.");
    }
}
