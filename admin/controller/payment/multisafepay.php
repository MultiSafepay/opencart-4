<?php
namespace Opencart\Admin\Controller\Extension\Multisafepay\Payment;

require_once(DIR_EXTENSION . 'multisafepay/system/library/multisafepay.php');
require_once(DIR_EXTENSION . 'multisafepay/system/library/multisafepayevents.php');

use Opencart\System\Engine\Controller;
use MultiSafepay\Api\Transactions\TransactionResponse;
use Opencart\System\Library\Multisafepayevents;

class Multisafepay extends Controller {

    protected $registry;

    private string $oc_version;
    private string $route;
    private string $key_prefix;
    private string $extension_list_route;
    private string $token_name;
    private string $model_call;
    private string $extension_directory_route;
    private string $customer_group_model_route;
    private string $customer_group_model_call;
    private array $error = array();

    public function __construct($registry) {
        parent::__construct($registry);
        $this->registry->set('multisafepay', new \Opencart\System\Library\Multisafepay($registry));
        $this->oc_version = $this->multisafepay->oc_version;
        $this->route = $this->multisafepay->route;
        $this->key_prefix = $this->multisafepay->key_prefix;
        $this->extension_list_route = $this->multisafepay->extension_list_route;
        $this->token_name = $this->multisafepay->token_name;
        $this->model_call = $this->multisafepay->model_call;
        $this->extension_directory_route = $this->multisafepay->extension_directory_route;
        $this->customer_group_model_route = $this->multisafepay->customer_group_model_route;
        $this->customer_group_model_call = $this->multisafepay->customer_group_model_call;
    }

    /**
     * Handles the settings form page for MultiSafepay payment extension
     *
     * @return void
     */
    public function index(): void
    {
        $this->registry->set('multisafepay', new \Opencart\System\Library\Multisafepay($this->registry));

        $this->load->language($this->route);

        $this->document->setTitle($this->language->get('heading_title'));
        $this->document->addStyle('../extension/multisafepay/admin/view/stylesheet/multisafepay.css');
        $this->document->addScript('../extension/multisafepay/admin/view/javascript/dragula.js');
        $this->document->addScript('../extension/multisafepay/admin/view/javascript/multisafepay.js');

        $this->load->model('setting/setting');
        $this->load->model($this->route);

        $data = $this->getTexts();

        $data['heading_title'] = $this->language->get('heading_title') . ' <small>v.' . $this->multisafepay->getPluginVersion() . '</small>';
        $data['store_id'] = 0;

        $data['needs_upgrade'] = $this->{$this->model_call}->checkForNewVersions();

        if ($data['needs_upgrade']) {
            $data['text_needs_upgrade_warning'] = sprintf($this->language->get('text_needs_upgrade_warning'), 'https://www.opencart.com/index.php?route=marketplace/extension/info&extension_id=39960');
        }

        if (isset($this->request->get['store_id'])) {
            $data['store_id'] = (int)$this->request->get['store_id'];
        }
        $data['stores'] = $this->getStores();
        $data['token'] = $this->session->data[$this->token_name];
        $data['token_name'] = $this->token_name;
        $data['oc_version'] = $this->oc_version;

        $data[$this->token_name] = $this->session->data[$this->token_name];
        $data['key_prefix'] = $this->key_prefix;

        $breadcrumbs_array = array();
        $breadcrumbs_array[] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', $this->token_name . '=' . $this->session->data[$this->token_name], true)
        );

