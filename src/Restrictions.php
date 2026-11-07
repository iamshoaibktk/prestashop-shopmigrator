<?php
/**
 * Shop Migrator - Upgrade to PrestaShop 9 by Data Transfer
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Iamshoaibktk\Shopmigrator;

/**
 * Which customer groups see each module, and which countries, currencies and carriers each
 * payment module works with. PrestaShop keeps these per module ID, and module IDs differ between
 * two shops, so they are matched by module name (1.6 names included, see Plan::MODULE_ALIASES).
 *
 * Without this, customer groups copied from the old shop see no module at all (modules are only
 * enabled for the groups that existed when they were installed), and payment modules are not
 * offered with the old shop's carriers.
 */
class Restrictions
{
    /** @var string[] lines for the report */
    public array $notes = [];

    /**
     * @param string[] $sourceTables tables of the old shop
     * @param int[] $addedCountries countries Geo added to this shop in this run
     * @param string[] $types data types of this run
     */
    public function __construct(
        private Client $client,
        private array $sourceTables,
        private array $shopMap,
        private array $currencyMap,
        private array $geoMap,
        private array $addedCountries,
        private bool $fresh,
        private array $types,
    ) {
    }

    public function run(): void
    {
        if (!$this->has('module')) {
            return;
        }
        $here = [];
        foreach (\Db::getInstance()->executeS('SELECT id_module, name FROM `' . _DB_PREFIX_ . 'module`') ?: [] as $m) {
            $here[strtolower($m['name'])] = (int) $m['id_module'];
        }
        $old = []; // old module ID => module ID here
        foreach ($this->client->allRows('module') as $m) {
            $name = strtolower((string) $m['name']);
            $name = Plan::MODULE_ALIASES[$name] ?? $name;
            if (isset($here[$name])) {
                $old[(int) $m['id_module']] = $here[$name];
            }
        }
        $shops = array_values(array_unique(array_map('intval', $this->shopMap)));
        if (!$shops) {
            return;
        }
        if (in_array('customers', $this->types, true) && $this->has('module_group')) {
            $this->groups($old, $shops);
        }
        $this->payments($old, $shops);
        $this->checkDefaultCountry();
    }

