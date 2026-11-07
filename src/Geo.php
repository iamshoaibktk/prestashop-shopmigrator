<?php
/**
 * Shop Migrator - Upgrade to PrestaShop 9 by Data Transfer
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Iamshoaibktk\Shopmigrator;

/**
 * Countries, zones and states are matched to this shop's own instead of being copied with their
 * IDs: PrestaShop 9 numbers countries differently from 1.6 and 1.7 (it dropped a few, so later IDs
 * shift), and this shop's default country, installed modules and own data already point at its
 * IDs. Countries match by ISO code, zones by name, states by ISO code then name; what this shop
 * doesn't have is added. Every copied row is then translated through the map (see Copier).
 *
 * Fresh copy: matched countries, zones and states take the old shop's settings and names, this
 * shop's countries and states the old shop doesn't have are deactivated (never deleted), and the
 * default country and shop address come from the old shop. New data only: just adds what is new.
 */
class Geo
{
    /** Maps of earlier runs, per old shop: rows added without an ISO code are found again. */
    public const CONFIG = 'SHOPMIGRATOR_GEO_MAP';

    /** @var string[] lines for the report */
    public array $notes = [];

    /** @var array{country: int[], state: int[], zone: int[]} IDs added here by this run */
    public array $added = ['country' => [], 'state' => [], 'zone' => []];

    /** @var array{country: array<int, int>, state: array<int, int>, zone: array<int, int>} old ID => ID here */
    private array $map = ['country' => [], 'state' => [], 'zone' => []];

    /** States whose country no longer exists on the old shop (left out). */
    private int $orphanStates = 0;

    /**
     * @param string[] $sourceTables tables of the old shop
     */
    public function __construct(
        private Client $client,
        private Copier $copier,
        private bool $fresh,
        private string $source,
        private array $sourceTables,
        private int $sourceLang,
    ) {
    }

    /** @return array{country: array<int, int>, state: array<int, int>, zone: array<int, int>} */
    public function run(): array
    {
        $saved = json_decode((string) \Configuration::getGlobalValue(self::CONFIG), true);
        $saved = is_array($saved) ? $saved : [];
        $previous = $saved[$this->source] ?? [];

        $this->zones($previous['zone'] ?? []);
        $names = $this->countries($previous['country'] ?? []);
        $this->states($previous['state'] ?? []);
        $this->formats();
        if ($this->fresh) {
            $this->defaults();
        }

        // Keep the maps of the last few old shops only.
        unset($saved[$this->source]);
        $saved[$this->source] = $this->map;
        \Configuration::updateGlobalValue(self::CONFIG, json_encode(array_slice($saved, -5, null, true)));
        $this->report($names);

        return $this->map;
    }

