<?php
/**
 * Shop Migrator - Upgrade to PrestaShop 9 by Data Transfer
 *
 * @author    iamshoaibktk@gmail.com (WhatsApp: +923459016286)
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace Iamshoaibktk\Shopmigrator;

/**
 * What each data type copies, table by table, in order. Tables missing on either shop are skipped,
 * so one plan covers PrestaShop 1.6, 1.7, 8 and 9.
 */
class Plan
{
    public const TYPES = [
        // Not copied table by table: matched to this shop's own countries, zones and states by ISO
        // code and name first (see Geo), so tax rules, carrier zones, addresses and cart rules can
        // be translated to this shop's IDs as they are copied.
        'geo' => ['zone', 'zone_shop', 'country', 'country_lang', 'country_shop', 'state', 'address_format'],
        'taxes' => ['tax', 'tax_lang', 'tax_rules_group', 'tax_rules_group_shop', 'tax_rule'],
        'manufacturers' => ['manufacturer', 'manufacturer_lang', 'manufacturer_shop'],
        'suppliers' => ['supplier', 'supplier_lang', 'supplier_shop'],
        'categories' => ['category', 'category_lang', 'category_shop', 'category_group'],
        'attributes' => [
            'attribute_group', 'attribute_group_lang', 'attribute_group_shop', 'attribute', 'attribute_lang', 'attribute_shop',
            'feature', 'feature_lang', 'feature_shop', 'feature_value', 'feature_value_lang',
        ],
        'products' => [
            'product', 'product_lang', 'product_shop', 'category_product',
            'product_attribute', 'product_attribute_shop', 'product_attribute_combination', 'product_attribute_image',
            'stock_available', 'specific_price', 'specific_price_priority', 'feature_product', 'product_supplier',
            'tag', 'product_tag', 'accessory', 'pack', 'customization_field', 'customization_field_lang',
            'image', 'image_lang', 'image_shop',
        ],
        'carriers' => [
            'carrier', 'carrier_lang', 'carrier_shop', 'carrier_group', 'carrier_zone', 'carrier_tax_rules_group_shop',
            'range_price', 'range_weight', 'delivery',
        ],
        'customers' => [
            'group', 'group_lang', 'group_shop', 'group_reduction',
            'customer', 'customer_group', 'address', 'cart', 'cart_product',
        ],
        'cart_rules' => [
            'cart_rule', 'cart_rule_lang', 'cart_rule_shop', 'cart_rule_country', 'cart_rule_group', 'cart_rule_carrier',
            'cart_rule_combination', 'cart_rule_product_rule_group', 'cart_rule_product_rule', 'cart_rule_product_rule_value',
            'cart_cart_rule',
        ],
        'contacts' => ['contact', 'contact_lang', 'contact_shop'],
        // Never emptied: existing employees (you) stay, only new ones are added.
        'employees' => ['employee', 'employee_shop'],
        'orders' => [
            'order_state', 'order_state_lang',
            'orders', 'order_detail', 'order_detail_tax', 'order_history', 'order_carrier', 'order_cart_rule',
            'order_invoice', 'order_invoice_payment', 'order_invoice_tax', 'order_payment',
            'order_slip', 'order_slip_detail', 'order_return', 'order_return_detail',
            'message', 'customer_thread', 'customer_message',
        ],
        'price_rules' => ['specific_price_rule', 'specific_price_rule_condition_group', 'specific_price_rule_condition'],
        'cms' => ['cms_category', 'cms_category_lang', 'cms_category_shop', 'cms', 'cms_lang', 'cms_shop'],
    ];

    /** Tables that only get rows whose ID and email are new here (see Runner::keepExisting). */
    public const INSERT_ONLY = ['employee', 'employee_shop'];

    /**
     * Tables whose id_lang is a person's preferred language, not the language of a text: a row
     * whose language the old shop deleted (or 0) gets the default language instead of being
     * left out. Texts in a language this shop doesn't have are still left out.
     */
    public const LANG_FALLBACK = ['customer', 'cart', 'orders', 'customer_thread', 'employee'];

    /**
     * Rules that apply to a country, state or zone: a row pointing at one the old shop deleted
     * never applied there, and its old ID may now be another country here (PrestaShop's own Spain
     * #6, say), so it is left out rather than applied to the wrong place.
     */
    public const GEO_RULES = ['tax_rule', 'carrier_zone', 'delivery', 'cart_rule_country', 'specific_price', 'specific_price_rule'];

