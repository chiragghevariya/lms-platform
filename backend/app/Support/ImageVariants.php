<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

/**
 * Responsive WebP variants for uploaded images (GD, no external service): the SPA serves them
 * via srcset so phones download ~40 KB instead of a multi-MB original.
 */
class ImageVariants
{
    /** @var list<int> */
    public const WIDTHS = [480, 960, 1600];

    /**
     * @return array<int, string> width => stored path
     */
    public function make(string $sourcePath, Filesystem $disk, string $targetBase, int $quality = 80): array
    {
        $info = @getimagesize($sourcePath);
        if (! $info) {
            throw new RuntimeException('Not an image');
        }
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => imagecreatefromwebp($sourcePath),
            default => throw new RuntimeException('Unsupported image type'),
        };
        if (! $src) {
            throw new RuntimeException('Unreadable image');
        }
        [$w, $h] = [imagesx($src), imagesy($src)];

        $out = [];
        try {
            foreach (self::WIDTHS as $target) {
                $tw = min($target, $w); // never upscale: the last variant is the original width
                if (isset($out[$tw])) {
                    break;
                }
                $th = (int) round($h * $tw / $w);
                $dst = imagecreatetruecolor($tw, $th);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
                ob_start();
                imagewebp($dst, null, $quality);
                $bytes = (string) ob_get_clean();
                imagedestroy($dst);

                $path = "{$targetBase}-{$tw}.webp";
                $disk->put($path, $bytes, ['visibility' => 'public', 'CacheControl' => 'public, max-age=31536000, immutable']);
                $out[$tw] = $path;
            }
        } finally {
            imagedestroy($src);
        }

        return $out;
    }
}
