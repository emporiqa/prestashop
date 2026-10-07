<?php
/**
 * Locks in which shops become sync channels:
 *
 * - only active shops with a storefront URL: a shop with no main URL has no
 *   storefront, and every link PrestaShop builds for it lacks a host;
 * - a product or page row in a shop outside the synced set (no URL,
 *   inactive, deleted, or excluded by the merchant) never names a channel;
 * - two shops whose names slugify alike get distinct channels (the later
 *   one carries its id), so neither overwrites the other's data;
 * - a channel's currencies are the shop's active ones with an exchange rate
 *   there (its default currency needs none): PrestaShop converts a price to
 *   0 in a currency the shop has no rate for;
 * - a product names only the shops it is shown in: active there and not
 *   hidden everywhere (visibility "Nowhere"), as customer_prices checks.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PrestaShop classes are stubbed
 * and the real EmporiqaChannelResolver is loaded.
 *
 * Run: php tests/ChannelMappingTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');

class Context
{
    public $shop;
}

class Shop
{
    public $id;
    public $name;
    /** @var array<int, array<string, mixed>> */
    public static $rows = [];

    public function __construct($id = null)
    {
        $this->id = $id;
        $this->name = 'Shop ' . $id;
    }

    public static function isFeatureActive()
    {
        return true;
    }

    public static function getShops($active = true)
    {
        return self::$rows;
    }

    public function getBaseURL($ssl)
    {
        return 'https://shop.test/';
    }
}

class Language
{
    public static function getLanguages($active, $shopId)
    {
        return [];
    }
}

class Currency
{
    public static $rows = [];

    public static function getCurrenciesByIdShop($shopId)
    {
        return self::$rows;
    }
}

class Configuration
{
    public static $values = [];

    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null)
    {
        return self::$values[$key] ?? false;
    }

    public static function getGlobalValue($key)
    {
        return self::$values[$key] ?? false;
    }
}

class Tools
{
    public static function replaceAccentedChars($value)
    {
        return $value;
    }
}

class DbQuery
{
    /** @var string[] the where() conditions of the last query built */
    public static $wheres = [];

    public function select($v)
    {
        self::$wheres = [];
    }

    public function from($v, $alias = null)
    {
    }

    public function where($v)
    {
        self::$wheres[] = $v;
    }
}

class Db
{
    public static $rows = [];

    public static function getInstance()
    {
        return new self();
    }

    public function executeS($sql)
    {
        return self::$rows;
    }
}

require __DIR__ . '/../classes/EmporiqaChannelResolver.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

function resolver()
{
    EmporiqaChannelResolver::reset();
    $context = new Context();
    $context->shop = new Shop(1);

    return new EmporiqaChannelResolver($context);
}

// Shop::getShops(true) as PrestaShop returns it: deleted and inactive shops
// are already gone; shop 4 has no row in shop_url.
Shop::$rows = [
    1 => ['name' => 'PrestaShop', 'domain' => 'shop.test', 'domain_ssl' => 'shop.test'],
    2 => ['name' => 'French Shop', 'domain' => 'fr.shop.test', 'domain_ssl' => 'fr.shop.test'],
    4 => ['name' => 'Test', 'domain' => null, 'domain_ssl' => null],
];

echo "Scenario 1: a shop with no storefront URL is not a channel\n";
Configuration::$values = [];
$r = resolver();
check('mapped shops', $r->getMapping() === [1 => 'prestashop', 2 => 'french-shop']);
check('nor offered in the shop list', array_keys($r->getAllMapping()) === [1, 2]);
check('nor counted as enabled', !$r->isShopEnabled(4));

echo "Scenario 2: product rows in unsynced shops name no channel\n";
Db::$rows = [['id_shop' => 1], ['id_shop' => 3], ['id_shop' => 4]];
check('only the synced shop', resolver()->getProductChannels(5) === ['prestashop']);
check('pages too', resolver()->getPageChannels(5) === ['prestashop']);
Configuration::$values = [EmporiqaChannelResolver::ENABLED_SHOPS_KEY => '[2]'];
Db::$rows = [['id_shop' => 1], ['id_shop' => 2]];
check('nor a shop the merchant excluded', resolver()->getProductChannels(5) === ['french-shop']);

echo "Scenario 3: shops named alike keep their own channels\n";
$saved = Shop::$rows;
Shop::$rows = [
    1 => ['name' => 'My Shop', 'domain' => 'a.test', 'domain_ssl' => 'a.test'],
    3 => ['name' => 'My shop!', 'domain' => 'b.test', 'domain_ssl' => 'b.test'],
];
Configuration::$values = [];
check('the later one gets its id', resolver()->getAllMapping() === [1 => 'my-shop', 3 => 'my-shop-3']);
Shop::$rows = $saved;

echo "Scenario 4: a channel's currencies\n";
Configuration::$values = ['PS_CURRENCY_DEFAULT' => 1];
Currency::$rows = [
    ['id_currency' => 1, 'iso_code' => 'EUR', 'active' => 1, 'deleted' => 0, 'conversion_rate' => 0],
    ['id_currency' => 2, 'iso_code' => 'USD', 'active' => 1, 'deleted' => 0, 'conversion_rate' => 1.15],
    ['id_currency' => 3, 'iso_code' => 'GBP', 'active' => 1, 'deleted' => 0, 'conversion_rate' => 0],
    ['id_currency' => 4, 'iso_code' => 'CHF', 'active' => 0, 'deleted' => 0, 'conversion_rate' => 1],
    ['id_currency' => 5, 'iso_code' => 'JPY', 'active' => 1, 'deleted' => 1, 'conversion_rate' => 160],
];
$build = new ReflectionMethod('EmporiqaChannelResolver', 'buildShopContext');
$build->setAccessible(true);
$ctx = $build->invoke(resolver(), 2, 'french-shop', []);
check('the default and the rated active currencies only', array_column($ctx['currencies'], 'iso_code') === ['EUR', 'USD']);

echo "Scenario 5: a product names only the shops it is shown in\n";
Configuration::$values = [];
Db::$rows = [['id_shop' => 1]];
resolver()->getProductShopIds(5);
check('active and not visibility Nowhere', in_array("ps.active = 1 AND ps.visibility != 'none'", DbQuery::$wheres, true));
resolver()->getPageShopIds(5);
check('pages have no visibility', DbQuery::$wheres === ['cs.id_cms = 5']);

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
