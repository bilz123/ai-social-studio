<?php

namespace Tests\Support;

use GdImage;

/** Builds real (decodable) pictures and a few deliberately broken files for upload tests. */
trait MakesImages
{
    /**
     * A picture with some seeded noise, so different seeds give different bytes (and hashes).
     *
     * @param  'jpg'|'png'|'webp'|'gif'  $format
     */
    protected function makeImage(string $format = 'jpg', int $width = 800, int $height = 600, int $seed = 1, bool $alpha = false): string
    {
        if ($format === 'webp' && ! function_exists('imagewebp')) {
            $this->markTestSkipped('This PHP build has no WebP support.');
        }

        mt_srand($seed);

        $image = imagecreatetruecolor($width, $height);

        if ($alpha) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 255, 0, 0, 127));
        } else {
            imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 120, 40));
        }

        for ($i = 0; $i < 40; $i++) {
            $x = mt_rand(0, $width - 1);
            $y = mt_rand(0, $height - 1);
            imagefilledrectangle($image, $x, $y, min($width - 1, $x + mt_rand(5, 120)), min($height - 1, $y + mt_rand(5, 120)), (int) imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }

        return $this->encode($image, $format);
    }

    /** A JPEG whose EXIF orientation flag says "rotate 90° clockwise" (as phones store portrait shots). */
    protected function makeJpegWithOrientation(int $orientation, int $width = 1200, int $height = 800): string
    {
        $jpeg = $this->makeImage('jpg', $width, $height);

        // Minimal big-endian EXIF block with a single Orientation entry.
        $exif = "Exif\0\0MM\0*\0\0\0\x08\0\x01\x01\x12\0\x03\0\0\0\x01\0".chr($orientation)."\0\0\0\0\0\0";
        $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    /** The first part of a valid file, as left behind by an interrupted transfer. */
    protected function truncated(string $bytes, float $keep = 0.5): string
    {
        return substr($bytes, 0, (int) (strlen($bytes) * $keep));
    }

    /** A PNG whose header claims absurd dimensions (a decompression-bomb shape) in a tiny file. */
    protected function pngClaimingSize(int $width, int $height): string
    {
        $png = $this->makeImage('png', 300, 300);

        return substr($png, 0, 16).pack('N', $width).pack('N', $height).substr($png, 24);
    }

    private function encode(GdImage $image, string $format): string
    {
        ob_start();

        match ($format) {
            'png' => imagepng($image),
            'webp' => imagewebp($image),
            'gif' => imagegif($image),
            default => imagejpeg($image, null, 90),
        };

        return (string) ob_get_clean();
    }
}
