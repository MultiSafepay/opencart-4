<?php
namespace Opencart\System\Library;

use Exception;
use MultiSafepay\Api\Transactions\UpdateRequest;

class Multisafepayevents {

    private object $registry;

    private string $route;
    private string $key_prefix;
    private string $model_call;
    private string $non_standard_model_call;
    private string $extension_directory_route;

    public function __construct($registry) {
        $this->registry = $registry;
        $this->registry->set('multisafepay', new Multisafepay($registry));
        $this->route = $this->multisafepay->route;
        $this->key_prefix = $this->multisafepay->key_prefix;
        $this->model_call = $this->multisafepay->model_call;
        $this->non_standard_model_call = $this->multisafepay->non_standard_model_call;
        $this->extension_directory_route = $this->multisafepay->extension_directory_route;
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
     * All payment methods in model checkout deployed
     *
     * Trigger that is called after catalog/model/checkout/payment/method/after
     * using OpenCart events system and overwrites it
     *
     * @param string $route
     * @param array $args
     * @param $output
     *
     * @return void
     *
     * phpcs:disabled ObjectCalisthenics.Metrics.MaxNestingLevel
     */
    public function catalogModelCheckoutPaymentMethodAfter(string &$route, array &$args, &$output): void
    {
        $totals = $sort_order = $method_data = array();
        $taxes = $this->cart->getTaxes();
        $total = 0.0;

        $this->load->model('setting/extension');

        $results = $this->model_setting_extension->getExtensionsByType('total');

        foreach ($results as $key => $value) {
            $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
        }

        array_multisort($sort_order, SORT_ASC, $results);

        foreach ($results as $result) {
            if ($this->config->get('total_' . $result['code'] . '_status')) {
                $this->load->model('extension/' . $result['extension'] . '/total/' . $result['code']);
                // __call can not pass-by-reference, so we get PHP to call it as an anonymous function.
                ($this->{'model_extension_' . $result['extension'] . '_total_' . $result['code']}->getTotal)($totals, $taxes, $total);
            }
        }

        $this->load->model('setting/extension');
        $results = $this->model_setting_extension->getExtensionsByType('payment');
        $recurring = $this->cart->hasSubscription();
        $toggle_address = $this->multisafepay->togglePaymentShippingAddress();

        foreach ($results as $result) {
            if (((string)$result['code'] === 'multisafepay') && $this->config->get($this->key_prefix . 'multisafepay_status')) {
                $this->load->model($this->extension_directory_route . 'payment/multisafepay');
                $method = $this->{$this->model_call}->getMethod();
                $method_data = $this->extractPaymentMethodsArray($method, $result, $total, $recurring, $method_data, $toggle_address);
            }
            if (((string)$result['code'] !== 'multisafepay') && $this->config->get($this->key_prefix . $result['code'] . '_status')) {
                $this->load->model('extension/' . $result['extension'] . '/payment/' . $result['code']);
                $method = $this->{'model_extension_' . $result['extension'] . '_payment_' . $result['code']}->getMethods($toggle_address);

                if ($method) {
                    $method_data[$result['code']] = $method;
                }
            }
        }

        $output = $this->sortMethods($method_data);
    }

    /**
     * Returns the payment methods ordered according to a natural language criteria
     *
     * @param array $method_data
     *
     * @return array
     */
    private function sortMethods(array $method_data): array
    {
        $sort_order = array();
        foreach ($method_data as $key => $value) {
            if ($value['sort_order'] && str_contains($key, 'multisafepay')) {
                $sort_order[$key] = $this->config->get($this->key_prefix . 'multisafepay_sort_order') . '.' . $value['sort_order'];
            }
            if (!$value['sort_order'] && str_contains($key, 'multisafepay')) {
                $sort_order[$key] = $this->config->get($this->key_prefix . 'multisafepay_sort_order');
            }
            if (!str_contains($key, 'multisafepay')) {
                $sort_order[$key] = $value['sort_order'];
            }
        }
        array_multisort($sort_order, SORT_ASC, SORT_NATURAL, $method_data);
        return $method_data;
    }

    /**
     * Extract payment methods from a loop for each payment extension
     *
     * @param bool|array $method
     * @param array $extension
     * @param float $total
     * @param bool $recurring
     * @param array $method_data
     * @param array $toggle_address
     *
     * @return array
     */
    private function extractPaymentMethodsArray(bool|array $method, array $extension, float $total, bool $recurring = false, array $method_data = array(), array $toggle_address = array()): array
    {
        if ($method && !$recurring && ((string)$extension['code'] === 'multisafepay')) {
            $methods = $this->{$this->non_standard_model_call . '_payment_multisafepay'}->getMethods($toggle_address, $total);
            foreach ($methods as $multisafepay_method) {
                $method_data[$multisafepay_method['code']] = $multisafepay_method;
            }
        }
        return $method_data;
    }

    /**
     * Simplify payment method name so can be found on database for extensions
     *
     * Trigger that is called after catalog/model/setting/extension/after
     * using OpenCart events system and overwrites it
     *
     * @param string $route
     * @param array $args
     * @param $output
     *
     * @return void
     */
    public function catalogModelSettingExtensionAfter(string &$route, array &$args, &$output): void
    {
        foreach ($args as $value) {
            if (str_contains($value, 'multisafepay')) {
                $gateway = 'multisafepay';
                $gateway_name_array = explode('/', $value);
                if (!empty($gateway_name_array[1])) {
                    $gateway .= '.' . strtolower($gateway_name_array[1]);
                }

                $output = array(
                    'extension' => 'multisafepay',
                    'code' => $gateway
                );
            }
        }
    }

    /**
     * Sets as invoiced the order in MultiSafepay
     *
     * Trigger that is called before admin/model/sale/order/createInvoiceNo/before
     * using OpenCart events system and overwrites it
     *
     * @param string $route
     * @param array $args
     *
     * @return void
     */
    public function adminModelSaleOrderCreateInvoiceNoBefore(string $route, array $args): void
    {
        if ($args) {
            $order_id = (int)$args[0];
            $this->load->model('sale/order');
            $this->load->model($this->route);
            $order_info = $this->model_sale_order->getOrder($order_id);
            $invoice_id = '';

            if ($order_info &&
                isset($order_info['payment_method']['code']) &&
                str_contains($order_info['payment_method']['code'], 'multisafepay')
            ) {
                $invoice_no = $this->{$this->model_call}->getNextInvoiceId($order_id);
                $invoice_id = $order_info['invoice_prefix'] . $invoice_no;

                $this->registry->set('multisafepay', new Multisafepay($this->registry));
                $sdk = $this->multisafepay->getSdkObject((int)$order_info['store_id']);
                $transaction_manager = $sdk->getTransactionManager();

                $update_order = new UpdateRequest();
                $update_order->addData(array('invoice_id' => $invoice_id));
                $transaction_manager->update((string)$order_id, $update_order);
            }

            if ($order_info &&
                isset($order_info['payment_method']['code']) &&
                str_contains($order_info['payment_method']['code'], 'multisafepay') &&
                $this->model_setting_setting->getValue(
                    $this->key_prefix . 'multisafepay_debug_mode',
                    (int)$order_info['store_id']
                )
            ) {
                $this->log->write('OpenCart Event to send Invoice ID: ' . $invoice_id . ' to MultiSafepay, for Order ID ' . $order_id . '.');
            }
        }
    }

    /**
     * Sets MultiSafepay tab in admin order view page
     *
     * Trigger that is called before admin/view/sale/order_info/before
     * using OpenCart events system and overwrites it
     *
     * @param string $route
     * @param array $args
     *
     * @return void
     */
    public function adminViewSaleOrderInfoBefore(string &$route, array &$args): void
    {
        unset($args['tabs']);
        $args['tabs'] = array();

        $this->load->model('sale/order');
        $order_info = $this->model_sale_order->getOrder((int)$args['order_id']);

        $this->registry->set('multisafepay', new Multisafepay($this->registry));
        $multisafepay_order = $this->multisafepay->getAdminOrderObject((int)$args['order_id']);

        if ($multisafepay_order &&
            isset($order_info['payment_method']['code']) &&
            str_contains($order_info['payment_method']['code'], 'multisafepay') &&
            $multisafepay_order->getTransactionId() &&
            $this->user->hasPermission('access', $this->route)
        ) {
            $this->load->language($this->route);
            $content = $this->load->controller($this->route . '.order');

            $args['tabs'][] = array(
                'code' => 'multisafepay-order',
                'title' => $this->language->get('tab_order'),
                'content' => $content
            );
        }

        if (!empty($order_info['payment_method']['code'])) {
            if (str_contains($order_info['payment_method']['code'], 'multisafepay')) {
                $order_info['payment_method']['code'] = 'multisafepay';
            }
            $extension_info = $this->model_setting_extension->getExtensionByCode('payment', $order_info['payment_method']['code']);

            if ($extension_info
                && (!str_contains($extension_info['extension'], 'multisafepay'))
                && $this->user->hasPermission('access', 'extension/' . $extension_info['extension'] . '/payment/' . $extension_info['code'])
            ) {
                $content = '';
                if (is_file(DIR_EXTENSION . $extension_info['extension'] . '/admin/controller/payment/' . $extension_info['code'] . '.php')) {
                    $content = $this->load->controller('extension/' . $extension_info['extension'] . '/payment/' . $extension_info['code'] . '.order');
                }
                if (!$content instanceof Exception) {
                    $this->load->language('extension/' . $extension_info['extension'] . '/payment/' . $extension_info['code'], 'extension');

                    $args['tabs'][] = array(
                        'code' => $extension_info['code'],
                        'title' => $this->language->get('extension_heading_title'),
                        'content' => $content
                    );
                }
            }
        }

        // Extension Order Tabs can are called here.
        $this->load->model('setting/extension');

        $extensions = $this->model_setting_extension->getExtensionsByType('fraud');

        foreach ($extensions as $extension) {
            if ($this->config->get('fraud_' . $extension['code'] . '_status')) {
                $content = $this->load->controller('extension/' . $extension['extension'] . '/fraud/' . $extension['code'] . '.order');

                if (!$content instanceof Exception) {
                    $this->load->language('extension/' . $extension['extension'] . '/fraud/' . $extension['code'], 'extension');

                    $args['tabs'][] = array(
                        'code' => $extension['code'],
                        'title'   => $this->language->get('extension_heading_title'),
                        'content' => $content
                    );
                }
            }
        }
    }

    /**
     * Add CSS on Header and JS on Footer to the checkout page
     *
     * Trigger that is called catalog/view/common/header/before and ... catalog/view/common/footer/before
     * using OpenCart events system and overwrites it
     *
     * @param string $route
     * @param array $args
     * @param string $position
     *
     * @return array
     */
    public function catalogViewCommonHeaderFooterBefore(string &$route, array &$args, string $position): array
    {
        if (!empty($this->request->get['route']) && str_contains($this->request->get['route'], 'checkout/checkout')) {
            if ($position === 'header') {
                $this->document->addStyle('https://pay.multisafepay.com/sdk/components/v2/components.css');
                $this->document->addStyle('../extension/multisafepay/catalog/view/stylesheet/multisafepay.css');
                $this->document->addStyle('../extension/multisafepay/catalog/view/stylesheet/select2.min.css');
                $args['styles'] = $this->document->getStyles();
                return $args;
            }

            $js_position = 'footer';
            $this->document->addScript('https://pay.multisafepay.com/sdk/components/v2/components.js', $js_position);
            $this->document->addScript('../extension/multisafepay/catalog/view/javascript/multisafepay.js', $js_position);
            $this->document->addScript('../extension/multisafepay/catalog/view/javascript/select2.min.js', $js_position);

            if ($this->config->get($this->key_prefix . 'multisafepay_googlepay_status')) {
                $this->document->addScript('https://pay.google.com/gp/p/js/pay.js', $js_position);
            }
            $args['scripts'] = $this->document->getScripts($js_position);
        }
        return $args;
    }
}
