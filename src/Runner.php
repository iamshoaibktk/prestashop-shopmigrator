<?php
/**
 * Shop Migrator - Upgrade to PrestaShop 9 by Data Transfer
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Iamshoaibktk\Shopmigrator;

/**
 * The migration as a list of small steps. Each browser request runs steps for a few seconds
 * and saves where it stopped, so a run survives pauses, closed tabs and PHP time limits.
 */
class Runner
{
    public const TABLE = 'shopmigrator_state';

    private array $s;

    public function __construct()
    {
        $data = \Db::getInstance()->getValue('SELECT data FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id = 1');
        $this->s = json_decode((string) $data, true) ?: ['status' => 'idle'];
    }

    public function state(): array
    {
        return $this->s;
    }

    public function reset(): void
    {
        $this->s = ['status' => 'idle'];
        $this->save();
    }

    /** Builds the step list for a new run. */
    public function start(string $url, string $key, array $types, int $batch, bool $fresh, bool $thumbs = true): void
    {
        $client = new Client($url, $key);
        $info = $client->info();
        $types = Plan::resolve($types);

        // Languages are matched by ISO code; shops all go to this shop for now.
        $langMap = [];
        $warnings = [];
        foreach ($info['languages'] as $l) {
            $id = (int) \Language::getIdByIso($l['iso_code']);
            if ($id) {
                $langMap[$l['id_lang']] = $id;
            } else {
                $warnings[] = ['type' => 'language', 'id' => $l['id_lang'], 'msg' => 'Language "' . $l['name'] . '" (' . $l['iso_code'] . ') is not installed here; its texts were skipped. Install it in International > Translations and run again.'];
            }
        }
        $shopMap = [];
        $target = (int) \Configuration::get('PS_SHOP_DEFAULT');
        foreach ($info['shops'] as $sh) {
            $shopMap[$sh['id_shop']] = $target;
        }
        if (count($info['shops']) > 1) {
            $warnings[] = ['type' => 'shop', 'id' => 0, 'msg' => 'The old shop has ' . count($info['shops']) . ' shops; all were copied into this shop.'];
        }

        // Currencies are matched by ISO code (USD → this shop's USD).
        $currencyMap = [];
        foreach ($info['currencies'] ?? [] as $c) {
            $id = (int) \Currency::getIdByIsoCode($c['iso_code']);
            if ($id) {
                $currencyMap[$c['id_currency']] = $id;
            } else {
                $warnings[] = ['type' => 'currency', 'id' => $c['id_currency'], 'msg' => 'Currency ' . $c['iso_code'] . ' is not installed here; orders in it keep its old ID. Add it in International > Localization > Currencies.'];
            }
        }
        if (in_array('customers', $types, true) && !empty($info['password_salt'])) {
            // Lets old MD5 passwords be recognised (and upgraded) at the customer's first login.
            \Configuration::updateGlobalValue('SHOPMIGRATOR_PASSWORD_SALT', $info['password_salt']);
        }

        $copier = new Copier($langMap, $shopMap, $currencyMap);
        $tables = array_values(array_filter(Plan::tables($types), fn ($t) => in_array($t, $info['tables'], true) && $copier->hasTable($t)));
        $counts = $client->count($tables);

        // "New data only": start each table after the highest ID already here, measured now,
        // before anything is copied (product_lang starts after the last product, too).
        $from = $fresh ? [] : self::startCursors($tables, $types);

        $steps = [];
        if ($fresh) {
            $steps[] = ['k' => 'clean'];
        }
        foreach ($types as $type) {
            foreach (Plan::TYPES[$type] as $t) {
                if (in_array($t, $tables, true)) {
                    $steps[] = ['k' => 'table', 't' => $t, 'type' => $type, 'total' => (int) ($counts[$t] ?? 0), 'done' => 0]
                        + ($from[$t] ?? []);
                }
            }
        }
        foreach ($types as $type) {
            if (isset(Plan::IMAGES[$type])) {
                [$kind, $table, $col] = Plan::IMAGES[$type];
                $after = $fresh ? 0 : (int) \Db::getInstance()->getValue('SELECT MAX(`' . bqSQL($col) . '`) FROM `' . _DB_PREFIX_ . bqSQL($table) . '`');
                $steps[] = ['k' => 'images', 'kind' => $kind, 't' => $table, 'col' => $col, 'type' => 'images', 'total' => 0, 'done' => 0, 'missing' => 0, 'from' => $after];
            }
        }
        $posts = array_merge(
            ['tree', 'product_type'],
            in_array('carriers', $types, true) ? ['carrier_default'] : [],
            in_array('orders', $types, true) ? ['order_messages'] : [],
            in_array('price_rules', $types, true) ? ['price_rules'] : [],
            ['redirects', 'search', 'cache']
        );
        foreach ($posts as $p) {
            $steps[] = ['k' => 'post', 'p' => $p];
        }

        $this->s = [
            'status' => 'running',
            'url' => $url,
            'key' => $key,
            'source' => ['ps_version' => $info['ps_version'], 'name' => $info['shop_name']],
            'types' => $types,
            // The speed setting is where the batch size starts; it then adapts (see table()).
            'batch' => max(10, min(1000, $batch)),
            'batchMax' => max(100, min(5000, $batch * 20)),
            'thumbs' => $thumbs,
            // New data only: just the new products get indexed for search.
            'searchFrom' => $fresh ? null : (int) \Db::getInstance()->getValue('SELECT MAX(id_product) FROM `' . _DB_PREFIX_ . 'product`'),
            'fresh' => $fresh,
            'langMap' => $langMap,
            'shopMap' => $shopMap,
            'currencyMap' => $currencyMap,
            'steps' => $steps,
            'i' => 0,
            'cursor' => null,
            'warnings' => $warnings,
            'started' => time(),
            'finished' => null,
        ];
        $this->save();
    }

