<?php
/**
 * HiPay Enterprise SDK Prestashop.
 *
 * 2017 HiPay
 *
 * NOTICE OF LICENSE
 *
 * @author    HiPay <support.tpp@hipay.com>
 * @copyright 2017 HiPay
 * @license   https://github.com/hipay/hipay-enterprise-sdk-prestashop/blob/master/LICENSE.md
 */
require_once dirname(__FILE__).'/../../classes/apiHandler/ApiHandler.php';
require_once dirname(__FILE__).'/../../classes/helper/HipayHelper.php';
require_once dirname(__FILE__).'/../../classes/helper/HipayCCToken.php';
require_once dirname(__FILE__).'/../../classes/helper/enums/ThreeDS.php';
require_once dirname(__FILE__).'/../../classes/helper/enums/ApiMode.php';
require_once dirname(__FILE__).'/../../classes/helper/enums/UXMode.php';
require_once dirname(__FILE__).'/../../classes/helper/enums/CardPaymentProduct.php';

/**
 * Class Hipay_enterprisePendingModuleFrontController.
 *
 * @author      HiPay <support.tpp@hipay.com>
 * @copyright   Copyright (c) 2017 - HiPay
 * @license     https://github.com/hipay/hipay-enterprise-sdk-prestashop/blob/master/LICENSE.md
 *
 * @see    https://github.com/hipay/hipay-enterprise-sdk-prestashop
 */
class Hipay_enterpriseRedirectModuleFrontController extends ModuleFrontController
{
    /** @var Hipay_entreprise */
    public $module;

    /** @var ApiHandler */
    private $apiHandler;
    /** @var HipayCCToken */
    private $ccToken;
    /** @var array<string,mixed> */
    private $creditCard;
    /** @var Context */
    protected $context;
    /** @var Customer */
    private $customer;
    /** @var array<string,mixed> */
    private $savedCC;
    /** @var Country */
    private $deliveryCountry;
    /** @var Cart */
    private $currentCart;

    /**
     * Init data.
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function init()
    {
        parent::init();

        $this->context = Context::getContext();
        $this->apiHandler = new ApiHandler($this->module, $this->context);
        $this->currentCart = $this->context->cart;
        $this->customer = new Customer((int) $this->currentCart->id_customer);
        $this->ccToken = new HipayCCToken($this->module);
        $this->savedCC = $this->ccToken->getSavedCC($this->currentCart->id_customer);
        $delivery = new Address((int) $this->currentCart->id_address_delivery);
        $this->deliveryCountry = new Country((int) $delivery->id_country);
        $currency = new Currency((int) $this->currentCart->id_currency);

        $this->creditCard = HipayHelper::getActivatedPaymentByCountryAndCurrency(
            $this->module,
            $this->module->hipayConfigTool->getConfigHipay(),
            'credit_card',
            $this->deliveryCountry,
            $currency
        );
    }

    /**
     * Process post from payment form.
     *
     * @throws PrestaShopException
     */
    public function postProcess()
    {
        $apiMode = $this->module->hipayConfigTool->getPaymentGlobal()['operating_mode']['APIMode'];
        $isApplePay = false;
        // If it's an apple pay payment, force the api mode to direct post
        if ('true' === Tools::getValue('is-apple-pay')) {
            $apiMode = ApiMode::DIRECT_POST;
            $isApplePay = true;
        }

        switch ($apiMode) {
            case ApiMode::HOSTED_PAGE:
                if ('redirect' == $this->module->hipayConfigTool->getPaymentGlobal()['display_hosted_page']) {
                    $ccToken = Tools::getValue('ccTokenHipay', '');
                    if ($this->module->hipayConfigTool->getPaymentGlobal()['card_token']
                        && ((_PS_VERSION_ > '1.7' && !empty($ccToken) && 'noToken' != $ccToken)
                            ||
                            (_PS_VERSION_ < '1.7' &&
                                (empty($ccToken) || (!empty($ccToken) && 'noToken' != $ccToken))))) {
                        $path = $this->apiSavedCC(
                            Tools::getValue('ccTokenHipay'),
                            $this->currentCart,
                            $this->savedCC,
                            $this->context
                        );

                        return $this->setTemplate($path);
                    } else {
                        $this->apiHandler->handleCreditCard(
                            ApiMode::HOSTED_PAGE,
                            [
                                'method' => CardPaymentProduct::HOSTED,
                                'authentication_indicator' => $this->setAuthenticationIndicator($this->currentCart),
                                'isApplePay' => $isApplePay,
                            ]
                        );
                    }
                }
                break;
            case ApiMode::DIRECT_POST:
                if (Tools::getValue('card-token') && Tools::getValue('card-brand') && Tools::getValue('card-pan')) {
                    $this->apiNewCC($this->currentCart, $this->context, $this->customer, $this->savedCC, $isApplePay);
                } elseif (Tools::getValue('ccTokenHipay')) {
                    $path = $this->apiSavedCC(
                        Tools::getValue('ccTokenHipay'),
                        $this->currentCart,
                        $this->savedCC,
                        $this->context
                    );

                    return $this->setTemplate($path);
                }
        }
    }