        $breadcrumbs_array[] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', $this->token_name . '=' . $this->session->data[$this->token_name] . '&type=payment', true)
        );

        $breadcrumbs_array[] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link($this->route, $this->token_name . '=' . $this->session->data[$this->token_name], true)
        );
        $data['breadcrumbs'] = $breadcrumbs_array;

        if (isset($this->request->get['store_id'])) {
            $data['save'] = $this->url->link($this->route . '.save', $this->token_name . '=' . $this->session->data[$this->token_name] . '&store_id=' . $data['store_id'], true);
        }
        if (!isset($this->request->get['store_id'])) {
            $data['save'] = $this->url->link($this->route . '.save', $this->token_name . '=' . $this->session->data[$this->token_name], true);
        }

        $data['back'] = $this->url->link($this->extension_list_route, $this->token_name . '=' . $this->session->data[$this->token_name] . '&type=payment', true);

        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $gateways = $this->multisafepay->getOrderedGateways($data['store_id']);

        $data['gateways'] = $gateways;
        $data['configurable_payment_component'] = $this->multisafepay->configurable_payment_component;
        $data['configurable_tokenization'] = $this->multisafepay->configurable_tokenization;

        $this->load->model($this->customer_group_model_route);
        $data['customer_groups'] = $this->{$this->customer_group_model_call}->getCustomerGroups(array('sort' => 'cg.sort_order'));

        $this->load->model('localisation/currency');
        $data['currencies'] = $this->model_localisation_currency->getCurrencies();

        $fields = $this->getFields();
        $data['payment_methods_fields_values'] = $this->getPaymentMethodsFieldsValues($data['store_id']);

        foreach ($fields as $field) {
            if (isset($this->request->post[$field])) {
                $data[$field] = $this->request->post[$field];
                continue;
            }
            if (!isset($this->request->post[$field])) {
                $data[$field] = $this->{$this->model_call}->getSettingValue($field, $data['store_id']);
            }
        }

        // Generic
        $data['payment_generic_fields_values'] = $this->getPaymentGenericFieldsValues($data['store_id']);

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view($this->route, $data));
    }

    /**
     * Define the common fields for each payment method
     *
     * @return array
     */
    private function getPaymentGenericFields(): array
    {
        return array(
            'name',
            'code',
            'require_shopping_cart'
        );
    }

    /**
     * Returns the values of fields for each generic methods keys
     *
     * @param int $store_id
     *
     * @return array
     */
    private function getPaymentGenericFieldsValues(int $store_id = 0): array
    {
        $this->registry->set('multisafepay', new \Opencart\System\Library\Multisafepay($this->registry));
        $generic_gateways = $this->multisafepay->getGatewayByType('generic');
        $fields = array();
        if (!empty($generic_gateways)) {
            foreach ($generic_gateways as $gateway) {
                $fields = $this->extractGenericFieldsByGateway($gateway, $fields, $store_id);
            }
        }
        return $fields;
    }

    /**
     * Extract the values of fields for each generic methods keys for each gateway
     *
     * @param array $gateway
     * @param array $fields
     * @param int $store_id
     *
     * @return array
     *
     * phpcs:disabled ObjectCalisthenics.ControlStructures.NoElse
     */
    private function extractGenericFieldsByGateway(array $gateway, array $fields, int $store_id = 0): array
    {
        $payment_fields = $this->getPaymentGenericFields();

        foreach ($payment_fields as $payment_field) {
            $generic_field_key = $this->key_prefix . 'multisafepay_' . $gateway['code'] . '_' . $payment_field;
            if (!empty($this->request->post[$generic_field_key])) {
                $request_post = $this->request->post[$generic_field_key];
            }

            if (isset($request_post)) {
                $fields[$gateway['code']][$payment_field] = $request_post;
            } else {
                $fields[$gateway['code']][$payment_field] = $this->{$this->model_call}->getSettingValue($this->key_prefix . 'multisafepay_' . $gateway['code'] . '_' . $payment_field, $store_id);
            }
        }
        return $fields;
    }

    /**
     * Uninstall action by default for this admin extension controller
     *
     * @return void
     */
    public function uninstall(): void
    {
        $this->deleteMultiSafepayEvents();
    }

    /**
     * Add the events to OpenCart when the extension is enabled
     *
     * @return void
     */
    private function addMultiSafepayEvents(): void
    {
        if (!$this->oc_version) {
            return;
        }

        $this->load->model('setting/event');

        // All payment methods in model checkout deployed after: multisafepay_all_methods_at_model
        $event_multisafepay_multiple_gateways_model = $this->model_setting_event->getEventByCode('multisafepay_all_methods_at_model');
        if (!$event_multisafepay_multiple_gateways_model) {
            $this->model_setting_event->addEvent(
                array(
                    'code' => 'multisafepay_all_methods_at_model',
                    'description' => 'All payment methods in model checkout deployed after',
                    'trigger' => 'catalog/model/checkout/payment_method/getMethods/after',
                    'action' => $this->extension_directory_route . 'payment/multisafepay.catalogModelCheckoutPaymentMethodAfter',
                    'status' => 1,
                    'sort_order' => 0
                )
            );
        }

        // Simplify payment method name so can be found on database for extensions after: multisafepay_simplify_methods
        $event_multisafepay_simplify_methods = $this->model_setting_event->getEventByCode('multisafepay_simplify_methods');
        if (!$event_multisafepay_simplify_methods) {
            $this->model_setting_event->addEvent(
                array(
                    'code' => 'multisafepay_simplify_methods',
                    'description' => 'Simplify payment method name so can be found on database for extensions after',
                    'trigger' => 'catalog/model/setting/extension/getExtensionByCode/after',
                    'action' => $this->extension_directory_route . 'payment/multisafepay.catalogModelSettingExtensionAfter',
                    'status' => 1,
                    'sort_order' => 0
                )
            );
        }

        // Set as invoiced the order in MSP before: multisafepay_set_invoiced_to_msp
        $event_multisafepay_create_invoice = $this->model_setting_event->getEventByCode('multisafepay_set_invoiced_to_msp');
        if (!$event_multisafepay_create_invoice) {
            $this->model_setting_event->addEvent(
                array(
                    'code' => 'multisafepay_set_invoiced_to_msp',
                    'description' => 'Set as invoiced the order in MSP',
                    'trigger' => 'admin/model/sale/order/createInvoiceNo/before',
                    'action' => $this->extension_directory_route . 'payment/multisafepay.adminModelSaleOrderCreateInvoiceNoBefore',
                    'status' => 1,
                    'sort_order' => 0
                )
            );
        }

        // Set MultiSafepay tab in admin order view page before: multisafepay_set_order_tab
        $event_multisafepay_order_tabs = $this->model_setting_event->getEventByCode('multisafepay_set_order_tab');
        if (!$event_multisafepay_order_tabs) {
            $this->model_setting_event->addEvent(
                array(
                    'code' => 'multisafepay_set_order_tab',
                    'description' => 'Set MultiSafepay tab in admin order view page',
                    'trigger' => 'admin/view/sale/order_info/before',
                    'action' => $this->extension_directory_route . 'payment/multisafepay.adminViewSaleOrderInfoBefore',
                    'status' => 1,
                    'sort_order' => 0
                )
            );
        }

        // Add CSS on Header to the checkout page before: multisafepay_assets_header
        $event_api_multisafepay_add_payment_component_asset_header = $this->model_setting_event->getEventByCode('multisafepay_assets_header');
        if (!$event_api_multisafepay_add_payment_component_asset_header) {
            $this->model_setting_event->addEvent(
                array(
                    'code' => 'multisafepay_assets_header',
                    'description' => 'Add CSS on Header to the checkout page',
                    'trigger' => 'catalog/view/common/header/before',
                    'action' => $this->extension_directory_route . 'payment/multisafepay.catalogViewCommonHeaderBefore',
                    'status' => 1,
                    'sort_order' => 0
                )
            );
        }

        // Add JS on Footer to the checkout page before: multisafepay_assets_footer
        $event_api_multisafepay_add_payment_component_asset_footer = $this->model_setting_event->getEventByCode('multisafepay_assets_footer');
        if (!$event_api_multisafepay_add_payment_component_asset_footer) {
            $this->model_setting_event->addEvent(
                array(
                    'code' => 'multisafepay_assets_footer',
                    'description' => 'Add JS on Footer to the checkout page',
                    'trigger' => 'catalog/view/common/footer/before',
                    'action' => $this->extension_directory_route . 'payment/multisafepay.catalogViewCommonFooterBefore',
                    'status' => 1,
                    'sort_order' => 0
                )
            );
        }
    }

    /**
     * Delete the events from OpenCart when the extension is disabled or uninstalled
     *
     * @return void
     */
    private function deleteMultiSafepayEvents(): void
    {
        if (!$this->oc_version) {
            return;
        }

        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('multisafepay_all_methods_at_model');
        $this->model_setting_event->deleteEventByCode('multisafepay_simplify_methods');
        $this->model_setting_event->deleteEventByCode('multisafepay_set_invoiced_to_msp');
        $this->model_setting_event->deleteEventByCode('multisafepay_set_order_tab');
        $this->model_setting_event->deleteEventByCode('multisafepay_assets_header');
        $this->model_setting_event->deleteEventByCode('multisafepay_assets_footer');
    }

    /**
     * Define the common fields for each payment method
     *
     * @return array
     */
    private function getPaymentMethodsFields(): array
    {
        return array(
            'status',
            'min_amount',
            'max_amount',
            'currency',
            'geo_zone_id',
            'customer_group_id',
            'order_status_id_initialized',
            'redirect',
            'sort_order',
            'payment_component',
            'tokenization'
        );
    }

    /**
     * Returns the values of fields for each payment methods keys
     *
     * @param int $store_id
     *
     * @return array
     */
    private function getPaymentMethodsFieldsValues(int $store_id = 0): array
    {
        $this->registry->set('multisafepay', new \Opencart\System\Library\Multisafepay($this->registry));
        $gateways = $this->multisafepay->getGateways();
        $fields = array();
        foreach ($gateways as $gateway) {
            $fields = $this->extractFieldsByGateway($gateway, $fields, $store_id);
        }
        return $fields;
    }

    /**
     * Extract the values of fields for each key of the payment methods, and for each gateway
     *
     * @param array $gateway
     * @param array $fields
     * @param int $store_id
     *
     * @return array
     */
    private function extractFieldsByGateway(array $gateway, array $fields, int $store_id = 0): array
    {
        $payment_fields = $this->getPaymentMethodsFields();

        foreach ($payment_fields as $payment_field) {
            $request_string = $this->key_prefix . 'multisafepay_' . $gateway['code'] . '_' . $payment_field;
            if (!empty($this->request->post[$request_string])) {
                $request_post = $this->request->post[$request_string];
            }

            if (isset($request_post)) {
                $fields[$gateway['code']][$payment_field] = $request_post;
            } else {
                $fields[$gateway['code']][$payment_field] = $this->{$this->model_call}->getSettingValue($request_string, $store_id);
            }
        }
        return $fields;
    }

    /**
     * Returns an array of stores to provide multi-store support
     *
     * @return array
     */
    public function getStores(): array
    {
        $shops = array();
        $shops[0] = array(
            'store_id' => 0,
            'name' => $this->config->get('config_name'),
            'href' => $this->url->link($this->route, $this->token_name . '=' . $this->session->data[$this->token_name] . '&store_id=0', true)
        );

        $this->load->model('setting/store');
        $stores = $this->model_setting_store->getStores();

        if (!empty($stores)) {
            foreach ($stores as $store) {
                $shops[$store['store_id']] = array(
                    'store_id' => (int)$store['store_id'],
                    'name' => $store['name'],
                    'href' => $this->url->link($this->route, $this->token_name . '=' . $this->session->data[$this->token_name] . '&store_id=' . (int)$store['store_id'], true)
                );
            }
        }
        return $shops;
    }

    /**
     * Returns an array of fields to process the form quickly on submit
     *
     * @return array
     */
    private function getFields(): array
    {
        return array(
            $this->key_prefix . 'multisafepay_status',
            $this->key_prefix . 'multisafepay_sort_order',
            $this->key_prefix . 'multisafepay_environment',
            $this->key_prefix . 'multisafepay_debug_mode',
            $this->key_prefix . 'multisafepay_account_type',
            $this->key_prefix . 'multisafepay_sandbox_api_key',
            $this->key_prefix . 'multisafepay_api_key',
            $this->key_prefix . 'multisafepay_order_description',
            $this->key_prefix . 'multisafepay_days_active',
            $this->key_prefix . 'multisafepay_unit_lifetime_payment_link',
            $this->key_prefix . 'multisafepay_second_chance',
            $this->key_prefix . 'multisafepay_shopping_cart_disabled',
            $this->key_prefix . 'multisafepay_order_status_id_initialized',
            $this->key_prefix . 'multisafepay_order_status_id_completed',
            $this->key_prefix . 'multisafepay_order_status_id_uncleared',
            $this->key_prefix . 'multisafepay_order_status_id_reserved',
            $this->key_prefix . 'multisafepay_order_status_id_void',
            $this->key_prefix . 'multisafepay_order_status_id_refunded',
            $this->key_prefix . 'multisafepay_order_status_id_declined',
            $this->key_prefix . 'multisafepay_order_status_id_expired',
            $this->key_prefix . 'multisafepay_order_status_id_shipped',
            $this->key_prefix . 'multisafepay_order_status_id_partial_refunded',
            $this->key_prefix . 'multisafepay_order_status_id_cancelled',
            $this->key_prefix . 'multisafepay_custom_order_total_keys',
            $this->key_prefix . 'multisafepay_payment_component_template_id'
        );
    }

    /**
     * Returns an array of error keys to be used in validations
     *
     * @return array
     */
    private function getErrorsKeysAndTypes(): array
    {
        return array(
            'warning' => 'string',
            'api_key' => 'string',
            'sandbox_api_key' => 'string',
            'days_active' => 'string'
        );
    }

    /**
     * Handles the form validation in the setting page.
     *
     * @return bool|array
     */
    protected function validate(): bool|array
    {
        $this->load->model($this->route);

        if (!$this->user->hasPermission('modify', $this->route)) {
            $this->error['warning'] = $this->language->get('error_check_form');
            return !$this->error;
        }

        if (((string)$this->request->post[$this->key_prefix . 'multisafepay_environment'] === '1') && ((string)$this->request->post[$this->key_prefix . 'multisafepay_sandbox_api_key'] === '')) {
            $this->error['sandbox_api_key'] = $this->language->get('error_empty_api_key');
        }
        if (((string)$this->request->post[$this->key_prefix . 'multisafepay_environment'] === '0') && ((string)$this->request->post[$this->key_prefix . 'multisafepay_api_key'] === '')) {
            $this->error['api_key'] = $this->language->get('error_empty_api_key');
        }

        if (!isset($this->request->post[$this->key_prefix . 'multisafepay_days_active']) || ((int)$this->request->post[$this->key_prefix . 'multisafepay_days_active'] < 1)) {
            $this->error['days_active'] = $this->language->get('error_days_active');
        }

        if ($this->error) {
            $this->error['warning'] = $this->language->get('error_check_form');
        }
        return !$this->error;
    }

    /**
     * Load all language strings values and keys into $this->data
     *
     * @return array
     */
    public function getTexts(): array
    {
        $this->load->language($this->route);
        return $this->getSupportTabData();
    }

    /**
     * Returns the array of texts used in the Support Tab
     *
     * @return array
     */
    private function getSupportTabData(): array
    {
        $this->registry->set('multisafepay', new \Opencart\System\Library\Multisafepay($this->registry));
        $plugin_version = $this->multisafepay->getPluginVersion();

        $support_variables = array(
            'support_row_value_multisafepay_version' => $plugin_version,
            'support_row_value_multisafepay_version_oc_supported' => '4.0.0.0, 4.0.1.0, 4.0.1.1',
            'support_manual_link' => 'https://docs.multisafepay.com/docs/opencart#user-guide',
            'support_changelog_link' => 'https://github.com/MultiSafepay/OpenCart/blob/master/CHANGELOG.md',
            'support_faq_link' => 'https://docs.multisafepay.com/docs/opencart',
            'support_api_documentation_link' => 'https://docs.multisafepay.com/reference/introduction',
            'support_multisafepay_github_link' => 'https://github.com/MultiSafepay/OpenCart',
            'support_create_test_account' => 'https://testmerchant.multisafepay.com/signup',
            'sales_telephone_netherlands' => 'tel:+31208500501',
            'sales_readable_telephone_netherlands' => '+31 (0)20 - 8500501',
            'sales_email_netherlands' => 'mailto:sales@multisafepay.com',
            'sales_readable_email_netherlands' => 'sales@multisafepay.com',
            'sales_telephone_belgium' => 'tel:+3238081241',
            'sales_readable_telephone_belgium' => '+32 3 808 12 41',
            'sales_email_belgium' => 'mailto:sales.belgium@multisafepay.com',
            'sales_readable_email_belgium' => 'sales.belgium@multisafepay.com',
            'sales_telephone_spain' => 'tel:+34911230486',
            'sales_readable_telephone_spain' => '+34 911 230 486',
            'sales_email_spain' => 'mailto:comercial@multisafepay.es',
            'sales_readable_email_spain' => 'comercial@multisafepay.es',
            'sales_telephone_italy' => 'tel:+390294750118',
            'sales_readable_telephone_italy' => '+39 02 947 50 118',
            'sales_email_italy' => 'mailto:sales@multisafepay.it',
            'sales_readable_email_italy' => 'sales@multisafepay.it',
            'support_assistance_telephone' => 'tel:+31208500500',
            'support_assistance_readable_telephone' => '+31 (0)20 - 8500500',
            'support_assistance_readable_email' => 'integration@multisafepay.com',
            'support_assistance_email' => 'mailto:integration@multisafepay.com'
        );

        $data['text_row_value_multisafepay_version'] = sprintf(
            $this->language->get('text_row_value_multisafepay_version'),
            $support_variables['support_row_value_multisafepay_version']
        );

        $data['text_row_value_multisafepay_version_oc_supported'] = sprintf(
            $this->language->get('text_row_value_multisafepay_version_oc_supported'),
            $support_variables['support_row_value_multisafepay_version_oc_supported']
        );

        $data['text_manual_link'] = sprintf(
            $this->language->get('text_manual_link'),
            $support_variables['support_manual_link']
        );

        $data['text_changelog_link'] = sprintf(
            $this->language->get('text_changelog_link'),
            $support_variables['support_changelog_link']
        );

        $data['text_faq_link'] = sprintf(
            $this->language->get('text_faq_link'),
            $support_variables['support_faq_link']
        );

        $data['text_api_documentation_link'] = sprintf(
            $this->language->get('text_api_documentation_link'),
            $support_variables['support_api_documentation_link']
        );

        $data['text_multisafepay_github_link'] = sprintf(
            $this->language->get('text_multisafepay_github_link'),
            $support_variables['support_multisafepay_github_link']
        );

        $data['text_create_test_account'] = sprintf(
            $this->language->get('text_create_test_account'),
            $support_variables['support_create_test_account']
        );

        $data['text_sales_telephone_netherlands'] = sprintf(
            $this->language->get('text_sales_telephone'),
            $support_variables['sales_telephone_netherlands'],
            $support_variables['sales_readable_telephone_netherlands']
        );

        $data['text_sales_email_netherlands'] = sprintf(
            $this->language->get('text_sales_email'),
            $support_variables['sales_email_netherlands'],
            $support_variables['sales_readable_email_netherlands']
        );

        $data['text_sales_telephone_belgium'] = sprintf(
            $this->language->get('text_sales_telephone'),
            $support_variables['sales_telephone_belgium'],
            $support_variables['sales_readable_telephone_belgium']
        );

        $data['text_sales_email_belgium'] = sprintf(
            $this->language->get('text_sales_email'),
            $support_variables['sales_email_belgium'],
            $support_variables['sales_readable_email_belgium']
        );

        $data['text_sales_telephone_spain'] = sprintf(
            $this->language->get('text_sales_telephone'),
            $support_variables['sales_telephone_spain'],
            $support_variables['sales_readable_telephone_spain']
        );

        $data['text_sales_email_spain'] = sprintf(
            $this->language->get('text_sales_email'),
            $support_variables['sales_email_spain'],
            $support_variables['sales_readable_email_spain']
        );

        $data['text_sales_telephone_italy'] = sprintf(
            $this->language->get('text_sales_telephone'),
            $support_variables['sales_telephone_italy'],
            $support_variables['sales_readable_telephone_italy']
        );

        $data['text_sales_email_italy'] = sprintf(
            $this->language->get('text_sales_email'),
            $support_variables['sales_email_italy'],
            $support_variables['sales_readable_email_italy']
        );

        $data['text_assistance_telephone'] = sprintf(
            $this->language->get('text_assistance_telephone'),
            $support_variables['support_assistance_telephone'],
            $support_variables['support_assistance_readable_telephone']
        );

        $data['text_assistance_email'] = sprintf(
            $this->language->get('text_assistance_email'),
            $support_variables['support_assistance_email'],
            $support_variables['support_assistance_readable_email']
        );
        return $data;
    }

    /**
     * Returns an array of results from the refund request transaction (in JSON format)
     *
     * @return bool
     */
    public function refundOrder(): bool
    {
        if (!isset($this->request->get['order_id'])) {
            return false;
        }

        $this->load->language($this->route);
        $json = array();
        $this->registry->set('multisafepay', new \Opencart\System\Library\Multisafepay($this->registry));

        $multisafepay_order = $this->multisafepay->getAdminOrderObject((int)$this->request->get['order_id']);
        $order_info = $this->multisafepay->getAdminOrderInfo((int)$this->request->get['order_id']);
        $refund_request = $this->multisafepay->createRefundRequestObject($multisafepay_order);
        $refund_request->addMoney($multisafepay_order->getMoney());
        $description = sprintf($this->language->get('text_description_refunded'), (int)$this->request->get['order_id'], date($this->language->get('datetime_format')));
        $refund_request->addDescriptionText($description);

        if ($this->refundWithShoppingCart($order_info, $multisafepay_order)) {
            $multisafepay_shopping_cart = $multisafepay_order->getShoppingCart();
            $multisafepay_shopping_cart_data = $multisafepay_shopping_cart->getData();
            foreach ($multisafepay_shopping_cart_data['items'] as $multisafepay_cart_item) {
                $checkout_data = $refund_request->getCheckoutData();
                $checkout_data->refundByMerchantItemId($multisafepay_cart_item['merchant_item_id'], $multisafepay_cart_item['quantity']);
            }
        }

        $process_refund = $this->multisafepay->processRefundRequestObject($multisafepay_order, $refund_request);

        if (!$process_refund) {
            $json['error'] = $this->language->get('text_refund_error');
        }

        if ($process_refund) {
            $this->load->model($this->route);
            $this->{$this->model_call}->removeCouponsVouchersRewardsPointsAffiliateCommission((int)$this->request->get['order_id']);
            $order_info = $this->multisafepay->getAdminOrderInfo((int)$this->request->get['order_id']);
            $status_id_refunded = (int)$this->{$this->model_call}->getSettingValue($this->key_prefix . 'multisafepay_order_status_id_refunded', (int)$order_info['store_id']);
            $this->{$this->model_call}->addOrderHistory((int)$this->request->get['order_id'], $status_id_refunded, $description);
            $json['success'] = $this->language->get('text_refund_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
        return true;
    }

    /**
     * Check if ShoppingCart is required to process a refund
     *
     * @param array $order_info
     * @param TransactionResponse $multisafepay_order
     *
     * @return bool
     */
    private function refundWithShoppingCart(array $order_info, TransactionResponse $multisafepay_order): bool
    {
        if (isset($order_info['payment_method']['code']) &&
            str_contains((string)$order_info['payment_method']['code'], 'multisafepay/generic') &&
            $this->{$this->model_call}->getSettingValue($this->key_prefix . 'multisafepay_generic_require_shopping_cart', (int)$order_info['store_id'])
        ) {
            return true;
        }

        if ($multisafepay_order->requiresShoppingCart()) {
            return true;
        }
        return false;
    }

    /**
     * Returns an array of results from the cancel or shipped order update transaction (in JSON format)
     *
     * @return bool
     */
    public function changeMultiSafepayOrderStatusTo(): bool
    {
        if (!isset($this->request->get['order_id'], $this->request->get['type'])) {
            return false;
        }

        $order_id = (int)$this->request->get['order_id'];
        $type = $this->request->get['type'];
        $this->load->language($this->route);
        $json = array();

        // Set Order Status
        $this->registry->set('multisafepay', new \Opencart\System\Library\Multisafepay($this->registry));
        $order_info = $this->multisafepay->getAdminOrderInfo($order_id);
        $this->multisafepay->changeMultiSafepayOrderStatusTo($order_id, $type);

        $order_status_id = 0;
        if ((string)$type === 'cancelled') {
            $order_status_id = (int)$this->{$this->model_call}->getSettingValue($this->key_prefix . 'multisafepay_order_status_id_cancelled', (int)$order_info['store_id']);
        }
        if ((string)$type === 'shipped') {
            $order_status_id = (int)$this->{$this->model_call}->getSettingValue($this->key_prefix . 'multisafepay_order_status_id_shipped', (int)$order_info['store_id']);
        }

        if ($this->{$this->model_call}->getSettingValue($this->key_prefix . 'multisafepay_debug_mode', (int)$order_info['store_id'])) {
            $this->log->write('OpenCart set the transaction to ' . $type . ' in MultiSafepay for Order ID ' . $order_id . ' and Status ID ' . $order_status_id . '.');
        }

        // Update Order Status
        $this->load->model($this->route);
        $description = sprintf($this->language->get('text_description_shipped_or_cancelled'), $type);
        $this->{$this->model_call}->addOrderHistory($order_id, $order_status_id, $description);
        $json['success'] = $this->language->get('text_' . $type . '_success');

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));

        return true;
    }

    /**
     * Returns the Order Tab view to be called in custom payment order tabs
     *
     * @return bool|string
     */
    public function order(): bool|string
    {
        if (!isset($this->request->get['order_id'])) {
            return false;
        }

        $this->load->language($this->route);
        $this->registry->set('multisafepay', new \Opencart\System\Library\Multisafepay($this->registry));
        $multisafepay_order = $this->multisafepay->getAdminOrderObject((int)$this->request->get['order_id']);

        if (!$multisafepay_order || !$multisafepay_order->getTransactionId()) {
            return false;
        }

        $data['token_name'] = $this->token_name;
        $data['token'] = $this->session->data[$this->token_name];
        $data['status'] = $multisafepay_order->getStatus();
        $data['order_id'] = (int)$this->request->get['order_id'];
        $data['extension_route'] = $this->route;
        $data[$this->token_name] = $this->session->data[$this->token_name];

        $total = $multisafepay_order->getMoney();
        $data['total'] = $this->currency->format($total->__toString(), $multisafepay_order->getCurrency(), 1.00000000, true);
        return $this->load->view($this->route . '_order', $data);
    }

    /**
     * Returns Order Tab output from order function
     *
     * @return void
     */
    public function refreshOrderTab(): void
    {
        $this->response->setOutput($this->order());
    }

    /**
     * Sets as invoiced the order in MultiSafepay
     *
     * Trigger that is called after admin/controller/sale/order/createInvoiceNo
     * using OpenCart events system and overwrites it
     *
     * @param string $route
     * @param array $args
     *
     * @return void
     */
    public function adminModelSaleOrderCreateInvoiceNoBefore(string $route, array $args): void
    {
        $this->registry->set('multisafepayevents', new Multisafepayevents($this->registry));
        $this->multisafepayevents->adminModelSaleOrderCreateInvoiceNoBefore($route, $args);
    }

    /**
     * Sets MultiSafepay Tab in admin order view page
     *
     * Trigger that is called before admin/view/sale/order_info
     * using OpenCart events system and overwrites it
     *
     * @param string $route
     * @param array $args
     *
     * @return void
     */
    public function adminViewSaleOrderInfoBefore(string &$route, array &$args): void
    {
        $this->registry->set('multisafepayevents', new Multisafepayevents($this->registry));
        $this->multisafepayevents->adminViewSaleOrderInfoBefore($route, $args);
    }

    /**
     * Saving the configuration in database following the new standard of OpenCart 4
     *
     * @return void
     *
     * phpcs:disabled ObjectCalisthenics.Metrics.MaxNestingLevel
     */
    public function save(): void
    {
        if (((string)$this->request->server['REQUEST_METHOD'] === 'POST')) {
            $this->load->language($this->route);

            $json = array();

            if (!$this->user->hasPermission('modify', $this->route)) {
                $json['error'] = $this->language->get('error_permission');
            }

            // If everything is OK, save the configuration
            if (!$json && $this->validate()) {
                $this->load->model('setting/setting');

                if ($this->request->post[$this->key_prefix . 'multisafepay_status']) {
                    $this->addMultiSafepayEvents();
                }
                if (!$this->request->post[$this->key_prefix . 'multisafepay_status']) {
                    $this->deleteMultiSafepayEvents();
                }

                $store_id = $this->request->get['store_id'] ?? 0;
                $this->model_setting_setting->editSetting($this->key_prefix . 'multisafepay', $this->request->post, (int)$store_id);

                $json['success'] = $this->language->get('text_success');
            } else {
                $error_keys = $this->getErrorsKeysAndTypes();
                foreach ($error_keys as $key => $type) {
                    if (isset($this->error[$key])) {
                        $json['error'][$key] = $this->error[$key];
                    }
                    if (!isset($this->error[$key]) && ($type === 'string')) {
                        $json['error'][$key] = '';
                    }
                    if (!isset($this->error[$key]) && ($type === 'array')) {
                        $json['error'][$key] = array();
                    }
                }
            }

            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
        }
    }
}
