<?php
/**
 * CHIP for PrestaShop 1.6 - CHIP payment gateway module.
 *
 * @author CHIPAsia
 * @license http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Chip extends PaymentModule
{
    /** @var string */
    public $name = 'chip';

    /** @var string */
    public $tab = 'payments_gateways';

    /** @var string */
    public $version = '1.0.2';

    /** @var string */
    public $author = 'CHIPAsia';

    /** @var bool */
    public $need_instance = 1;

    /** @var bool */
    public $ps_versions_compliancy = array('min' => '1.6', 'max' => '1.6.99');

    /** @var bool */
    public $bootstrap = true;

    /** @var string */
    public $displayName;

    /** @var string */
    public $description;

    public function __construct()
    {
        $this->displayName = $this->l('CHIP');
        $this->description = $this->l('Accept payments via CHIP (FPX, DuitNow QR, cards and more).');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the CHIP payment module?');

        parent::__construct();
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        $this->registerHook('displayPayment');
        $this->registerHook('displayPaymentReturn');

        Configuration::updateValue('CHIP_SECRET_KEY', '');
        Configuration::updateValue('CHIP_BRAND_ID', '');
        Configuration::updateValue('CHIP_PAYMENT_METHOD_WHITELIST', '');
        Configuration::updateValue('CHIP_DUE_STRICT', 0);
        Configuration::updateValue('CHIP_PURCHASE_TIME_ZONE', 'Asia/Kuala_Lumpur');
        Configuration::updateValue('CHIP_CHECKOUT_TEXT', '');

        return true;
    }

    public function uninstall()
    {
        Configuration::deleteByName('CHIP_SECRET_KEY');
        Configuration::deleteByName('CHIP_BRAND_ID');
        Configuration::deleteByName('CHIP_PAYMENT_METHOD_WHITELIST');
        Configuration::deleteByName('CHIP_DUE_STRICT');
        Configuration::deleteByName('CHIP_PURCHASE_TIME_ZONE');
        Configuration::deleteByName('CHIP_CHECKOUT_TEXT');
        Configuration::deleteByName('CHIP_PUBLIC_KEY');

        return parent::uninstall();
    }

    /**
     * Admin configuration (HelperForm).
     *
     * @return string HTML
     */
    public function getContent()
    {
        $output = '';
        $errors = array();

        if (Tools::isSubmit('submitChipConfig')) {
            $secret_key = trim((string) Tools::getValue('CHIP_SECRET_KEY'));
            $brand_id = trim((string) Tools::getValue('CHIP_BRAND_ID'));

            if ($secret_key === '') {
                $errors[] = $this->l('Secret key is required.');
            }
            if ($brand_id === '') {
                $errors[] = $this->l('Brand ID is required.');
            }

            if (count($errors) === 0) {
                Configuration::updateValue('CHIP_SECRET_KEY', $secret_key);
                Configuration::updateValue('CHIP_BRAND_ID', $brand_id);

                // Clear cached public key when credentials change
                Configuration::deleteByName('CHIP_PUBLIC_KEY');

                $whitelist = Tools::getValue('CHIP_PAYMENT_METHOD_WHITELIST[]');
                if (is_array($whitelist)) {
                    $whitelist = array_values(array_filter($whitelist));
                    Configuration::updateValue('CHIP_PAYMENT_METHOD_WHITELIST', Tools::jsonEncode($whitelist));
                } else {
                    Configuration::updateValue('CHIP_PAYMENT_METHOD_WHITELIST', '');
                }

                Configuration::updateValue('CHIP_DUE_STRICT', (int) Tools::getValue('CHIP_DUE_STRICT', 0));
                Configuration::updateValue('CHIP_PURCHASE_TIME_ZONE', (string) Tools::getValue('CHIP_PURCHASE_TIME_ZONE', 'Asia/Kuala_Lumpur'));
                Configuration::updateValue('CHIP_CHECKOUT_TEXT', (string) Tools::getValue('CHIP_CHECKOUT_TEXT', ''));

                $output .= $this->displayConfirmation($this->l('Settings updated'));
            } else {
                $output .= $this->displayError(implode('<br />', $errors));
            }
        }

        $output .= $this->renderForm();

        return $output;
    }

    /**
     * Admin form via HelperForm (PrestaShop 1.6).
     *
     * @return string HTML
     */
    protected function buildForm()
    {
        $default_lang = (int) Configuration::get('PS_LANG_DEFAULT');

        $fields_form = array(
            array(
                'type' => 'legend',
                'title' => $this->l('CHIP Settings'),
                'icon' => 'icon-credit-card',
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Secret Key'),
                'name' => 'CHIP_SECRET_KEY',
                'required' => true,
                'desc' => $this->l('Your CHIP secret key (per brand). Never share this key.'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Brand ID'),
                'name' => 'CHIP_BRAND_ID',
                'required' => true,
                'desc' => $this->l('Your CHIP brand ID.'),
            ),
            array(
                'type' => 'select',
                'label' => $this->l('Payment Methods'),
                'name' => 'CHIP_PAYMENT_METHOD_WHITELIST[]',
                'multiple' => true,
                'options' => array(
                    'query' => array(
                        array('id' => 'fpx', 'name' => 'FPX'),
                        array('id' => 'fpx_b2b1', 'name' => 'FPX B2B1'),
                        array('id' => 'card', 'name' => 'Card (Visa, Mastercard, Maestro)'),
                        array('id' => 'duitnow_qr', 'name' => 'DuitNow QR'),
                        array('id' => 'razer_atome', 'name' => 'Atome'),
                        array('id' => 'razer_grabpay', 'name' => 'GrabPay'),
                        array('id' => 'razer_maybankqr', 'name' => 'Maybank QRPay'),
                        array('id' => 'razer_shopeepay', 'name' => 'ShopeePay'),
                        array('id' => 'razer_tng', 'name' => "Touch 'n Go eWallet"),
                        array('id' => 'crypto_coin', 'name' => 'Crypto Coin'),
                    ),
                    'id' => 'id',
                    'name' => 'name',
                ),
                'desc' => $this->l('Leave empty to allow all payment methods. Select only the methods you want to offer.'),
            ),
            array(
                'type' => 'switch',
                'label' => $this->l('Due Strict'),
                'name' => 'CHIP_DUE_STRICT',
                'is_bool' => true,
                'values' => array(
                    array('id' => 'active_on', 'value' => 1, 'label' => $this->l('Enabled')),
                    array('id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled')),
                ),
                'desc' => $this->l('When enabled, payment must be completed before the due time.'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Purchase Timezone'),
                'name' => 'CHIP_PURCHASE_TIME_ZONE',
                'required' => true,
                'desc' => $this->l('Timezone used for the purchase (e.g. Asia/Kuala_Lumpur).'),
            ),
            array(
                'type' => 'textarea',
                'label' => $this->l('Checkout Text'),
                'name' => 'CHIP_CHECKOUT_TEXT',
                'desc' => $this->l('Text shown under "Pay with CHIP" on the checkout page. Leave empty to list the configured payment methods automatically.'),
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->title = $this->displayName;
        $helper->submit_action = 'submitChipConfig';
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        $helper->fields_value = array(
            'CHIP_SECRET_KEY' => Configuration::get('CHIP_SECRET_KEY'),
            'CHIP_BRAND_ID' => Configuration::get('CHIP_BRAND_ID'),
            'CHIP_PAYMENT_METHOD_WHITELIST[]' => Tools::jsonDecode(Configuration::get('CHIP_PAYMENT_METHOD_WHITELIST'), true),
            'CHIP_DUE_STRICT' => (int) Configuration::get('CHIP_DUE_STRICT'),
            'CHIP_PURCHASE_TIME_ZONE' => Configuration::get('CHIP_PURCHASE_TIME_ZONE'),
            'CHIP_CHECKOUT_TEXT' => Configuration::get('CHIP_CHECKOUT_TEXT'),
        );

        return $helper->generateForm(array(array('form' => $fields_form)));
    }

    /**
     * Render the configuration form.
     *
     * @return string HTML
     */
    public function renderForm()
    {
        return $this->buildForm();
    }

    /**
     * Hook displayPayment: render the "Pay with CHIP" button in the order page.
     *
     * @return string HTML
     */
    public function hookDisplayPayment()
    {
        if (!$this->active || !$this->context->cart || !$this->context->cart->id) {
            return '';
        }

        // Avoid offering CHIP when the cart is empty or has no customer
        if ((int) $this->context->cart->id_customer <= 0) {
            return '';
        }

        // Cart already has an order (payment retry is handled elsewhere)
        if (Order::getOrderByCartId((int) $this->context->cart->id) !== false) {
            return '';
        }

        $payment_url = $this->context->link->getModuleLink('chip', 'payment', array('id_cart' => (int) $this->context->cart->id), true);

        // Show any payment error left in the session (redirected back from the callback)
        $error_message = '';
        if (isset($this->context->cookie->chip_payment_error) && $this->context->cookie->chip_payment_error !== '') {
            $error_message = (string) $this->context->cookie->chip_payment_error;
            unset($this->context->cookie->chip_payment_error);
            $this->context->cookie->write();
        }

        // Custom checkout text from config; fall back to listing the
        // configured payment methods.
        $checkout_text = trim((string) Configuration::get('CHIP_CHECKOUT_TEXT'));

        $this->context->smarty->assign(array(
            'chip_payment_url' => $payment_url,
            'chip_logo' => $this->_path . 'logo.png',
            'chip_methods' => $this->getFormattedMethodLabels(),
            'chip_checkout_text' => $checkout_text,
            'chip_module_name' => $this->displayName,
            'chip_error' => $error_message,
        ));

        return $this->display(__FILE__, 'payment.tpl');
    }

    /**
     * Hook displayPaymentReturn: show the payment status after the order is placed.
     *
     * @param array $params total_to_pay, currency, objOrder, currencyObj
     * @return string HTML
     */
    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active) {
            return '';
        }

        $order = isset($params['objOrder']) ? $params['objOrder'] : null;
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        $this->context->smarty->assign(array(
            'chip_order' => $order,
            'chip_reference' => $order->reference,
            'chip_total_paid' => isset($params['total_to_pay']) ? $params['total_to_pay'] : 0,
            'chip_currency_sign' => isset($params['currency']) ? $params['currency'] : '',
            'chip_module_name' => $this->displayName,
        ));

        return $this->display(__FILE__, 'payment_return.tpl');
    }

    /**
     * Human readable payment method labels for the checkout template (exact spellings).
     *
     * @return array
     */
    protected function getFormattedLabels()
    {
        return array(
            'fpx' => 'FPX',
            'fpx_b2b1' => 'FPX B2B1',
            'card' => 'Card (Visa, Mastercard, Maestro)',
            'duitnow_qr' => 'DuitNow QR',
            'dnqr' => 'DuitNow QR',
            'razer_atome' => 'Atome',
            'razer_grabpay' => 'GrabPay',
            'razer_maybankqr' => 'Maybank QRPay',
            'razer_shopeepay' => 'ShopeePay',
            'razer_tng' => "Touch 'n Go eWallet",
            'crypto_coin' => 'Crypto Coin',
        );
    }

    /**
     * Config whitelist codes mapped to display names.
     *
     * @return array list of display names
     */
    protected function getFormattedMethodLabels()
    {
        $whitelist = Configuration::get('CHIP_PAYMENT_METHOD_WHITELIST');
        if (empty($whitelist)) {
            return array();
        }

        $whitelist = Tools::jsonDecode($whitelist, true);
        if (!is_array($whitelist)) {
            return array();
        }

        $labels = $this->getFormattedLabels();
        $output = array();
        foreach ($whitelist as $code) {
            $code = (string) $code;
            if (isset($labels[$code])) {
                $output[] = $labels[$code];
            } else {
                $output[] = $code;
            }
        }

        return $output;
    }
}
