<?php
namespace Opencart\Catalog\Controller\Extension\Multisafepay\Payment;

require_once(DIR_EXTENSION . 'multisafepay/system/library/multisafepay.php');
require_once(DIR_EXTENSION . 'multisafepay/system/library/multisafepayevents.php');

use MultiSafepay\Api\Transactions\TransactionResponse;
use Opencart\System\Engine\Controller;
use Opencart\System\Library\Multisafepayevents;

/**
 * phpcs:disabled ObjectCalisthenics.Files.ClassTraitAndInterfaceLength
 */
class Multisafepay extends Controller {

    protected $registry;

    private string $route;
    private string $key_prefix;
    private string $model_call;

    public function __construct($registry) {
        parent::__construct($registry);
        $this->registry->set('multisafepay', new \Opencart\System\Library\Multisafepay($registry));
        $this->route = $this->multisafepay->route;
        $this->key_prefix = $this->multisafepay->key_prefix;
        $this->model_call = $this->multisafepay->model_call;
    }

    /**
     * Load all language strings (values and keys) into $this->data
     *
     * @return array
     */
    public function getTexts(): array
    {
        $this->load->language($this->route);
        return array();
    }

    /**
     * Gets Extra Data for Payment Component
     *
     * @property object config
     *
     * @param array $data
     *
     * @return array
     */
    public function getDataForPaymentComponent(array $data): array
    {
        $order_info = $this->multisafepay->getOrderInfo($data['order_id']);

        $data['type'] = 'direct';

        $data['fields']['payment_component_enabled'] = (bool)$this->config->get($this->key_prefix . 'multisafepay_' . strtolower($data['gateway']) . '_payment_component');
        $data['fields']['tokenization'] = (bool)$this->config->get($this->key_prefix . 'multisafepay_' . strtolower($data['gateway']) . '_tokenization');
        $data['currency'] = $order_info['currency_code'];
        $data['amount'] = (float)$order_info['total'] * 100;
        $data['locale'] = $this->multisafepay->getLocale();
        $data['country'] = $order_info['payment_iso_code_2'];
        $data['apiToken'] = $this->multisafepay->getUserApiToken();

        $data['env'] = 'live';
        if ($data['test_mode']) {
            $data['env'] = 'test';
        }
        
        $order_template = array(
            'currency' => $data['currency'],
            'amount' => $data['amount'],
            'customer' => array(
                'locale' => $data['locale'],
                'country' => $data['country'],
            ),
            'template' => array(
                'settings' => array(
                    'embed_mode' => true
                )
            )
        );

        // Payment Component Template ID.
        $template_id = $this->config->get($this->key_prefix . 'multisafepay_payment_component_template_id') ?? '';
        if (!empty($template_id)) {
            $order_template['payment_options']['template_id'] = $template_id;
        }

        // Recurring model is just working when payment components and tokenization are enabled at the same time, and for some specific credit cards
        if ($data['fields']['tokenization'] && $this->customer->isLogged() && in_array($data['gateway'], $this->multisafepay->configurable_recurring_payment_methods)) {
            $recurring['model'] = 'cardOnFile';
            $recurring['tokens'] = $this->multisafepay->getTokensByGatewayCode($order_info['customer_id'], $data['gateway']);
            $data['recurring'] = json_encode($recurring);
        }
        $data['order_data'] = json_encode($order_template);

        return $data;
    }

