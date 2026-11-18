<?php
/**
 * Shop Migrator - Upgrade to PrestaShop 9 by Data Transfer
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Iamshoaibktk\Shopmigrator;

/**
 * Multistore: what becomes of each shop of an old multistore installation.
 *
 * The plan (chosen in the wizard) gives each old shop one of:
 * - "own": it gets a shop here (the same ID when free) with its name, URL, languages, currencies
 *   and main settings, and its own prices, texts, stock and orders.
 * - "into:<old id>": its customers, carts and orders go to the shop made for that other old shop.
 *   Its prices and texts are not copied: that shop keeps its own.
 * - "skip": nothing of it is copied.
 * The old default shop is always "own" and becomes this shop's default shop.
 */
class Shops
{
    /** Map of each old multistore to the shops made here: source key => old id_shop => new id_shop. */
    public const CONFIG = 'SHOPMIGRATOR_SHOP_MAP';

    /** Settings set per shop on the old shop that are copied to that shop here. */
    private const SETTINGS = [
        'PS_SHOP_NAME', 'PS_SHOP_EMAIL', 'PS_SHOP_ENABLE', 'PS_LANG_DEFAULT', 'PS_CURRENCY_DEFAULT', 'PS_COUNTRY_DEFAULT',
        'PS_SHOP_DETAILS', 'PS_SHOP_ADDR1', 'PS_SHOP_ADDR2', 'PS_SHOP_CODE', 'PS_SHOP_CITY', 'PS_SHOP_PHONE', 'PS_SHOP_FAX',
        'PS_SHOP_COUNTRY_ID', 'PS_SHOP_STATE_ID', 'PS_MAINTENANCE_IP', 'PS_MAINTENANCE_TEXT', 'PS_META_TITLE', 'PS_META_DESCRIPTION',
    ];

    /** @var string[] lines for the report */
    public array $notes = [];

    public function __construct(private Client $client)
    {
    }

    /** The old default shop: PS_SHOP_DEFAULT of the old shop, else its lowest shop ID. */
    public static function oldDefault(Client $client, array $shops): int
    {
        foreach ($client->allRows('configuration') as $r) {
            if ($r['name'] === 'PS_SHOP_DEFAULT' && empty($r['id_shop']) && empty($r['id_shop_group'])) {
                return (int) $r['value'];
            }
        }

        return (int) min(array_map(fn ($s) => (int) $s['id_shop'], $shops));
    }

    /**
     * A complete, consistent plan: every old shop gets a choice, the default shop is "own", and a
     * merge into a shop that is itself merged or left out goes to the default shop instead.
     *
     * @param array<int, string> $given old id_shop => "own" | "skip" | "into:<old id_shop>"
     *
     * @return array<int, string>
     */
    public static function plan(array $shops, int $oldDefault, array $given): array
    {
        $ids = array_map(fn ($s) => (int) $s['id_shop'], $shops);
        $plan = [];
        foreach ($ids as $id) {
            $v = (string) ($given[$id] ?? 'own');
            if ($id === $oldDefault) {
                $v = 'own';
            } elseif (str_starts_with($v, 'into:')) {
                $target = (int) substr($v, 5);
                $v = in_array($target, $ids, true) && $target !== $id ? 'into:' . $target : 'own';
            } elseif (!in_array($v, ['own', 'skip'], true)) {
                $v = 'own';
            }
            $plan[$id] = $v;
        }
        foreach ($plan as $id => $v) {
            if (str_starts_with($v, 'into:') && ($plan[(int) substr($v, 5)] ?? '') !== 'own') {
                $plan[$id] = 'into:' . $oldDefault;
            }
        }

        return $plan;
    }

