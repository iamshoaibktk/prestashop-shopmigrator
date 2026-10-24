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

    /** @return bool false when the old shop has no file for it */
    public function copy(string $kind, int $id): bool
    {
        $bytes = $this->client->image($kind, $id);
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
        foreach (\ImageType::getImagesTypes($types) as $type) {
            foreach ($formats ?: ['jpg'] as $ext) {
                \ImageManager::resize($base . '.jpg', $base . '-' . stripslashes($type['name']) . '.' . $ext, (int) $type['width'], (int) $type['height'], $ext);
            }
        }

        return true;
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
