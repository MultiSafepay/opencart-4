<?php
namespace Opencart\System\Library;

require_once(DIR_EXTENSION . 'multisafepay/vendor/autoload.php');
require_once(DIR_SYSTEM . 'vendor.php');

use GuzzleHttp\Client;
use MultiSafepay\Api\ApiTokenManager;
use MultiSafepay\Api\Base\Response;
use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\CustomerDetails;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\Description;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\GatewayInfo\Account;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\GatewayInfo\Ideal;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\GatewayInfo\Issuer;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\GatewayInfo\Meta;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\GatewayInfo\QrCode;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\GoogleAnalytics;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\PaymentOptions;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\PluginDetails;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\SecondChance;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart;
use MultiSafepay\Api\Transactions\RefundRequest;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Api\Transactions\UpdateRequest;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Sdk;
use MultiSafepay\Util\Notification;
use MultiSafepay\ValueObject\CartItem;
use MultiSafepay\ValueObject\Customer\Address;
use MultiSafepay\ValueObject\Customer\AddressParser;
use MultiSafepay\ValueObject\IbanNumber;
use MultiSafepay\ValueObject\Money;
use MultiSafepay\ValueObject\Weight;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * phpcs:disabled ObjectCalisthenics.Files.ClassTraitAndInterfaceLength
 */
class Multisafepay {

    public const MULTISAFEPAY_PLUGIN_VERSION = '1.0.0';

    public const ROUTE = 'extension/multisafepay/payment/multisafepay';
    public const KEY_PREFIX = 'payment_';
    public const EXTENSION_LIST_ROUTE = 'marketplace/extension';
    public const TOKEN_NAME = 'user_token';
    public const MODEL_CALL = 'model_extension_multisafepay_payment_multisafepay';
    public const EXTENSION_DIRECTORY_ROUTE = 'extension/multisafepay/';
    public const CUSTOMER_GROUP_MODEL_ROUTE = 'customer/customer_group';
    public const CUSTOMER_GROUP_MODEL_CALL = 'model_customer_customer_group';
    public const NON_STANDARD_MODEL_CALL = 'model_extension_multisafepay';
    public const TOTAL_EXTENSION_KEY_PREFIX = 'total_';

    public const CONFIGURABLE_PAYMENT_COMPONENT = array('AMEX', 'CREDITCARD', 'MAESTRO', 'MASTERCARD', 'VISA', 'BNPL_INSTM');
    public const CONFIGURABLE_TOKENIZATION = array('AMEX', 'CREDITCARD', 'MAESTRO', 'MASTERCARD', 'VISA');
    public const CONFIGURABLE_RECURRING_PAYMENT_METHODS = array('AMEX', 'MAESTRO', 'MASTERCARD', 'VISA', 'CREDITCARD');
    public const CONFIGURABLE_TYPE_SEARCH = array('AFTERPAY', 'DIRDEB', 'EINVOICE', 'IN3', 'IDEAL', 'MYBANK', 'PAYAFTER', 'SANTANDER');
    public const CONFIGURABLE_GATEWAYS_WITH_ISSUERS = array('IDEAL', 'MYBANK');

    public const FIXED_TYPE = 'F';
    public const PERCENTAGE_TYPE = 'P';

    private object $registry;

    public string $oc_version;
    public string $route;
    public string $key_prefix;
    public string $extension_list_route;
    public string $token_name;
    public string $model_call;
    public string $extension_directory_route;
    public string $customer_group_model_route;
    public string $customer_group_model_call;
    public string $non_standard_model_call;
    private string $total_extension_key_prefix;
    public array $configurable_payment_component;
    public array $configurable_tokenization;
    public array $configurable_type_search;
    public array $configurable_recurring_payment_methods;
    private array $configurable_gateways_with_issuers;

    public function __construct($registry) {
        $this->registry = $registry;
        $this->oc_version = VERSION;
        $this->route = self::ROUTE;
        $this->key_prefix = self::KEY_PREFIX;
        $this->extension_list_route = self::EXTENSION_LIST_ROUTE;
        $this->token_name = self::TOKEN_NAME;
        $this->model_call = self::MODEL_CALL;
        $this->extension_directory_route = self::EXTENSION_DIRECTORY_ROUTE;
        $this->customer_group_model_route = self::CUSTOMER_GROUP_MODEL_ROUTE;
        $this->customer_group_model_call = self::CUSTOMER_GROUP_MODEL_CALL;
        $this->non_standard_model_call = self::NON_STANDARD_MODEL_CALL;
        $this->total_extension_key_prefix = self::TOTAL_EXTENSION_KEY_PREFIX;
        $this->configurable_payment_component = self::CONFIGURABLE_PAYMENT_COMPONENT;
        $this->configurable_tokenization = self::CONFIGURABLE_TOKENIZATION;
        $this->configurable_type_search = self::CONFIGURABLE_TYPE_SEARCH;
        $this->configurable_recurring_payment_methods = self::CONFIGURABLE_RECURRING_PAYMENT_METHODS;
        $this->configurable_gateways_with_issuers = self::CONFIGURABLE_GATEWAYS_WITH_ISSUERS;
    }

    /**
     * Magic method that returns any object used in OpenCart (from the registry object)
     * when has not been found inside this class
     *
     * @param string $name
     *
     * @return object
     */
    public function __get(string $name) {
        return $this->registry->get($name);
    }

    /**
     * Magic method that sets any key inside the object used in OpenCart
     *
     * @param string $key
     * @param object $value
     *
     * @return void
     */
    public function __set(string $key, object $value): void {
        $this->registry->set($key, $value);
    }

    /**
     * Returns the plugin version of MultiSafepay
     *
     * @return string self::MULTISAFEPAY_PLUGIN_VERSION
     */
    public function getPluginVersion(): string
    {
        return self::MULTISAFEPAY_PLUGIN_VERSION;
    }

    /**
     * Returns the ShoppingCart object
     *
     * @param int $order_id
     *
     * @return ShoppingCart object
     *
     * phpcs:disabled ObjectCalisthenics.Metrics.MaxNestingLevel
     */
    public function getShoppingCartItems(int $order_id): ShoppingCart
    {
        $order_products = $this->getOrderProducts($order_id);
        $coupon_info = $this->getCouponInfo($order_id);
        $shopping_cart_items = array();

        // Order Products
        foreach ($order_products as $product) {
            $shopping_cart_item = $this->getCartItem($product, $order_id, $order_products);
            $shopping_cart_items[$this->config->get($this->total_extension_key_prefix . 'sub_total_sort_order')][] = $shopping_cart_item;
        }

        // Gift Cards - Vouchers
        $vouchers_in_cart = $this->getOrderVouchersItemsInCart($order_id);
        if ($vouchers_in_cart) {
            foreach ($vouchers_in_cart as $voucher_in_cart) {
                $shopping_cart_items[$this->config->get($this->total_extension_key_prefix . 'sub_total_sort_order')][] = $this->getOrderVouchersItem($order_id, $voucher_in_cart);
            }
        }

        // Shipping Cost
        $shipping_info = $this->getShippingInfo($order_id);
        if ($shipping_info) {
            $shipping_cart_item = $this->getShippingItem($order_id);
            if ($shipping_cart_item) {
                $shopping_cart_items[$this->config->get($this->total_extension_key_prefix . 'shipping_sort_order')][] = $shipping_cart_item;
            }
        }

        // Fixed Coupons applied after taxes
        if ($coupon_info) {
            $coupon_cart_item = $this->getCouponItem($order_id);
            if ($coupon_cart_item) {
                $shopping_cart_items[$this->config->get($this->total_extension_key_prefix . 'coupon_sort_order')][] = $coupon_cart_item;
            }
        }

        // Handling Fee
        $handling_fee_info = $this->getHandlingFeeInfo($order_id);
        if ($handling_fee_info) {
            $handling_fee_cart_item = $this->getHandlingFeeItem($order_id);
            if ($handling_fee_cart_item) {
                $shopping_cart_items[$this->config->get($this->total_extension_key_prefix . 'handling_sort_order')][] = $handling_fee_cart_item;
            }
        }

        // Low Order Fee
        $low_order_fee_info = $this->getLowOrderFeeInfo($order_id);
        if ($low_order_fee_info) {
            $low_order_fee_info_cart_item = $this->getLowOrderFeeItem($order_id);
            if ($low_order_fee_info_cart_item) {
                $shopping_cart_items[$this->config->get($this->total_extension_key_prefix . 'low_order_fee_sort_order')][] = $low_order_fee_info_cart_item;
            }
        }

        // Fixed Taxes
        $fixed_taxes_items = $this->getFixedTaxesItems($order_id);
        if ($fixed_taxes_items) {
            $shopping_cart_items[$this->config->get($this->total_extension_key_prefix . 'tax_sort_order')] = $fixed_taxes_items;
        }

        // Customer Balance - Credit
        $customer_additional_data = $this->getAdditionalCustomerData();

        // Customer Balance included in the order
        $customer_balance_info = $this->getCustomerBalanceInfo($order_id);
        if (($customer_additional_data['customer_balance'] > 0.0) && $customer_balance_info) {
            $customer_balance_item = $this->getCustomerBalanceItem($order_id);
            if ($customer_balance_item) {
                $shopping_cart_items[$this->config->get($this->total_extension_key_prefix . 'credit_sort_order')][] = $customer_balance_item;
            }
        }

        // Vouchers Gift Cards
        $vouchers_info = $this->getVoucherInfo($order_id);
        if ($vouchers_info) {
            $voucher_info_cart_item = $this->getVouchersItem($order_id);
            if ($voucher_info_cart_item) {
                $shopping_cart_items[$this->config->get($this->total_extension_key_prefix . 'voucher_sort_order')] = $voucher_info_cart_item;
            }
        }

        // Custom Order Totals
        $detected_order_total_keys = $this->checkForThirdPartyPluginsOrderTotals();
        if (!empty($detected_order_total_keys)) {
            foreach ($detected_order_total_keys as $custom_order_total_key) {
                $custom_order_total_key = trim($custom_order_total_key);
                $custom_order_total_info = $this->getCustomOrderTotalInfo($order_id, $custom_order_total_key);
                if ($custom_order_total_info) {
                    $custom_order_total_cart_item = $this->getCustomOrderTotalItem($order_id, $custom_order_total_key);
                    if ($custom_order_total_cart_item) {
                        $shopping_cart_items[$this->config->get($this->total_extension_key_prefix . $custom_order_total_key . '_sort_order')][] = $custom_order_total_cart_item;
                    }
                }
            }
        }

        // Sort Order Shopping Cart Items
        $cart_items = $this->reOrderShoppingCartItems($shopping_cart_items);
        return new ShoppingCart($cart_items);
    }