    public function pause(): void
    {
        if (($this->s['status'] ?? '') === 'running') {
            $this->s['status'] = 'paused';
            $this->save();
        }
    }

    public function resume(): void
    {
        if (($this->s['status'] ?? '') === 'paused') {
            $this->s['status'] = 'running';
            $this->save();
        }
    }

    /** Runs steps for about $seconds; returns the progress. */
    public function tick(float $seconds = 6.0): array
    {
        if (($this->s['status'] ?? '') !== 'running') {
            return $this->progress();
        }
        $until = microtime(true) + $seconds;
        $client = new Client($this->s['url'], $this->s['key']);
        $copier = new Copier($this->s['langMap'], $this->s['shopMap'], $this->s['currencyMap'] ?? []);
        $images = new Images($client, $this->s['thumbs'] ?? true);

        while (microtime(true) < $until && $this->s['status'] === 'running') {
            $i = $this->s['i'];
            if (!isset($this->s['steps'][$i])) {
                $this->s['status'] = 'done';
                $this->s['finished'] = time();
                break;
            }
            $step = &$this->s['steps'][$i];
            try {
                $next = match ($step['k']) {
                    'clean' => $this->clean($copier),
                    'table' => $this->table($step, $client, $copier),
                    'images' => $this->images($step, $images),
                    'post' => $this->post($step['p'], $until),
                };
            } catch (\Throwable $e) {
                $this->s['status'] = 'error';
                $this->s['error'] = $e->getMessage();
                $next = false;
            }
            unset($step);
            if ($next) {
                ++$this->s['i'];
                $this->s['cursor'] = null;
            }
            $this->save();
        }
        foreach ($copier->warnings as $w) {
            $this->s['warnings'][] = $w;
        }
        $this->save();

        return $this->progress();
    }

    /** @return bool true when the step is finished */
    private function clean(Copier $copier): bool
    {
        foreach ($this->s['steps'] as $st) {
            if ($st['k'] === 'table' && !in_array($st['t'], Plan::INSERT_ONLY, true)) {
                $copier->truncate($st['t']);
            }
            if ($st['k'] === 'images') {
                Images::wipe($st['kind']);
            }
        }

        return true;
    }

