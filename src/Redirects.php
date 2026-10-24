<?php
/**
 * Shop Migrator - Upgrade to PrestaShop 9 by Data Transfer
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Iamshoaibktk\Shopmigrator;

/**
 * Finds the new address of a link from the old shop that leads nowhere here. IDs are kept by
 * the migration, so the ID in the old link is enough: PrestaShop 1.6 product links had the
 * category in front (women/5-faded-short.html), brands were 3_name and suppliers 2__name.
 */
class Redirects
{
    /** @return string|null the URL to send the visitor to with a 301 */
    public static function find(string $uri, \Context $context): ?string
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $base = (string) $context->shop->getBaseURI();
        if ($base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = trim(rawurldecode($path), '/');
        $lang = (int) $context->language->id;
        // Language prefix (en/…) when the shop has several languages.
        if (preg_match('#^([a-z]{2})/(.+)$#', $path, $m) && ($id = (int) \Language::getIdByIso($m[1]))) {
            $lang = $id;
            $path = $m[2];
        }
        if ($path === '') {
            return null;
        }
        $link = $context->link;

        $url = match (true) {
            (bool) preg_match('#^content/category/(\d+)-#', $path, $m) => self::active('cms_category', (int) $m[1]) ? $link->getCMSCategoryLink((int) $m[1], null, $lang) : null,
            (bool) preg_match('#^content/(\d+)-#', $path, $m) => self::active('cms', (int) $m[1]) ? $link->getCMSLink((int) $m[1], null, null, $lang) : null,
            (bool) preg_match('#(?:^|/)(\d+)(?:-\d+)?-[^/]*\.html$#', $path, $m) => self::active('product', (int) $m[1]) ? $link->getProductLink((int) $m[1], null, null, null, $lang) : null,
            (bool) preg_match('#^(\d+)__[^/]*$#', $path, $m) => self::active('supplier', (int) $m[1]) ? $link->getSupplierLink((int) $m[1], null, $lang) : null,
            (bool) preg_match('#^(\d+)_[^/]*$#', $path, $m) => self::active('manufacturer', (int) $m[1]) ? $link->getManufacturerLink((int) $m[1], null, $lang) : null,
            (bool) preg_match('#^(?:[^/]+/)*(\d+)-[^/.]*$#', $path, $m) => self::active('category', (int) $m[1]) ? $link->getCategoryLink((int) $m[1], null, $lang) : null,
            default => null,
        };
        if ($url === null) {
            return null;
        }
        // Never send the visitor back to the address that just failed.
        $target = trim(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '/');

        return str_ends_with($target, $path) ? null : $url;
    }

    private static function active(string $table, int $id): bool
    {
        $col = 'id_' . $table;
        $sql = 'SELECT 1 FROM `' . _DB_PREFIX_ . bqSQL($table) . '` WHERE `' . $col . '` = ' . $id;
        if (in_array($table, ['product', 'category', 'cms', 'manufacturer', 'supplier', 'cms_category'], true)) {
            $sql .= ' AND active = 1';
        }

        return $id > 0 && (bool) \Db::getInstance()->getValue($sql);
    }
}
