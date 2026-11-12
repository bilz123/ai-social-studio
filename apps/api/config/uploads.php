<?php

/*
|--------------------------------------------------------------------------
| Product image uploads
|--------------------------------------------------------------------------
| Originals are stored exactly as uploaded on a private disk and are only reachable through
| short-lived signed URLs. Everything here can be tuned from the environment.
*/

return [

    // Any disk from config/filesystems.php. Must be private: "local" (storage/app/private) in
    // development, "s3" (S3-compatible, private bucket) in production.
    'disk' => env('UPLOADS_DISK', 'local'),

    // Largest accepted file, in megabytes. Keep PHP's upload_max_filesize / post_max_size and the
    // gateway's client_max_body_size above this value.
    'max_megabytes' => (int) env('UPLOADS_MAX_MB', 10),

    // Most images one project may hold.
    'max_images_per_project' => (int) env('UPLOADS_MAX_IMAGES', 10),

    // Accepted formats: the file's detected content type => the extension it is stored under.
    // The name and content type sent by the browser are never trusted.
    'formats' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ],

    // Pixel limits. The decompressed bitmap needs about 4 bytes per pixel in memory, so the pixel
    // cap also protects against decompression bombs.
    'min_dimension' => (int) env('UPLOADS_MIN_DIMENSION', 256),
    'max_dimension' => (int) env('UPLOADS_MAX_DIMENSION', 8000),
    'max_pixels' => (int) env('UPLOADS_MAX_PIXELS', 36_000_000),

    'thumbnail' => [
        // Longest side in pixels and JPEG quality of the generated preview.
        'size' => 640,
        'quality' => 82,
    ],

    // How long a signed file URL works. Expiry is rounded up to the next bucket so repeated API
    // responses return the same URL (browser caching) instead of a new one on every request.
    'url_ttl_minutes' => (int) env('UPLOADS_URL_TTL_MINUTES', 10),
    'url_bucket_minutes' => 5,

];
