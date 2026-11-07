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
    /** @var int[] IDs whose file is cut off or unreadable (not copied) */
    public array $damaged = [];

    /** @var int[] IDs whose thumbnails could not be built (copied without them) */
    public array $noThumbs = [];

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
     * Downloads several images at once, then saves them and builds their thumbnails. A file that
     * arrives incomplete is downloaded once more on its own before it counts as damaged.
     *
     * @param int[] $ids
     *
     * @return int[] IDs the old shop has no file for
     */
    public function copyMany(string $kind, array $ids): array
    {
        $missing = [];
        foreach ($this->client->images($kind, $ids) as $id => $bytes) {
            $result = $this->save($kind, (int) $id, $bytes);
            if ($result === 'damaged') {
                $result = $this->save($kind, (int) $id, $this->client->image($kind, (int) $id));
            }
            if ($result === 'missing') {
                $missing[] = (int) $id;
            } elseif ($result === 'damaged') {
                $this->damaged[] = (int) $id;
            }
        }

        return $missing;
    }

    /** @return string ok, missing (no file on the old shop) or damaged (cut off, unreadable) */
    private function save(string $kind, int $id, ?string $bytes): string
    {
        if ($bytes === null || $bytes === '') {
            return 'missing';
        }
        if (@getimagesizefromstring($bytes) === false || !self::complete($bytes)) {
            // A cut-off original would break this shop's pages: PrestaShop 9 stops with a fatal
            // error when it builds thumbnails from a file it cannot decode.
            return 'damaged';
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
            return 'ok'; // carrier logos have none; or rebuilt later in Design > Image Settings
        }
        if (!\ImageManager::checkImageMemoryLimit($base . '.jpg')) {
            $this->noThumbs[] = $id; // more pixels than PHP's memory_limit allows to decode
            return 'ok';
        }
        $formats = array_filter(array_map('trim', explode(',', (string) (\Configuration::get('PS_IMAGE_FORMAT') ?: 'jpg'))));
        $imageTypes = \ImageType::getImagesTypes($types);
        // PrestaShop decodes the source again for every size. Decode a big original (often
        // 2000 px and more) once, shrink it to the largest size needed, and resize from that.
        $source = self::reducedSource($base . '.jpg', $imageTypes) ?? $base . '.jpg';
        try {
            foreach ($imageTypes as $type) {
                foreach ($formats ?: ['jpg'] as $ext) {
                    \ImageManager::resize($source, $base . '-' . stripslashes($type['name']) . '.' . $ext, (int) $type['width'], (int) $type['height'], $ext);
                }
            }
        } catch (\Throwable $e) {
            $this->noThumbs[] = $id; // an original GD can't read despite a valid header
        } finally {
            if ($source !== $base . '.jpg') {
                @unlink($source);
            }
        }

        return 'ok';
    }

    /**
     * Whether the file ends the way its format requires (JPEG FFD9, PNG IEND, GIF trailer, WebP
     * RIFF size). A transfer cut off midway passes getimagesize(), which reads only the header.
     */
    public static function complete(string $bytes): bool
    {
        $tail = substr($bytes, -1024);
        if (str_starts_with($bytes, "\xFF\xD8")) {
            return strpos($tail, "\xFF\xD9") !== false;
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return strpos(substr($bytes, -64), 'IEND') !== false;
        }
        if (str_starts_with($bytes, 'GIF8')) {
            return str_ends_with(rtrim($bytes, "\0\n\r "), ';');
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return strlen($bytes) >= unpack('V', substr($bytes, 4, 4))[1] + 8;
        }

        return true; // other formats: no reliable end marker
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
        $w = max(1, (int) round($info[0] * $ratio));
        $h = max(1, (int) round($info[1] * $ratio));
        $small = imagecreatetruecolor($w, $h);
        // PNG keeps its transparency in a PNG copy (PrestaShop then makes PNG thumbnails when
        // "PNG for PNG images" is on, as it would from the original). Other formats go to a JPEG
        // copy on white, which is what PrestaShop itself puts behind them in JPEG thumbnails.
        $png = $info[2] === IMAGETYPE_PNG;
        if ($png) {
            imagealphablending($small, false);
            imagesavealpha($small, true);
            imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        } else {
            imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
        }
        imagecopyresampled($small, $img, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
        imagedestroy($img);
        // Quality 95 / fast PNG compression: the copy is decoded again right away and deleted.
        $tmp = $file . ($png ? '.sm.png' : '.sm.jpg');
        $ok = $png ? imagepng($small, $tmp, 1) : imagejpeg($small, $tmp, 95);
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
