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

    /** Tables whose rows count as this type's records in the report (images: every file). */
    public const RECORDS = [
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
        'products' => ['taxes', 'manufacturers', 'suppliers', 'categories', 'attributes'],
        // An order points at its customer, products and carrier; keep them consistent.
        'orders' => ['customers', 'products', 'carriers', 'cart_rules', 'contacts', 'taxes', 'manufacturers', 'suppliers', 'categories', 'attributes'],
        'carriers' => ['taxes'],
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