    /**
     * PrestaShop 1.7 renamed most native modules: old name => new name, so customer-group access
     * and payment restrictions set on a 1.6 shop apply to the same module here.
     */
    public const MODULE_ALIASES = [
        'bankwire' => 'ps_wirepayment',
        'cheque' => 'ps_checkpayment',
        'cashondelivery' => 'ps_cashondelivery',
        'blocklayered' => 'ps_facetedsearch',
        'blockcart' => 'ps_shoppingcart',
        'blocksearch' => 'ps_searchbar',
        'blocknewsletter' => 'ps_emailsubscription',
        'homeslider' => 'ps_imageslider',
        'blockcategories' => 'ps_categorytree',
        'blocklanguages' => 'ps_languageselector',
        'blockcurrencies' => 'ps_currencyselector',
        'blockuserinfo' => 'ps_customersignin',
        'blockmyaccount' => 'ps_customeraccountlinks',
        'blocksocial' => 'ps_socialfollow',
        'blockcontact' => 'ps_contactinfo',
        'blockbestsellers' => 'ps_bestsellers',
        'blocknewproducts' => 'ps_newproducts',
        'blockspecials' => 'ps_specials',
        'homefeatured' => 'ps_featuredproducts',
        'blocktopmenu' => 'ps_mainmenu',
        'blockmanufacturer' => 'ps_brandlist',
        'blocksupplier' => 'ps_supplierlist',
        'blockviewed' => 'ps_viewedproduct',
        'blockcmsinfo' => 'ps_customtext',
        'blockbanner' => 'ps_banner',
        'blockcms' => 'ps_linklist',
    ];

    /** Tables whose rows count as this type's records in the report (images: every file). */
    public const RECORDS = [
        'geo' => ['country'],
        'taxes' => ['tax'],
        'manufacturers' => ['manufacturer'],
        'suppliers' => ['supplier'],
        'categories' => ['category'],
        'attributes' => ['attribute', 'feature_value'],
        'products' => ['product'],
        'carriers' => ['carrier'],
        'customers' => ['customer'],
        'orders' => ['orders'],
        'cart_rules' => ['cart_rule'],
        'contacts' => ['contact'],
        'employees' => ['employee'],
        'price_rules' => ['specific_price_rule'],
        'cms' => ['cms', 'cms_category'],
        'images' => ['manufacturer', 'supplier', 'category', 'image', 'carrier'],
    ];

    /** Data types that need another one (selecting products selects categories, taxes…). */
    public const NEEDS = [
        // Needs are resolved one level deep, so 'geo' is listed wherever countries or zones are referenced.
        'taxes' => ['geo'],
        'products' => ['geo', 'taxes', 'manufacturers', 'suppliers', 'categories', 'attributes'],
        // An order points at its customer, products and carrier; keep them consistent.
        'orders' => ['geo', 'customers', 'products', 'carriers', 'cart_rules', 'contacts', 'taxes', 'manufacturers', 'suppliers', 'categories', 'attributes'],
        'carriers' => ['geo', 'taxes'],
        'customers' => ['geo'],
        'cart_rules' => ['geo'],
        // Catalog price rules can be limited to a country.
        'price_rules' => ['geo'],
    ];

    /** Image files copied after the data: type => [image kind on the old shop, table, id column]. */
    public const IMAGES = [
        'manufacturers' => ['m', 'manufacturer', 'id_manufacturer'],
        'suppliers' => ['su', 'supplier', 'id_supplier'],
        'categories' => ['c', 'category', 'id_category'],
        'products' => ['p', 'image', 'id_image'],
        'carriers' => ['s', 'carrier', 'id_carrier'],
    ];

    /** Columns renamed between PrestaShop versions: old name => new name. */
    public const RENAMED = [
        'product' => ['id_product_redirected' => 'id_type_redirected'],
        'product_shop' => ['id_product_redirected' => 'id_type_redirected'],
    ];

    /** @return string[] selected types plus what they need, in plan order */
    public static function resolve(array $selected): array
    {
        $all = $selected;
        foreach ($selected as $type) {
            foreach (self::NEEDS[$type] ?? [] as $need) {
                $all[] = $need;
            }
        }

        return array_values(array_filter(array_keys(self::TYPES), fn ($t) => in_array($t, $all, true)));
    }

    /** @return string[] */
    public static function tables(array $types): array
    {
        $out = [];
        foreach ($types as $type) {
            foreach (self::TYPES[$type] ?? [] as $table) {
                $out[] = $table;
            }
        }

        return $out;
    }
}
