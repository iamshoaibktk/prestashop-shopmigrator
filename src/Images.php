<?php
/**
 * Shop Migrator - Upgrade to PrestaShop 9 by Data Transfer
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Iamshoaibktk\Shopmigrator;

/**
 * Copies original images from the old shop and builds this shop's thumbnails
 * (every image type and every enabled format: jpg, webp, avif…).
 */
class Images
{
    public function __construct(private Client $client, private bool $thumbs = true)
    {
    }

    /** Removes this shop's existing image files of one kind (fresh copy mode). */
    public static function wipe(string $kind): void
    {
        $dir = self::dir($kind);
        if (is_dir($dir)) {
            \Image::deleteAllImages($dir);
        }
    }

    /**
     * Downloads several images at once, then saves them and builds their thumbnails.
     *
     * @param int[] $ids
     *
     * @return int[] IDs the old shop has no file for
     */
    public function copyMany(string $kind, array $ids): array
    {
        $missing = [];
        foreach ($this->client->images($kind, $ids) as $id => $bytes) {
            if (!$this->save($kind, (int) $id, $bytes)) {
                $missing[] = (int) $id;
            }
        }

        return $missing;
    }

    /** @return bool false when the old shop has no file for it */
    public function copy(string $kind, int $id): bool
    {
        return $this->save($kind, $id, $this->client->image($kind, $id));
    }

    private function save(string $kind, int $id, ?string $bytes): bool
    {
        if ($bytes === null || @getimagesizefromstring($bytes) === false) {
            return false;
        }
        if ($kind === 'p') {
            $image = new \Image($id);
            $base = $image->getPathForCreation(); // creates img/p/1/2/3/
            $types = 'products';
        } else {
            $base = self::dir($kind) . $id;
            $types = ['c' => 'categories', 'm' => 'manufacturers', 'su' => 'suppliers', 's' => null][$kind];
        }
        if (file_put_contents($base . '.jpg', $bytes) === false) {
            throw new \RuntimeException('Cannot write ' . $base . '.jpg — check folder permissions');
        }

        if ($types === null || !$this->thumbs) {
            return true; // carrier logos have none; or rebuilt later in Design > Image Settings
        }
        $formats = array_filter(array_map('trim', explode(',', (string) (\Configuration::get('PS_IMAGE_FORMAT') ?: 'jpg'))));
        $imageTypes = \ImageType::getImagesTypes($types);
        // PrestaShop decodes the source again for every size. Decode a big original (often
        // 2000 px and more) once, shrink it to the largest size needed, and resize from that.
        $source = self::reducedSource($base . '.jpg', $imageTypes) ?? $base . '.jpg';
        foreach ($imageTypes as $type) {
            foreach ($formats ?: ['jpg'] as $ext) {
                \ImageManager::resize($source, $base . '-' . stripslashes($type['name']) . '.' . $ext, (int) $type['width'], (int) $type['height'], $ext);
            }
        }
        if ($source !== $base . '.jpg') {
            @unlink($source);
        }

        return true;
    }

    /** A temporary copy no larger than the biggest thumbnail, or null when the original is small enough. */
    private static function reducedSource(string $file, array $imageTypes): ?string
    {
        $max = 0;
        foreach ($imageTypes as $t) {
            $max = max($max, (int) $t['width'], (int) $t['height']);
        }
        $info = @getimagesize($file);
        if (!$max || !$info || max($info[0], $info[1]) <= $max * 1.5 || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring((string) file_get_contents($file));
        if (!$img) {
            return null;
        }
        $ratio = $max / max($info[0], $info[1]);
        $small = imagescale($img, max(1, (int) round($info[0] * $ratio)), max(1, (int) round($info[1] * $ratio)), IMG_BICUBIC);
        imagedestroy($img);
        if (!$small) {
            return null;
        }
        // JPEG like the original (a PNG source would make PrestaShop write PNG thumbnails when
        // "PNG for PNG images" is on); quality 95 keeps the second compression invisible.
        $tmp = $file . '.sm.jpg';
        $ok = imagejpeg($small, $tmp, 95);
        imagedestroy($small);

        return $ok ? $tmp : null;
    }

    private static function dir(string $kind): string
    {
        return match ($kind) {
            'p' => _PS_PRODUCT_IMG_DIR_,
            'c' => _PS_CAT_IMG_DIR_,
            'm' => _PS_MANU_IMG_DIR_,
            'su' => _PS_SUPP_IMG_DIR_,
            's' => _PS_SHIP_IMG_DIR_,
        };
    }
}
