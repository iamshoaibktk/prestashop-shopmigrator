<?php
/**
 * Shop Migrator - Upgrade to PrestaShop 9 by Data Transfer
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Iamshoaibktk\Shopmigrator;

/**
 * Writes rows from the old shop into this shop's tables, adapting them to PrestaShop 9:
 * renamed columns, new required columns, language and shop IDs, and values older versions
 * stored differently. Rows keep their IDs, and a second run updates instead of duplicating.
 */
class Copier
{
    /** @var array<string, array<string, array{type: string, null: bool, default: ?string}>> */
    private array $columns = [];

    /** @var string[] notes for the report */
    public array $warnings = [];

    /**
     * @param array<int, int> $langMap old id_lang => new id_lang
     * @param array<int, int> $shopMap old id_shop => new id_shop
     * @param array<int, int> $currencyMap old id_currency => new id_currency (matched by ISO code)
     */
    public function __construct(private array $langMap, private array $shopMap, private array $currencyMap = [])
    {
        // Older versions stored values (zero dates, empty enums) that strict MySQL would refuse.
        \Db::getInstance()->execute("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    }

    public function hasTable(string $table): bool
    {
        return $this->targetColumns($table) !== [];
    }

    /** @return array<string, array{type: string, null: bool, default: ?string}> */
    public function targetColumns(string $table): array
    {
        if (!isset($this->columns[$table])) {
            $this->columns[$table] = [];
            $rows = \Db::getInstance()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . bqSQL($table) . '`', true, false) ?: [];
            foreach ($rows as $c) {
                $this->columns[$table][$c['Field']] = ['type' => strtolower($c['Type']), 'null' => $c['Null'] === 'YES', 'default' => $c['Default']];
            }
        }

        return $this->columns[$table];
    }

    public function truncate(string $table): void
    {
        if ($this->hasTable($table)) {
            \Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . bqSQL($table) . '`');
        }
    }

    /**
     * @return array{written: int, skipped: int}
     */
    public function write(string $table, array $rows): array
    {
        $cols = $this->targetColumns($table);
        if (!$cols || !$rows) {
            return ['written' => 0, 'skipped' => count($rows)];
        }
        $names = array_keys($cols);
        $values = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $mapped = $this->map($table, $row, $cols);
            if ($mapped === null) {
                ++$skipped;
                continue;
            }
            $values[] = '(' . implode(', ', array_map(fn ($n) => $this->sql($mapped[$n]), $names)) . ')';
        }
        if (!$values) {
            return ['written' => 0, 'skipped' => $skipped];
        }
        $quoted = array_map(fn ($n) => '`' . bqSQL($n) . '`', $names);
        $update = implode(', ', array_map(fn ($q) => $q . ' = VALUES(' . $q . ')', $quoted));
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . bqSQL($table) . '` (' . implode(', ', $quoted) . ') VALUES ' . implode(', ', $values)
            . ' ON DUPLICATE KEY UPDATE ' . $update;
        if (!\Db::getInstance()->execute($sql)) {
            throw new \RuntimeException($table . ': ' . \Db::getInstance()->getMsgError());
        }

        return ['written' => count($values), 'skipped' => $skipped];
    }

    /** One old row → one row for this shop, or null when it doesn't belong here. */
    private function map(string $table, array $row, array $cols): ?array
    {
        foreach (Plan::RENAMED[$table] ?? [] as $old => $new) {
            if (array_key_exists($old, $row) && !array_key_exists($new, $row)) {
                $row[$new] = $row[$old];
            }
        }
        if (array_key_exists('id_lang', $row)) {
            $lang = $this->langMap[(int) $row['id_lang']] ?? null;
            if ($lang === null) {
                return null; // language not installed on the new shop
            }
            $row['id_lang'] = $lang;
        }
        foreach (['id_shop', 'id_shop_default'] as $key) {
            if (array_key_exists($key, $row) && (int) $row[$key] > 0) {
                $shop = $this->shopMap[(int) $row[$key]] ?? null;
                if ($shop === null) {
                    return null;
                }
                $row[$key] = $shop;
            }
        }
        if (array_key_exists('id_currency', $row) && isset($this->currencyMap[(int) $row['id_currency']])) {
            $row['id_currency'] = $this->currencyMap[(int) $row['id_currency']];
        }
        $this->fix($table, $row);

        $out = [];
        foreach ($cols as $name => $c) {
            // Text columns the old version doesn't have get '' (PrestaShop 9 code expects strings
            // in new columns like product_mpn even where the database allows NULL).
            $v = array_key_exists($name, $row) ? $row[$name]
                : ($c['default'] === null && preg_match('/^(varchar|char|text|tinytext|mediumtext|longtext)/', $c['type']) ? '' : $this->fallback($c));
            if ($v === null && !$c['null']) {
                $v = $this->fallback($c);
            }
            if ($v !== null && (str_starts_with($c['type'], 'date') || str_starts_with($c['type'], 'timestamp')) && str_starts_with((string) $v, '0000-00-00')) {
                $v = $c['null'] ? null : $v;
            }
            if ($v !== null && str_starts_with($c['type'], 'enum(')) {
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $c['type'], $m);
                if (!in_array((string) $v, $m[1], true)) {
                    $v = $c['default'] ?? ($m[1][0] ?? '');
                }
            }
            $out[$name] = $v;
        }

        return $out;
    }

    /** Values whose meaning changed between versions. */
    private function fix(string $table, array &$row): void
    {
        if (in_array($table, ['image', 'image_shop'], true) && isset($row['cover']) && (int) $row['cover'] === 0) {
            $row['cover'] = null; // PrestaShop 1.7+ uses NULL for "not cover" (unique key)
        }
        if (in_array($table, ['product', 'product_shop'], true) && array_key_exists('redirect_type', $row)) {
            $row['redirect_type'] = match ((string) $row['redirect_type']) {
                '', '0' => 'default',
                '301' => '301-product',
                '302' => '302-product',
                default => $row['redirect_type'],
            };
        }
    }

    /** A safe value for a column the old shop doesn't have. */
    private function fallback(array $c): mixed
    {
        if ($c['default'] !== null) {
            return $c['default'];
        }
        if ($c['null']) {
            return null;
        }
        $t = $c['type'];

        return match (true) {
            (bool) preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|float|double)/', $t) => 0,
            str_starts_with($t, 'datetime') || str_starts_with($t, 'timestamp') => date('Y-m-d H:i:s'),
            str_starts_with($t, 'date') => date('Y-m-d'),
            default => '',
        };
    }

    private function sql(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }

        return "'" . pSQL((string) $v, true) . "'";
    }
}
