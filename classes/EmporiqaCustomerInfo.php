<?php
/**
 * The `customer_info` action: who the signed-in shopper is.
 *
 * Read-only. For a customer id from a token Emporiqa verified, the name and
 * email on the account (not on an order) and the account's newest placed
 * orders in the shops synced to Emporiqa, so the chat can answer "where is
 * my order?" without a number and pass a handoff on without asking for a
 * name and email. Nothing else: no address, phone, group or internal id.
 * Guest orders placed with the same email are not the account's and are
 * left out, as Order status proves an order.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaCustomerInfo
{
    public const MAX_ORDERS = 10;

    public const RATE_PER_CUSTOMER = 30;

    public const RATE_PER_SHOP = 600;

    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * Count this call against its customer and the shop (10-minute window,
     * fails closed, as for order_status).
     *
     * @param array $payload decoded, signature-verified request body
     * @param int $shopId
     *
     * @return array{scope: string, retry_after: int}|null
     */
    public static function rateLimitHit(array $payload, $shopId)
    {
        $shopBucket = 'info_shop:' . (int) $shopId;
        $buckets = [$shopBucket => self::RATE_PER_SHOP];
        $customerId = self::customerId($payload);
        if ($customerId > 0) {
            $buckets['info_customer:' . (int) $shopId . ':' . $customerId] = self::RATE_PER_CUSTOMER;
        }

        return EmporiqaOrderStatus::countHits($buckets, $shopBucket);
    }

    /**
     * @param array $payload decoded, signature-verified request body
     *
     * @return array response envelope
     */
    public function handle(array $payload)
    {
        $customerId = self::customerId($payload);
        if ($customerId <= 0) {
            return ['status' => 'rejected', 'message_code' => 'missing_field'];
        }

        $shopIds = array_map('intval', array_keys((new EmporiqaChannelResolver($this->context))->getMapping()));
        $customer = new Customer($customerId);
        if (!Validate::isLoadedObject($customer) || !$customer->active || $customer->deleted || $customer->is_guest
            || !array_intersect($shopIds, array_map('intval', Shop::getSharedShops((int) $customer->id_shop, Shop::SHARE_CUSTOMER)))
        ) {
            return ['status' => 'not_found'];
        }

        $data = [
            'customer' => $this->account($customer),
            'orders' => $this->orders($customerId, $shopIds),
        ];

        // Runs after both parts are filled, so a module can change or remove
        // any of it. Fields of its own go under `extra`, with the same bounds
        // as Order status. Example:
        //   public function hookActionEmporiqaCustomerInfo(array $params)
        //   {
        //       $params['data']['extra']['loyalty_points'] = 120;
        //   }
        Hook::exec('actionEmporiqaCustomerInfo', [
            'data' => &$data,
            'customer' => $customer,
        ]);

        return ['status' => 'found', 'data' => $data];
    }

    /**
     * The name and email on the account, empty ones left out.
     *
     * @return array<string, string>|stdClass
     */
    private function account(Customer $customer)
    {
        $first = trim((string) $customer->firstname);
        $last = trim((string) $customer->lastname);
        $account = array_filter([
            'name' => trim($first . ' ' . $last),
            'first_name' => $first,
            'last_name' => $last,
            'email' => trim((string) $customer->email),
        ], function ($value) {
            return $value !== '';
        });

        return $account ?: new stdClass();
    }

    /**
     * The customer's newest placed orders in the synced shops, one per
     * reference (a split checkout is one purchase, as in Order status: its
     * newest order's status, the total of all of them), newest first.
     *
     * @param int $customerId
     * @param int[] $shopIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function orders($customerId, array $shopIds)
    {
        if (empty($shopIds)) {
            return [];
        }
        // A split checkout is at most a few orders, so 5 rows per answer is plenty.
        $rows = Db::getInstance()->executeS(
            'SELECT `id_order`, `reference` FROM `' . _DB_PREFIX_ . 'orders`'
            . ' WHERE `id_customer` = ' . (int) $customerId
            . ' AND `id_shop` IN (' . implode(',', array_map('intval', $shopIds)) . ')'
            . ' ORDER BY `date_add` DESC, `id_order` DESC LIMIT ' . (self::MAX_ORDERS * 5),
        );

        $byReference = [];
        foreach ($rows ?: [] as $row) {
            $reference = (string) $row['reference'];
            $key = $reference !== '' ? $reference : '#' . (int) $row['id_order'];
            if (!isset($byReference[$key]) && count($byReference) >= self::MAX_ORDERS) {
                continue;
            }
            $byReference[$key][] = (int) $row['id_order'];
        }

        $orders = [];
        foreach ($byReference as $ids) {
            $entry = $this->order($ids);
            if ($entry !== null) {
                $orders[] = $entry;
            }
        }

        return $orders;
    }

    /**
     * One purchase: the orders sharing a reference, the newest first.
     *
     * @param int[] $ids
     *
     * @return array<string, mixed>|null
     */
    private function order(array $ids)
    {
        $order = new Order((int) $ids[0]);
        if (!Validate::isLoadedObject($order)) {
            return null;
        }
        $currency = new Currency((int) $order->id_currency);
        $precision = Validate::isLoadedObject($currency) ? (int) $currency->precision : 2;
        $total = 0.0;
        foreach ($ids as $id) {
            $each = $id === (int) $order->id ? $order : new Order($id);
            $total += (float) $each->total_paid_tax_incl;
        }

        $state = new OrderState((int) $order->getCurrentState(), (int) $order->id_lang);
        $label = '';
        if (Validate::isLoadedObject($state)) {
            $label = is_array($state->name) ? (string) reset($state->name) : (string) $state->name;
        }

        $entry = [
            'order_number' => (string) $order->reference !== '' ? (string) $order->reference : (string) $order->id,
            'placed_at' => date('c', strtotime($order->date_add)),
            'status_code' => EmporiqaOrderStatus::statusCode($state),
            'status_label' => $label,
            'total' => round($total, $precision),
            'currency' => Validate::isLoadedObject($currency) ? Tools::strtoupper((string) $currency->iso_code) : '',
        ];

        return array_filter($entry, function ($value) {
            return $value !== '';
        });
    }

    private static function customerId(array $payload)
    {
        $id = isset($payload['customer']['id']) ? $payload['customer']['id'] : null;

        return is_scalar($id) && ctype_digit((string) $id) ? (int) $id : 0;
    }
}
