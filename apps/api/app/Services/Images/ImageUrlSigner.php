<?php

namespace App\Services\Images;

use App\Models\ProjectImage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * Issues temporary signed links to private image files. A link works for roughly ten minutes, can
 * not be edited (the signature covers the whole URL) and only ever reaches the one file it names.
 * Anyone who may see a project gets fresh links from the API; links are never stored.
 */
class ImageUrlSigner
{
    public function expiry(): CarbonImmutable
    {
        $ttl = max(1, (int) config('uploads.url_ttl_minutes')) * 60;
        $bucket = max(1, (int) config('uploads.url_bucket_minutes')) * 60;

        // Round up to the next bucket so every response inside it carries the same URL and the
        // browser can reuse its cached copy.
        $now = now()->getTimestamp();
        $expires = (intdiv($now, $bucket) + 1) * $bucket + $ttl;

        return CarbonImmutable::createFromTimestamp($expires);
    }

    public function thumbnail(ProjectImage $image): ?string
    {
        return $image->thumbnail_key !== null ? $this->url($image, 'thumb') : null;
    }

    public function original(ProjectImage $image): string
    {
        return $this->url($image, 'original');
    }

    private function url(ProjectImage $image, string $variant): string
    {
        // Relative on purpose: it works behind any proxy or host name, and the signature is
        // checked against the same relative form (`signed:relative`).
        return URL::temporarySignedRoute(
            'v1.files.images.show',
            $this->expiry(),
            ['image' => $image->ulid, 'variant' => $variant],
            absolute: false,
        );
    }
}
