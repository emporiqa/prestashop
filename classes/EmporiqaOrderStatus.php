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
     * `data` per the catalog schema: status_code, status_label, placed_at, tracking.
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

        return $data;
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
