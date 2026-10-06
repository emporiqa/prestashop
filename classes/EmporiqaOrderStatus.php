<?php
/**
 * The `order_status` ready-made rule: request fields in, response envelope out.
 *
 * Read-only. "No such order" and "the email or customer does not match" are
 * one answer on purpose, and required fields are checked before the lookup,
 * so the answer never reveals whether an order exists.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaOrderStatus
{
    public const REQUEST_TABLE = 'emporiqa_action_request';

    /** Emporiqa retries a call with the same request_id for up to 10 minutes. */
    public const DEDUPE_TTL_SECONDS = 600;

    public const RATE_TABLE = 'emporiqa_action_rate';

    /**
     * Design §5.1: a validly signed caller still must not walk order numbers
     * or emails. 10 lookups per value per 10-minute window is far above what
     * one shopper asking about one order needs; the shop ceiling caps the
     * total if the values are varied instead.
     */
    public const RATE_WINDOW_SECONDS = 600;

    public const RATE_PER_VALUE = 10;

    public const RATE_PER_SHOP = 300;

    /** Emporiqa reads at most 50 order lines. */
    public const MAX_ITEMS = 50;

    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * Count this call against its order number, its email and the shop, and
     * say which limit, if any, it is now over: null when under every limit,
     * scope "store" when the shop ceiling is hit (it then holds every value
     * back), "value" when only an order number or email is, and the seconds
     * until the window ends.
     *
     * Fails closed: a counter that cannot be written or read back counts as
     * the shop ceiling, since an uncounted lookup is exactly what the limit
     * exists to stop.
     *
     * @param string $orderNumber '' when not given
     * @param string $email '' when not given
     * @param int $shopId
     *
     * @return array{scope: string, retry_after: int}|null
     */
    public static function rateLimitHit($orderNumber, $email, $shopId)
    {
        $shopBucket = 'shop:' . (int) $shopId;
        $buckets = [$shopBucket => self::RATE_PER_SHOP];
        foreach (['order_number' => $orderNumber, 'email' => $email] as $name => $value) {
            $value = Tools::strtolower(trim((string) $value));
            if ($value !== '') {
                $buckets[$name . ':' . (int) $shopId . ':' . $value] = self::RATE_PER_VALUE;
            }
        }

        return self::countHits($buckets, $shopBucket);
    }

    /**
     * Count one hit on each bucket in the current window and say which
     * limit, if any, is now exceeded (see rateLimitHit). Shared by every
     * action of the endpoint, each with its own bucket names.
     *
     * @param array<string, int> $buckets bucket => limit per window
     * @param string $shopBucket the bucket whose excess holds every value back
     *
     * @return array{scope: string, retry_after: int}|null
     */
    public static function countHits(array $buckets, $shopBucket)
    {
        $now = time();
        $windowEnd = $now - ($now % self::RATE_WINDOW_SECONDS) + self::RATE_WINDOW_SECONDS;
        $table = '`' . _DB_PREFIX_ . self::RATE_TABLE . '`';
        $db = Db::getInstance();

        if (mt_rand(1, 20) === 1) {
            $db->execute('DELETE FROM ' . $table . ' WHERE `expires_at` <= ' . (int) $now);
        }

        $scope = null;
        foreach ($buckets as $bucket => $limit) {
            $hash = pSQL(hash('sha256', $bucket . '|' . $windowEnd));
            $written = $db->execute(
                'INSERT INTO ' . $table . ' (`bucket_hash`, `hits`, `expires_at`) VALUES ("' . $hash . '", 1, '
                . (int) $windowEnd . ') ON DUPLICATE KEY UPDATE `hits` = `hits` + 1',
            );
            $hits = $written
                ? (int) $db->getValue('SELECT `hits` FROM ' . $table . ' WHERE `bucket_hash` = "' . $hash . '"', false)
                : 0;
            if ($hits < 1) {
                $scope = 'store';
            } elseif ($hits > $limit) {
                $scope = $bucket === $shopBucket ? 'store' : ($scope ?? 'value');
            }
        }

        return $scope === null ? null : ['scope' => $scope, 'retry_after' => max(1, $windowEnd - $now)];
    }

    /**
     * The answer already given to this request_id, if any.
     *
     * @return array{0: int, 1: string}|null http code and raw body
     */
    public static function remembered($requestId)
    {
        $row = Db::getInstance()->getRow(
            'SELECT `http_code`, `response` FROM `' . _DB_PREFIX_ . self::REQUEST_TABLE . '` '
            . 'WHERE `request_hash` = "' . pSQL(hash('sha256', $requestId)) . '" '
            . 'AND `created_at` >= ' . (int) (time() - self::DEDUPE_TTL_SECONDS),
        );

        return is_array($row) ? [(int) $row['http_code'], (string) $row['response']] : null;
    }

    public static function remember($requestId, $httpCode, $body)
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::REQUEST_TABLE . '` '
            . 'WHERE `created_at` < ' . (int) (time() - self::DEDUPE_TTL_SECONDS),
        );
        Db::getInstance()->insert(self::REQUEST_TABLE, [
            'request_hash' => pSQL(hash('sha256', $requestId)),
            'http_code' => (int) $httpCode,
            'response' => pSQL($body, true),
            'created_at' => time(),
        ], false, true, Db::REPLACE);
    }

    /**
     * A request field as a trimmed string, '' when missing or not scalar.
     *
     * @param array $payload decoded, signature-verified request body
     * @param string $name
     *
     * @return string
     */
    public static function field(array $payload, $name)
    {
        $value = isset($payload['fields'][$name]) ? $payload['fields'][$name] : null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array $payload decoded, signature-verified request body
     *
     * @return array response envelope
     */
    public function handle(array $payload)
    {
        $customerId = 0;
        if (isset($payload['customer']['id']) && is_scalar($payload['customer']['id'])) {
            $customerId = (int) $payload['customer']['id'];
        }

        $orderNumber = self::field($payload, 'order_number');
        $email = self::field($payload, 'email');

        // The email proves the order unless a verified customer stands in for it.
        $missing = [];
        if ($orderNumber === '') {
            $missing[] = 'order_number';
        }
        if ($email === '' && $customerId <= 0) {
            $missing[] = 'email';
        }
        if ($missing) {
            return ['status' => 'rejected', 'ask' => $missing, 'message_code' => 'missing_field'];
        }
        if (Tools::strlen($orderNumber) > 64 || ($email !== '' && !Validate::isEmail($email))) {
            return ['status' => 'rejected', 'message_code' => 'invalid_field'];
        }

        $orders = $this->findOrders($orderNumber, $email, $customerId);
        if (empty($orders)) {
            return ['status' => 'not_found'];
        }
        // A split checkout gives several orders one reference: the newest
        // carries the status, and every one of them its tracking.
        $order = $orders[0];

        $data = $this->buildData($order, $orders);

        // Runs after every key is filled, so a module can change any of them.
        // Fields of its own go under `extra` (string keys; string, number,
        // bool or nested values; Emporiqa keeps 30 keys, 3 levels, 500
        // characters a string); other unknown keys are dropped. Example:
        //   public function hookActionEmporiqaOrderStatus(array $params)
        //   {
        //       $params['data']['extra']['gift_message'] = 'Happy birthday';
        //   }
        Hook::exec('actionEmporiqaOrderStatus', [
            'data' => &$data,
            'order' => $order,
        ]);

        return ['status' => 'found', 'data' => $data];
    }

    /**
     * Orders with this reference (or id) in an enabled shop that the caller
     * owns, newest first.
     *
     * @return Order[]
     */
    private function findOrders($orderNumber, $email, $customerId)
    {
        $candidates = [];
        foreach (Order::getByReference($orderNumber) as $order) {
            if ($order instanceof Order) {
                $candidates[] = $order;
            }
        }
        if (empty($candidates) && ctype_digit($orderNumber)) {
            $order = new Order((int) $orderNumber);
            if (Validate::isLoadedObject($order)) {
                $candidates[] = $order;
            }
        }

        $shops = (new EmporiqaChannelResolver($this->context))->getMapping();
        $orders = [];
        foreach ($candidates as $order) {
            if (isset($shops[(int) $order->id_shop]) && $this->ownedBy($order, $email, $customerId)) {
                $orders[] = $order;
            }
        }
        usort($orders, function ($a, $b) {
            $byDate = strcmp((string) $b->date_add, (string) $a->date_add);

            return $byDate !== 0 ? $byDate : (int) $b->id - (int) $a->id;
        });

        return $orders;
    }

    /**
     * With an email, the order's customer email must match it, whoever is
     * signed in: a signed-in shopper looking up a guest order they placed
     * proves it the same way as when signed out. Without one, the verified
     * customer must be the order's customer.
     */
    private function ownedBy(Order $order, $email, $customerId)
    {
        if ((int) $order->id_customer <= 0) {
            return false;
        }
        if ($email === '') {
            return $customerId > 0 && (int) $order->id_customer === $customerId;
        }
        $customer = new Customer((int) $order->id_customer);

        return Validate::isLoadedObject($customer)
            && Tools::strtolower((string) $customer->email) === Tools::strtolower($email);
    }

    /**
     * `data` per the catalog schema: status_code, status_label, placed_at,
     * tracking, then the order's details (orderDetails).
     *
     * @param Order $order the order whose status is reported
     * @param Order[] $orders every order sharing its reference, for tracking
     */
    private function buildData(Order $order, array $orders)
    {
        $langId = (int) $order->id_lang;
        $state = new OrderState((int) $order->getCurrentState(), $langId);
        $label = '';
        if (Validate::isLoadedObject($state)) {
            $label = is_array($state->name) ? (string) reset($state->name) : (string) $state->name;
        }

        $data = [
            'status_code' => $this->statusCode($state),
            'status_label' => $label,
            'placed_at' => date('c', strtotime($order->date_add)),
            'tracking' => [],
        ];

        $seen = [];
        foreach ($orders as $each) {
            foreach ($this->trackingEntries($each) as $entry) {
                if (!isset($seen[$entry['number']]) && count($data['tracking']) < 10) {
                    $seen[$entry['number']] = true;
                    $data['tracking'][] = $entry;
                }
            }
        }

        return $data + $this->orderDetails($order, $orders, $state);
    }

    /**
     * What the shopper's order page shows: number, name, items, totals,
     * payment, carrier and addresses. A split checkout is one purchase to the
     * shopper, so items and totals cover every order sharing the reference.
     * Amounts are in the order's currency, tax included unless the customer's
     * group is shown prices tax excluded; `total` is always what is paid, so
     * subtotal - discount + shipping + fees is the total (plus tax when tax
     * is excluded). Gift wrapping is the `fees`.
     * Empty values are left out. No ids and no email.
     *
     * @param Order $order the newest order with the reference
     * @param Order[] $orders every order sharing its reference
     * @param OrderState $state the current state of $order
     *
     * @return array<string, mixed>
     */
    private function orderDetails(Order $order, array $orders, OrderState $state)
    {
        $langId = (int) $order->id_lang;
        $currency = new Currency((int) $order->id_currency);
        $precision = Validate::isLoadedObject($currency) ? (int) $currency->precision : 2;
        $taxIncluded = (int) $order->getTaxCalculationMethod() !== (int) PS_TAX_EXC;
        $amount = function ($value) use ($precision) {
            return round((float) $value, $precision);
        };

        $details = ['order_number' => (string) $order->reference];

        $customer = new Customer((int) $order->id_customer);
        if (Validate::isLoadedObject($customer)) {
            $details['customer_name'] = trim($customer->firstname . ' ' . $customer->lastname);
        }
        if (Validate::isLoadedObject($currency)) {
            $details['currency'] = (string) $currency->iso_code;
        }

        $items = [];
        $totals = ['subtotal' => 0.0, 'shipping' => 0.0, 'fees' => 0.0, 'tax' => 0.0, 'discount' => 0.0, 'total' => 0.0];
        $carriers = [];
        foreach (array_reverse($orders) as $each) {
            foreach ((array) $each->getProductsDetail() as $line) {
                if (count($items) < self::MAX_ITEMS) {
                    $items[] = $this->item($line, $langId, $taxIncluded, $amount);
                }
            }
            $totals['subtotal'] += (float) ($taxIncluded ? $each->total_products_wt : $each->total_products);
            $totals['shipping'] += (float) ($taxIncluded ? $each->total_shipping_tax_incl : $each->total_shipping_tax_excl);
            $totals['discount'] += (float) ($taxIncluded ? $each->total_discounts_tax_incl : $each->total_discounts_tax_excl);
            // Gift wrapping is PrestaShop's only order fee.
            $totals['fees'] += (float) ($taxIncluded ? $each->total_wrapping_tax_incl : $each->total_wrapping_tax_excl);
            $totals['tax'] += (float) $each->total_paid_tax_incl - (float) $each->total_paid_tax_excl;
            $totals['total'] += (float) $each->total_paid_tax_incl;
            $name = EmporiqaOrderFormatter::carrierLabels($each)['name'];
            if ($name !== '') {
                $carriers[$name] = true;
            }
        }
        foreach (['discount', 'fees'] as $optional) {
            if ($totals[$optional] <= 0) {
                unset($totals[$optional]);
            }
        }
        if ($items) {
            $details['items'] = $items;
        }
        $details['totals'] = array_map($amount, $totals);

        if (trim((string) $order->payment) !== '') {
            $details['payment_method'] = trim((string) $order->payment);
        }
        $paymentStatus = $this->paymentStatus($order, $state);
        if ($paymentStatus !== '') {
            $details['payment_status'] = $paymentStatus;
        }
        if ($carriers) {
            $details['shipping_method'] = implode(', ', array_keys($carriers));
        }
        $delay = EmporiqaOrderFormatter::carrierLabels($order)['delay'];
        if ($delay !== '') {
            $details['delivery_time'] = $delay;
        }

        foreach (['shipping_address' => $order->id_address_delivery, 'billing_address' => $order->id_address_invoice] as $key => $idAddress) {
            $address = EmporiqaOrderFormatter::addressFields((int) $idAddress, $langId);
            if ($address) {
                $details[$key] = $address;
            }
        }

        return $details;
    }

    /**
     * One order line: the name as ordered (with its combination, as the
     * invoice shows it), the combination alone as `variant`.
     *
     * @param array $line an order_detail row
     * @param int $langId
     * @param bool $taxIncluded
     * @param callable $amount rounds to the currency's precision
     *
     * @return array<string, mixed>
     */
    private function item(array $line, $langId, $taxIncluded, callable $amount)
    {
        $item = [
            'name' => trim((string) ($line['product_name'] ?? '')),
            'sku' => trim((string) ($line['product_reference'] ?? '')),
            'quantity' => (int) ($line['product_quantity'] ?? 0),
            'unit_price' => $amount($taxIncluded ? ($line['unit_price_tax_incl'] ?? 0) : ($line['unit_price_tax_excl'] ?? 0)),
            'total_price' => $amount($taxIncluded ? ($line['total_price_tax_incl'] ?? 0) : ($line['total_price_tax_excl'] ?? 0)),
        ];
        if ($item['sku'] === '') {
            unset($item['sku']);
        }
        $variant = $this->variant($line, $langId);
        if ($variant !== '') {
            $item['variant'] = $variant;
        }

        return $item;
    }

    /**
     * The combination label of an order line. PrestaShop writes it into the
     * line's name after the product name ("T-shirt - Color : White, Size : S",
     * or "T-shirt (Size: S - Color: White)" depending on the version), so it is
     * that remainder; when the product was renamed since, the combination's
     * current attributes in the order's language.
     *
     * @param array $line an order_detail row
     * @param int $langId
     *
     * @return string '' for a product without combinations
     */
    private function variant(array $line, $langId)
    {
        $idCombination = (int) ($line['product_attribute_id'] ?? 0);
        if ($idCombination <= 0) {
            return '';
        }
        $name = trim((string) ($line['product_name'] ?? ''));
        $base = trim((string) Product::getProductName((int) ($line['product_id'] ?? 0), null, (int) $langId));
        if ($base !== '' && strpos($name, $base) === 0) {
            $rest = trim(substr($name, strlen($base)), " -\t");
            if (preg_match('/^\((.*)\)$/s', $rest, $inner)) {
                $rest = trim($inner[1]);
            }
            if ($rest !== '') {
                return $rest;
            }
        }

        $rows = Db::getInstance()->executeS(
            'SELECT agl.`public_name` AS `group_name`, al.`name` AS `value` '
            . 'FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac '
            . 'JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute` '
            . 'JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . (int) $langId . ' '
            . 'JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl ON agl.`id_attribute_group` = a.`id_attribute_group` AND agl.`id_lang` = ' . (int) $langId . ' '
            . 'WHERE pac.`id_product_attribute` = ' . $idCombination . ' ORDER BY a.`id_attribute_group` ASC',
        );
        $parts = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $parts[] = $row['group_name'] . ' : ' . $row['value'];
        }

        return implode(', ', $parts);
    }

    /**
     * "refunded", "paid" or "pending"; '' for a cancelled or failed order
     * that was never paid, where neither word is true.
     */
    private function paymentStatus(Order $order, OrderState $state)
    {
        $code = $this->statusCode($state);
        if ($code === 'refunded') {
            return 'refunded';
        }
        if ((Validate::isLoadedObject($state) && $state->paid) || $order->hasBeenPaid()) {
            return 'paid';
        }

        return in_array($code, ['cancelled', 'failed'], true) ? '' : 'pending';
    }

    /**
     * OrderCarrier's number, then those of PrestaShop 9.2's shipments
     * (EmporiqaOrderFormatter::shipmentTracking), each with its carrier.
     * Same carrier lookup as the legacy formatter, which documents why the
     * language-scoped load can fail in multistore.
     *
     * @return array<int, array{carrier: string, number: string, url?: string}>
     */
    private function trackingEntries(Order $order)
    {
        $entries = [];
        $idOrderCarrier = (int) $order->getIdOrderCarrier();
        if ($idOrderCarrier) {
            $orderCarrier = new OrderCarrier($idOrderCarrier);
            if (Validate::isLoadedObject($orderCarrier) && (string) $orderCarrier->tracking_number !== '') {
                $entries[] = $this->trackingEntry((int) $order->id_carrier, (string) $orderCarrier->tracking_number);
            }
        }
        foreach (EmporiqaOrderFormatter::shipmentTracking((int) $order->id) as $shipment) {
            $entries[] = $this->trackingEntry(
                $shipment['id_carrier'] ?: (int) $order->id_carrier,
                $shipment['number'],
            );
        }

        return $entries;
    }

    /**
     * @param int $idCarrier
     * @param string $number
     *
     * @return array{carrier: string, number: string, url?: string}
     */
    private function trackingEntry($idCarrier, $number)
    {
        $carrier = new Carrier((int) $idCarrier);
        $carrierName = '';
        $url = '';
        if (Validate::isLoadedObject($carrier)) {
            $carrierName = EmporiqaOrderFormatter::carrierName($carrier);
            if (!empty($carrier->url)) {
                $url = str_replace('@', rawurlencode($number), (string) $carrier->url);
            }
        }
        $entry = ['carrier' => $carrierName, 'number' => $number];
        if (preg_match('#^https://#i', $url)) {
            $entry['url'] = $url;
        }

        return $entry;
    }

    private function statusCode(OrderState $state)
    {
        if (!Validate::isLoadedObject($state)) {
            return 'pending';
        }
        $byConfig = [
            'PS_OS_CANCELED' => 'cancelled',
            'PS_OS_REFUND' => 'refunded',
            'PS_OS_ERROR' => 'failed',
            'PS_OS_DELIVERED' => 'delivered',
            'PS_OS_SHIPPING' => 'shipped',
            'PS_OS_PREPARATION' => 'processing',
            'PS_OS_PAYMENT' => 'processing',
            'PS_OS_WS_PAYMENT' => 'processing',
            'PS_OS_OUTOFSTOCK_PAID' => 'on_hold',
            'PS_OS_OUTOFSTOCK_UNPAID' => 'pending_payment',
            'PS_OS_CHEQUE' => 'pending_payment',
            'PS_OS_BANKWIRE' => 'pending_payment',
            'PS_OS_COD_VALIDATION' => 'pending_payment',
        ];
        foreach ($byConfig as $key => $code) {
            if ((int) Configuration::get($key) === (int) $state->id) {
                return $code;
            }
        }

        // A merchant's own state: its flags are all we know.
        return $state->shipped ? 'shipped' : 'processing';
    }
}