    /**
     * Compare the results of the order totals keys found in database, and
     * return a result
     */
    public function checkForThirdPartyPluginsOrderTotals(): array
    {
        $default_order_total_keys = array('sub_total', 'shipping', 'total', 'coupon', 'tax', 'handling', 'voucher', 'credit', 'low_order_fee', 'reward', 'klarna_fee');
        $detected_order_total_keys = $this->{$this->model_call}->getDetectedOrderTotalsKeys();

        // Custom order totals keys after removing default ones (included in OpenCart)
        $custom_order_total_keys = array_diff($detected_order_total_keys, $default_order_total_keys);

        // Custom order totals keys defined in settings to be excluded
        $exclude_order_total_keys = explode(',', ($this->config->get($this->key_prefix . 'multisafepay_custom_order_total_keys') ?? ''));
        return array_diff($custom_order_total_keys, $exclude_order_total_keys);
    }

    /**
     * Returns the tax rate value applied for an item in the cart
     *
     * @param float $total
     * @param int $tax_class_id
     *
     * @return float
     */
    private function getItemTaxRate(float $total, int $tax_class_id): float
    {
        $tax_rate = 0.0;

        $rates = $this->tax->getRates($total, $tax_class_id);
        if (!empty($rates)) {
            foreach ($rates as $oc_tax_rate) {
                if (isset($oc_tax_rate['type']) && ((string)$oc_tax_rate['type'] === self::PERCENTAGE_TYPE)) {
                    $tax_rate += $oc_tax_rate['rate'];
                }
            }
        }
        return $tax_rate;
    }

    /**
     * Returns a boolean if the sort order module provided is lower than the one set for taxes.
     * It is used to determine if taxes need to be calculated for those modules
     *
     * @param string $module_sort_order
     *
     * @return bool
     */
    private function isSortOrderLowerThanTaxes(string $module_sort_order): bool
    {
        $tax_sort_order = $this->config->get($this->total_extension_key_prefix . 'tax_sort_order');
        return (int)$tax_sort_order > (int)$module_sort_order;
    }

    /**
     * Returns a Sdk object
     *
     * @param int $store_id
     *
     * @return Sdk object
     */
    public function getSdkObject(int $store_id = 0): Sdk
    {
        $this->load->language($this->route);
        $this->load->model($this->route);
        $sdk = false;

        $environment = empty($this->model_setting_setting->getValue($this->key_prefix . 'multisafepay_environment', $store_id));
        $api_key = (($environment) ? $this->model_setting_setting->getValue($this->key_prefix . 'multisafepay_api_key', $store_id) : $this->model_setting_setting->getValue($this->key_prefix . 'multisafepay_sandbox_api_key', $store_id));
        $client = new Client();
        $factory = new Psr17Factory();

        try {
            $sdk = new Sdk($api_key, $environment, $client, $factory, $factory);
        }
        catch (InvalidApiKeyException $invalidApiKeyException) {
            if ($this->model_setting_setting->getValue($this->key_prefix . 'multisafepay_debug_mode', $store_id)) {
                $this->log->write($invalidApiKeyException->getMessage());
            }
            $this->session->data['error'] = $this->language->get('text_error');
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }
        return $sdk;
    }

    /**
     * Returns the Order Request Object
     *
     * @param $data
     *
     * @return OrderRequest object
     */
    public function getOrderRequestObject($data): OrderRequest
    {
        $this->load->language($this->route);
        $order_info = $this->getOrderInfo($data['order_id']);

        // Order Request
        $multisafepay_order = new OrderRequest();
        $multisafepay_order->addOrderId((string)$data['order_id']);

        if (isset($data['gateway']) && empty($data['issuer_id']) && in_array($data['gateway'], $this->configurable_gateways_with_issuers)) {
            $data['type'] = 'redirect';
            $data['gateway_info'] = '';
        }

        $multisafepay_order->addType($data['type']);

        // Order Request: Gateway
        if (!empty($data['gateway'])) {
            $multisafepay_order->addGatewayCode($data['gateway']);
        }

        if (isset($data['gateway_info']) && ((string)$data['gateway_info'] !== '')) {
            $gateway_info = $this->getGatewayInfoInterfaceObject($data);
            $multisafepay_order->addGatewayInfo($gateway_info);
        }

        // If order goes through Payment Component
        if (isset($data['payload']) && ((string)$data['payload'] !== '')) {
            $multisafepay_order->addData(array('payment_data' => array('payload' => $data['payload'])));
        }

        // Order Request: Plugin details
        $plugin_details = $this->getPluginDetailsObject();
        $multisafepay_order->addPluginDetails($plugin_details);

        // Order Request: Money
        $order_total = $this->getMoneyObjectOrderAmount($order_info['total'], $order_info['currency_code'], $order_info['currency_value']);
        $multisafepay_order->addMoney($order_total);

        // Order Request: Description
        $description = $this->getOrderDescriptionObject($data['order_id']);
        $multisafepay_order->addDescription($description);

        // Order Request: Payment Options
        $payment_options = $this->getPaymentOptionsObject();
        $multisafepay_order->addPaymentOptions($payment_options);

        // Order Request: Second Chance
        $payment_multisafepay_second_chance = $this->config->get($this->key_prefix . 'multisafepay_second_chance') ? true : false;
        $second_chance = $this->getSecondChanceObject($payment_multisafepay_second_chance);
        $multisafepay_order->addSecondChance($second_chance);

        // Order Request: Shopping Cart Items - Products
        if (!$this->config->get($this->key_prefix . 'multisafepay_shopping_cart_disabled')) {
            $shopping_cart = $this->getShoppingCartItems($data['order_id']);
            $multisafepay_order->addShoppingCart($shopping_cart);
        }

        // Order Request: Customer
        $customer_payment = $this->getCustomerObject($data['order_id'], 'payment');
        $multisafepay_order->addCustomer($customer_payment);

        // Order Request: Customer Delivery. Only if the order requires delivery.
        if ((string)$order_info['shipping_method'] !== '') {
            $customer_shipping = $this->getCustomerObject($data['order_id'], 'shipping');
            $multisafepay_order->addDelivery($customer_shipping);
        }

        // Order Request: Lifetime of Payment Link
        if ($this->config->get($this->key_prefix . 'multisafepay_days_active') && $this->config->get($this->key_prefix . 'multisafepay_unit_lifetime_payment_link')) {
            $payment_multisafepay_unit_lifetime_payment_link = $this->config->get($this->key_prefix . 'multisafepay_unit_lifetime_payment_link');
            switch ($payment_multisafepay_unit_lifetime_payment_link) {
                case 'days':
                    $multisafepay_order->addDaysActive((int)$this->config->get($this->key_prefix . 'multisafepay_days_active'));
                    break;
                case 'hours':
                    $hours = (int)$this->config->get($this->key_prefix . 'multisafepay_days_active') * 60 * 60;
                    $multisafepay_order->addSecondsActive($hours);
                    break;
                case 'seconds':
                    $multisafepay_order->addSecondsActive((int)$this->config->get($this->key_prefix . 'multisafepay_days_active'));
                    break;
            }
        }
        return $multisafepay_order;
    }

    /**
     * Processes an Order Request
     *
     * @param OrderRequest $multisafepay_order
     *
     * @return bool|TransactionResponse object
     */
    public function processOrderRequestObject(OrderRequest $multisafepay_order): bool|TransactionResponse
    {
        if (!$multisafepay_order) {
            return false;
        }

        $this->load->language($this->route);
        $order_id = (int)$multisafepay_order->getOrderId();
        $order_info = $this->getOrderInfo($order_id);
        $sdk = $this->getSdkObject((int)$order_info['store_id']);
        $transaction_manager = $sdk->getTransactionManager();

        try {
            return $transaction_manager->create($multisafepay_order);
        }
        catch (ApiException $apiException) {
            if ($this->model_setting_setting->getValue($this->key_prefix . 'multisafepay_debug_mode', (int)$order_info['store_id'])) {
                $this->log->write($apiException->getMessage());
            }
            $this->session->data['error'] = $this->language->get('text_error');
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }
        return false;
    }

    /**
     * Processes a Refund Request
     *
     * @param TransactionResponse $multisafepay_order
     * @param $refund_request
     *
     * @return bool|Response object
     */
    public function processRefundRequestObject(TransactionResponse $multisafepay_order, $refund_request): bool|Response
    {
        if (!$multisafepay_order || !$refund_request) {
            return false;
        }

        $order_id = (int)$multisafepay_order->getOrderId();
        $order_info = $this->getAdminOrderInfo($order_id);
        $sdk = $this->getSdkObject((int)$order_info['store_id']);
        $transaction_manager = $sdk->getTransactionManager();

        try {
            return $transaction_manager->refund($multisafepay_order, $refund_request, (string)$order_id);
        }
        catch (ApiException $apiException) {
            if ($this->model_setting_setting->getValue($this->key_prefix . 'multisafepay_debug_mode', (int)$order_info['store_id'])) {
                $this->log->write($apiException->getMessage());
            }
            return false;
        }
    }

    /**
     * Creates a Refund Request
     *
     * @param TransactionResponse $multisafepay_order
     *
     * @return bool|RefundRequest object
     */
    public function createRefundRequestObject(TransactionResponse $multisafepay_order): bool|RefundRequest
    {
        if (!$multisafepay_order) {
            return false;
        }

        $order_id = (int)$multisafepay_order->getOrderId();
        $order_info = $this->getAdminOrderInfo($order_id);
        $sdk = $this->getSdkObject((int)$order_info['store_id']);
        $transaction_manager = $sdk->getTransactionManager();

        try {
            return $transaction_manager->createRefundRequest($multisafepay_order);
        }
        catch (ApiException $apiException) {
            if ($this->model_setting_setting->getValue($this->key_prefix . 'multisafepay_debug_mode', (int)$order_info['store_id'])) {
                $this->log->write($apiException->getMessage());
            }
            return false;
        }
    }

    /**
     * Returns the Issuers by gateway code
     *
     * @param string $gateway_code
     *
     * @return bool|array Issuers
     */
    public function getIssuersByGatewayCode(string $gateway_code): bool|array
    {
        $sdk = $this->getSdkObject((int)$this->config->get('config_store_id'));
        try {
            $issuer_manager = $sdk->getIssuerManager();
            $issuers = $issuer_manager->getIssuersByGatewayCode($gateway_code);
        }
        catch (InvalidArgumentException $invalidArgumentException) {
            if ($this->config->get($this->key_prefix . 'multisafepay_debug_mode')) {
                $this->log->write($invalidArgumentException->getMessage());
            }
            return false;
        }

        $data_issuers = array();
        if (!empty($issuers)) {
            foreach ($issuers as $issuer) {
                $data_issuers[] = array(
                    'code' => $issuer->getCode(),
                    'description' => $issuer->getDescription()
                );
            }
        }
        return $data_issuers;
    }