    /** @param array<int, int> $old old module ID => module ID here */
    private function groups(array $old, array $shops): void
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $groups = array_flip(array_map('intval', array_column($db->executeS("SELECT id_group FROM `{$p}group`") ?: [], 'id_group')));
        // Groups that no module is enabled for yet: the ones just copied from the old shop.
        $new = array_map('intval', array_column($db->executeS("SELECT g.id_group FROM `{$p}group` g
            WHERE NOT EXISTS (SELECT 1 FROM {$p}module_group mg WHERE mg.id_group = g.id_group)") ?: [], 'id_group'));
        $want = [];
        foreach ($this->client->allRows('module_group') as $r) {
            $id = $old[(int) $r['id_module']] ?? null;
            $shop = $this->shopMap[(int) $r['id_shop']] ?? null;
            if ($id && $shop && isset($groups[(int) $r['id_group']])) {
                $want[$id][] = [$id, $shop, (int) $r['id_group']];
            }
        }
        $known = array_values(array_unique($old));
        if ($this->fresh) {
            // The old shop's choices, module by module (a module it hid from a group stays hidden).
            if ($known) {
                $db->execute("DELETE FROM {$p}module_group WHERE id_module IN (" . implode(',', $known) . ') AND id_shop IN (' . implode(',', $shops) . ')');
            }
            $this->insert('module_group', ['id_module', 'id_shop', 'id_group'], array_merge(...array_values($want ?: [[]])));
        } else {
            $newSet = array_flip($new);
            $rows = [];
            foreach ($want as $list) {
                foreach ($list as $r) {
                    if (isset($newSet[$r[2]])) {
                        $rows[] = $r;
                    }
                }
            }
            $this->insert('module_group', ['id_module', 'id_shop', 'id_group'], $rows);
        }
        // Modules the old shop doesn't have: new groups get what visitors get (PrestaShop's own
        // default when a group is created in the back office is every module).
        $visitor = (int) \Configuration::get('PS_UNIDENTIFIED_GROUP');
        foreach ($visitor ? $new : [] as $g) {
            $db->execute("INSERT IGNORE INTO {$p}module_group (id_module, id_shop, id_group)
                SELECT id_module, id_shop, " . (int) $g . " FROM {$p}module_group WHERE id_group = " . $visitor
                . ($known ? ' AND id_module NOT IN (' . implode(',', $known) . ')' : ''));
        }
        $this->notes[] = sprintf(
            'Customer groups: module access copied from the old shop for %d modules%s.',
            count($want),
            $new ? '; ' . count($new) . ' groups new here also see the other modules visitors see' : ''
        );
    }

    /** @param array<int, int> $old old module ID => module ID here */
    private function payments(array $old, array $shops): void
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $refs = array_map('intval', array_column($db->executeS("SELECT DISTINCT id_reference FROM {$p}carrier WHERE deleted = 0") ?: [], 'id_reference'));
        $newRefs = array_map('intval', array_column($db->executeS("SELECT DISTINCT c.id_reference FROM {$p}carrier c
            WHERE c.deleted = 0 AND NOT EXISTS (SELECT 1 FROM {$p}module_carrier mc WHERE mc.id_reference = c.id_reference)") ?: [], 'id_reference'));
        $carriers = in_array('carriers', $this->types, true);
        // Countries added here that the old shop sells to (PrestaShop gives a newly installed
        // payment module the active countries).
        $addedActive = $this->addedCountries ? array_map('intval', array_column($db->executeS("SELECT id_country FROM {$p}country
            WHERE active = 1 AND id_country IN (" . implode(',', array_map('intval', $this->addedCountries)) . ')') ?: [], 'id_country')) : [];
        $payment = []; // modules restricted by country or currency on the old shop: payment modules
        $kept = [];
        $copied = [];
        foreach (['country' => 'id_country', 'currency' => 'id_currency', 'carrier' => 'id_reference'] as $what => $col) {
            $table = 'module_' . $what;
            if ($what === 'carrier' && !$carriers) {
                continue;
            }
            $want = [];
            $restricted = [];
            if ($this->has($table)) {
                foreach ($this->client->allRows($table) as $r) {
                    $id = $old[(int) $r['id_module']] ?? null;
                    $shop = $this->shopMap[(int) $r['id_shop']] ?? null;
                    if (!$id || !$shop) {
                        continue;
                    }
                    $restricted[$id] = true;
                    if ($what !== 'carrier') {
                        $payment[$id] = true;
                    }
                    $value = $this->value($what, (int) $r[$col], $refs);
                    if ($value !== null) {
                        $want[$id][$shop . '|' . $value] = [$id, $shop, $value];
                    }
                }
            } elseif ($what === 'carrier') {
                // PrestaShop 1.6 had no carrier restrictions: its payment modules took every carrier.
                foreach (array_keys($payment) as $id) {
                    $restricted[$id] = true;
                    foreach ($shops as $shop) {
                        foreach ($refs as $ref) {
                            $want[$id][$shop . '|' . $ref] = [$id, $shop, $ref];
                        }
                    }
                }
            }
            if ($this->fresh) {
                // A module none of whose old values exist here (only currencies this shop doesn't
                // have, say) keeps its own setting rather than ending up with none at all.
                foreach (array_keys($restricted) as $id) {
                    if (empty($want[$id])) {
                        unset($restricted[$id]);
                        $kept[$what][] = $id;
                    }
                }
                if ($restricted) {
                    $db->execute("DELETE FROM {$p}{$table} WHERE id_module IN (" . implode(',', array_keys($restricted)) . ') AND id_shop IN (' . implode(',', $shops) . ')');
                }
                $rows = array_merge(...array_values(array_map('array_values', $want ?: [[]])));
            } else {
                // New data only: just the countries and carriers that are new here.
                $isNew = array_flip($what === 'country' ? $addedActive : ($what === 'carrier' ? $newRefs : []));
                $rows = [];
                foreach ($want as $list) {
                    foreach ($list as $r) {
                        if (isset($isNew[$r[2]])) {
                            $rows[] = $r;
                        }
                    }
                }
            }
            $this->insert($table, ['id_module', 'id_shop', $col], $rows);
            $copied[$what] = count($restricted);

            // Payment modules the old shop doesn't have keep their own settings, plus the new
            // countries and carriers (a payment module is installed with all of them).
            $values = $what === 'country' ? $addedActive : ($what === 'carrier' ? ($this->fresh ? $refs : $newRefs) : []);
            if ($values) {
                $known = array_values(array_unique($old));
                $others = array_map('intval', array_column($db->executeS("SELECT DISTINCT id_module FROM {$p}{$table}"
                    . ($known ? ' WHERE id_module NOT IN (' . implode(',', $known) . ')' : '')) ?: [], 'id_module'));
                if ($what === 'carrier' && $this->fresh && $others) {
                    // The carriers they pointed at were replaced by the old shop's.
                    $db->execute("DELETE FROM {$p}module_carrier WHERE id_module IN (" . implode(',', $others) . ') AND id_shop IN (' . implode(',', $shops) . ')');
                }
                $rows = [];
                foreach ($others as $id) {
                    foreach ($shops as $shop) {
                        foreach ($values as $v) {
                            $rows[] = [$id, $shop, (int) $v];
                        }
                    }
                }
                $this->insert($table, ['id_module', 'id_shop', $col], $rows);
            }
        }
        $name = fn (array $ids) => implode(', ', array_column($db->executeS("SELECT name FROM {$p}module WHERE id_module IN ("
            . implode(',', array_map('intval', $ids)) . ') ORDER BY name') ?: [], 'name'));
        if ($payment) {
            $this->notes[] = sprintf(
                'Payment modules: countries, currencies%s copied from the old shop for %s.',
                $carriers ? ' and carriers' : '',
                $name(array_keys($payment))
            );
        }
        foreach ($kept as $what => $ids) {
            $this->notes[] = sprintf(
                'Payment modules %s keep this shop\'s %s settings: none of the old shop\'s %s exist here.',
                $name($ids),
                $what,
                ['country' => 'countries', 'currency' => 'currencies', 'carrier' => 'carriers'][$what]
            );
        }
    }

    /** A value of the old shop as this shop knows it, null when it has no equivalent here. */
    private function value(string $what, int $v, array $refs): ?int
    {
        return match ($what) {
            'country' => $this->geoMap['country'][$v] ?? null,
            // -1: the customer's currency, -2: the shop's default currency.
            'currency' => $v < 0 ? $v : ($this->currencyMap[$v] ?? null),
            'carrier' => in_array($v, $refs, true) ? $v : null,
        };
    }

    private function checkDefaultCountry(): void
    {
        $db = \Db::getInstance();
        $p = _DB_PREFIX_;
        $payment = array_map('intval', array_column($db->executeS("SELECT DISTINCT hm.id_module FROM {$p}hook_module hm
            JOIN {$p}hook h ON (h.id_hook = hm.id_hook AND h.name = 'paymentOptions')
            JOIN {$p}module m ON (m.id_module = hm.id_module AND m.active = 1)") ?: [], 'id_module'));
        $default = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        if ($payment && !(int) $db->getValue("SELECT COUNT(*) FROM {$p}module_country WHERE id_country = " . $default
            . ' AND id_module IN (' . implode(',', $payment) . ')')) {
            $this->notes[] = 'No payment method is enabled for the default country yet: tick it in Payment > Preferences > Country restrictions.';
        }
    }

    private function has(string $table): bool
    {
        return in_array($table, $this->sourceTables, true);
    }

    /** Multi-row INSERT IGNORE, 500 rows per query. */
    private function insert(string $table, array $cols, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $values = implode(',', array_map(fn ($r) => '(' . implode(',', array_map('intval', $r)) . ')', $chunk));
            \Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . bqSQL($table) . '` (`' . implode('`, `', $cols) . '`) VALUES ' . $values);
        }
    }
}