    private function table(array &$step, Client $client, Copier $copier): bool
    {
        // Batch size adapts to the two servers: aim for about 1.5 s per batch (fetch + write).
        $size = (int) ($step['batch'] ?? $this->s['batch']);
        $t0 = microtime(true);
        $r = $client->rows($step['t'], $this->s['cursor'] ?? $step['from'] ?? null, $size, $step['filter'] ?? null);
        $rows = in_array($step['t'], Plan::INSERT_ONLY, true) ? $this->keepExisting($step['t'], $r['rows']) : $r['rows'];
        $w = $copier->write($step['t'], $rows);
        $took = microtime(true) - $t0;
        $step['batch'] = $took < 0.5 ? min($this->s['batchMax'] ?? 2000, $size * 2) : ($took > 3 ? max(10, intdiv($size, 2)) : $size);
        $step['done'] += count($rows);
        $step['skipped'] = ($step['skipped'] ?? 0) + $w['skipped'] + count($r['rows']) - count($rows);
        $this->s['cursor'] = $r['next'];
        if ($r['next'] === null && (!empty($step['from']) || !empty($step['filter']) || in_array($step['t'], Plan::INSERT_ONLY, true))) {
            $step['total'] = $step['done']; // only part of the old table was due
        }

        return $r['next'] === null;
    }

