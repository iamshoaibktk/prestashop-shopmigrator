<?php
/**
 * Shop Migrator - Upgrade to PrestaShop 9 by Data Transfer
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Iamshoaibktk\Shopmigrator\Client;
use Iamshoaibktk\Shopmigrator\Plan;
use Iamshoaibktk\Shopmigrator\Redirects;
use Iamshoaibktk\Shopmigrator\Runner;

class Shopmigrator extends Module
{
    public function __construct()
    {
        $this->name = 'shopmigrator';
        $this->tab = 'migration_tools';
        $this->version = '1.0.0';
        $this->author = 'iamshoaibktk@gmail.com';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Shop Migrator - Upgrade to PrestaShop 9 by Data Transfer', [], 'Modules.Shopmigrator.Admin');
        $this->description = $this->trans('Copy products, categories, customers, orders and more from any PrestaShop 1.6, 1.7, 8 or 9 shop into a new PrestaShop 9 store. The old shop is never changed.', [], 'Modules.Shopmigrator.Admin');
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall this module?', [], 'Modules.Shopmigrator.Admin');
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        return parent::install() && Runner::install()
            && $this->registerHook(['actionAuthenticationBefore', 'actionPageNotFoundControllerInitBefore']);
    }

    public function uninstall(): bool
    {
        Configuration::deleteByName('SHOPMIGRATOR_URL');
        Configuration::deleteByName('SHOPMIGRATOR_KEY');
        Configuration::deleteByName('SHOPMIGRATOR_PASSWORD_SALT');
        Configuration::deleteByName('SHOPMIGRATOR_REDIRECTS');

        return Runner::uninstall() && parent::uninstall();
    }

    /**
     * Migrated customers keep their password. PrestaShop 1.6 (and old 1.7 accounts) stored
     * md5(old shop salt + password); when such a customer logs in with the right password,
     * the hash is replaced by a modern bcrypt hash just before PrestaShop checks it.
     */
    public function hookActionAuthenticationBefore(): void
    {
        $salt = (string) Configuration::getGlobalValue('SHOPMIGRATOR_PASSWORD_SALT');
        $email = trim((string) Tools::getValue('email'));
        $password = (string) Tools::getValue('password');
        if ($salt === '' || $password === '' || !Validate::isEmail($email)) {
            return;
        }
        $row = Db::getInstance()->getRow('SELECT id_customer, passwd FROM `' . _DB_PREFIX_ . 'customer`
            WHERE email = "' . pSQL($email) . '" AND deleted = 0 AND is_guest = 0' . Shop::addSqlRestriction(Shop::SHARE_CUSTOMER));
        if (!$row || !preg_match('/^[a-f0-9]{32}$/', (string) $row['passwd'])) {
            return;
        }
        if (hash_equals((string) $row['passwd'], md5($salt . $password))) {
            $hash = (new \PrestaShop\PrestaShop\Core\Crypto\Hashing())->hash($password);
            Db::getInstance()->update('customer', ['passwd' => pSQL($hash)], 'id_customer = ' . (int) $row['id_customer']);
        }
    }

    public function getContent(): string
    {
        if (Tools::getValue('sm_ajax')) {
            $this->ajax();
        }

        $this->context->smarty->assign([
            'sm_ajax_url' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '&sm_ajax=1',
            'sm_url' => (string) Configuration::get('SHOPMIGRATOR_URL'),
            'sm_key' => (string) Configuration::get('SHOPMIGRATOR_KEY'),
            'sm_state_json' => json_encode((new Runner())->progress(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'sm_types_json' => json_encode($this->typeLabels(), JSON_UNESCAPED_UNICODE),
            'sm_needs_json' => json_encode(Plan::NEEDS),
            'sm_target' => ['version' => _PS_VERSION_, 'name' => Configuration::get('PS_SHOP_NAME')],
            'sm_css' => $this->_path . 'views/css/wizard.css',
            'sm_js' => $this->_path . 'views/js/wizard.js',
        ]);

        return $this->renderAuthor() . $this->display(__FILE__, 'views/templates/admin/wizard.tpl');
    }

    /** An old shop's link that has no page here: send the visitor to the same item's new URL. */
    public function hookActionPageNotFoundControllerInitBefore(): void
    {
        if (!Configuration::getGlobalValue('SHOPMIGRATOR_REDIRECTS') || headers_sent()) {
            return;
        }
        $url = Redirects::find((string) ($_SERVER['REQUEST_URI'] ?? ''), $this->context);
        if ($url !== null) {
            header('HTTP/1.1 301 Moved Permanently');
            header('Location: ' . $url);
            exit;
        }
    }

    /** @return array<string, string> */
    public function typeLabels(): array
    {
        return [
            'taxes' => $this->trans('Taxes and tax rules', [], 'Modules.Shopmigrator.Admin'),
            'manufacturers' => $this->trans('Brands', [], 'Modules.Shopmigrator.Admin'),
            'suppliers' => $this->trans('Suppliers', [], 'Modules.Shopmigrator.Admin'),
            'categories' => $this->trans('Categories', [], 'Modules.Shopmigrator.Admin'),
            'attributes' => $this->trans('Attributes and features', [], 'Modules.Shopmigrator.Admin'),
            'products' => $this->trans('Products (combinations, prices, stock, images)', [], 'Modules.Shopmigrator.Admin'),
            'carriers' => $this->trans('Carriers and shipping prices', [], 'Modules.Shopmigrator.Admin'),
            'customers' => $this->trans('Customers (addresses, groups, carts, passwords)', [], 'Modules.Shopmigrator.Admin'),
            'cart_rules' => $this->trans('Cart rules (vouchers)', [], 'Modules.Shopmigrator.Admin'),
            'contacts' => $this->trans('Customer service contacts', [], 'Modules.Shopmigrator.Admin'),
            'employees' => $this->trans('Employees (existing ones are kept)', [], 'Modules.Shopmigrator.Admin'),
            'orders' => $this->trans('Orders (invoices, payments, history, credit slips, messages)', [], 'Modules.Shopmigrator.Admin'),
            'price_rules' => $this->trans('Catalog price rules', [], 'Modules.Shopmigrator.Admin'),
            'cms' => $this->trans('CMS pages and categories', [], 'Modules.Shopmigrator.Admin'),
            'images' => $this->trans('Images and thumbnails', [], 'Modules.Shopmigrator.Admin'),
        ];
    }

    private function ajax(): void
    {
        @set_time_limit(120);
        $runner = new Runner();
        try {
            $action = (string) Tools::getValue('sm_action');
            $out = match ($action) {
                'test' => $this->ajaxTest(),
                'start' => $this->ajaxStart($runner),
                'tick' => $runner->tick(6.0),
                'pause' => (function () use ($runner) { $runner->pause(); return $runner->progress(); })(),
                'resume' => (function () use ($runner) { $runner->resume(); return $runner->progress(); })(),
                'reset' => (function () use ($runner) { $runner->reset(); return $runner->progress(); })(),
                default => $runner->progress(),
            };
            $this->json(['ok' => true] + $out);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function ajaxTest(): array
    {
        $url = trim((string) Tools::getValue('sm_url'));
        $key = trim((string) Tools::getValue('sm_key'));
        if (!preg_match('#^https?://#i', $url) || strlen($key) < 32) {
            throw new \RuntimeException($this->trans('Enter the old shop address (starting with http:// or https://) and the 64-character key from Shop Migrator Bridge.', [], 'Modules.Shopmigrator.Admin'));
        }
        $client = new Client($url, $key);
        $info = $client->info();
        $counts = $client->count(array_values(array_intersect(['product', 'product_attribute', 'category', 'image', 'manufacturer', 'supplier', 'tax', 'customer', 'orders', 'carrier', 'cart_rule', 'contact', 'employee', 'specific_price_rule', 'cms'], $info['tables'])));
        Configuration::updateValue('SHOPMIGRATOR_URL', $url);
        Configuration::updateValue('SHOPMIGRATOR_KEY', $key);

        $missing = [];
        foreach ($info['languages'] as $l) {
            if (!Language::getIdByIso($l['iso_code'])) {
                $missing[] = $l['name'] . ' (' . $l['iso_code'] . ')';
            }
        }

        return ['info' => [
            'ps_version' => $info['ps_version'],
            'php_version' => $info['php_version'],
            'shop_name' => $info['shop_name'],
            'shop_url' => $info['shop_url'],
            'languages' => array_map(fn ($l) => $l['iso_code'], $info['languages']),
            'shops' => count($info['shops']),
            'missingLanguages' => $missing,
        ], 'counts' => $counts];
    }

    private function ajaxStart(Runner $runner): array
    {
        $types = array_values(array_intersect((array) Tools::getValue('sm_types'), array_keys(Plan::TYPES)));
        if (!$types) {
            throw new \RuntimeException($this->trans('Choose at least one type of data to migrate.', [], 'Modules.Shopmigrator.Admin'));
        }
        $runner->start(
            (string) Configuration::get('SHOPMIGRATOR_URL'),
            (string) Configuration::get('SHOPMIGRATOR_KEY'),
            $types,
            (int) Tools::getValue('sm_batch', 100),
            Tools::getValue('sm_mode') !== 'new',
            Tools::getValue('sm_thumbs') !== '0'
        );

        return $runner->progress();
    }

    private function json(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function renderAuthor(): string
    {
        $this->context->smarty->assign('author_img', $this->_path . 'views/img/author.jpg');

        return $this->display(__FILE__, 'views/templates/admin/author.tpl');
    }
}