    private function zones(array $previous): void
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $here = $db->executeS("SELECT id_zone, name FROM {$p}zone ORDER BY id_zone") ?: [];
        $byName = [];
        foreach ($here as $z) {
            $byName[self::key($z['name'])] ??= (int) $z['id_zone'];
        }
        $used = array_fill_keys(array_map('intval', array_column($here, 'id_zone')), true);
        $taken = [];
        $write = [];
        foreach ($this->rows('zone') as $z) {
            $old = (int) $z['id_zone'];
            $id = self::still($previous[$old] ?? null, $used) ?? ($byName[self::key((string) $z['name'])] ?? null);
            if ($id !== null && isset($taken[$id])) {
                $id = null; // two old zones with one name: the second one is added
            }
            if ($id === null) {
                $id = self::free($old, $used);
                $this->added['zone'][] = $id;
                $write[] = ['id_zone' => $id] + $z;
            } elseif ($this->fresh) {
                $write[] = ['id_zone' => $id] + $z;
            }
            $taken[$id] = true;
            $this->map['zone'][$old] = $id;
        }
        $this->copier->write('zone', $write);
        $this->copier->write('zone_shop', $this->translate($this->rows('zone_shop'), 'id_zone', 'zone'));
    }

    /** @return array<int, string> old country ID => its name on the old shop */
    private function countries(array $previous): array
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $here = $db->executeS("SELECT id_country, iso_code, active, id_zone FROM {$p}country ORDER BY id_country") ?: [];
        // For an added country whose zone the old shop deleted: a zone that exists here.
        $fallbackZone = (int) $db->getValue("SELECT id_zone FROM {$p}country WHERE id_country = " . (int) \Configuration::get('PS_COUNTRY_DEFAULT'))
            ?: (int) $db->getValue("SELECT MIN(id_zone) FROM {$p}zone");
        $hereIso = [];
        $hereZone = [];
        foreach ($here as $c) {
            $hereIso[self::iso($c['iso_code'])][] = (int) $c['id_country'];
            $hereZone[(int) $c['id_country']] = (int) $c['id_zone'];
        }
        $used = array_fill_keys(array_map('intval', array_column($here, 'id_country')), true);
        $old = $this->rows('country');
        $byId = [];
        foreach ($old as $c) {
            $byId[(int) $c['id_country']] = $c;
        }
        // Active countries first, then by ID, so duplicate ISO codes resolve the same way every run.
        uasort($byId, fn ($a, $b) => [(int) $b['active'], (int) $a['id_country']] <=> [(int) $a['active'], (int) $b['id_country']]);
        $taken = [];
        $match = function (int $oldId, ?int $id) use (&$taken) {
            if ($id !== null && !isset($taken[$id]) && !isset($this->map['country'][$oldId])) {
                $this->map['country'][$oldId] = $id;
                $taken[$id] = true;
            }
        };
        // 1. Earlier runs from this old shop, 2. same ID and same ISO code, 3. same ISO code.
        foreach ($byId as $oldId => $c) {
            $match($oldId, self::still($previous[$oldId] ?? null, $used));
        }
        foreach ($byId as $oldId => $c) {
            $iso = self::iso($c['iso_code']);
            if ($iso !== '' && in_array($oldId, $hereIso[$iso] ?? [], true)) {
                $match($oldId, $oldId);
            }
        }
        foreach ($byId as $oldId => $c) {
            $iso = self::iso($c['iso_code']);
            foreach ($iso === '' ? [] : ($hereIso[$iso] ?? []) as $id) {
                if (!isset($taken[$id])) {
                    $match($oldId, $id);
                    break;
                }
            }
        }

        $write = [];
        foreach ($byId as $oldId => $c) {
            $add = !isset($this->map['country'][$oldId]);
            if ($add) {
                // No country with this ISO code here (or a second old country with the same code,
                // like a custom Canary Islands): added, keeping its old ID when it is free.
                $this->map['country'][$oldId] = self::free($oldId, $used);
                $this->added['country'][] = $this->map['country'][$oldId];
            }
            if ($add || $this->fresh) {
                $id = $this->map['country'][$oldId];
                $c['id_country'] = $id;
                // A zone deleted on the old shop: the country keeps its zone here.
                $c['id_zone'] = $this->map['zone'][(int) $c['id_zone']] ?? ($hereZone[$id] ?? $fallbackZone);
                $write[] = $c;
            }
        }
        $this->copier->write('country', $write);

        // Names (the old shop's, in its languages) and shops, for the rows written above.
        $written = array_flip(array_map('intval', array_column($write, 'id_country')));
        $names = [];
        $lang = [];
        foreach ($this->rows('country_lang') as $l) {
            if ((int) $l['id_lang'] === $this->sourceLang || !isset($names[(int) $l['id_country']])) {
                $names[(int) $l['id_country']] = (string) $l['name'];
            }
            $id = $this->map['country'][(int) $l['id_country']] ?? null;
            if ($id !== null && isset($written[$id])) {
                $lang[] = ['id_country' => $id] + $l;
            }
        }
        $this->copier->write('country_lang', $lang);
        $this->copier->write('country_shop', array_values(array_filter(
            $this->translate($this->rows('country_shop'), 'id_country', 'country'),
            fn ($r) => isset($written[(int) $r['id_country']])
        )));
        foreach ($this->added['country'] as $id) {
            // Languages the old shop doesn't have: its name in the old shop's language.
            $old = array_search($id, $this->map['country'], true);
            $db->execute("INSERT IGNORE INTO {$p}country_lang (id_country, id_lang, name)
                SELECT " . (int) $id . ", id_lang, '" . pSQL($names[$old] ?? '') . "' FROM {$p}lang");
            $db->execute("INSERT IGNORE INTO {$p}country_shop (id_country, id_shop) SELECT " . (int) $id . ", id_shop FROM {$p}shop");
        }
        if ($this->fresh && $this->map['country']) {
            // The old shop didn't sell there: kept for later, but not offered at checkout.
            $db->execute("UPDATE {$p}country SET active = 0 WHERE id_country NOT IN (" . implode(',', array_map('intval', $this->map['country'])) . ')');
        }

        return $names;
    }

    private function states(array $previous): void
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $countryIso = [];
        $countryZone = [];
        foreach ($db->executeS("SELECT id_country, iso_code, id_zone FROM {$p}country") ?: [] as $c) {
            $countryIso[(int) $c['id_country']] = (string) $c['iso_code'];
            $countryZone[(int) $c['id_country']] = (int) $c['id_zone'];
        }
        $here = $db->executeS("SELECT id_state, id_country, iso_code, name FROM {$p}state ORDER BY id_state") ?: [];
        $byIso = [];
        $byName = [];
        foreach ($here as $s) {
            $c = (int) $s['id_country'];
            $byIso[$c][self::stateIso((string) $s['iso_code'], $countryIso[$c] ?? '')] ??= (int) $s['id_state'];
            $byName[$c][self::key((string) $s['name'])] ??= (int) $s['id_state'];
        }
        $used = array_fill_keys(array_map('intval', array_column($here, 'id_state')), true);
        $taken = [];
        $write = [];
        $orphans = 0;
        foreach ($this->rows('state') as $s) {
            $old = (int) $s['id_state'];
            $country = $this->map['country'][(int) $s['id_country']] ?? null;
            if ($country === null) {
                ++$orphans; // its country was deleted on the old shop
                continue;
            }
            $id = self::still($previous[$old] ?? null, $used)
                ?? ($byIso[$country][self::stateIso((string) $s['iso_code'], $countryIso[$country] ?? '')] ?? null)
                ?? ($byName[$country][self::key((string) $s['name'])] ?? null);
            if ($id !== null && isset($taken[$id])) {
                $id = null;
            }
            $add = $id === null;
            if ($add) {
                $id = self::free($old, $used);
                $this->added['state'][] = $id;
            }
            if ($add || $this->fresh) {
                $s['id_state'] = $id;
                $s['id_country'] = $country;
                $s['id_zone'] = $this->map['zone'][(int) $s['id_zone']] ?? ($countryZone[$country] ?? (int) $s['id_zone']);
                $write[] = $s;
            }
            $taken[$id] = true;
            $this->map['state'][$old] = $id;
        }
        $this->copier->write('state', $write);
        if ($this->fresh && $this->map['state']) {
            // In the countries the old shop has states for, offer only the old shop's states.
            $countries = array_unique(array_map('intval', array_column($write, 'id_country')));
            if ($countries) {
                $db->execute("UPDATE {$p}state SET active = 0 WHERE id_country IN (" . implode(',', $countries) . ')
                    AND id_state NOT IN (' . implode(',', array_map('intval', $this->map['state'])) . ')');
            }
        }
        $this->orphanStates = $orphans;
    }

    private function formats(): void
    {
        $added = array_flip($this->added['country']);
        $rows = array_values(array_filter(
            $this->translate($this->rows('address_format'), 'id_country', 'country'),
            fn ($r) => $this->fresh || isset($added[(int) $r['id_country']])
        ));
        $this->copier->write('address_format', $rows);
    }

    /** Default country and the shop's own address, as on the old shop. */
    private function defaults(): void
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $config = $this->sourceConfig(['PS_COUNTRY_DEFAULT', 'PS_SHOP_COUNTRY_ID', 'PS_SHOP_STATE_ID']);
        foreach (['PS_COUNTRY_DEFAULT', 'PS_SHOP_COUNTRY_ID'] as $key) {
            $id = $this->map['country'][(int) ($config[$key] ?? 0)] ?? null;
            if ($id) {
                \Configuration::updateValue($key, $id);
            }
        }
        $country = (int) \Configuration::get('PS_SHOP_COUNTRY_ID');
        $state = $this->map['state'][(int) ($config['PS_SHOP_STATE_ID'] ?? 0)] ?? (int) \Configuration::get('PS_SHOP_STATE_ID');
        $belongs = $state && (int) $db->getValue("SELECT id_country FROM {$p}state WHERE id_state = " . (int) $state) === $country;
        \Configuration::updateValue('PS_SHOP_STATE_ID', $belongs ? $state : 0);
        // PrestaShop 9 needs its default country to exist and checkout needs it active.
        $db->execute("UPDATE {$p}country SET active = 1 WHERE id_country = " . (int) \Configuration::get('PS_COUNTRY_DEFAULT'));
    }

    private function report(array $names): void
    {
        $added = array_flip($this->added['country']);
        $matched = array_filter($this->map['country'], fn ($id) => !isset($added[$id]));
        $moved = array_filter($matched, fn ($id, $old) => $id !== $old, ARRAY_FILTER_USE_BOTH);
        $this->notes[] = sprintf(
            'Countries: %d of the old shop\'s %d match this shop\'s by ISO code (%d of them under a different ID here), %d added. Zones: %d match by name, %d added. States: %d match, %d added%s.',
            count($matched),
            count($this->map['country']),
            count($moved),
            count($this->added['country']),
            count($this->map['zone']) - count($this->added['zone']),
            count($this->added['zone']),
            count($this->map['state']) - count($this->added['state']),
            count($this->added['state']),
            $this->orphanStates ? ', ' . $this->orphanStates . ' left out (their country no longer exists on the old shop)' : ''
        );
        $active = array_flip(array_map('intval', array_column(
            \Db::getInstance()->executeS('SELECT id_country FROM `' . _DB_PREFIX_ . 'country` WHERE active = 1') ?: [],
            'id_country'
        )));
        $lines = [];
        foreach ($moved as $old => $id) {
            if (isset($active[$id])) {
                $lines[] = trim(($names[$old] ?? '') . ' #' . $old) . ' → #' . $id;
            }
        }
        if ($lines) {
            $this->notes[] = 'Countries in use that have a different ID here (addresses, taxes and shipping follow them): '
                . implode(', ', array_slice($lines, 0, 20)) . (count($lines) > 20 ? '…' : '') . '.';
        }
    }

    /** @return array<string, string> the old shop's values (its default shop's, else global) */
    private function sourceConfig(array $names): array
    {
        $out = [];
        $shop = [];
        if (!in_array('configuration', $this->sourceTables, true)) {
            return $out;
        }
        foreach ($this->client->allRows('configuration') as $r) {
            if (!in_array($r['name'], $names, true)) {
                continue;
            }
            if (empty($r['id_shop']) && empty($r['id_shop_group'])) {
                $out[$r['name']] = (string) $r['value'];
            } elseif (!empty($r['id_shop']) && !isset($shop[$r['name']])) {
                $shop[$r['name']] = (string) $r['value'];
            }
        }

        return $shop + $out;
    }

    /** All rows of one of the old shop's tables (none when it doesn't have it). */
    private function rows(string $table): array
    {
        return in_array($table, $this->sourceTables, true) ? $this->client->allRows($table) : [];
    }

    /** Rows with one column translated through a map; rows it can't translate are left out. */
    private function translate(array $rows, string $col, string $kind): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (isset($this->map[$kind][(int) $r[$col]])) {
                $r[$col] = $this->map[$kind][(int) $r[$col]];
                $out[] = $r;
            }
        }

        return $out;
    }

    /** An ID from an earlier run, if that row still exists here. */
    private static function still(mixed $id, array $used): ?int
    {
        return $id !== null && isset($used[(int) $id]) ? (int) $id : null;
    }

    /** The old ID when it is free here, otherwise the next free one. */
    private static function free(int $want, array &$used): int
    {
        if ($want > 0 && !isset($used[$want])) {
            $used[$want] = true;

            return $want;
        }
        $id = ($used ? max(array_keys($used)) : 0) + 1;
        $used[$id] = true;

        return $id;
    }

    private static function iso(mixed $iso): string
    {
        return strtoupper(trim((string) $iso));
    }

    /** "ES-A" and "A" are the same state of Spain. */
    private static function stateIso(string $iso, string $countryIso): string
    {
        $iso = self::iso($iso);
        $prefix = self::iso($countryIso) . '-';

        return $prefix !== '-' && str_starts_with($iso, $prefix) ? substr($iso, strlen($prefix)) : $iso;
    }

    /** Case, accents and spacing don't count when names are compared ("Málaga" = "malaga"). */
    private static function key(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : false;
        $plain = $ascii !== false ? (string) preg_replace('/[^a-z0-9]+/', '', strtolower($ascii)) : '';

        return $plain !== '' ? $plain : $name;
    }
}