    /**
     * Makes the shops of the plan here (or finds the ones a previous run made).
     *
     * @return array{shopMap: array<int, int>, own: int[], groupMap: array<int, int>}
     */
    public function apply(array $plan, int $oldDefault, bool $fresh, string $source, array $langMap, array $currencyMap, bool $withCategories): array
    {
        $oldShops = self::byId($this->client->allRows('shop'), 'id_shop');
        $oldGroups = self::byId($this->client->allRows('shop_group'), 'id_shop_group');
        $oldUrls = [];
        foreach ($this->client->allRows('shop_url') as $u) {
            $oldUrls[(int) $u['id_shop']][] = $u;
        }
        $oldLangs = $this->client->allRows('lang_shop');
        $oldCurrencies = $this->client->allRows('currency_shop');

        $newDefault = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $default = new \Shop($newDefault);
        $group = new \ShopGroup((int) $default->id_shop_group);

        // Customers, orders and stock are shared between shops (or not) as on the old shop.
        $oldGroup = $oldGroups[(int) ($oldShops[$oldDefault]['id_shop_group'] ?? 0)] ?? null;
        if ($oldGroup && $fresh) {
            $group->share_customer = (int) $oldGroup['share_customer'];
            $group->share_order = (int) $oldGroup['share_order'];
            $group->share_stock = (int) $oldGroup['share_stock'];
            $group->update();
        }
        $groupMap = array_fill_keys(array_keys($oldGroups), (int) $group->id);

        $saved = json_decode((string) \Configuration::getGlobalValue(self::CONFIG), true);
        $saved = is_array($saved) ? $saved : [];
        $previous = array_map('intval', $saved[$source] ?? []);

        $shopMap = [];
        $own = [];
        $created = [];
        foreach ($plan as $old => $choice) {
            if ($choice !== 'own' || !isset($oldShops[$old])) {
                continue;
            }
            if ($old === $oldDefault) {
                $new = $newDefault;
            } else {
                [$new, $isNew] = $this->ensureShop($old, $oldShops[$old], $previous[$old] ?? 0, $default, $group, $withCategories);
                if ($isNew) {
                    $created[] = $old;
                }
            }
            $shopMap[$old] = $new;
            $own[] = $old;
        }
        foreach ($plan as $old => $choice) {
            if (str_starts_with($choice, 'into:')) {
                $shopMap[$old] = $shopMap[(int) substr($choice, 5)];
            }
        }

        // URLs, languages and currencies: set on a fresh copy, or for shops made by this run.
        foreach ($own as $old) {
            $new = $shopMap[$old];
            if (!$fresh && !in_array($old, $created, true)) {
                continue;
            }
            if ($new !== $newDefault) {
                $this->url($new, $oldUrls[$old] ?? [], $default);
            }
            $this->replaceRows('lang_shop', 'id_lang', $new, $old, $oldLangs, $langMap, [(int) \Configuration::get('PS_LANG_DEFAULT')]);
            $this->replaceRows('currency_shop', 'id_currency', $new, $old, $oldCurrencies, $currencyMap, [(int) \Configuration::get('PS_CURRENCY_DEFAULT')]);
        }

        if (count($own) > 1) {
            \Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', 1);
        }
        $saved[$source] = $shopMap;
        \Configuration::updateGlobalValue(self::CONFIG, json_encode($saved));
        \Shop::resetStaticCache();
        \Shop::cacheShops(true);
        \Tools::generateHtaccess();

        $merged = count(array_filter($plan, fn ($c) => str_starts_with($c, 'into:')));
        $skipped = count(array_filter($plan, fn ($c) => $c === 'skip'));
        $this->notes[] = count($own) . ' shops kept as their own shop (' . count($created) . ' created here), '
            . $merged . ' merged into another shop, ' . $skipped . ' left out.';
        if ($merged && $oldGroup && !(int) $oldGroup['share_customer']) {
            $this->notes[] = 'Customer accounts were not shared between the old shops, so a customer of a merged shop who also had an account in the shop it was merged into now has two accounts there with the same email.';
        }

        return ['shopMap' => $shopMap, 'own' => $own, 'groupMap' => $groupMap];
    }

    /**
     * Copies each shop's own settings (name, email, default language, currency, country, address)
     * once languages, currencies and countries are mapped.
     */
    public function settings(array $shopMap, array $own, array $langMap, array $currencyMap, array $geoMap): void
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $rows = array_filter($this->client->allRows('configuration'), fn ($r) => !empty($r['id_shop']) && in_array($r['name'], self::SETTINGS, true));
        foreach ($rows as $r) {
            $old = (int) $r['id_shop'];
            if (!in_array($old, $own, true)) {
                continue;
            }
            $new = $shopMap[$old];
            $value = $r['value'];
            switch ($r['name']) {
                case 'PS_LANG_DEFAULT':
                    $value = $langMap[(int) $value] ?? null;
                    if ($value) {
                        $db->execute("INSERT IGNORE INTO {$p}lang_shop (id_lang, id_shop) VALUES (" . (int) $value . ', ' . (int) $new . ')');
                    }
                    break;
                case 'PS_CURRENCY_DEFAULT':
                    $value = $currencyMap[(int) $value] ?? null;
                    if ($value) {
                        $db->execute("INSERT IGNORE INTO {$p}currency_shop (id_currency, id_shop, conversion_rate)
                            SELECT id_currency, " . (int) $new . ', conversion_rate FROM ' . $p . 'currency WHERE id_currency = ' . (int) $value);
                    }
                    break;
                case 'PS_COUNTRY_DEFAULT':
                case 'PS_SHOP_COUNTRY_ID':
                    $value = $geoMap['country'][(int) $value] ?? null;
                    break;
                case 'PS_SHOP_STATE_ID':
                    $value = $geoMap['state'][(int) $value] ?? null;
                    break;
            }
            if ($value === null) {
                continue;
            }
            $shop = new \Shop($new);
            \Configuration::updateValue($r['name'], $value, false, (int) $shop->id_shop_group, (int) $new);
        }
    }

    /** @return array{0: int, 1: bool} the shop's ID here and whether it was created now */
    private function ensureShop(int $old, array $row, int $previous, \Shop $default, \ShopGroup $group, bool $withCategories): array
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $exists = fn (int $id) => $id > 0 && (bool) $db->getValue("SELECT 1 FROM {$p}shop WHERE id_shop = " . $id);
        // A previous run from this old shop made it: use that one.
        if ($exists($previous)) {
            return [$previous, false];
        }