    /**
     * Returns a CustomerDetails object used to build the order request object
     * (in addCustomer and addDelivery methods)
     *
     * @param int $order_id
     * @param string $type Used to build the object with the order`s shipping or payment information.
     *
     * @return CustomerDetails object
     */
    public function getCustomerObject(int $order_id, string $type = 'shipping'): CustomerDetails
    {
        if (!$this->config->get('config_checkout_address') && $type == 'payment') {
            $type = 'shipping';
        }
        $order_info = $this->getOrderInfo($order_id);
        $customer_obj = new CustomerDetails();
        $customer_obj->addIpAddressAsString($order_info['ip']);
        if ($order_info['forwarded_ip']) {
            $customer_obj->addForwardedIpAsString($order_info['forwarded_ip']);
        }

        $type_company = $type . '_company';
        if (isset($order_info[$type_company]) && !empty($order_info[$type_company])) {
            $customer_obj->addCompanyName($order_info[$type_company]);
        }
        $customer_obj->addUserAgent($order_info['user_agent']);
        $customer_obj->addPhoneNumberAsString($order_info['telephone']);
        $customer_obj->addLocale($this->getLocale());
        $customer_obj->addEmailAddressAsString($order_info['email']);
        $customer_obj->addFirstName($order_info[$type . '_firstname'] ?? '');
        $customer_obj->addLastName($order_info[$type . '_lastname'] ?? '');

        $customer_address_parser_obj = new AddressParser();
        $parsed_address = $customer_address_parser_obj->parse($order_info[$type . '_address_1'] ?? '', $order_info[$type . '_address_2'] ?? '');

        $customer_address_obj = new Address();
        $customer_address_obj->addStreetName($parsed_address[0] ?? '');
        $customer_address_obj->addHouseNumber($parsed_address[1] ?? '');
        $customer_address_obj->addZipCode($order_info[$type . '_postcode'] ?? '');
        $customer_address_obj->addCity($order_info[$type . '_city'] ?? '');
        $customer_address_obj->addState($order_info[$type . '_zone'] ?? '');
        $customer_address_obj->addCountryCode($order_info[$type . '_iso_code_2'] ?? '');
        $customer_obj->addAddress($customer_address_obj);

        return $customer_obj;
    }

    /**
     * Returns SecondChance object to be used in the OrderRequest transaction
     *
     * @param bool $second_chance_status
     *
     * @return SecondChance object
     */
    public function getSecondChanceObject(bool $second_chance_status): SecondChance
    {
        $second_chance_details = new SecondChance();
        $second_chance_details->addSendEmail($second_chance_status);

        return $second_chance_details;
    }

    /**
     * Returns PluginDetails object to be used in the OrderRequest transaction
     *
     * @return PluginDetails object
     */
    public function getPluginDetailsObject(): PluginDetails
    {
        $plugin_details = new PluginDetails();
        $plugin_details->addApplicationName('OpenCart');
        $plugin_details->addApplicationVersion((string)$this->oc_version);
        $plugin_details->addPluginVersion($this->getPluginVersion());
        $plugin_details->addShopRootUrl($this->getShopUrl());

        return $plugin_details;
    }