    /**
     * Display payment form API/Iframe/HostedPage(PS16).
     *
     * @throws PrestaShopException
     */
    public function initContent()
    {
        // $this->display_column_left = false;
        // $this->display_column_right = false;
        parent::initContent();

        if (null == $this->currentCart->id) {
            $this->module->getLogs()->logErrors('# Cart ID is null in initContent');
            Tools::redirect('index.php?controller=order');
        }
        $this->module->getLogs()->logInfos('# Redirect init CART ID'.$this->context->cart->id);

        $this->context->smarty->assign(
            [
                'nbProducts' => $this->currentCart->nbProducts(),
                'cust_currency' => $this->currentCart->id_currency,
                'activatedCreditCard' => json_encode(array_keys($this->creditCard)),
                'currencies' => $this->module->getCurrency((int) $this->currentCart->id_currency),
                'total' => $this->currentCart->getOrderTotal(true, Cart::BOTH),
                'this_path' => $this->module->getPathUri(),
                'this_path_bw' => $this->module->getPathUri(),
                'this_path_ssl' => Tools::getShopDomainSsl(true, true).
                    __PS_BASE_URI__.
                    'modules/'.
                    $this->module->name.
                    '/',
                'hipay_enterprise_tpl_dir' => _PS_MODULE_DIR_.$this->module->name.'/views/templates',
            ]
        );

        $uxMode = $this->module->hipayConfigTool->getPaymentGlobal()['operating_mode']['UXMode'];

        // Displaying different forms depending of the operating mode chosen in the BO configuration
        switch ($uxMode) {
            case UXMode::HOSTED_PAGE:
                if ('redirect' !== $this->module->hipayConfigTool->getPaymentGlobal()['display_hosted_page']
                    && Tools::getValue('iframeCall')) {
                    $this->context->smarty->assign(
                        [
                            'url' => $this->apiHandler->handleCreditCard(
                                ApiMode::HOSTED_PAGE_IFRAME,
                                [
                                    'method' => CardPaymentProduct::HOSTED,
                                    'authentication_indicator' => $this->setAuthenticationIndicator($this->currentCart),
                                ]
                            ),
                        ]
                    );
                    $path = (_PS_VERSION_ >= '1.7' ? 'module:'.
                            $this->module->name.
                            '/views/templates/front/payment/ps17/paymentFormIframe-17'
                            : 'payment/ps16/paymentFormIframe-16').'.tpl';
                } elseif ($this->module->hipayConfigTool->getPaymentGlobal()['card_token'] && _PS_VERSION_ < '1.7') {
                    $this->assignTemplate();
                    $path = 'payment/ps16/paymentForm-'.$uxMode.'-16.tpl';
                }
                break;
            case UXMode::DIRECT_POST:
            case UXMode::HOSTED_FIELDS:
                $this->assignTemplate();

                $path = 'payment/ps16/paymentForm-'.$uxMode.'-16.tpl';
                break;
            default:
                break;
        }

        return $this->setTemplate($path);
    }