    /**
     * Data to be included in each payment method as base
     *
     * @param string $gateway
     *
     * @return array
     */
    private function paymentMethodBase(string $gateway = ''): array
    {
        $data = $this->getTexts();
        $data['issuers'] = $data['fields'] = $data['gateway_info'] = array();
        $data['gateway'] = $gateway;
        $data['order_id'] = (int)$this->session->data['order_id'];
        $data['action'] = $this->url->link($this->route . '|confirm', '', true);
        $data['back'] = $this->url->link('checkout/checkout', '', true);
        $data['type'] = 'redirect';
        $data['route'] = $this->route;
        $data['test_mode'] = $this->config->get($this->key_prefix . 'multisafepay_environment') ? true : false;
        $data['unavailable_api'] = empty($this->multisafepay->getApiStatus());

        if (in_array($gateway, $this->multisafepay->configurable_type_search)) {
            $data['type'] = $this->config->get($this->key_prefix . 'multisafepay_' . strtolower($gateway) . '_redirect') ? 'redirect' : 'direct';
        }

        // Payment component is enabled both with and without tokenization
        if (in_array($gateway, $this->multisafepay->configurable_payment_component) && (bool)$this->config->get($this->key_prefix . 'multisafepay_' . strtolower($gateway) . '_payment_component')) {
            $data = $this->getDataForPaymentComponent($data);
        }

        return $data;
    }

    /**
     * Handles the confirm order form for MultiSafepay payment method
     *
     * @return string
     */
    public function index(): string
    {
        $this->load->language($this->route);
        $data = $this->paymentMethodBase();
        return $this->load->view($this->route, $data);
    }

    /**
     * Change the terms and conditions links for Riverty / Afterpay - Riverty payment
     * according to the selected language and the billing country of the customer
     *
     * @return string
     *
     * phpcs:disabled ObjectCalisthenics.ControlStructures.NoElse
     */
    public function afterPayGeoTerms(): string
    {
        $terms = $this->language->get('entry_afterpay_terms');
        $toggle_address = $this->multisafepay->togglePaymentShippingAddress();

        if ($toggle_address['country_id']) {
            $this->load->model('localisation/country');
            $billing_country = $this->model_localisation_country->getCountry($toggle_address['country_id']);
            $billing_code = strtolower($billing_country['iso_code_2']);
            $language_code = strtolower($this->language->get('code'));

            if (!empty($billing_code) && !empty($language_code)) {
                if (($billing_code === 'de') && (str_contains($language_code, 'en'))) {
                    $terms = str_replace('/nl_en/', '/de_en/', $terms);
                } else if (($billing_code === 'at') && (str_contains($language_code, 'de'))) {
                    $terms = str_replace('/de_de/', '/at_de/', $terms);
                } else if ($billing_code === 'at') {
                    $terms = str_replace('/nl_en/', '/at_en/', $terms);
                } else if (($billing_code === 'ch') && (str_contains($language_code, 'de'))) {
                    $terms = str_replace('/de_de/', '/ch_de/', $terms);
                } else if (($billing_code === 'ch') && (str_contains($language_code, 'fr'))) {
                    $terms = str_replace('/nl_en/', '/ch_fr/', $terms);
                } else if ($billing_code === 'ch') {
                    $terms = str_replace('/nl_en/', '/ch_en/', $terms);
                } else if (($billing_code === 'be') && (str_contains($language_code, 'nl'))) {
                    $terms = str_replace('/nl_nl/', '/be_nl/', $terms);
                } else if (($billing_code === 'be') && (str_contains($language_code, 'fr'))) {
                    $terms = str_replace('/nl_en/', '/be_fr/', $terms);
                } else if ($billing_code === 'be') {
                    $terms = str_replace('/nl_en/', '/be_en/', $terms);
                }
            }
        }
        return $terms;
    }

