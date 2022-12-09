<?php
namespace Opencart\Catalog\Model\Extension\Multisafepay\Payment;

require_once(DIR_EXTENSION . 'multisafepay/system/library/multisafepay.php');

use Opencart\System\Engine\Model;

class Multisafepay extends Model {

    protected $registry;

    private string $route;
    private string $key_prefix;

    public function __construct($registry) {
        parent::__construct($registry);
        $this->registry->set('multisafepay', new \Opencart\System\Library\Multisafepay($registry));
        $this->route = $this->multisafepay->route;
        $this->key_prefix = $this->multisafepay->key_prefix;
    }

    /**
     * Retrieves MultiSafepay as payment method
     *
     * @return bool|array
     */
    public function getMethod(): bool|array
    {
        if (!$this->config->get($this->key_prefix . 'multisafepay_status')) {
            return false;
        }

        return array(
            'code' => 'multisafepay',
            'title' => 'MultiSafepay',
            'sort_order' => $this->config->get($this->key_prefix . 'multisafepay_sort_order')
        );
    }

    /**
     * Retrieves allowed MultiSafepay payment methods
     *
     * @param array $address
     * @param float $total
     *
     * @return bool|array $method_data
     *
     * phpcs:disabled ObjectCalisthenics.Metrics.MaxNestingLevel
     */
    public function getMethods(array $address = array(), float $total = 0.0): bool|array
    {
        if (($total <= 0.0) || !$this->config->get($this->key_prefix . 'multisafepay_status')) {
            return false;
        }

        $this->load->language($this->route);
        $this->load->model('localisation/currency');
        $this->registry->set('multisafepay', new \Opencart\System\Library\Multisafepay($this->registry));
        $gateways = $this->multisafepay->getOrderedGateways((int)$this->config->get('config_store_id'));

        $methods_data = array();

        foreach ($gateways as $gateway) {
            // if enable
            if (!$this->config->get($this->key_prefix . 'multisafepay_' . $gateway['code'] . '_status')) {
                continue;
            }

            // if order amount is higher than minimum amount
            if ($this->config->get($this->key_prefix . 'multisafepay_' . $gateway['code'] . '_min_amount') > 0 && $this->config->get($this->key_prefix . 'multisafepay_' . $gateway['code'] . '_min_amount') > $total) {
                continue;
            }

            // if order amount is lower than maximum amount
            if ($this->config->get($this->key_prefix . 'multisafepay_' . $gateway['code'] . '_max_amount') > 0 && $this->config->get($this->key_prefix . 'multisafepay_' . $gateway['code'] . '_max_amount') < $total) {
                continue;
            }

            // if order currency
            $currencies = $this->config->get($this->key_prefix . 'multisafepay_' . $gateway['code'] . '_currency');
            $currency_info = $this->model_localisation_currency->getCurrencyByCode($this->session->data['currency']);
            if ($this->config->get($this->key_prefix . 'multisafepay_' . $gateway['code'] . '_currency') && !in_array($currency_info['currency_id'], $currencies)) {
                continue;
            }

            // if customer belongs to customer group set for this payment method
            $allowed_customer_groups_id = $this->config->get($this->key_prefix . 'multisafepay_' . $gateway['code'] . '_customer_group_id');
            $customer_group_id = ($this->customer->getGroupId()) ?: (int)$this->config->get('config_customer_group_id');

            if ($this->config->get($this->key_prefix . 'multisafepay_' . $gateway['code'] . '_customer_group_id') && !in_array($customer_group_id, $allowed_customer_groups_id)) {
                continue;
            }

            $country_id = !empty($address['country_id']) ? (int)$address['country_id'] : 0;
            $zone_id = !empty($address['zone_id']) ? (int)$address['zone_id'] : 0;

            $query = $this->db->query(
                "SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone`
                WHERE `geo_zone_id` = '" . (int)$this->config->get($this->key_prefix . 'multisafepay_' . $gateway['code'] . '_geo_zone_id') . "'
                AND `country_id` = '" . $country_id . "'
                AND (`zone_id` = '" . $zone_id . "'
                OR `zone_id` = '0')"
            );

            if (!$query->num_rows && $this->config->get($this->key_prefix . 'multisafepay_' . $gateway['code'] . '_geo_zone_id')) {
                continue;
            }

            $title = '';
            if ((string)$gateway['type'] === 'generic') {
                $description = $this->config->get($this->key_prefix . 'multisafepay_' . $gateway['code'] . '_name');
                if (!empty($description)) {
                    $title = $description;
                }
            }

            if (((string)$gateway['type'] !== 'generic') && !empty($gateway['description'])) {
                $title = $gateway['description'];
            }

            $methods_data[] = array(
                'code' => $gateway['route'],
                'title' => $title,
                'terms' => '',
                'sort_order' => $this->config->get($this->key_prefix . 'multisafepay_' . $gateway['code'] . '_sort_order')
            );
        }

        $sort_order = array();
        foreach ($methods_data as $key => $value) {
            $sort_order[$key] = $value['sort_order'];
        }
        array_multisort($sort_order, SORT_ASC, $methods_data);

        return $methods_data;
    }