    /**
     *  Assign Order template.
     */
    private function assignTemplate()
    {
        $this->context->smarty->assign(
            [
                'status_error' => '200', // Force to ok for first call
                'status_error_oc' => '200',
                'cart_id' => $this->currentCart->id,
                'savedCC' => $this->savedCC,
                'is_guest' => $this->customer->is_guest,
                'customerFirstName' => $this->customer->firstname,
                'customerLastName' => $this->customer->lastname,
                'amount' => $this->currentCart->getOrderTotal(true, Cart::BOTH),
                'confHipay' => $this->module->hipayConfigTool->getConfigHipay(),
            ]
        );
    }

    /**
     * handle One click payment.
     *
     * @return string
     */
    private function apiSavedCC($token, $cart, $savedCC, $context)
    {
        if ($tokenDetails = $this->ccToken->getTokenDetails($cart->id_customer, $token)) {
            $params = [
                'deviceFingerprint' => Tools::getValue('ioBB'),
                'productlist' => $tokenDetails['brand'],
                'cardtoken' => $tokenDetails['token'],
                'card_holder' => $tokenDetails['card_holder'],
                'card_pan' => $tokenDetails['pan'],
                'card_expiration_date' => '0'.
                    $tokenDetails['card_expiry_month'].
                    '/'.
                    $tokenDetails['card_expiry_year'],
                'oneClick' => true,
                'method' => $tokenDetails['brand'],
                'authentication_indicator' => $this->setAuthenticationIndicator($cart),
                'browser_info' => json_decode(Tools::getValue('browserInfo')),
            ];
            $this->apiHandler->handleCreditCard(ApiMode::DIRECT_POST, $params);
        } else {
            if (_PS_VERSION_ >= '1.7') {
                $redirectUrl = $context->link->getModuleLink(
                    $this->module->name,
                    'exception',
                    ['status_error' => 405],
                    true
                );
                Tools::redirect($redirectUrl);
            }
            $context->smarty->assign(
                [
                    'status_error' => '200',
                    'status_error_oc' => '400',
                    'cart_id' => $cart->id,
                    'savedCC' => $savedCC,
                    'amount' => $cart->getOrderTotal(true, Cart::BOTH),
                    'confHipay' => $this->module->hipayConfigTool->getConfigHipay(),
                ]
            );

            return 'payment/ps16/paymentForm-'.UXMode::DIRECT_POST.'-16.tpl';
        }
    }

    /**
     * Handle Credit card payment (not one click).
     *
     * @return string
     */
    private function apiNewCC($cart, $context, $customer, $savedCC, $isApplePay)
    {
        $selectedCC = Tools::getValue('card-brand');

        if (in_array($selectedCC, array_keys($this->creditCard))) {
            try {
                $params = [
                    'deviceFingerprint' => Tools::getValue('ioBB'),
                    'productlist' => $selectedCC,
                    'cardtoken' => Tools::getValue('card-token'),
                    'card_holder' => Tools::getValue('card-holder'),
                    'card_pan' => Tools::getValue('card-pan'),
                    'card_expiration_date' => Tools::getValue('card-expiry-month').
                        '/'.
                        Tools::getValue('card-expiry-year'),
                    'method' => $selectedCC,
                    'authentication_indicator' => $this->setAuthenticationIndicator($cart),
                    'browser_info' => json_decode(Tools::getValue('browserInfo')),
                    'isApplePay' => $isApplePay,
                ];
                $this->apiHandler->handleCreditCard(ApiMode::DIRECT_POST, $params);
            } catch (Exception $e) {
                $this->module->getLogs()->logException($e);

                return HipayHelper::redirectToErrorPage($context, $this->module, $cart, $savedCC);
            }
        } else {
            return HipayHelper::redirectToErrorPage($context, $this->module, $cart, $savedCC);
        }
    }