    /**
     * Handles the confirm order form for Riverty / Afterpay - Riverty payment method
     *
     * @return string
     */
    public function afterPay(): string
    {
        $data = $this->paymentMethodBase('AFTERPAY');
        if ((string)$data['type'] === 'direct') {
            $data['gateway_info'] = 'Meta';
            $data['fields'] = array(
                'gender' => true,
                'birthday' => true,
                'afterpay_terms' => true
            );
        }
        $data['entry_afterpay_terms'] = $this->afterPayGeoTerms();

        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Amazon Pay payment method
     */
    public function amazonPay() {
        $data = $this->paymentMethodBase('AMAZONBTN');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for American Express payment method
     *
     * @return string
     */
    public function amex(): string
    {
        $data = $this->paymentMethodBase('AMEX');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Alipay payment method
     *
     * @return string
     */
    public function aliPay(): string
    {
        $data = $this->paymentMethodBase('ALIPAY');
        $data['type'] = 'direct';
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Alipay+ payment method
     *
     * @return string
     */
    public function alipayplus(): string
    {
        $data = $this->paymentMethodBase('ALIPAYPLUS');
        $data['type'] = 'direct';
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Apple Pay payment method
     *
     * @return string
     */
    public function applePay(): string
    {
        $data = $this->paymentMethodBase('APPLEPAY');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Baby Cadeaubon payment method
     *
     * @return string
     */
    public function babycad(): string
    {
        $data = $this->paymentMethodBase('BABYCAD');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Bancontact payment method
     *
     * @return string
     */
    public function bancontact(): string
    {
        $data = $this->paymentMethodBase('MISTERCASH');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Bank Transfer payment method
     *
     * @return string
     */
    public function bankTransfer(): string
    {
        $data = $this->paymentMethodBase('BANKTRANS');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Beauty & Wellness payment method
     *
     * @return string
     */
    public function beautyWellness(): string
    {
        $data = $this->paymentMethodBase('BEAUTYWELL');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Belfius payment method
     *
     * @return string
     */
    public function belfius(): string
    {
        $data = $this->paymentMethodBase('BELFIUS');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Boekenbon payment method
     *
     * @return string
     */
    public function boekenbon(): string
    {
        $data = $this->paymentMethodBase('BOEKENBON');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for CBC payment method
     *
     * @return string
     */
    public function cbc(): string
    {
        $data = $this->paymentMethodBase('CBC');
        $data['type'] = 'direct';
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for CreditCard payment method
     *
     * @return string
     */
    public function creditCard(): string
    {
        $data = $this->paymentMethodBase('CREDITCARD');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Request to Pay powered by Deutsche Bank payment method
     *
     * @return string
     */
    public function dbrtp(): string
    {
        $data = $this->paymentMethodBase('DBRTP');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Direct Bank payment method
     *
     * @return string
     */
    public function directBank(): string
    {
        $data = $this->paymentMethodBase('DIRECTBANK');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Dotpay payment method
     *
     * @return string
     */
    public function dotpay(): string
    {
        $data = $this->paymentMethodBase('DOTPAY');
        $data['gateway_info'] = 'Meta';
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for E-Invoicing payment method
     *
     * @return string
     */
    public function eInvoice(): string
    {
        $data = $this->paymentMethodBase('EINVOICE');
        if ((string)$data['type'] === 'direct') {
            $data['fields'] = array(
                'birthday' => true,
                'bankaccount' => true
            );
            $data['gateway_info'] = 'Meta';
        }
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for EPS payment method
     *
     * @return string
     */
    public function eps(): string
    {
        $data = $this->paymentMethodBase('EPS');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for fashionCheque payment method
     *
     * @return string
     */
    public function fashionCheque(): string
    {
        $data = $this->paymentMethodBase('FASHIONCHQ');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for fashionGiftCard payment method
     *
     * @return string
     */
    public function fashionGiftCard(): string
    {
        $data = $this->paymentMethodBase('FASHIONGFT');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Fietsenbon payment method
     *
     * @return string
     */
    public function fietsenbon(): string
    {
        $data = $this->paymentMethodBase('FIETSENBON');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for GivaCard payment method
     *
     * @return string
     */
    public function givaCard(): string
    {
        $data = $this->paymentMethodBase('GIVACARD');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Good Card payment method
     *
     * @return string
     */
    public function goodCard(): string
    {
        $data = $this->paymentMethodBase('GOODCARD');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Google Pay payment method
     */
    public function googlePay() {
        $data = $this->paymentMethodBase('GOOGLEPAY');

        $data['mode_string'] = 'PRODUCTION';
        if ($data['test_mode']) {
            $data['mode_string'] = 'TEST';
        }
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for in3 payment method
     *
     * @return string
     */
    public function in3(): string
    {
        $data = $this->paymentMethodBase('IN3');
        if ((string)$data['type'] === 'direct') {
            $data['gateway_info'] = 'Meta';
            $data['fields'] = array(
                'gender' => true
            );
        }
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Gezondheidsbon payment method
     *
     * @return string
     */
    public function gezondheidsbon(): string
    {
        $data = $this->paymentMethodBase('GEZONDHEID');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for giroPay payment method
     *
     * @return string
     */
    public function giroPay(): string
    {
        $data = $this->paymentMethodBase('GIROPAY');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Good4fun Giftcard payment method
     *
     * @return string
     */
    public function good4fun(): string
    {
        $data = $this->paymentMethodBase('GOOD4FUN');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for iDEAL payment method
     *
     * @return string
     */
    public function ideal(): string
    {
        $data = $this->paymentMethodBase('IDEAL');
        if ((string)$data['type'] === 'direct') {
            $issuers = $this->multisafepay->getIssuersByGatewayCode($data['gateway']);
            if ($issuers) {
                $data['issuers'] = $issuers;
                $data['type'] = 'direct';
                $data['gateway_info'] = 'Ideal';
            }
        }
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for iDEAL QR payment method
     *
     * @return string
     */
    public function idealQr(): string
    {
        $data = $this->paymentMethodBase('IDEALQR');
        $data['gateway_info'] = 'QrCode';
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for KBC payment method
     *
     * @return string
     */
    public function kbc(): string
    {
        $data = $this->paymentMethodBase('KBC');
        $data['type'] = 'direct';
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Klarna payment method
     *
     * @return string
     */
    public function klarna(): string
    {
        $data = $this->paymentMethodBase('KLARNA');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Maestro payment method
     *
     * @return string
     */
    public function maestro(): string
    {
        $data = $this->paymentMethodBase('MAESTRO');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Mastercard payment method
     *
     * @return string
     */
    public function mastercard(): string
    {
        $data = $this->paymentMethodBase('MASTERCARD');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Mybank payment method
     *
     * @return string
     */
    public function mybank(): string
    {
        $data = $this->paymentMethodBase('MYBANK');
        if ((string)$data['type'] === 'direct') {
            $issuers = $this->multisafepay->getIssuersByGatewayCode($data['gateway']);
            if ($issuers) {
                $data['issuers'] = $issuers;
                $data['gateway_info'] = 'MyBank';
            }
        }
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Nationale Tuinbon payment method
     *
     * @return string
     */
    public function nationaleTuinbon(): string
    {
        $data = $this->paymentMethodBase('NATNLETUIN');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Parfum Cadeaukaart payment method
     *
     * @return string
     */
    public function parfumCadeaukaart(): string
    {
        $data = $this->paymentMethodBase('PARFUMCADE');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Pay After Delivery payment method
     *
     * @return string
     */
    public function payAfterDelivery(): string
    {
        $data = $this->paymentMethodBase('PAYAFTER');
        if ((string)$data['type'] === 'direct') {
            $data['gateway_info'] = 'Meta';
            $data['fields'] = array(
                'birthday' => true,
                'bankaccount' => true
            );
        }
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Pay After Delivery Installments payment method
     *
     * @return string
     */
    public function payAfterDeliveryInstallments(): string
    {
        $data = $this->paymentMethodBase('BNPL_INSTM');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for PayPal payment method
     *
     * @return string
     */
    public function payPal(): string
    {
        $data = $this->paymentMethodBase('PAYPAL');
        $data['type'] = 'direct';
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for paysafecard payment method
     *
     * @return string
     */
    public function paysafecard(): string
    {
        $data = $this->paymentMethodBase('PSAFECARD');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Podium payment method
     *
     * @return string
     */
    public function podium(): string
    {
        $data = $this->paymentMethodBase('PODIUM');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for betaalplan payment method
     *
     * @return string
     */
    public function betaalplan(): string
    {
        $data = $this->paymentMethodBase('SANTANDER');
        if ((string)$data['type'] === 'direct') {
            $data['gateway_info'] = 'Meta';
            $data['fields'] = array(
                'sex' => true,
                'birthday' => true,
                'bankaccount' => true
            );
        }
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for SEPA Direct Debt payment method
     *
     * @return string
     */
    public function dirDeb(): string
    {
        $data = $this->paymentMethodBase('DIRDEB');
        if ((string)$data['type'] === 'direct') {
            $data['gateway_info'] = 'Account';
            $data['fields'] = array(
                'account_holder_name' => true,
                'account_holder_iban' => true,
                'emandate' => true,
            );
        }
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Sport & Fit payment method
     *
     * @return string
     */
    public function sportFit(): string
    {
        $data = $this->paymentMethodBase('SPORTENFIT');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Trustly payment method
     *
     * @return string
     */
    public function trustly(): string
    {
        $data = $this->paymentMethodBase('TRUSTLY');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Visa payment method
     *
     * @return string
     */
    public function visa(): string
    {
        $data = $this->paymentMethodBase('VISA');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirmation order form for Zinia payment method
     *
     * @return string
     */
    public function zinia(): string
    {
        $data = $this->paymentMethodBase('ZINIA');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for VVV Cadeaukaart payment method
     *
     * @return string
     */
    public function vvvGiftCard(): string
    {
        $data = $this->paymentMethodBase('VVVGIFTCRD');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Webshop Giftcard payment method
     *
     * @return string
     */
    public function webshopGiftCard(): string
    {
        $data = $this->paymentMethodBase('WEBSHOPGIFTCARD');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Wellness gift card payment method
     *
     * @return string
     */
    public function wellnessGiftCard(): string
    {
        $data = $this->paymentMethodBase('WELLNESSGIFTCARD');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Wijncadeau payment method
     *
     * @return string
     */
    public function wijnCadeau(): string
    {
        $data = $this->paymentMethodBase('WIJNCADEAU');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for Winkelcheque payment method
     *
     * @return string
     */
    public function winkelCheque(): string
    {
        $data = $this->paymentMethodBase('WINKELCHEQUE');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form for YourGift payment method
     *
     * @return string
     */
    public function yourGift(): string
    {
        $data = $this->paymentMethodBase('YOURGIFT');
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the confirm order form the generic payment method
     *
     * @return string
     */
    public function generic(): string
    {
        $data = $this->paymentMethodBase($this->config->get($this->key_prefix . 'multisafepay_generic_code'));
        return $this->load->view($this->route, $data);
    }

    /**
     * Handles the form validation before submit and return errors if existed
     *
     * @return void
     */
    public function validateForm(): void
    {
        $this->load->language($this->route);

        $json = array();

        if ((isset($this->request->post['gender'])) && ((string)$this->request->post['gender'] === '')) {
            $json['error']['gender'] = $this->language->get('text_error_empty_gender');
        }

        if (isset($this->request->post['birthday']) && ((string)$this->request->post['birthday'] === '')) {
            $json['error']['birthday'] = $this->language->get('text_error_empty_date_of_birth');
        }

        if (isset($this->request->post['bankaccount']) && ((string)$this->request->post['bankaccount'] === '')) {
            $json['error']['bankaccount'] = $this->language->get('text_error_empty_bank_account');
        }

        if (
            isset($this->request->post['bankaccount']) &&
            ((string)$this->request->post['bankaccount'] !== '') &&
            !$this->multisafepay->validateIban((string)$this->request->post['bankaccount'])
        ) {
            $json['error']['bankaccount'] = $this->language->get('text_error_not_valid_iban');
        }

        if (isset($this->request->post['account_holder_name']) && ((string)$this->request->post['account_holder_name'] === '')) {
            $json['error']['account-holder-name'] = $this->language->get('text_error_empty_account_holder_name');
        }

        if (isset($this->request->post['account_holder_iban']) && ((string)$this->request->post['account_holder_iban'] === '')) {
            $json['error']['account-holder-iban'] = $this->language->get('text_error_empty_account_holder_iban');
        }

        if (isset($this->request->post['account_holder_iban']) &&
            ((string)$this->request->post['account_holder_iban'] !== '') &&
            !$this->multisafepay->validateIban((string)$this->request->post['account_holder_iban'])
        ) {
            $json['error']['account-holder-iban'] = $this->language->get('text_error_not_valid_iban');
        }

        if (isset($this->request->post['afterpay_terms']) && ((string)$this->request->post['afterpay_terms'] !== '1')) {
            $json['error']['afterpay-terms'] = $this->language->get('text_error_empty_afterpay_terms');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Handles the confirm order form in OpenCart checkout page
     *
     * @return void
     */
    public function confirm(): void
    {
        if (!isset($this->request->post['order_id'], $this->request->post['type'])) {
            return;
        }

        $order_id = (int)$this->request->post['order_id'];
        $multisafepay_order = $this->multisafepay->getOrderRequestObject($this->request->post);
        $order_request = $this->multisafepay->processOrderRequestObject($multisafepay_order);

        if ($order_request && $order_request->getPaymentUrl()) {
            if ($this->config->get($this->key_prefix . 'multisafepay_debug_mode')) {
                $this->log->write('Start transaction in MultiSafepay for Order ID ' . $order_id . ' on ' . date($this->language->get('datetime_format')) . '.');
                $this->log->write('Payment Link: ' . $order_request->getPaymentUrl() . '.');
            }
            $this->response->redirect($order_request->getPaymentUrl());
        }
    }

    /**
     * Processes POST and GET notifications
     *
     * @param int $order_id
     * @param bool|TransactionResponse $transaction
     *
     * @return void
     */
    private function processCallBack(int $order_id, bool|TransactionResponse $transaction): void
    {
        if (!$transaction) {
            $this->log->write('No transaction found for Order ID ' . $order_id . '.');
            return;
        }

        $timestamp = date($this->language->get('datetime_format'));
        $this->load->model('checkout/order');
        $this->load->model($this->route);
        $order_info = $this->model_checkout_order->getOrder($order_id);

        $current_order_status = $order_info['order_status_id'];
        $psp_id = $transaction->getTransactionId();
        $payment_details = $transaction->getPaymentDetails();
        $gateway_id = $payment_details->getType();
        $gateway_details = $this->multisafepay->getGatewayById($gateway_id);
        $status = $transaction->getStatus();

        switch ($status) {
            case 'completed':
                $order_status_id = $this->config->get($this->key_prefix . 'multisafepay_order_status_id_completed');
                break;
            case 'uncleared':
                $order_status_id = $this->config->get($this->key_prefix . 'multisafepay_order_status_id_uncleared');
                break;
            case 'reserved':
                $order_status_id = $this->config->get($this->key_prefix . 'multisafepay_order_status_id_reserved');
                break;
            case 'void':
                $order_status_id = $this->config->get($this->key_prefix . 'multisafepay_order_status_id_void');
                break;
            case 'cancelled':
                $order_status_id = $this->config->get($this->key_prefix . 'multisafepay_order_status_id_cancelled');
                break;
            case 'declined':
                $order_status_id = $this->config->get($this->key_prefix . 'multisafepay_order_status_id_declined');
                break;
            case 'reversed':
                $order_status_id = $this->config->get($this->key_prefix . 'multisafepay_order_status_id_reversed');
                break;
            case 'refunded':
                $order_status_id = $this->config->get($this->key_prefix . 'multisafepay_order_status_id_refunded');
                break;
            case 'partial_refunded':
                $order_status_id = $this->config->get($this->key_prefix . 'multisafepay_order_status_id_partial_refunded');
                break;
            case 'expired':
                $order_status_id = $this->config->get($this->key_prefix . 'multisafepay_order_status_id_expired');
                break;
            case 'shipped':
                $order_status_id = $this->config->get($this->key_prefix . 'multisafepay_order_status_id_shipped');
                break;
            case 'initialized':
                $order_status_id = $this->getOrderStatusInitialized($gateway_details);
                break;
            default:
                $order_status_id = $this->config->get($this->key_prefix . 'multisafepay_order_status_id_initialized');
                break;
        }

        if ($gateway_details && ((string)$gateway_details['route'] !== (string)$order_info['payment_code'])) {
            $this->log->write('Callback received with a different payment method for Order ID ' . $order_id . ' on ' . $timestamp . ' with Status: ' . $status . ', and PSP ID: ' . $psp_id . '. and payment method pass from ' . $order_info['payment_method'] . ' to ' . $gateway_details['description'] . '.');
            $this->{$this->model_call}->editOrderPaymentMethod($order_id, $gateway_details);
        }

        if (!$gateway_details) {
            $this->log->write('Callback received with an unregistered payment method for Order ID ' . $order_id . ' on ' . $timestamp . ' with Status: ' . $status . ', and PSP ID: ' . $psp_id . '.');
        }

        if (((string)$order_status_id !== '0') && ((string)$order_status_id !== (string)$current_order_status)) {
            if ($this->config->get($this->key_prefix . 'multisafepay_debug_mode')) {
                $this->log->write('Callback received for Order ID ' . $order_id . ' on ' . $timestamp . ' with Status: ' . $status . ', and PSP ID: ' . $psp_id . '.');
            }
            $comment = '';
            if ((string)$current_order_status !== '0') {
                $comment .= sprintf($this->language->get('text_comment_callback'), $order_id, $timestamp, $status, $psp_id);
            }
            $this->model_checkout_order->addHistory($order_id, (int)$order_status_id, $comment, true);
        }

        // If $order_status_id is 0, nothing happens. Callback will not trigger any order status change
        if (((string)$order_status_id === '0') && ((string)$order_status_id !== (string)$current_order_status) && $this->config->get($this->key_prefix . 'multisafepay_debug_mode')) {
            $comment = sprintf($this->language->get('text_comment_callback'), $order_id, $timestamp, $status, $psp_id);
            $this->model_checkout_order->addHistory($order_id, (int)$current_order_status, $comment);
            $this->log->write('Callback received for Order ID ' . $order_id . ', has not been processed.');
        }

        $this->response->addHeader('Content-type: text/plain');
        $this->response->setOutput('OK');
    }

    /**
     * Handles the callback from MultiSafepay using POST method
     *
     * @return void
     */
    public function postCallback(): void
    {
        // Check for required query arguments
        if (!$this->checkRequiredArgumentsInNotification()) {
            return;
        }

        // Check if the order exist in the shop and belongs to MultiSafepay.
        if (!$this->checkIfOrderExistAndBelongsToMultiSafepay()) {
            return;
        }

        // Check if POST notification is empty
        if (!$this->checkIfPostBodyNotEmpty()) {
            return;
        }

        $body = file_get_contents('php://input');

        // Check if signature is valid
        if (!$this->checkIfSignatureIsValid($body)) {
            return;
        }

        $transaction = $this->multisafepay->getTransactionFromPostNotification($body);
        $this->processCallBack((int)$this->request->get['transactionid'], $transaction);
    }

    /**
     * Check if required query arguments are present in the notification
     *
     * @return bool
     */
    private function checkRequiredArgumentsInNotification(): bool
    {
        $required_arguments = array('transactionid', 'timestamp');
        foreach ($required_arguments as $required_argument) {
            if (empty($this->request->get[$required_argument])) {
                $this->log->write('It seems the notification URL has been triggered but does not contain the required query arguments.');
                $this->response->addHeader('Content-type: text/plain');
                $this->response->setOutput('OK');
                return false;
            }
        }
        return true;
    }

    /**
     * Check if the order exists in the shop and belongs to MultiSafepay.
     *
     * @return bool
     */
    private function checkIfOrderExistAndBelongsToMultiSafepay(): bool
    {
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder((int)$this->request->get['transactionid']);
        if (isset($order_info['payment_code']) && !str_contains($order_info['payment_code'], 'multisafepay')) {
            $this->log->write('Callback received for an order which currently does not have a MultiSafepay payment method assigned.');
            $this->response->addHeader('Content-type: text/plain');
            $this->response->setOutput('OK');
            return false;
        }
        return true;
    }

    /**
     * Check if POST notification is empty
     *
     * @return bool
     */
    private function checkIfPostBodyNotEmpty(): bool
    {
        if (empty(file_get_contents('php://input'))) {
            $this->log->write('It seems the notification URL has been triggered but does not contain a body in the POST request.');
            $this->response->addHeader('Content-type: text/plain');
            $this->response->setOutput('OK');
            return false;
        }
        return true;
    }

    /**
     * Check if the signature of a POST request is valid
     *
     * @param bool|string $body
     *
     * @return bool
     */
    private function checkIfSignatureIsValid(bool|string $body): bool
    {
        $this->load->model($this->route);
        $environment = empty($this->model_setting_setting->getValue($this->key_prefix . 'multisafepay_environment', (int)$this->config->get('config_store_id')));
        $api_key = (($environment) ? $this->model_setting_setting->getValue($this->key_prefix . 'multisafepay_api_key', (int)$this->config->get('config_store_id')) : $this->model_setting_setting->getValue($this->key_prefix . 'multisafepay_sandbox_api_key', (int)$this->config->get('config_store_id')));
        if (!$this->multisafepay->verifyNotification($body, $_SERVER['HTTP_AUTH'], $api_key)) {
            $this->log->write('Notification for transaction ID ' . (int)$this->request->get['transactionid'] . ' has been received but is not valid.');
            $this->response->addHeader('Content-type: text/plain');
            $this->response->setOutput('OK');
            return false;
        }
        return true;
    }

    /**
     * Returns a custom order_status_id initialized when has been set
     * for a payment method
     *
     * @param bool|array $gateway_details
     *
     * @return int $custom_order_status_id_initialized
     */
    public function getOrderStatusInitialized(bool|array $gateway_details = false): int
    {
        if (!$gateway_details) {
            return $this->config->get($this->key_prefix . 'multisafepay_order_status_id_initialized');
        }

        $order_status_id_initialized_key = $this->key_prefix . 'multisafepay_' . $gateway_details['code'] . '_order_status_id_initialized';
        $custom_order_status_id_initialized = $this->config->get($order_status_id_initialized_key);

        if (!$custom_order_status_id_initialized) {
            return $this->config->get($this->key_prefix . 'multisafepay_order_status_id_initialized');
        }
        return $custom_order_status_id_initialized;
    }

    /**
     * All payment methods in model checkout deployed
     *
     * Trigger that is called before catalog/model/checkout/payment/method/after
     * using OpenCart events system and overwrites it
     *
     * @param string $route
     * @param array $args
     * @param $output
     *
     * @return void
     */
    public function catalogModelCheckoutPaymentMethodAfter(string &$route, array &$args, &$output): void
    {
        $this->registry->set('multisafepayevents', new Multisafepayevents($this->registry));
        $this->multisafepayevents->catalogModelCheckoutPaymentMethodAfter($route, $args, $output);
    }

    /**
     * Simplify payment method name so can be found on database for extensions
     *
     * Trigger that is called after catalog/model/setting/extension/method/after
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
        $this->registry->set('multisafepayevents', new Multisafepayevents($this->registry));
        $this->multisafepayevents->catalogModelSettingExtensionAfter($route, $args, $output);
    }

    /**
     * Add CSS on Header to the checkout page
     *
     * Trigger that is called before catalog/view/common/header/before
     * using OpenCart events system and overwrites it
     *
     * @param string $route
     * @param array $args
     *
     * @return void
     */
    public function catalogViewCommonHeaderBefore(string &$route, array &$args): void
    {
        $this->registry->set('multisafepayevents', new Multisafepayevents($this->registry));
        $this->multisafepayevents->catalogViewCommonHeaderFooterBefore($route, $args, 'header');
    }

    /**
     * Add JS on Footer to the checkout page
     *
     * Trigger that is called before catalog/view/common/footer/before
     * using OpenCart events system and overwrites it
     *
     * @param string $route
     * @param array $args
     *
     * @return void
     */
    public function catalogViewCommonFooterBefore(string &$route, array &$args): void
    {
        $this->registry->set('multisafepayevents', new Multisafepayevents($this->registry));
        $this->multisafepayevents->catalogViewCommonHeaderFooterBefore($route, $args, 'footer');
    }
}