    /**
     * Employees: never overwrite the people who already use this back office. An old employee
     * is added only when both its ID and its email are free here; its shop links follow it.
     */
    private function keepExisting(string $table, array $rows): array
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        if ($table === 'employee_shop') {
            $added = $this->s['employeesAdded'] ?? [];

            return array_values(array_filter($rows, fn ($r) => in_array((int) $r['id_employee'], $added, true)));
        }
        $tab = (int) \Tab::getIdFromClassName('AdminDashboard');
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id_employee'];
            $byId = $db->getValue("SELECT email FROM {$p}employee WHERE id_employee = " . $id);
            $byEmail = (int) $db->getValue("SELECT id_employee FROM {$p}employee WHERE email = '" . pSQL($r['email']) . "'");
            if ($byId !== false || $byEmail) {
                if (!$byEmail || $byEmail !== $id) {
                    $this->s['warnings'][] = ['type' => 'employee', 'id' => $id, 'msg' => 'Employee ' . $r['email'] . ' was not added: ' . ($byEmail ? 'this email is already used by employee #' . $byEmail : 'ID #' . $id . ' is already used by ' . $byId) . '.'];
                }
                continue;
            }
            if (!$db->getValue("SELECT 1 FROM {$p}profile WHERE id_profile = " . (int) $r['id_profile'])) {
                $this->s['warnings'][] = ['type' => 'employee', 'id' => $id, 'msg' => 'Employee ' . $r['email'] . ' was not added: their permission profile #' . (int) $r['id_profile'] . ' does not exist here.'];
                continue;
            }
            $r['default_tab'] = $tab; // tab IDs differ between versions
            if (preg_match('/^[a-f0-9]{32}$/', (string) $r['passwd'])) {
                $this->s['warnings'][] = ['type' => 'employee', 'id' => $id, 'msg' => 'Employee ' . $r['email'] . ' was added; their old-style password cannot be read by PrestaShop 9, so they should use "Forgot your password?" on the login page.'];
            }
            $this->s['employeesAdded'][] = $id;
            $out[] = $r;
        }

        return $out;
    }

    /**
     * @param string[] $tables
     * @param string[] $types
     *
     * @return array<string, array> table => cursor to start after
     */
    private static function startCursors(array $tables, array $types): array
    {
        $typeOf = [];
        foreach ($types as $type) {
            foreach (Plan::TYPES[$type] as $t) {
                $typeOf[$t] = $type;
            }
        }
        $pk = [];
        foreach ($tables as $t) {
            $keys = \Db::getInstance()->executeS('SHOW KEYS FROM `' . _DB_PREFIX_ . bqSQL($t) . "` WHERE Key_name = 'PRIMARY'") ?: [];
            usort($keys, fn ($a, $b) => $a['Seq_in_index'] <=> $b['Seq_in_index']);
            $pk[$t] = array_column($keys, 'Column_name');
        }
        $max = [];
        foreach ($tables as $t) {
            if (count($pk[$t]) === 1) {
                $max[$t] = (int) \Db::getInstance()->getValue('SELECT MAX(`' . bqSQL($pk[$t][0]) . '`) FROM `' . _DB_PREFIX_ . bqSQL($t) . '`');
            }
        }
        $out = [];
        foreach ($tables as $t) {
            if (isset($max[$t])) {
                $out[$t] = $max[$t] ? ['from' => [$max[$t]]] : [];
                continue;
            }
            // product_lang, cms_shop…: follow their main table of the same type (first key column
            // = its ID); category_product is a product link, so it is copied in full.
            foreach ($max as $main => $m) {
                if ($m && $pk[$t] && ($typeOf[$t] ?? '') === ($typeOf[$main] ?? null) && str_starts_with($t, $main . '_') && $pk[$t][0] === $pk[$main][0]) {
                    $out[$t] = ['from' => array_merge([$m], array_fill(0, count($pk[$t]) - 1, '999999999999'))];
                    continue 2;
                }
            }
            // Link tables (category_product, customer_group, order_detail_tax…): only rows that
            // point at a new record of the same type, e.g. id_product above the last product here.
            $cols = array_column(\Db::getInstance()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . bqSQL($t) . '`') ?: [], 'Field');
            foreach ($max as $main => $m) {
                if ($m && ($typeOf[$t] ?? '') === ($typeOf[$main] ?? null) && in_array($pk[$main][0], $cols, true)) {
                    $out[$t] = ['filter' => [$pk[$main][0], $m]];
                    continue 2;
                }
            }
        }

        return $out; // anything else is re-copied in full (updates, never duplicates)
    }

    private function images(array &$step, Images $images): bool
    {
        if (!$step['total']) {
            $step['total'] = (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . bqSQL($step['t']) . '`
                WHERE `' . bqSQL($step['col']) . '` > ' . (int) ($step['from'] ?? 0));
        }
        $after = (int) ($this->s['cursor'] ?? $step['from'] ?? 0);
        $ids = \Db::getInstance()->executeS('SELECT `' . bqSQL($step['col']) . '` AS id FROM `' . _DB_PREFIX_ . bqSQL($step['t']) . '`
            WHERE `' . bqSQL($step['col']) . '` > ' . $after . ' ORDER BY `' . bqSQL($step['col']) . '` LIMIT 5') ?: [];
        foreach ($ids as $row) {
            $id = (int) $row['id'];
            if (!$images->copy($step['kind'], $id) && $step['kind'] === 'p') {
                ++$step['missing'];
                $this->s['warnings'][] = ['type' => 'image', 'id' => $id, 'msg' => 'Product image #' . $id . ' has no file on the old shop.'];
            }
            ++$step['done'];
            $this->s['cursor'] = $id;
        }

        return count($ids) < 5;
    }

    /** @return bool true when the step is finished; long ones work in chunks until $until */
    private function post(string $what, float $until): bool
    {
        switch ($what) {
            case 'tree':
                \Category::regenerateEntireNtree();
                break;
            case 'product_type':
                // PrestaShop 1.7.8+ needs product_type; older shops don't have it.
                $p = _DB_PREFIX_;
                \Db::getInstance()->execute("UPDATE {$p}product p SET p.product_type = CASE
                    WHEN p.cache_is_pack = 1 THEN 'pack'
                    WHEN p.is_virtual = 1 THEN 'virtual'
                    WHEN EXISTS (SELECT 1 FROM {$p}product_attribute pa WHERE pa.id_product = p.id_product) THEN 'combinations'
                    ELSE 'standard' END
                    WHERE p.product_type = '' OR p.product_type IS NULL OR p.product_type = 'standard'");
                break;
            case 'carrier_default':
                // The default carrier must exist after the old shop's carriers replaced this shop's.
                $current = (int) \Configuration::get('PS_CARRIER_DEFAULT');
                $exists = (int) \Db::getInstance()->getValue('SELECT id_carrier FROM `' . _DB_PREFIX_ . 'carrier` WHERE id_carrier = ' . $current . ' AND deleted = 0 AND active = 1');
                if (!$exists) {
                    $first = (int) \Db::getInstance()->getValue('SELECT id_carrier FROM `' . _DB_PREFIX_ . 'carrier` WHERE deleted = 0 AND active = 1 ORDER BY position, id_carrier');
                    if ($first) {
                        \Configuration::updateValue('PS_CARRIER_DEFAULT', $first);
                    }
                }
                break;
            case 'price_rules':
                // Rebuild the prices the catalog rules create, rule by rule, 500 products at a time.
                return $this->chunked($until, function (array $c) {
                    $p = _DB_PREFIX_;
                    $rule = (int) \Db::getInstance()->getValue("SELECT id_specific_price_rule FROM {$p}specific_price_rule
                        WHERE id_specific_price_rule >= " . (int) ($c['r'] ?? 0) . ' ORDER BY id_specific_price_rule');
                    if (!$rule) {
                        return null;
                    }
                    $ids = array_column(\Db::getInstance()->executeS("SELECT id_product FROM {$p}product WHERE id_product > " . (int) ($c['p'] ?? $this->s['searchFrom'] ?? 0)
                        . ' ORDER BY id_product LIMIT 500') ?: [], 'id_product');
                    if (!$ids) {
                        return ['r' => $rule + 1];
                    }
                    (new \SpecificPriceRule($rule))->apply($ids);

                    return ['r' => $rule, 'p' => (int) end($ids)];
                });
            case 'redirects':
                // Old links (other URL formats of PrestaShop 1.6/1.7) now lead to the same page here.
                \Configuration::updateGlobalValue('SHOPMIGRATOR_REDIRECTS', 1);
                break;
            case 'order_messages':
                return $this->chunked($until, fn (array $c) => $this->convertOrderMessages((int) ($c['m'] ?? 0)));
            case 'search':
                // Rebuild the search index 50 products at a time (one call for 1M products would time out).
                return $this->chunked($until, function (array $c) {
                    $p = _DB_PREFIX_;
                    $db = \Db::getInstance();
                    if (!isset($c['p']) && isset($this->s['searchFrom'])) {
                        $c['p'] = (int) $this->s['searchFrom'];
                    }
                    if (!isset($c['p'])) {
                        $db->execute("TRUNCATE {$p}search_index");
                        $db->execute("TRUNCATE {$p}search_word");
                        $db->execute("UPDATE {$p}product_shop SET indexed = 0");
                        $db->execute("UPDATE {$p}product SET indexed = 0");
                        $c['p'] = 0;
                    }
                    $ids = array_column($db->executeS("SELECT id_product FROM {$p}product WHERE id_product > " . (int) $c['p']
                        . ' ORDER BY id_product LIMIT 50') ?: [], 'id_product');
                    foreach ($ids as $id) {
                        \Search::indexation(false, (int) $id);
                    }

                    return $ids ? ['p' => (int) end($ids)] : null;
                });
            case 'cache':
                \Tools::clearAllCache();
                break;
        }

        return true;
    }

    /**
     * PrestaShop 1.6 kept order notes in `message`; 1.7+ only shows customer-service threads on
     * the order page. Copy each old note into the order's thread (created if needed), once.
     *
     * @return array|null where to continue, null when all notes are done
     */
    private function convertOrderMessages(int $after): ?array
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $rows = $db->executeS("SELECT m.*, c.email, c.id_lang AS customer_lang FROM {$p}message m
            LEFT JOIN {$p}customer c ON (c.id_customer = m.id_customer)
            WHERE m.id_order > 0 AND m.id_message > " . $after . ' ORDER BY m.id_message LIMIT 200') ?: [];
        if (!$rows) {
            return null;
        }
        $shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        foreach ($rows as $m) {
            $thread = (int) $db->getValue("SELECT id_customer_thread FROM {$p}customer_thread
                WHERE id_order = " . (int) $m['id_order'] . ' AND id_customer = ' . (int) $m['id_customer'] . ' ORDER BY id_customer_thread');
            if (!$thread) {
                $db->insert('customer_thread', [
                    'id_shop' => $shop,
                    'id_lang' => (int) ($this->s['langMap'][(int) $m['customer_lang']] ?? $lang) ?: $lang,
                    'id_contact' => 0,
                    'id_customer' => (int) $m['id_customer'],
                    'id_order' => (int) $m['id_order'],
                    'id_product' => 0,
                    'status' => 'closed',
                    'email' => pSQL((string) $m['email']),
                    'token' => pSQL(\Tools::passwdGen(12)),
                    'date_add' => pSQL($m['date_add']),
                    'date_upd' => pSQL($m['date_add']),
                ]);
                $thread = (int) $db->Insert_ID();
            }
            $exists = $db->getValue("SELECT 1 FROM {$p}customer_message WHERE id_customer_thread = " . $thread
                . " AND date_add = '" . pSQL($m['date_add']) . "' AND message = '" . pSQL($m['message'], true) . "'");
            if (!$exists) {
                $db->insert('customer_message', [
                    'id_customer_thread' => $thread,
                    'id_employee' => (int) $m['id_employee'],
                    'message' => pSQL($m['message'], true),
                    'private' => (int) $m['private'],
                    'read' => 1,
                    'date_add' => pSQL($m['date_add']),
                    'date_upd' => pSQL($m['date_add']),
                ], true);
            }
        }

        return ['m' => (int) end($rows)['id_message']];
    }

    /**
     * Runs $chunk (cursor in → next cursor out, null when finished) until the tick's time is up;
     * the cursor is saved with the state, so the next tick continues where this one stopped.
     */
    private function chunked(float $until, callable $chunk): bool
    {
        $c = $this->s['postCursor'] ?? [];
        do {
            $c = $chunk($c);
            if ($c === null) {
                unset($this->s['postCursor']);

                return true;
            }
            $this->s['postCursor'] = $c;
        } while (microtime(true) < $until);

        return false;
    }

    public function progress(): array
    {
        $groups = [];
        foreach ($this->s['steps'] ?? [] as $idx => $st) {
            if (!isset($st['type'])) {
                continue;
            }
            $g = &$groups[$st['type']];
            $g['total'] = ($g['total'] ?? 0) + $st['total'];
            $g['done'] = ($g['done'] ?? 0) + $st['done'];
            // Records = rows of the type's main tables (products, not product_lang…).
            if (in_array($st['t'] ?? '', Plan::RECORDS[$st['type']] ?? [], true)) {
                $g['records'] = ($g['records'] ?? 0) + $st['done'];
            }
            $g['state'] = $idx < ($this->s['i'] ?? 0) ? 'done' : ($idx === ($this->s['i'] ?? 0) ? 'active' : ($g['state'] ?? 'waiting'));
            if (($g['state'] ?? '') === 'done' && $idx >= ($this->s['i'] ?? 0)) {
                $g['state'] = 'active';
            }
            unset($g);
        }
        $current = $this->s['steps'][$this->s['i'] ?? 0] ?? null;

        return [
            'status' => $this->s['status'] ?? 'idle',
            'error' => $this->s['error'] ?? null,
            'source' => $this->s['source'] ?? null,
            'groups' => $groups,
            'current' => $current ? ($current['t'] ?? $current['p'] ?? $current['k']) : null,
            'step' => ($this->s['i'] ?? 0),
            'steps' => count($this->s['steps'] ?? []),
            'warnings' => array_slice($this->s['warnings'] ?? [], -200),
            'warningCount' => count($this->s['warnings'] ?? []),
            'started' => $this->s['started'] ?? null,
            'finished' => $this->s['finished'] ?? null,
        ];
    }

    public static function install(): bool
    {
        return \Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id` INT UNSIGNED NOT NULL, `data` MEDIUMTEXT NOT NULL, `date_upd` DATETIME NOT NULL, PRIMARY KEY (`id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    public static function uninstall(): bool
    {
        return \Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    private function save(): void
    {
        // Keep the report readable: the first 1000 warnings are enough to act on.
        if (count($this->s['warnings'] ?? []) > 1000) {
            $this->s['warningsTruncated'] = true;
            $this->s['warnings'] = array_slice($this->s['warnings'], 0, 1000);
        }
        \Db::getInstance()->execute('REPLACE INTO `' . _DB_PREFIX_ . self::TABLE . "` (id, data, date_upd) VALUES (1, '"
            . pSQL(json_encode($this->s, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), true) . "', NOW())");
    }
}