    /**
     * After a payment link is generated (in an order generated in the admin),
     * save the payment link in the order history
     *
     * @param int $order_id
     * @param int $order_status_id
     * @param string $comment
     * @param bool $notify
     *
     * @return void
     */
    public function addPaymentLinkToOrderHistory(int $order_id, int $order_status_id, string $comment = '', bool $notify = false): void
    {
        $this->db->query("INSERT INTO `" . DB_PREFIX . "order_history` SET `order_id` = '" . $order_id . "', `order_status_id` = '" . $order_status_id . "', `notify` = '" . (int)$notify . "', `comment` = '" . $this->db->escape($comment) . "', `date_added` = NOW()");
    }

    /**
     * In the case that a customer change the payment method in the payment page,
     * (using second chance or redirecting the payment methods), this function
     * edits the payment method for the given order_id
     *
     * @param int $order_id
     * @param array $data
     *
     * @return void
     */
    public function editOrderPaymentMethod(int $order_id, array $data = array()): void
    {
        $payment_method_title = trim(strip_tags($data['description']));
        $this->db->query("UPDATE `" . DB_PREFIX . "order` SET `payment_code` = '" . $this->db->escape($data['route']) . "', `payment_method` = '" . $this->db->escape($payment_method_title) . "' WHERE `order_id` = '" . $order_id . "'");
    }

