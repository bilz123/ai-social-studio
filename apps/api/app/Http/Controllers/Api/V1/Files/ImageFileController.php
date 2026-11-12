<?php

namespace App\Http\Controllers\Api\V1\Files;

use App\Http\Controllers\Controller;
use App\Models\ProjectImage;
use App\Services\Images\ImageStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a private image file to anyone holding a valid signed link (the `signed:relative`
 * middleware has already checked signature and expiry). The content type comes from our own
 * database record, never from the file name, and the response forbids sniffing and scripting.
 */
class ImageFileController extends Controller
{
    public function __invoke(ProjectImage $image, string $variant, ImageStorage $storage): StreamedResponse
    {
        $thumbnail = $variant === 'thumb';
        $key = $thumbnail ? $image->thumbnail_key : $image->object_key;

        abort_if($key === null, 404);

        $stream = $storage->readStream($key);

        abort_if($stream === null, 404);

        $mime = $thumbnail ? 'image/jpeg' : $image->mime;
        $extension = $thumbnail ? 'jpg' : pathinfo($key, PATHINFO_EXTENSION);
        $length = $thumbnail ? $image->thumbnail_bytes : $image->bytes;

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, array_filter([
            'Content-Type' => $mime,
            'Content-Length' => $length !== null ? (string) $length : null,
            'Content-Disposition' => 'inline; filename="'.$image->ulid.'.'.$extension.'"',
            // Shorter than the link lifetime, and never shared between users by proxies.
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]));
    }
}
