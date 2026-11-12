<?php

namespace App\Services\Images;

use App\Exceptions\ImageRejectedException;
use finfo;
use GdImage;

/**
 * Looks inside an uploaded file and makes its preview. Nothing here trusts the file name, the
 * extension or the content type the browser sent: the type comes from the file's own bytes, is
 * cross-checked against the image parser, and the picture must decode completely before it is
 * accepted. The original file is only ever read, never rewritten.
 */
final class ImageProcessor
{
    /** @param  array<string, mixed>  $config  See config/uploads.php. */
    public function __construct(private readonly array $config) {}

    /**
     * Cheap checks first (size, type, header, pixel counts); throws ImageRejectedException on the
     * first problem. Only reads the file header, so oversized pictures are refused before any
     * bitmap is decoded into memory.
     */
    public function inspect(string $path): ImageInfo
    {
        $bytes = is_file($path) ? filesize($path) : false;

        if ($bytes === false || $bytes === 0) {
            throw ImageRejectedException::empty();
        }

        $maxMegabytes = (int) ($this->config['max_megabytes'] ?? 10);
        if ($bytes > $maxMegabytes * 1024 * 1024) {
            throw ImageRejectedException::tooLarge($maxMegabytes);
        }

        // 1. The type according to the file's content (libmagic), not its name.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $formats = $this->formats();

        if (! is_string($mime) || ! isset($formats[$mime])) {
            throw ImageRejectedException::unsupportedType();
        }

        // 2. The image parser must agree with libmagic and find real dimensions.
        $size = @getimagesize($path);

        if ($size === false || image_type_to_mime_type($size[2]) !== $mime) {
            throw ImageRejectedException::corrupt();
        }

        // GD silently accepts a cut-off JPEG and fills the rest with grey, so check for the
        // end-of-image marker ourselves. (PNG and WebP are rejected by the decoder.)
        if ($mime === 'image/jpeg' && ! $this->jpegIsComplete((string) file_get_contents($path))) {
            throw ImageRejectedException::corrupt();
        }

        $width = $size[0];
        $height = $size[1];

        if ($width < 1 || $height < 1) {
            throw ImageRejectedException::corrupt();
        }

        $minimum = (int) ($this->config['min_dimension'] ?? 256);
        if (min($width, $height) < $minimum) {
            throw ImageRejectedException::tooSmall($minimum);
        }

        if (
            max($width, $height) > (int) ($this->config['max_dimension'] ?? 8000)
            || $width * $height > (int) ($this->config['max_pixels'] ?? 36_000_000)
            || ! $this->fitsInMemory($width, $height)
        ) {
            throw ImageRejectedException::tooManyPixels();
        }

        $orientation = $mime === 'image/jpeg' ? $this->exifOrientation($path) : 1;

        // Phones store rotated photos with an orientation flag; report the size people see.
        if ($orientation >= 5 && $orientation <= 8) {
            [$width, $height] = [$height, $width];
        }

        return new ImageInfo(
            mime: $mime,
            extension: $formats[$mime],
            width: $width,
            height: $height,
            bytes: $bytes,
            sha256: (string) hash_file('sha256', $path),
            orientation: $orientation,
        );
    }

    /**
     * Decodes the whole picture (proof that it is a real, complete image) and returns a JPEG
     * preview. Re-encoding drops all metadata (GPS position, camera serial, embedded profiles),
     * so previews are safe to show anywhere. Transparent areas are placed on white.
     */
    public function thumbnail(string $path, ImageInfo $info): string
    {
        $data = file_get_contents($path);
        $source = $data === false ? false : @imagecreatefromstring($data);
        unset($data);

        if (! $source instanceof GdImage) {
            throw ImageRejectedException::corrupt();
        }

        $source = $this->orient($source, $info->orientation);

        $width = imagesx($source);
        $height = imagesy($source);

        $longest = (int) ($this->config['thumbnail']['size'] ?? 640);
        $scale = min(1.0, $longest / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $written = imagejpeg($canvas, null, (int) ($this->config['thumbnail']['quality'] ?? 82));
        $jpeg = (string) ob_get_clean();

        if (! $written || $jpeg === '') {
            throw ImageRejectedException::corrupt();
        }

        return $jpeg;
    }

    /** @return array<string, string> */
    private function formats(): array
    {
        /** @var array<string, string> $formats */
        $formats = (array) ($this->config['formats'] ?? []);

        return $formats;
    }

    /**
     * Walks the JPEG segments up to the end-of-image marker. Bytes after that marker (some phones
     * append extra data) are ignored.
     */
    private function jpegIsComplete(string $data): bool
    {
        $length = strlen($data);
        $position = 2; // past the start-of-image marker

        while ($position + 1 < $length) {
            if ($data[$position] !== "\xFF") {
                return false;
            }

            $marker = ord($data[$position + 1]);

            if ($marker === 0xFF) { // fill byte
                $position++;

                continue;
            }

            if ($marker === 0xD9) { // end of image
                return true;
            }

            if ($marker === 0x00 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $position += 2;

                continue;
            }

            if ($position + 3 >= $length) {
                return false;
            }

            $segment = (ord($data[$position + 2]) << 8) | ord($data[$position + 3]);

            if ($segment < 2) {
                return false;
            }

            $position += 2 + $segment;

            if ($marker !== 0xDA) { // not a start-of-scan: no entropy-coded data follows
                continue;
            }

            // Skip the compressed scan data: it ends at the next marker that is not a stuffed 0xFF00
            // byte or a restart marker.
            while (true) {
                $next = strpos($data, "\xFF", $position);

                if ($next === false || $next + 1 >= $length) {
                    return false;
                }

                $code = ord($data[$next + 1]);

                if ($code === 0x00 || ($code >= 0xD0 && $code <= 0xD7)) {
                    $position = $next + 2;

                    continue;
                }

                $position = $next;
                break;
            }
        }

        return false;
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    private function exifOrientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path, 'IFD0');
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /** Decoding needs the bitmap (4 bytes per pixel) plus a working copy; refuse what cannot fit. */
    private function fitsInMemory(int $width, int $height): bool
    {
        $limit = $this->memoryLimitBytes();

        if ($limit === null) {
            return true;
        }

        $needed = $width * $height * 4 * 2 + 16 * 1024 * 1024;

        return $needed <= $limit - memory_get_usage(true);
    }

    private function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $number = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