    /**
     * Returns the products for the given order_id
     *
     * @param int $order_id
     *
     * @return array
     */
    public function getOrderProducts(int $order_id): array
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_product` WHERE `order_id` = '" . $order_id . "'");
        return $query->rows;
    }

    /**
     * Returns the products options selected in a given order_id for the given order_product_id
     *
     * @param int $order_id
     * @param int $order_product_id
     *
     * @return array
     */
    public function getOrderOptions(int $order_id, int $order_product_id): array
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_option` WHERE `order_id` = '" . $order_id . "' AND `order_product_id` = '" . $order_product_id . "'");
        return $query->rows;
    }

    /**
     * Returns all order totals lines for a given order_id
     *
     * @param int $order_id
     *
     * @return array
     */
    public function getOrderTotals(int $order_id): array
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_total` WHERE `order_id` = '" . $order_id . "' ORDER BY `sort_order`");
        return $query->rows;
    }

    /**
     * Returns all gift vouchers lines for a given order_id
     *
     * @param int $order_id
     *
     * @return array
     */
    public function getOrderVouchers(int $order_id): array
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_voucher` WHERE `order_id` = '" . $order_id . "'");
        return $query->rows;
    }

    /**
     * Explore the database and returns the Order Totals Keys
     *
     * @return array
     */
    public function getDetectedOrderTotalsKeys(): array
    {
        $query = $this->db->query("SELECT DISTINCT `code` FROM `" . DB_PREFIX . "extension` WHERE `type` = 'total'");

        $codes = array();
        if ($query->num_rows) {
            foreach ($query->rows as $result) {
                $codes[] = $result['code'];
            }
        }
        return $codes;
    }

    /**
     * Gets the coupon code
     *
     * @param string $code

     * @return array
     */
    // phpcs:ignore
    public function getCoupon(string $code): array {
        $status = true;
        $product_data = $coupon_array = array();

        $coupon_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "coupon` WHERE `code` = '" . $this->db->escape($code) . "' AND ((`date_start` = '0000-00-00' OR `date_start` < NOW()) AND (`date_end` = '0000-00-00' OR `date_end` > NOW())) AND `status` = '1'");

        if ($coupon_query->num_rows) {
            if ($coupon_query->row['total'] > $this->cart->getSubTotal()) {
                $status = false;
            }

            $coupon_history_query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "coupon_history` ch WHERE `ch`.`coupon_id` = '" . (int)$coupon_query->row['coupon_id'] . "'");

            if (($coupon_query->row['uses_total'] > 0) && ($coupon_history_query->row['total'] >= $coupon_query->row['uses_total'])) {
                $status = false;
            }

            if ($coupon_query->row['logged'] && !$this->customer->getId()) {
                $status = false;
            }

            if ($this->customer->getId()) {
                $coupon_history_query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "coupon_history` ch WHERE `ch`.`coupon_id` = '" . (int)$coupon_query->row['coupon_id'] . "' AND `ch`.`customer_id` = '" . (int)$this->customer->getId() . "'");

                if (((int)$coupon_query->row['uses_customer'] > 0) && ($coupon_history_query->row['total'] >= (int)$coupon_query->row['uses_customer'])) {
                    $status = false;
                }
            }

            // Products
            $coupon_product_data = array();

            $coupon_product_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "coupon_product` WHERE `coupon_id` = '" . (int)$coupon_query->row['coupon_id'] . "'");

            foreach ($coupon_product_query->rows as $product) {
                $coupon_product_data[] = $product['product_id'];
            }

            // Categories
            $coupon_category_data = array();

            $coupon_category_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "coupon_category` cc LEFT JOIN `" . DB_PREFIX . "category_path` cp ON (`cc`.`category_id` = `cp`.`path_id`) WHERE `cc`.`coupon_id` = '" . (int)$coupon_query->row['coupon_id'] . "'");

            foreach ($coupon_category_query->rows as $category) {
                $coupon_category_data[] = $category['category_id'];
            }

            if ($coupon_product_data || $coupon_category_data) {
                foreach ($this->cart->getProducts() as $product) {
                    if (in_array($product['product_id'], $coupon_product_data)) {
                        $product_data[] = $product['product_id'];
                        continue;
                    }

                    foreach ($coupon_category_data as $category_id) {
                        $coupon_category_query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "product_to_category` WHERE `product_id` = '" . (int)$product['product_id'] . "' AND `category_id` = '" . (int)$category_id . "'");

                        if ($coupon_category_query->row['total']) {
                            $product_data[] = $product['product_id'];
                        }
                    }
                }

                if (!$product_data) {
                    $status = false;
                }
            }
        }

        if (!$coupon_query->num_rows) {
            $status = false;
        }

        if ($status) {
            $coupon_array = array(
                'coupon_id'     => $coupon_query->row['coupon_id'],
                'code'          => $coupon_query->row['code'],
                'name'          => $coupon_query->row['name'],
                'type'          => $coupon_query->row['type'],
                'discount'      => $coupon_query->row['discount'],
                'shipping'      => $coupon_query->row['shipping'],
                'total'         => $coupon_query->row['total'],
                'product'       => $product_data,
                'date_start'    => $coupon_query->row['date_start'],
                'date_end'      => $coupon_query->row['date_end'],
                'uses_total'    => $coupon_query->row['uses_total'],
                'uses_customer' => $coupon_query->row['uses_customer'],
                'status'        => $coupon_query->row['status'],
                'date_added'    => $coupon_query->row['date_added']
            );
        }
        return $coupon_array;
    }
}