        $shop = new \Shop();
        if (!$exists($old)) {
            $shop->force_id = true;
            $shop->id = $old;
        }
        $shop->name = mb_substr((string) $row['name'], 0, 64);
        $shop->id_shop_group = (int) $group->id;
        $shop->id_category = $withCategories && (int) $row['id_category'] ? (int) $row['id_category'] : (int) $default->id_category;
        $shop->theme_name = $default->theme_name;
        $shop->color = '';
        $shop->active = (int) $row['active'];
        $shop->deleted = 0;
        if (!$shop->add()) {
            throw new \RuntimeException('Shop "' . $row['name'] . '" could not be created.');
        }

        // Modules, hooks, page URLs, countries… as on the default shop; catalog data comes next.
        $tables = array_fill_keys(['module', 'hook_module', 'meta_lang', 'lang', 'currency', 'country', 'zone', 'group', 'tax_rules_group', 'carrier', 'cms', 'contact'], true);
        foreach (\Hook::getHookModuleExecList('actionShopDataDuplication') ?: [] as $m) {
            $tables['Module' . ucfirst($m['module'])] = true;
        }
        $shop->copyShopData((int) $default->id, $tables);
        $shop->associateSuperAdmins();

        // The theme's settings for this shop (layouts per page) follow the default shop's.
        $dir = _PS_CONFIG_DIR_ . 'themes/' . $default->theme_name . '/';
        if (is_file($dir . 'shop' . (int) $default->id . '.json') && !is_file($dir . 'shop' . (int) $shop->id . '.json')) {
            @copy($dir . 'shop' . (int) $default->id . '.json', $dir . 'shop' . (int) $shop->id . '.json');
        }

        return [(int) $shop->id, true];
    }

    /** The shop's address: the old domain, this installation's folder, the old virtual folder. */
    private function url(int $new, array $urls, \Shop $default): void
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        usort($urls, fn ($a, $b) => (int) $b['main'] <=> (int) $a['main']);
        $u = $urls[0] ?? null;
        $physical = (string) $db->getValue("SELECT physical_uri FROM {$p}shop_url WHERE id_shop = " . (int) $default->id . ' AND main = 1') ?: '/';
        $domain = $u ? $u['domain'] : (string) $db->getValue("SELECT domain FROM {$p}shop_url WHERE id_shop = " . (int) $default->id . ' AND main = 1');
        $domainSsl = $u ? ($u['domain_ssl'] ?: $u['domain']) : $domain;
        $virtual = $u ? trim((string) $u['virtual_uri'], '/') : '';
        // Two shops can't share an address: give this one its own folder when it would.
        $taken = fn (string $v) => (bool) $db->getValue("SELECT 1 FROM {$p}shop_url WHERE id_shop <> " . $new
            . " AND domain = '" . pSQL($domain) . "' AND physical_uri = '" . pSQL($physical) . "' AND virtual_uri = '" . pSQL($v === '' ? '' : $v . '/') . "'");
        if ($taken($virtual)) {
            $base = $virtual === '' ? 'shop' . $new : $virtual . '-' . $new;
            $virtual = $base;
            $this->notes[] = 'Shop #' . $new . ' would have had the same address as another shop; it is at ' . $domain . $physical . $virtual . '/ for now.';
        }
        $db->execute("DELETE FROM {$p}shop_url WHERE id_shop = " . $new);
        $db->insert('shop_url', [
            'id_shop' => $new,
            'domain' => pSQL($domain),
            'domain_ssl' => pSQL($domainSsl),
            'physical_uri' => pSQL($physical),
            'virtual_uri' => pSQL($virtual === '' ? '' : $virtual . '/'),
            'main' => 1,
            'active' => 1,
        ]);
    }

    /** Replaces a shop's languages or currencies with the old shop's (only those that exist here). */
    private function replaceRows(string $table, string $col, int $new, int $old, array $oldRows, array $map, array $always): void
    {
        $ids = [];
        $rates = [];
        foreach ($oldRows as $r) {
            if ((int) $r['id_shop'] === $old && isset($map[(int) $r[$col]])) {
                $ids[] = $map[(int) $r[$col]];
                $rates[$map[(int) $r[$col]]] = $r['conversion_rate'] ?? null;
            }
        }
        if (!$ids) {
            return; // nothing usable: keep what the shop has
        }
        $ids = array_values(array_unique(array_merge($ids, array_filter($always))));
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $db->execute("DELETE FROM {$p}{$table} WHERE id_shop = " . $new);
        foreach ($ids as $id) {
            if ($table === 'currency_shop') {
                $rate = $rates[$id] ?? $db->getValue("SELECT conversion_rate FROM {$p}currency WHERE id_currency = " . (int) $id);
                $db->execute("INSERT IGNORE INTO {$p}currency_shop (id_currency, id_shop, conversion_rate) VALUES (" . (int) $id . ', ' . $new . ", '" . (float) $rate . "')");
            } else {
                $db->execute("INSERT IGNORE INTO {$p}{$table} ({$col}, id_shop) VALUES (" . (int) $id . ', ' . $new . ')');
            }
        }
    }

    private static function byId(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r[$key]] = $r;
        }

        return $out;
    }
}