    /**
     * Returns a PaymentOptions object used to build the order request object
     *
     * @return PaymentOptions object
     */
    public function getPaymentOptionsObject(): PaymentOptions
    {
        $payment_options_details = new PaymentOptions();
        $payment_options_details->addNotificationUrl($this->url->link($this->route . '|postCallback'));
        $payment_options_details->addRedirectUrl($this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true));
        $payment_options_details->addCancelUrl($this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true));

        return $payment_options_details;
    }

    /**
     * Returns a Description object used to build the order request object
     *
     * @param int $order_id
     *
     * @return Description object
     */
    public function getOrderDescriptionObject(int $order_id): Description
    {
        $this->load->language($this->route);
        $description = sprintf($this->language->get('text_order_description'), $order_id, $this->config->get('config_name'), date($this->language->get('datetime_format')));

        if ($this->config->get($this->key_prefix . 'multisafepay_order_description')) {
            $description = $this->config->get($this->key_prefix . 'multisafepay_order_description');
            $description = str_replace('{order_id}', $order_id, $description);
        }

        $description_details = new Description();
        $description_details->addDescription($description);

        return $description_details;
    }

    /**
     * Returns GatewayInfoInterface object to be used in OrderRequest transaction
     *
     * @param array $data
     * @return bool|array|Ideal|Issuer|QrCode|Account|Meta objects
     */
    public function getGatewayInfoInterfaceObject(array $data): bool|array|Ideal|Issuer|QrCode|Account|Meta
    {
        if (!isset($data['gateway_info'])) {
            return false;
        }

        switch ($data['gateway_info']) {
            case 'Ideal':
                if (empty($data['issuer_id'])) {
                    return false;
                }
                $gateway_info = new Ideal();
                $gateway_info->addIssuerId((string)$data['issuer_id']);
                break;
            case 'MyBank':
                if (empty($data['issuer_id'])) {
                    return false;
                }
                $gateway_info = new Issuer();
                $gateway_info->addIssuerId((string)$data['issuer_id']);
                break;
            case 'QrCode':
                $gateway_info = new QrCode();
                $gateway_info->addQrSize(250);
                $gateway_info->addAllowChangeAmount(false);
                $gateway_info->addAllowMultiple(false);
                break;
            case 'Account':
                $gateway_info = new Account();
                $gateway_info->addAccountHolderName($data['account_holder_name']);
                $gateway_info->addAccountIdAsString($data['account_holder_iban']);
                $gateway_info->addAccountHolderIbanAsString($data['account_holder_iban']);
                $gateway_info->addEmanDate($data['emandate']);
                break;
            case 'Meta':
                $order_info = $this->getOrderInfo($data['order_id']);
                $gateway_info = new Meta();
                $gateway_info->addPhoneAsString($order_info['telephone']);
                $gateway_info->addEmailAddressAsString($order_info['email']);
                if (isset($data['gender']) && !empty($data['gender'])) {
                    $gateway_info->addGenderAsString($data['gender']);
                }
                if (isset($data['birthday']) && !empty($data['birthday'])) {
                    $gateway_info->addBirthdayAsString($data['birthday']);
                }
                if (isset($data['bankaccount']) && !empty($data['bankaccount'])) {
                    $gateway_info->addBankAccountAsString($data['bankaccount']);
                }
                break;
            default:
                $gateway_info = array();
        }
        return $gateway_info;
    }

    /**
     * Returns a CartItem object used to build the order request object
     *
     * @param float $price
     * @param array $order_info
     * @param string $name
     * @param int $quantity
     * @param string $merchant_item_id
     * @param float $tax_rate
     * @param string $description
     * @param string|bool $weight_unit
     * @param float|bool $weight_value
     *
     * @return CartItem object
     */
    private function getCartItemObject(float $price, array $order_info, string $name, int $quantity, string $merchant_item_id,
                                       float $tax_rate, string $description = '', string|bool $weight_unit = false, float|bool $weight_value = false): CartItem
    {
        $unit_price = $this->getMoneyObject($price, $order_info['currency_code'], $order_info['currency_value']);

        $cart_item = new CartItem();
        $cart_item->addName($name);
        $cart_item->addUnitPrice($unit_price);
        $cart_item->addQuantity($quantity);
        $cart_item->addMerchantItemId($merchant_item_id);
        $cart_item->addTaxRate($tax_rate);
        $cart_item->addDescription($description);
        if ($weight_unit && $weight_value) {
            $cart_item_weight = $this->getWeightObject((string)$weight_unit, (float)$weight_value);
            $cart_item->addWeight($cart_item_weight);
        }
        return $cart_item;
    }

    /**
     * Returns a negative CartItem object used to build the order request object
     *
     * @param float $price
     * @param array $order_info
     * @param string $name
     * @param int $quantity
     * @param string $merchant_item_id
     * @param float $tax_rate
     * @param string $description
     * @param string|bool $weight_unit
     * @param float|bool $weight_value
     *
     * @return CartItem object
     */
    private function getNegativeCartItemObject(float $price, array $order_info, string $name, int $quantity, string $merchant_item_id,
                                               float $tax_rate, string $description = '', string|bool $weight_unit = false, float|bool $weight_value = false): CartItem
    {
        $unit_price = $this->getMoneyObject($price, $order_info['currency_code'], $order_info['currency_value']);
        $unit_price = $unit_price->negative();

        $cart_item = new CartItem();
        $cart_item->addName($name);
        $cart_item->addUnitPrice($unit_price);
        $cart_item->addQuantity($quantity);
        $cart_item->addMerchantItemId($merchant_item_id);
        $cart_item->addTaxRate($tax_rate);
        $cart_item->addDescription($description);
        if ($weight_unit && $weight_value) {
            $cart_item_weight = $this->getWeightObject((string)$weight_unit, (float)$weight_value);
            $cart_item->addWeight($cart_item_weight);
        }
        return $cart_item;
    }

    /**
     * Returns a Weight object used to build the order request object
     *
     * @param string $weight_unit
     * @param float $weight_value
     *
     * @return Weight object
     */
    private function getWeightObject(string $weight_unit, float $weight_value): Weight
    {
        return new Weight(strtoupper($weight_unit), $weight_value);
    }

    /**
     * Returns an amount convert into another currency
     *
     * @param float $number
     * @param string $currency
     * @param float $value
     *
     * @return float
     */
    public function formatByCurrency(float $number, string $currency, float $value = 0.0): float
    {
        $this->load->model('localisation/currency');

        $currencies = $this->model_localisation_currency->getCurrencies();

        if (!$value) {
            $value = $currencies[$currency]['value'];
        }

        $amount = ($value) ? ($number * $value) : $number;

        return round($amount, 10);
    }

    /**
     * Returns a Money object used to build the order request object (addMoney method)
     *
     * @param float $amount
     * @param string $currency_code
     * @param float $currency_value
     *
     * @return Money object
     */
    public function getMoneyObjectOrderAmount(float $amount, string $currency_code, float $currency_value): Money
    {
        $amount = $this->formatByCurrency($amount, $currency_code, $currency_value);
        $amount *= 100;

        return new Money($amount, $currency_code);
    }

    /**
     * Returns a Money object used to build the order request object
     * (taking the prices of the shopping cart)
     *
     * @param float $amount
     * @param string $currency_code
     * @param float $currency_value
     *
     * @return Money object
     */
    public function getMoneyObject(float $amount, string $currency_code, float $currency_value): Money
    {
        $amount = round(($amount * 100), 10);
        $amount = $this->formatByCurrency($amount, $currency_code, $currency_value);

        return new Money($amount, $currency_code);
    }

    /**
     * Returns an Order object called from the admin area
     *
     * @param int $order_id
     *
     * @return bool|object
     *
     * phpcs:disabled ObjectCalisthenics.ControlStructures.NoElse
     */
    public function getAdminOrderObject(int $order_id): bool|object
    {
        $order_info = $this->getAdminOrderInfo($order_id);
        if (isset($order_info['store_id'])) {
            $sdk = $this->multisafepay->getSdkObject((int)$order_info['store_id']);
            $transaction_manager = $sdk->getTransactionManager();

            try {
                $order = $transaction_manager->get((string)$order_id);
            }
            catch (ApiException) {
                return false;
            }
        }
        else {
            return false;
        }

        return $order;
    }

    /**
     * Returns a boolean after validates IBAN format
     *
     * @param string $iban
     *
     * @return bool
     */
    public function validateIban(string $iban): bool
    {
        require_once(DIR_EXTENSION . 'multisafepay/vendor/autoload.php');
        try {
            new IbanNumber($iban);
            return true;
        }
        catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Set the order status as shipped or cancelled
     *
     * @param int $order_id
     * @param string $status allowed values are shipped and cancelled
     *
     * @return void
     */
    public function changeMultiSafepayOrderStatusTo(int $order_id, string $status): void
    {
        $order_info = $this->getAdminOrderInfo($order_id);
        $sdk = $this->getSdkObject((int)$order_info['store_id']);
        $transaction_manager = $sdk->getTransactionManager();
        $update_order = new UpdateRequest();
        $update_order->addId((string)$order_id);
        $update_order->addStatus($status);

        try {
            $transaction_manager->update((string)$order_id, $update_order);
        }
        catch (ApiException $apiException) {
            die($apiException->getMessage());
        }
    }

    /**
     * Returns an array with additional information of the customer (to be used as additional
     * customer information in the order transaction)
     *
     * @return array
     */
    private function getAdditionalCustomerData(): array
    {
        if (!$this->customer->isLogged()) {
            return array(
                'customer_id' => 0,
                'customer_group_id' => (int)$this->config->get('config_customer_group_id'),
                'customer_balance' => 0.0,
                'customer_reward_points' => 0.0,
            );
        }

        return array(
            'customer_id' => $this->customer->getId(),
            'customer_group_id' => $this->customer->getGroupId(),
            'customer_balance' => $this->customer->getBalance(),
            'customer_reward_points' => $this->customer->getRewardPoints(),
        );
    }

    /**
     * Returns the language code required by MultiSafepay
     *
     * Language code concatenated with the country code. Format: ab_CD
     *
     * @return string
     *
     */
    public function getLocale(): string
    {
        $this->load->model('localisation/language');
        $language = $this->model_localisation_language->getLanguage($this->config->get('config_language_id'));
        $locale = 'en_US';

        if ((strlen($language['code']) !== 5) && (strlen($language['code']) !== 2)) {
            return $locale;
        }

        if (strlen($language['code']) === 5) {
            $locale_strings = explode('-', $language['code']);
            $locale = $locale_strings[0] . '_' . strtoupper($locale_strings[1]);
        }

        if (strlen($language['code']) === 2) {
            $locale = $language['code'] . '_' . strtoupper($language['code']);
        }

        if ($locale === 'en_EN') {
            return 'en_US';
        }
        return $locale;
    }

    /**
     * Returns the shop url according to the selected protocol
     *
     * @return string
     */
    public function getShopUrl(): string
    {
        return $this->config->get('config_url') ?? '';
    }

    /**
     * Returns a unique product ID, formed with the Product ID concatenated with the ID
     * of the products options, and selected in the order.
     *
     * @param int $order_id The Order ID
     * @param array $product The product from order information
     *
     * @return string
     */
    private function getUniqueProductId(int $order_id, array $product): string
    {
        $unique_product_id = $product['product_id'];
        $option_data = $this->getProductOptionsData($order_id, $product);

        if (!empty($option_data)) {
            foreach ($option_data as $option) {
                $unique_product_id .= '-' .  $option['product_option_id'];
            }
        }
        return $unique_product_id;
    }

    /**
     * Returns product name, according to order information,
     * including quantity and options selected
     *
     * @param int $order_id The Order ID
     * @param array $product The product from order information
     *
     * @return string
     */
    private function getProductName(int $order_id, array $product): string
    {
        $option_data = $this->getProductOptionsData($order_id, $product);
        $product_name = $this->htmlEntityDecode($product['name']);

        if (empty($option_data)) {
            return $product['quantity'] . ' x ' . $product_name;
        }

        $option_output = '';
        foreach ($option_data as $option) {
            $option_output .= $this->htmlEntityDecode($option['name']) . ': ' . $option['value'] . ', ';
        }
        $option_output = ' (' . substr($option_output, 0, -2) . ')';

        return $product['quantity'] . ' x ' . $product_name . $option_output;
    }

    /**
     * Returns product options selected in the order
     *
     * @param int $order_id The Order ID
     * @param array $product The product from order information
     *
     * @return array
     */
    private function getProductOptionsData(int $order_id, array $product): array
    {
        $this->load->model($this->route);

        $option_data = array();
        $options = $this->{$this->model_call}->getOrderOptions($order_id, $product['product_id']);

        foreach ($options as $option) {
            if ((string)$option['type'] !== 'file') {
                $option_data[] = $this->extractOptionsData($option);
            }
            if ((string)$option['type'] === 'file') {
                $option_data[] = $this->extractOptionsFileData($option);
            }
        }
        return $option_data;
    }

    /**
     * Extract product options data from options array
     *
     * @param array $option
     *
     * @return array
     */
    private function extractOptionsData(array $option): array
    {
        return array(
            'name' => $option['name'],
            'value' => $option['value'],
            'product_option_id' => $option['product_option_id'],
            'order_option_id' => $option['order_option_id']
        );
    }

    /**
     * Extract product options data file from options array
     *
     * @param array $option
     *
     * @return array
     */
    private function extractOptionsFileData(array $option): array
    {
        $this->load->model('tool/upload');
        $upload_info = $this->model_tool_upload->getUploadByCode($option['value']);

        $option_data = array();
        if ($upload_info) {
            $option_data = array(
                'name' => $option['name'],
                'value' => $upload_info['name'],
                'product_option_id' => $option['product_option_id'],
                'order_option_id' => $option['order_option_id']
            );
        }
        return $option_data;
    }

    /**
     * Extract fixed rates from taxes that might be related to handling and low order fee total modules
     * (used as helper in the function getFixedTaxesItems)
     *
     * @param array $oc_tax_rate
     * @param int $quantity
     * @param array $fixed_taxes_items
     *
     * @return array $fixed_taxes_items
     */
    private function extractFixedTaxesRatesFromProducts(array $oc_tax_rate, int $quantity, array $fixed_taxes_items): array
    {
        if (isset($oc_tax_rate['type']) && ((string)$oc_tax_rate['type'] === self::FIXED_TYPE)) {
            for ($i = 1; $i <= $quantity; $i++) {
                $fixed_taxes_items[] = $oc_tax_rate;
            }
        }
        return $fixed_taxes_items;
    }

    /**
     * Extract fixed rates from taxes that might be related to handling and low order fee total modules
     * (used as helper in the function getFixedTaxesItems)
     *
     * @param array $order_totals
     * @param array $fixed_taxes_items
     * @param string $key
     * @param string $type
     *
     * @return array $fixed_taxes_items
     */
    private function extractFixedTaxesFromHandlingLowOrderFee(array $order_totals, array $fixed_taxes_items, string $key, string $type): array
    {
        $tax_class_id  = $this->config->get($this->total_extension_key_prefix . $type . '_tax_class_id');
        $is_order_lower_than_taxes = $this->isSortOrderLowerThanTaxes($this->config->get($this->total_extension_key_prefix . $type . '_sort_order'));
        if ($tax_class_id && $is_order_lower_than_taxes) {
            $fixed_taxes_items = $this->addToArrayOfFixedTaxes((float)$order_totals[$key]['value'], (int)$tax_class_id, $fixed_taxes_items);
        }
        return $fixed_taxes_items;
    }

    /**
     * Returns an array with fixed taxes that will be converted to cart items
     *
     * @param int $order_id
     *
     * @return bool|array $fixed_taxes_items
     */
    private function getFixedTaxesItems(int $order_id): bool|array
    {
        $order_totals = $this->getOrderTotals($order_id);
        $order_info = $this->getOrderInfo($order_id);
        $order_products = $this->getOrderProducts($order_id);
        $detected_third_party_order_total_keys = $this->checkForThirdPartyPluginsOrderTotals();

        $has_handling = array_search('handling', array_column($order_totals, 'code'));
        $has_low_order_fee = array_search('low_order_fee', array_column($order_totals, 'code'));
        $has_shipping = array_search('shipping', array_column($order_totals, 'code'));

        $fixed_taxes_items = array();

        foreach ($order_products as $product) {
            $product_info = $this->getProductInfo($product['product_id']);
            $oc_tax_rates = $this->tax->getRates($product['price'], $product_info['tax_class_id']);
            foreach ($oc_tax_rates as $oc_tax_rate) {
                $fixed_taxes_items = $this->extractFixedTaxesRatesFromProducts($oc_tax_rate, $product['quantity'], $fixed_taxes_items);
            }
        }

        if ($has_shipping !== false) {
            $shipping_tax_class_id = $this->getShippingTaxClassId($order_info['shipping_code']);
            if ($shipping_tax_class_id) {
                $fixed_taxes_items = $this->addToArrayOfFixedTaxes((float)$order_totals[$has_shipping]['value'], $shipping_tax_class_id, $fixed_taxes_items);
            }
        }

        if ($has_handling !== false) {
            $fixed_taxes_items = $this->extractFixedTaxesFromHandlingLowOrderFee($order_totals, $fixed_taxes_items, $has_handling, 'handling');
        }

        if ($has_low_order_fee !== false) {
            $fixed_taxes_items = $this->extractFixedTaxesFromHandlingLowOrderFee($order_totals, $fixed_taxes_items, $has_low_order_fee, 'low_order_fee');
        }

        if (!empty($detected_third_party_order_total_keys)) {
            foreach ($detected_third_party_order_total_keys as $custom_order_total_key) {
                $custom_order_total_key = trim($custom_order_total_key);
                $has_custom_order_total = array_search($custom_order_total_key, array_column($order_totals, 'code'));
                if ($has_custom_order_total) {
                  $fixed_taxes_items = $this->extractFixedTaxesFromHandlingLowOrderFee($order_totals, $fixed_taxes_items, $has_custom_order_total, $custom_order_total_key);
                }
            }
        }

        if (empty($fixed_taxes_items)) {
            return false;
        }

        $shopping_cart_items = $fixed_taxes_items_ungrouped = array();

        // If there are more than once with the same ID, must be grouped, and then counted
        foreach ($fixed_taxes_items as $fixed_taxes_item) {
            $fixed_taxes_items_ungrouped[$fixed_taxes_item['tax_rate_id']][] = $fixed_taxes_item;
        }
        foreach ($fixed_taxes_items_ungrouped as $fixed_taxes_item) {
            $fixed_taxes_item_quantity = count($fixed_taxes_item);
            $shopping_cart_item = $this->getCartItemObject(
                (float)$fixed_taxes_item[0]['amount'],
                $order_info,
                sprintf($this->language->get('text_fixed_product_name'), $fixed_taxes_item[0]['name']),
                $fixed_taxes_item_quantity,
                'TAX-' . $fixed_taxes_item[0]['tax_rate_id'],
                0.0
            );
            $shopping_cart_items[] = $shopping_cart_item;
        }
        return $shopping_cart_items;
    }

    /**
     * Search into the array of tax rates that belongs to shipping, handling or low order fees,
     * and add the rates found to an array, to be return and added to the transaction as items
     *
     * @param float $total
     * @param int $tax_class_id
     * @param array $array_taxes
     *
     * @return array
     */
    private function addToArrayOfFixedTaxes(float $total, int $tax_class_id, array $array_taxes): array
    {
        $rate = $this->tax->getRates($total, $tax_class_id);
        foreach ($rate as $oc_tax_rate) {
            if (isset($oc_tax_rate['type']) && ((string)$oc_tax_rate['type'] === self::FIXED_TYPE)) {
                $array_taxes[] = $oc_tax_rate;
            }
        }
        return $array_taxes;
    }

    /**
     * Returns the shipping tax class ID if exists from the order shipping code
     *
     * @param string $shipping_code
     *
     * @return bool|int
     */
    private function getShippingTaxClassId(string $shipping_code): bool|int
    {
        $shipping_code_array = explode('.', $shipping_code);
        if (!empty($shipping_code_array['0'])) {
            $shipping_tax_class_id_key = 'shipping_' . $shipping_code_array['0'] . '_tax_class_id';
            return $this->config->get($shipping_tax_class_id_key);
        }
        return false;
    }

    /**
     * Returns order totals information
     *
     * @param int $order_id
     *
     * @return array
     */
    public function getOrderTotals(int $order_id): array
    {
        $this->load->model($this->route);

        return $this->{$this->model_call}->getOrderTotals($order_id);
    }

    /**
     * Returns order using model from admin
     *
     * @param int $order_id
     *
     * @return array
     */
    public function getAdminOrderInfo(int $order_id): array
    {
        $this->load->model('sale/order');

        return $this->model_sale_order->getOrder($order_id);
    }

    /**
     * Returns order information
     *
     * @param int $order_id
     *
     * @return array $order_info
     */
    public function getOrderInfo(int $order_id): array
    {
        $this->load->model('checkout/order');

        return $this->model_checkout_order->getOrder($order_id);
    }

    /**
     * Returns vouchers information to be included as discount
     *
     * @param int $order_id
     *
     * @return bool|array
     */
    public function getOrderVouchers(int $order_id): bool|array
    {
        $order_totals = $this->getOrderTotals($order_id);
        $has_vouchers = array_search('voucher', array_column($order_totals, 'code'));

        if ($has_vouchers === false) {
            return false;
        }

        return array([
            'amount' => $order_totals[$has_vouchers]['value'],
            'description' => $this->htmlEntityDecode($order_totals[$has_vouchers]['title'])
        ]);
    }

    /**
     * Returns product information
     *
     * @param int $product_id
     * @return array $product_info
     *
     */
    private function getProductInfo(int $product_id): array
    {
        $this->load->model('catalog/product');

        return $this->model_catalog_product->getProduct($product_id);
    }

    /**
     * Returns order products
     *
     * @param int $order_id
     * @return array $order_products
     *
     */
    public function getOrderProducts(int $order_id): array
    {
        $this->load->model($this->route);

        return $this->{$this->model_call}->getOrderProducts($order_id);
    }

    /**
     * Returns coupon info if exists or is false
     *
     * @param int $order_id
     *
     * @return bool|array $coupon_info
     */
    public function getCouponInfo(int $order_id): bool|array
    {
        $order_totals = $this->getOrderTotals($order_id);

        // If coupon does not exist
        if (in_array('coupon', array_column($order_totals, 'code')) === false) {
            return false;
        }
        if (!isset($this->session->data['coupon']) || empty($this->session->data['coupon'])) {
            return false;
        }

        $this->load->model($this->route);
        $coupon_info = $this->{$this->model_call}->getCoupon($this->session->data['coupon']);
        $coupon_info['name'] = $this->htmlEntityDecode($coupon_info['name']);
        $coupon_info['is_order_lower_than_taxes'] = $this->isSortOrderLowerThanTaxes($this->config->get($this->total_extension_key_prefix . 'coupon_sort_order'));

        return $coupon_info;
    }

    /**
     * Returns shipping method info if exists or is false
     *
     * @param int $order_id
     *
     * @return bool|array $shipping_info
     *
     */
    private function getShippingInfo(int $order_id): bool|array
    {
        $order_totals = $this->getOrderTotals($order_id);
        $has_shipping = array_search('shipping', array_column($order_totals, 'code'));
        $tax_rate = 0.0;

        if ($has_shipping === false) {
            return false;
        }

        $order_info = $this->getOrderInfo($order_id);
        $shipping_tax_class_id = $this->getShippingTaxClassId($order_info['shipping_code']);
        if ($shipping_tax_class_id) {
            $tax_rate = $this->getItemTaxRate((float)$order_totals[$has_shipping]['value'], $shipping_tax_class_id);
        }

        return array(
            'value' => $order_totals[$has_shipping]['value'],
            'title' => $this->htmlEntityDecode($order_totals[$has_shipping]['title']),
            'tax_rate' => $tax_rate
        );
    }

    /**
     * Returns CartItem object with shipping information
     *
     * @param int $order_id
     *
     * @return bool|CartItem object
     */
    private function getShippingItem(int $order_id): bool|CartItem
    {
        $this->load->language($this->route);
        $order_info = $this->getOrderInfo($order_id);
        $coupon_info = $this->getCouponInfo($order_id);
        $shipping_info = $this->getShippingInfo($order_id);

        if ($coupon_info && isset($coupon_info['shipping']) && $coupon_info['shipping']) {
            return $this->getCartItemObject(
                0.0,
                $order_info,
                sprintf($this->language->get('text_coupon_applied_to_shipping'), $shipping_info['title'], $coupon_info['name']),
                1,
                'msp-shipping',
                0.0
            );
        }

        if (!$coupon_info || !$coupon_info['shipping']) {
            return $this->getCartItemObject(
                $shipping_info['value'],
                $order_info,
                $shipping_info['title'],
                1,
                'msp-shipping',
                $shipping_info['tax_rate']
            );
        }
        return false;
    }

    /**
     * Returns CartItem object with product information
     *
     * @param array $product
     * @param int $order_id
     * @param array $order_products
     *
     * @return CartItem object
     */
    private function getCartItem(array $product, int $order_id, array $order_products): CartItem
    {
        $this->load->language($this->route);
        $order_info = $this->getOrderInfo($order_id);
        $product_info = $this->getProductInfo($product['product_id']);
        $product_name = $this->getProductName($order_id, $product);
        $product_price = $product['price'];
        $product_description = '';
        $merchant_item_id = $this->getUniqueProductId($order_id, $product);

        $tax_rate = 0.0;
        if (isset($product['price'], $product_info['tax_class_id'])) {
            $tax_rate = $this->getItemTaxRate($product['price'], $product_info['tax_class_id']);
        }

        // Some third party extensions could set the product taxes to 0, even when the product has valid tax class id assigned
        if (isset($product['tax']) && (string)$product['tax'] === '0') {
            $tax_rate = 0.0;
        }

        $reward_info = $this->getRewardInfo($order_id);
        $coupon_info = $this->getCouponInfo($order_id);

        if ($reward_info) {
            $discount_by_product = $this->getRewardPointsDiscountByProduct($order_id);
            if (isset($discount_by_product[$product['product_id']]['discount_per_product'])) {
                $product_price -= $discount_by_product[$product['product_id']]['discount_per_product'];
                $discount = $this->currency->format(
                    (float)$discount_by_product[$product['product_id']]['discount_per_products'],
                    $order_info['currency_code'],
                    $order_info['currency_value'], true
                );
                $product_name .= sprintf($this->language->get('text_reward_applied'), $discount, strtolower($reward_info['title']));
                $product_description .= sprintf($this->language->get('text_reward_applied'), $discount, strtolower($reward_info['title']));
            }
        }

        // Coupons are a fixed type and apply just to a few items in the order before taxes
        if (
            $coupon_info
            && (isset($coupon_info['type']) && ((string)$coupon_info['type'] === self::FIXED_TYPE))
            && $coupon_info['is_order_lower_than_taxes']
            && !empty($coupon_info['product'])
            && ($coupon_info['discount'] > 0)
            && in_array($product['product_id'], $coupon_info['product'])
        ) {
            $count = 0;
            foreach ($order_products as $order_product) {
                if (in_array($order_product['product_id'], $coupon_info['product'])) {
                    $count++;
                }
            }
            $discount_by_product = ($coupon_info['discount'] / $count / $product['quantity']);
            $product_price -= $discount_by_product;
            $product_name .= ' - ' . sprintf($this->language->get('text_coupon_applied'), $coupon_info['name']);
            $product_description .= sprintf(
                $this->language->get('text_price_before_coupon'),
                $this->currency->format($product['price'], $order_info['currency_code'], $order_info['currency_value'], true),
                $coupon_info['name']
            );
        }

        // Coupons are a fixed type and apply to all items in the order before taxes
        if (
            $coupon_info
            && (isset($coupon_info['type']) && ((string)$coupon_info['type'] === self::FIXED_TYPE))
            && $coupon_info['is_order_lower_than_taxes']
            && empty($coupon_info['product'])
            && ($coupon_info['discount'] > 0)
        ) {
            // Coupon discount is distributed in the same way for each product in the cart
            $discount_by_product = ($coupon_info['discount'] / count($order_products) / $product['quantity']);
            $product_price -= $discount_by_product;
            $product_name .= ' - ' . sprintf($this->language->get('text_coupon_applied'), $coupon_info['name']);
            $product_description .= sprintf(
                $this->language->get('text_price_before_coupon'),
                $this->currency->format($product['price'], $order_info['currency_code'], $order_info['currency_value'], true),
                $coupon_info['name']
            );
        }

        // Coupons are a percentage type and apply just to a few items in the order
        if ($coupon_info
            && (isset($coupon_info['type']) && ((string)$coupon_info['type'] === self::PERCENTAGE_TYPE))
            && $coupon_info['is_order_lower_than_taxes']
            && !empty($coupon_info['product'])
            && in_array($product['product_id'], $coupon_info['product'])
        ) {
            $discount_by_product = ($product['price'] * ($coupon_info['discount'] / 100));
            $product_price -= $discount_by_product;
            // If coupon is just for free shipping, the name and description is not modified
            if ($coupon_info['discount'] > 0) {
                $product_name .= ' - ' . sprintf($this->language->get('text_coupon_applied'), $coupon_info['name']);
                $product_description .= sprintf(
                    $this->language->get('text_price_before_coupon'),
                    $this->currency->format($product['price'], $order_info['currency_code'], $order_info['currency_value'], true),
                    $coupon_info['name']
                );
            }
        }

        // Coupons are a percentage type and apply for all items in the order
        if ($coupon_info
            && (isset($coupon_info['type']) && ((string)$coupon_info['type'] === self::PERCENTAGE_TYPE))
            && $coupon_info['is_order_lower_than_taxes']
            && empty($coupon_info['product'])
        ) {
            $discount_by_product = ($product['price'] * ($coupon_info['discount'] / 100));
            $product_price -= $discount_by_product;
            // If coupon is just for free shipping, the name and description is not modified
            if ($coupon_info['discount'] > 0) {
                $product_name .= ' - ' . sprintf($this->language->get('text_coupon_applied'), $coupon_info['name']);
                $product_description .= sprintf(
                    $this->language->get('text_price_before_coupon'),
                    $this->currency->format($product['price'], $order_info['currency_code'], $order_info['currency_value'], true),
                    $coupon_info['name']
                );
            }
        }

        return $this->getCartItemObject(
            $product_price,
            $order_info,
            $product_name,
            $product['quantity'],
            $merchant_item_id,
            $tax_rate,
            $product_description,
            $this->weight->getUnit($product_info['weight_class_id']),
            $product_info['weight']
        );
    }

    /**
     * Returns CartItem object with product information
     *
     * @param int $order_id
     *
     * @return bool|CartItem object
     */
    private function getCouponItem(int $order_id): bool|CartItem
    {
        $coupon_info = $this->getCouponInfo($order_id);
        $order_info = $this->getOrderInfo($order_id);

        if (
            !$coupon_info
            || (isset($coupon_info['type']) && ((string)$coupon_info['type'] !== self::FIXED_TYPE))
            || (isset($coupon_info['type']) && ((string)$coupon_info['type'] === self::FIXED_TYPE) && ((string)$coupon_info['discount'] === '0'))
            || $coupon_info['is_order_lower_than_taxes']
        ) {
            return false;
        }

        return $this->getNegativeCartItemObject(
            $coupon_info['discount'],
            $order_info,
            $coupon_info['name'],
            1,
            'COUPON',
            0
        );
    }

    /**
     * Returns handling fee information if exists or is false
     *
     * @param int $order_id
     *
     * @return bool|array
     */
    private function getHandlingFeeInfo(int $order_id): bool|array
    {
        $order_totals = $this->getOrderTotals($order_id);
        $has_handling_fee = array_search('handling', array_column($order_totals, 'code'));
        $tax_rate = 0.0;

        if ($has_handling_fee === false) {
            return false;
        }

        $handling_tax_class_id = $this->config->get($this->total_extension_key_prefix . 'handling_tax_class_id');
        if ($handling_tax_class_id) {
            $tax_rate = $this->getItemTaxRate((float)$order_totals[$has_handling_fee]['value'], (int)$handling_tax_class_id);
        }

        return array(
            'value' => $order_totals[$has_handling_fee]['value'],
            'title' => $this->htmlEntityDecode($order_totals[$has_handling_fee]['title']),
            'is_order_lower_than_taxes' => $this->isSortOrderLowerThanTaxes($this->config->get($this->total_extension_key_prefix . 'handling_sort_order')),
            'tax_rate' => $tax_rate
        );
    }

    /**
     * Returns CartItem object with handling fee information
     *
     * @param int $order_id
     *
     * @return bool|CartItem object
     */
    private function getHandlingFeeItem(int $order_id): bool|CartItem
    {
        $handling_fee_info = $this->getHandlingFeeInfo($order_id);
        $order_info = $this->getOrderInfo($order_id);

        if (!$handling_fee_info) {
            return false;
        }

        return $this->getCartItemObject(
            $handling_fee_info['value'],
            $order_info,
            $handling_fee_info['title'],
            1,
            'HANDLING',
            $handling_fee_info['tax_rate']
        );
    }

    /**
     * Returns low order fee information
     *
     * @param int $order_id
     *
     * @return bool|array
     */
    private function getLowOrderFeeInfo(int $order_id): bool|array
    {
        $order_totals = $this->getOrderTotals($order_id);
        $has_low_order_fee = array_search('low_order_fee', array_column($order_totals, 'code'));
        $tax_rate = 0.0;

        if ($has_low_order_fee === false) {
            return false;
        }

        $low_order_fee_tax_class_id = $this->config->get($this->total_extension_key_prefix . 'low_order_fee_tax_class_id');
        if ($low_order_fee_tax_class_id) {
            $tax_rate = $this->getItemTaxRate((float)$order_totals[$has_low_order_fee]['value'], (int)$low_order_fee_tax_class_id);
        }

        return array(
            'value' => $order_totals[$has_low_order_fee]['value'],
            'title' => $this->htmlEntityDecode($order_totals[$has_low_order_fee]['title']),
            'is_order_lower_than_taxes' => $this->isSortOrderLowerThanTaxes($this->config->get($this->total_extension_key_prefix . 'low_order_fee_sort_order')),
            'tax_rate' => $tax_rate
        );
    }

    /**
     * Returns CartItem object with low order fee
     *
     * @param int $order_id
     *
     * @return bool|CartItem object
     */
    private function getLowOrderFeeItem(int $order_id): bool|CartItem
    {
        $low_order_fee_info = $this->getLowOrderFeeInfo($order_id);
        $order_info = $this->getOrderInfo($order_id);

        if ($low_order_fee_info) {
            return $this->getCartItemObject(
                $low_order_fee_info['value'],
                $order_info,
                $low_order_fee_info['title'],
                1,
                'LOWORDERFEE',
                $low_order_fee_info['tax_rate']
            );
        }
        return false;
    }

    /**
     * Returns reward info if exists or is false
     *
     * @param int $order_id
     *
     * @return bool|array
     */
    private function getRewardInfo(int $order_id): bool|array
    {
        $order_totals = $this->getOrderTotals($order_id);
        $has_reward = array_search('reward', array_column($order_totals, 'code'));

        if ($has_reward === false) {
            return false;
        }

        return array(
            'value' => $order_totals[$has_reward]['value'],
            'title' => $this->htmlEntityDecode($order_totals[$has_reward]['title']),
        );
    }

    /**
     * Returns reward discount by Product ID
     *
     * @param int $order_id
     *
     * @return array $discounts
     */
    public function getRewardPointsDiscountByProduct(int $order_id): array
    {
        $order_products = $this->getOrderProducts($order_id);
        $points_total = 0;

        foreach ($order_products as $product) {
            $product_info = $this->getProductInfo($product['product_id']);
            if ($product_info['points']) {
                $points_total += ($product_info['points'] * $product['quantity']);
            }
        }

        $discounts = array();
        foreach ($order_products as $product) {
            $product_info = $this->getProductInfo($product['product_id']);
            if ($product_info['points']) {
                $discount_per_products = $product['total'] * ($this->session->data['reward'] / $points_total);
                $discount_per_product = $discount_per_products / $product['quantity'];
                $discounts[$product['product_id']]['discount_per_product'] = $discount_per_product;
                $discounts[$product['product_id']]['discount_per_products'] = $discount_per_products;
            }
        }
        return $discounts;
    }

    /**
     * Returns if the order contains a credit-customer balance item
     *
     * @param int $order_id
     *
     * @return bool|array
     */
    private function getCustomerBalanceInfo(int $order_id): bool|array
    {
        $this->load->language($this->route);

        $order_totals = $this->getOrderTotals($order_id);
        $has_credit = array_search('credit', array_column($order_totals, 'code'));
        if ($has_credit === false) {
            return false;
        }

        return array(
            'value' => $order_totals[$has_credit]['value'],
            'title' => $this->htmlEntityDecode($this->language->get('text_customer_balance')),
        );
    }

    /**
     * Returns CartItem object with customer balance
     *
     * @param int $order_id
     *
     * @return bool|CartItem object
     */
    private function getCustomerBalanceItem(int $order_id): bool|CartItem
    {
        $customer_balance_item = $this->getCustomerBalanceInfo($order_id);
        $order_info = $this->getOrderInfo($order_id);

        if ($customer_balance_item) {
            return $this->getNegativeCartItemObject(
                -$customer_balance_item['value'],
                $order_info,
                $customer_balance_item['title'],
                1,
                'CREDIT',
                0
            );
        }
        return false;
    }

    /**
     * Returns vouchers information to be included as product
     *
     * @param int $order_id
     *
     * @return array $order_vouchers
     */
    public function getOrderVouchersItemsInCart(int $order_id): array
    {
        $this->load->model($this->route);
        $order_vouchers = $this->{$this->model_call}->getOrderVouchers($order_id);
        $voucher_info = array();
        foreach ($order_vouchers as $order_voucher) {
            $voucher_info[] = array(
                'order_voucher_id' => $order_voucher['order_voucher_id'],
                'value' => $order_voucher['amount'],
                'title' => $this->htmlEntityDecode($order_voucher['description']),
            );
        }
        return $voucher_info;
    }

    /**
     * Returns voucher information if exists or is false
     *
     * @param int $order_id
     *
     * @return bool|array
     */
    private function getVoucherInfo(int $order_id): bool|array
    {
        $order_vouchers = $this->getOrderVouchers($order_id);

        if (!$order_vouchers) {
            return false;
        }

        $voucher_info = array();
        foreach ($order_vouchers as $order_voucher) {
            $voucher_info[] = array(
                'value' => $order_voucher['amount'],
                'title' => $this->htmlEntityDecode($order_voucher['description']),
            );
        }
        return $voucher_info;
    }

    /**
     * Returns CartItem object with voucher
     *
     * @param int $order_id
     *
     * @return array object
     */
    private function getVouchersItem(int $order_id): array
    {
        $vouchers_info = $this->getVoucherInfo($order_id);
        $order_info = $this->getOrderInfo($order_id);

        $cart_items = array();
        if ($vouchers_info) {
            foreach ($vouchers_info as $voucher_info) {
                $cart_items[] = $this->getCartItemObject(
                    $voucher_info['value'],
                    $order_info,
                    $voucher_info['title'],
                    1,
                    'VOUCHER',
                    0.0
                );
            }
        }
        return $cart_items;
    }

    /**
     * Returns voucher information if exists or is false
     *
     * @param int $order_id
     * @param $voucher_info
     *
     * @return CartItem
     */
    private function getOrderVouchersItem(int $order_id, $voucher_info): CartItem
    {
        $order_info = $this->getOrderInfo($order_id);
        return $this->getCartItemObject(
            $voucher_info['value'],
            $order_info,
            $voucher_info['title'],
            1,
            $voucher_info['order_voucher_id'],
            0.0
        );
    }

    /**
     * Returns Custom Order Total information if exists or is false
     *
     * @param int $order_id
     * @param $custom_order_total_key
     *
     * @return bool|array
     */
    private function getCustomOrderTotalInfo(int $order_id, $custom_order_total_key): bool|array
    {
        $order_totals = $this->getOrderTotals($order_id);
        $has_custom_order_total = array_search($custom_order_total_key, array_column($order_totals, 'code'));
        $tax_rate = 0.0;

        if ($has_custom_order_total === false) {
            return false;
        }

        $custom_order_total_tax_class_id = $this->config->get($this->total_extension_key_prefix . $custom_order_total_key . '_tax_class_id');
        if ($custom_order_total_tax_class_id) {
            $tax_rate = $this->getItemTaxRate((float)$order_totals[$has_custom_order_total]['value'], (int)$custom_order_total_tax_class_id);
        }

        return array(
            'value' => $order_totals[$has_custom_order_total]['value'],
            'title' => $this->htmlEntityDecode($order_totals[$has_custom_order_total]['title']),
            'is_order_lower_than_taxes' => $this->isSortOrderLowerThanTaxes($this->config->get($this->total_extension_key_prefix . $custom_order_total_key . '_sort_order')),
            'tax_rate' => $tax_rate
        );
    }

    /**
     * Returns Custom Order Total Cart Item object
     *
     * @param int $order_id
     * @param string $custom_order_total_key
     *
     * @return bool|CartItem object
     */
    private function getCustomOrderTotalItem(int $order_id, string $custom_order_total_key): bool|CartItem
    {
        $custom_order_total_info = $this->getCustomOrderTotalInfo($order_id, $custom_order_total_key);
        $order_info = $this->getOrderInfo($order_id);

        if (!$custom_order_total_info) {
            return false;
        }

        if (!$custom_order_total_info['is_order_lower_than_taxes']) {
            $custom_order_total_info['tax_rate'] = 0.0;
        }

        return $this->getCartItemObject(
            $custom_order_total_info['value'],
            $order_info,
            $custom_order_total_info['title'],
            1,
            $custom_order_total_key,
            $custom_order_total_info['tax_rate']
        );
    }

    /**
     * Returns the string decoded when contains html entities
     *
     * @param string $string
     *
     * @return string
     */
    private function htmlEntityDecode(string $string): string
    {
        return html_entity_decode($string, ENT_COMPAT, 'UTF-8');
    }

    /**
     * Returns reordered cart items
     *
     * @param array $shopping_cart_items
     *
     * @return array $cart_items
     */
    private function reOrderShoppingCartItems(array $shopping_cart_items): array
    {
        ksort($shopping_cart_items);

        $cart_items = array();
        foreach ($shopping_cart_items as $value) {
            foreach ($value as $item) {
                $cart_items[] = $item;
            }
        }
        return $cart_items;
    }

    /**
     * Returns all gateways
     *
     * @return array $gateways
     */
    public function getGateways(): array
    {
        $this->load->language($this->route);
        $this->load->model('setting/setting');

        return array(
            array(
                'id' => 'MULTISAFEPAY',
                'code' => 'multisafepay',
                'route' => 'multisafepay',
                'description' => $this->language->get('text_title_multisafepay'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_multisafepay')
            ),
            array(
                'id' => 'AFTERPAY',
                'code' => 'afterpay',
                'route' => 'multisafepay/afterPay',
                'description' => $this->language->get('text_title_afterpay'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_afterpay')
            ),
            array(
                'id' => 'ALIPAY',
                'code' => 'alipay',
                'route' => 'multisafepay/alipay',
                'description' => $this->language->get('text_title_alipay'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_alipay')
            ),
            array(
                'id' => 'ALIPAYPLUS',
                'code' => 'alipayplus',
                'route' => 'multisafepay/alipayplus',
                'description' => $this->language->get('text_title_alipayplus'),
                'type' => 'gateway',
                'redirect_switch' => false,
                'brief_description' => $this->language->get('text_brief_description_alipay')
            ),
            array(
                'id' => 'AMAZONBTN',
                'code' => 'amazonbtn',
                'route' => 'multisafepay/amazonPay',
                'description' => $this->language->get('text_title_amazon_pay'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_amazonpay')
            ),
            array(
                'id' => 'AMEX',
                'code' => 'amex',
                'route' => 'multisafepay/amex',
                'description' => $this->language->get('text_title_american_express'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_amex')
            ),
            array(
                'id' => 'APPLEPAY',
                'code' => 'applepay',
                'route' => 'multisafepay/applePay',
                'description' => $this->language->get('text_title_apple_pay'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_applepay')
            ),
            array(
                'id' => 'MISTERCASH',
                'code' => 'mistercash',
                'route' => 'multisafepay/bancontact',
                'description' => $this->language->get('text_title_bancontact'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_mistercash')
            ),
            array(
                'id' => 'BABYCAD',
                'code' => 'babycad',
                'route' => 'multisafepay/babyCad',
                'description' => $this->language->get('text_title_baby_cad'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_babycad')
            ),
            array(
                'id' => 'BANKTRANS',
                'code' => 'banktrans',
                'route' => 'multisafepay/bankTransfer',
                'description' => $this->language->get('text_title_bank_transfer'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_banktrans')
            ),
            array(
                'id' => 'BEAUTYWELL',
                'code' => 'beautywellness',
                'route' => 'multisafepay/beautyWellness',
                'description' => $this->language->get('text_title_beauty_wellness'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_beautywellness')
            ),
            array(
                'id' => 'BELFIUS',
                'code' => 'belfius',
                'route' => 'multisafepay/belfius',
                'description' => $this->language->get('text_title_belfius'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_belfius')
            ),
            array(
                'id' => 'BOEKENBON',
                'code' => 'boekenbon',
                'route' => 'multisafepay/boekenbon',
                'description' => $this->language->get('text_title_boekenbon'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_boekenbon')
            ),
            array(
                'id' => 'CBC',
                'code' => 'cbc',
                'route' => 'multisafepay/cbc',
                'description' => $this->language->get('text_title_cbc'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_cbc')
            ),
            array(
                'id' => 'CREDITCARD',
                'code' => 'creditcard',
                'route' => 'multisafepay/creditCard',
                'description' => $this->language->get('text_title_credit_card'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_creditcard')
            ),
            array(
                'id' => 'DBRTP',
                'code' => 'dbrtp',
                'route' => 'multisafepay/dbrtp',
                'description' => $this->language->get('text_title_dbrtp'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_dbrtp')
            ),
            array(
                'id' => 'DIRECTBANK',
                'code' => 'directbank',
                'route' => 'multisafepay/directBank',
                'description' => $this->language->get('text_title_direct_bank'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_directbank')
            ),
            array(
                'id' => 'DOTPAY',
                'code' => 'dotpay',
                'route' => 'multisafepay/dotpay',
                'description' => $this->language->get('text_title_dotpay'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_dotpay')
            ),
            array(
                'id' => 'EPS',
                'code' => 'eps',
                'route' => 'multisafepay/eps',
                'description' => $this->language->get('text_title_eps'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_eps')
            ),
            array(
                'id' => 'EINVOICE',
                'code' => 'einvoice',
                'route' => 'multisafepay/eInvoice',
                'description' => $this->language->get('text_title_e_invoicing'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_einvoice')
            ),
            array(
                'id' => 'FASHIONCHQ',
                'code' => 'fashioncheque',
                'route' => 'multisafepay/fashionCheque',
                'description' => $this->language->get('text_title_fashion_cheque'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_fashioncheque')
            ),
            array(
                'id' => 'FASHIONGFT',
                'code' => 'fashiongiftcard',
                'route' => 'multisafepay/fashionGiftCard',
                'description' => $this->language->get('text_title_fashion_gift_card'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_fashiongiftcard')
            ),
            array(
                'id' => 'FIETSENBON',
                'code' => 'fietsenbon',
                'route' => 'multisafepay/fietsenbon',
                'description' => $this->language->get('text_title_fietsenbon'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_fietsenbon')
            ),
            array(
                'id' => 'GEZONDHEID',
                'code' => 'gezondheidsbon',
                'route' => 'multisafepay/gezondheidsbon',
                'description' => $this->language->get('text_title_gezondheidsbon'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_gezondheidsbon')
            ),
            array(
                'id' => 'GIVACARD',
                'code' => 'givacard',
                'route' => 'multisafepay/givaCard',
                'description' => $this->language->get('text_title_giva_card'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_givacard')
            ),
            array(
                'id' => 'GIROPAY',
                'code' => 'giropay',
                'route' => 'multisafepay/giroPay',
                'description' => $this->language->get('text_title_giropay'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_giropay')
            ),
            array(
                'id' => 'GOOD4FUN',
                'code' => 'good4fun',
                'route' => 'multisafepay/good4fun',
                'description' => $this->language->get('text_title_good4fun'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_good4fun')
            ),
            array(
                'id' => 'GOODCARD',
                'code' => 'goodcard',
                'route' => 'multisafepay/goodCard',
                'description' => $this->language->get('text_title_good_card'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_goodcard')
            ),
            array(
                'id' => 'GOOGLEPAY',
                'code' => 'googlepay',
                'route' => 'multisafepay/googlePay',
                'description' => $this->language->get('text_title_google_pay'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_googlepay')
            ),
            array(
                'id' => 'IN3',
                'code' => 'in3',
                'route' => 'multisafepay/in3',
                'description' => $this->language->get('text_title_in3'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_in3')
            ),
            array(
                'id' => 'IDEAL',
                'code' => 'ideal',
                'route' => 'multisafepay/ideal',
                'description' => $this->language->get('text_title_ideal'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_ideal')
            ),
            array(
                'id' => 'IDEALQR',
                'code' => 'idealqr',
                'route' => 'multisafepay/idealQr',
                'description' => $this->language->get('text_title_ideal_qr'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_idealqr')
            ),
            array(
                'id' => 'KBC',
                'code' => 'kbc',
                'route' => 'multisafepay/kbc',
                'description' => $this->language->get('text_title_kbc'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_kbc')
            ),
            array(
                'id' => 'KLARNA',
                'code' => 'klarna',
                'route' => 'multisafepay/klarna',
                'description' => $this->language->get('text_title_klarna'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_klarna')
            ),
            array(
                'id' => 'MAESTRO',
                'code' => 'maestro',
                'route' => 'multisafepay/maestro',
                'description' => $this->language->get('text_title_maestro'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_maestro')
            ),
            array(
                'id' => 'MASTERCARD',
                'code' => 'mastercard',
                'route' => 'multisafepay/mastercard',
                'description' => $this->language->get('text_title_mastercard'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_mastercard')
            ),
            array(
                'id' => 'MYBANK',
                'code' => 'mybank',
                'route' => 'multisafepay/mybank',
                'description' => $this->language->get('text_title_mybank'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_mybank')
            ),
            array(
                'id' => 'NATNLETUIN',
                'code' => 'nationaletuinbon',
                'route' => 'multisafepay/nationaleTuinbon',
                'description' => $this->language->get('text_title_nationale_tuinbon'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_nationaletuinbon')
            ),
            array(
                'id' => 'PARFUMCADE',
                'code' => 'parfumcadeaukaart',
                'route' => 'multisafepay/parfumCadeaukaart',
                'description' => $this->language->get('text_title_parfum_cadeaukaart'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_parfumcadeaukaart')
            ),
            array(
                'id' => 'PAYAFTER',
                'code' => 'payafter',
                'route' => 'multisafepay/payAfterDelivery',
                'description' => $this->language->get('text_title_pay_after_delivery'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_payafter')
            ),
            array(
                'id' => 'BNPL_INSTM',
                'code' => 'bnpl_instm',
                'route' => 'multisafepay/payAfterDeliveryInstallments',
                'description' => $this->language->get('text_title_pay_after_delivery_installments'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_payafter_installments')
            ),
            array(
                'id' => 'PAYPAL',
                'code' => 'paypal',
                'route' => 'multisafepay/payPal',
                'description' => $this->language->get('text_title_paypal'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_paypal')
            ),
            array(
                'id' => 'PODIUM',
                'code' => 'podium',
                'route' => 'multisafepay/podium',
                'description' => $this->language->get('text_title_podium'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_podium')
            ),
            array(
                'id' => 'PSAFECARD',
                'code' => 'paysafecard',
                'route' => 'multisafepay/paysafecard',
                'description' => $this->language->get('text_title_paysafecard'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_paysafecard')
            ),
            array(
                'id' => 'SANTANDER',
                'code' => 'santander',
                'route' => 'multisafepay/betaalplan',
                'description' => $this->language->get('text_title_santander_betaalplan'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_santander')
            ),
            array(
                'id' => 'DIRDEB',
                'code' => 'dirdeb',
                'route' => 'multisafepay/dirDeb',
                'description' => $this->language->get('text_title_sepa_direct_debit'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_dirdeb')
            ),
            array(
                'id' => 'SPORTENFIT',
                'code' => 'sportfit',
                'route' => 'multisafepay/sportFit',
                'description' => $this->language->get('text_title_sport_fit'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_sportfit')
            ),
            array(
                'id' => 'TRUSTLY',
                'code' => 'trustly',
                'route' => 'multisafepay/trustly',
                'description' => $this->language->get('text_title_trustly'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_trustly')
            ),
            array(
                'id' => 'VISA',
                'code' => 'visa',
                'route' => 'multisafepay/visa',
                'description' => $this->language->get('text_title_visa'),
                'type' => 'gateway',
                'brief_description' => $this->language->get('text_brief_description_visa')
            ),
            array(
                'id' => 'VVVGIFTCRD',
                'code' => 'vvv',
                'route' => 'multisafepay/vvvGiftCard',
                'description' => $this->language->get('text_title_vvv_cadeaukaart'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_vvv')
            ),
            array(
                'id' => 'WEBSHOPGIFTCARD',
                'code' => 'webshopgiftcard',
                'route' => 'multisafepay/webshopGiftCard',
                'description' => $this->language->get('text_title_webshop_giftcard'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_webshopgiftcard')
            ),
            array(
                'id' => 'WELLNESSGIFTCARD',
                'code' => 'wellnessgiftcard',
                'route' => 'multisafepay/wellnessGiftCard',
                'description' => $this->language->get('text_title_wellness_giftcard'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_wellnessgiftcard')
            ),
            array(
                'id' => 'WIJNCADEAU',
                'code' => 'wijncadeau',
                'route' => 'multisafepay/wijnCadeau',
                'description' => $this->language->get('text_title_wijncadeau'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_wijncadeau')
            ),
            array(
                'id' => 'WINKELCHEQUE',
                'code' => 'winkelcheque',
                'route' => 'multisafepay/winkelCheque',
                'description' => $this->language->get('text_title_winkel_cheque'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_winkelcheque')
            ),
            array(
                'id' => 'YOURGIFT',
                'code' => 'yourgift',
                'route' => 'multisafepay/yourGift',
                'description' => $this->language->get('text_title_yourgift'),
                'type' => 'giftcard',
                'brief_description' => $this->language->get('text_brief_description_yourgift')
            ),
            array(
                'id' => 'GENERIC',
                'code' => 'generic',
                'route' => 'multisafepay/generic',
                'description' => $this->language->get('text_title_generic'),
                'type' => 'generic',
                'brief_description' => $this->language->get('text_brief_description_generic')
            )
        );
    }

    /**
     * Returns gateway by gateway_id
     *
     * @param string $gateway_id
     *
     * @return bool|array
     */
    public function getGatewayById(string $gateway_id): bool|array
    {
        $gateways = $this->getGateways();
        $gateway_key = array_search($gateway_id, array_column($gateways, 'id'));

        if ($gateway_key === false) {
            return false;
        }
        return $gateways[$gateway_key];
    }

    /**
     * Returns gateway by gateway type
     *
     * @param string $type
     *
     * @return array $gateways_requested
     */
    public function getGatewayByType(string $type): array
    {
        $gateways = $this->getGateways();

        $gateways_requested = array();
        foreach ($gateways as $gateway) {
            if ((string)$gateway['type'] === $type) {
                $gateways_requested[] = $gateway;
            }
        }
        return $gateways_requested;
    }

    /**
     * Includes the configurable type of transaction in the array of gateways
     *
     * @return array
     */
    public function getGatewaysWithRedirectSwitchProperty(): array
    {
        $gateways = $this->getGateways();
        foreach ($gateways as $key => $gateway) {
            $gateways[$key]['redirect_switch'] = in_array($gateway['id'], $this->configurable_type_search);
        }
        return $gateways;
    }

    /**
     * Returns ordered gateways
     *
     * @param int $store_id
     *
     * @return array $gateways
     */
    public function getOrderedGateways(int $store_id = 0): array
    {
        $gateways = $this->getGatewaysWithRedirectSwitchProperty();
        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting($this->key_prefix . 'multisafepay', $store_id);

        $sort_order = array();
        foreach ($gateways as $key => $gateway) {
            $key_sort_order = $this->key_prefix . 'multisafepay_' . $gateway['code'] . '_sort_order';
            if (!isset($settings[$key_sort_order])) {
                $sort_order[$key] = 0;
            }

            if (isset($settings[$key_sort_order])) {
                $sort_order[$key] = $settings[$this->key_prefix . 'multisafepay_' . $gateway['code']. '_sort_order'];
            }
        }
        array_multisort($sort_order, SORT_ASC, $gateways);

        return $gateways;
    }

    /**
     * Verifies the signature of a POST notification
     *
     * @param bool|string|TransactionResponse $body object
     * @param string $auth
     * @param string $api_key
     *
     * @return bool
     */
    public function verifyNotification(bool|string|TransactionResponse $body, string $auth, string $api_key): bool
    {
        require_once(DIR_EXTENSION . 'multisafepay/vendor/autoload.php');
        if (Notification::verifyNotification($body, $auth, $api_key)) {
            return true;
        }
        return false;
    }

    /**
     * Gets the transaction from a POST notification
     *
     * @param bool|string $body
     *
     * @return bool|TransactionResponse
     */
    public function getTransactionFromPostNotification(bool|string $body): bool|TransactionResponse
    {
        if (!empty($body)) {
            require_once(DIR_EXTENSION . 'multisafepay/vendor/autoload.php');
            try {
                return new TransactionResponse(json_decode($body, true), $body);
            } catch (ApiException) {
                return false;
            }
        }
        return false;
    }

    /**
     * Gets the user api token manager
     *
     * @return ApiTokenManager
     */
    public function getUserApiTokenManager(): ApiTokenManager
    {
        return $this->getSdkObject((int)$this->config->get('config_store_id'))->getApiTokenManager();
    }

    /**
     * Gets the user api token
     *
     * @return string
     */
    public function getUserApiToken(): string
    {
        $token = '';
        $api_token_manager = $this->getUserApiTokenManager();
        try {
            $get_token = $api_token_manager->get();
            $token = $get_token->getApiToken();
        } catch (ClientExceptionInterface $client_exception) {
            $this->log->write($client_exception->getMessage());
        }
        return $token;
    }

    /**
     * Toggle between 'payment_address' and 'shipping address' according to
     * 'DB->oc_setting->config_checkout_address' value
     *
     * 'shipping_address' is used by default in OpenCart 4
     *
     * @return array
     */
    public function togglePaymentShippingAddress(): array
    {
        $toggle_address = array();
        if (!empty($this->session->data['payment_address']) && $this->config->get('config_checkout_address')) {
            $toggle_address = $this->session->data['payment_address'];
        }
        else if (!empty($this->session->data['shipping_address'])) {
            $toggle_address = $this->session->data['shipping_address'];
        }
        if (empty($toggle_address)) {
            $this->load->model('account/address');

            if ($this->customer->getAddressId()) {
                $toggle_address = $this->model_account_address->getAddress($this->customer->getAddressId()) ?: array();
            }
        }
        return $toggle_address;
    }
}