    /**
     * Add JS and CSS in checkout page.
     */
    public function setMedia()
    {
        parent::setMedia();

        $this->addJS([_MODULE_DIR_.'hipay_enterprise/views/js/cc.functions.js']);
        $this->addJS([_MODULE_DIR_.'hipay_enterprise/views/js/devicefingerprint.js']);
        $this->addCSS([_MODULE_DIR_.'hipay_enterprise/views/css/hipay-enterprise.css']);
        $this->context->controller->addJS(
            [
                $this->module->hipayConfigTool->getPaymentGlobal()['sdk_js_url'],
            ]
        );

        $uxMode = $this->module->hipayConfigTool->getPaymentGlobal()['operating_mode']['UXMode'];
        // Displaying different forms depending of the operating mode chosen in the BO configuration
        switch ($uxMode) {
            case UXMode::DIRECT_POST:
                $this->addJS([_MODULE_DIR_.'hipay_enterprise/views/js/strings.js']);
                $this->addJS([_MODULE_DIR_.'hipay_enterprise/views/js/card-js.min.js']);
                $this->addCSS([_MODULE_DIR_.'hipay_enterprise/views/css/card-js.min.css']);
                $this->addJS([_MODULE_DIR_.'hipay_enterprise/views/js/form-input-control.js']);
                break;
            case UXMode::HOSTED_FIELDS:
                $this->addJS([_MODULE_DIR_.'hipay_enterprise/views/js/hosted-fields.js']);
                break;
        }
    }

    /**
     * set 3D-secure or not from configuration.
     *
     * @return int
     */
    private function setAuthenticationIndicator($cart)
    {
        switch ($this->module->hipayConfigTool->getPaymentGlobal()['activate_3d_secure']) {
            case ThreeDS::THREE_D_S_DISABLED:
                return 0;
            case ThreeDS::THREE_D_S_TRY_ENABLE_ALL:
                return 1;
            case ThreeDS::THREE_D_S_TRY_ENABLE_RULES:
                $cartSummary = $cart->getSummaryDetails();
                foreach ($this->module->hipayConfigTool->getPaymentGlobal()['3d_secure_rules'] as $rule) {
                    if (isset($cartSummary[$rule['field']]) &&
                        !$this->criteriaMet(
                            (int) $cartSummary[$rule['field']],
                            html_entity_decode($rule['operator']),
                            (int) $rule['value']
                        )
                    ) {
                        return 0;
                    }
                }

                return 1;
            case ThreeDS::THREE_D_S_FORCE_ENABLE_ALL:
                return 2;
            case ThreeDS::THREE_D_S_FORCE_ENABLE_RULES:
                $cartSummary = $cart->getSummaryDetails();

                foreach ($this->module->hipayConfigTool->getPaymentGlobal()['3d_secure_rules'] as $rule) {
                    if (isset($cartSummary[$rule['field']]) &&
                        !$this->criteriaMet(
                            (int) $cartSummary[$rule['field']],
                            html_entity_decode($rule['operator']),
                            (int) $rule['value']
                        )
                    ) {
                        return 0;
                    }
                }

                return 2;
            default:
                return 0;
        }
    }

    /**
     * Test 2 value with $operator.
     *
     * @param type $value1
     * @param type $operator
     * @param type $value2
     *
     * @return bool
     */
    private function criteriaMet($value1, $operator, $value2)
    {
        switch ($operator) {
            case '<':
                return $value1 < $value2;
            case '<=':
                return $value1 <= $value2;
            case '>':
                return $value1 > $value2;
            case '>=':
                return $value1 >= $value2;
            case '==':
                return $value1 == $value2;
            case '!=':
                return $value1 != $value2;
            default:
                return false;
        }

        return false;
    }
}
