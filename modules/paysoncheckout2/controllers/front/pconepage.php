<?php
/**
 * 2018 Payson AB
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 *  @author    Payson AB <integration@payson.se>
 *  @copyright 2018 Payson AB
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;

class PaysonCheckout2PcOnePageModuleFrontController extends ModuleFrontController
{

    public $display_column_left = false;
    public $display_column_right = false;

    public function initContent()
    {
        parent::initContent();

        if (_PCO_LOG_) {
            Logger::addLog('* ' . __FILE__ . ' -> ' . __METHOD__ . ' *', 1, null, null, null, true);
        }

        if (!isset($this->context->cart->id) || $this->context->cart->nbProducts() < 1) {
            if (Tools::getIsset('pco_update') and Tools::getValue('pco_update') == '1') {
                die('reload');
            }
            Tools::redirect('index.php');
        }

        // Set delivery option on cart if needed
        if (!$this->context->cart->getDeliveryOption(null, true)) {
            $this->context->cart->setDeliveryOption($this->context->cart->getDeliveryOption());
            $this->context->cart->save();
            if (_PCO_LOG_) {
                Logger::addLog('Added default delivery: ' . print_r($this->context->cart->getDeliveryOption(), true), 1, null, null, null, true);
            }
        }

        // Check if rules apply
        CartRule::autoRemoveFromCart($this->context);
        CartRule::autoAddToCart($this->context);

        $cartCurrency = new Currency($this->context->cart->id_currency);
        if (_PCO_LOG_) {
            Logger::addLog('Cart Currency: ' . $cartCurrency->iso_code, 1, null, null, null, true);
        }

        if (isset($this->context->cart) && $this->context->cart->nbProducts() > 0) {
            $cartQuantities = $this->context->cart->checkQuantities(true);
            if ($cartQuantities !== true) {
                //$this->context->cookie->__set('validation_error', $this->l(sprintf('An item (%1s) in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.', $cartQuantities['name'])));
                $this->context->cookie->__set('validation_error', $this->l('An item in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.'));
                $this->context->cookie->__set('paysonCheckoutId', null);
            } else {
                $min_purchase = Tools::convertPrice((float) Configuration::get('PS_PURCHASE_MINIMUM'), $cartCurrency);
                if ($this->context->cart->getOrderTotal(false, Cart::ONLY_PRODUCTS) < $min_purchase) {
                    $this->context->cookie->__set('validation_error', $this->l('This order does not meet the requirement for minimum order value.'));
                    //Tools::redirect('index.php?fc=module&module=paysoncheckout2&controller=pconepage');
                }

                require_once(_PS_MODULE_DIR_ . 'paysoncheckout2/paysoncheckout2.php');
                $payson = new PaysonCheckout2();

                try {
                    $paysonApi = $payson->getPaysonApiInstance();
                    if (_PCO_LOG_) {
                        Logger::addLog('Payson API Merchant ID: ' . $paysonApi->getMerchantId(), 1, null, null, null, true);
                    }
                } catch (Exception $e) {
                    Logger::addLog('Payson API Failure: ' . $e->getMessage(), 3, null, null, null, true);
                    Tools::redirect('index.php');
                }

                // URL:s for JS/AJAX call, added to tpl
                $pcoUrl = $this->context->link->getModuleLink('paysoncheckout2', 'pconepage', array(), true);
                $validateUrl = $this->context->link->getModuleLink('paysoncheckout2', 'validation', array(), true);

                $address = new Address();
                $customer = new Customer();

                if ($this->context->customer->isLogged() || $this->context->customer->is_guest) {
                    if (_PCO_LOG_) {
                        Logger::addLog($this->context->customer->is_guest == 1 ? 'Customer is: Guest' : 'Customer is: Logged in', 1, null, null, null, true);
                    }
                    // Customer is logged in or has entered guest address information, we'll use this information
                    $customer = new Customer((int) ($this->context->cart->id_customer));
                    $address = new Address((int) ($this->context->cart->id_address_invoice));

//                    $state = null;
                    if ($address->id_state) {
                        $state = new State((int) ($address->id_state));
                    }

//                    if (!Validate::isLoadedObject($address)) {
//                        Logger::addLog('Unable to validate address.', 3, null, null, null, true);
//                        Tools::redirect('index.php');
//                    }

                    if (!Validate::isLoadedObject($customer)) {
                        Logger::addLog('Unable to validate customer.', 3, null, null, null, true);
                        Tools::redirect('index.php');
                    }
                    //if (_PCO_LOG_) {
                    //Logger::addLog('Customer: ' . print_r($customer, true), 1, null, null, null, true);
                    //}
                    //if (_PCO_LOG_) {
                    //Logger::addLog('Address: ' . print_r($address, true), 1, null, null, null, true);
                    //}
                } else {
                    if (_PCO_LOG_) {
                        Logger::addLog('Customer is not Guest or Logged in', 1, null, null, null, true);
                    }
                }

                try {
                    if ($this->context->cookie->paysonCheckoutId != null && $payson->canUpdate($paysonApi, $this->context->cookie->paysonCheckoutId) && $payson->checkCurrencyName($cartCurrency->iso_code, $paysonApi, $this->context->cookie->paysonCheckoutId)) {
                        // Get checkout object, ID from cookie
                        $chTempObj = $paysonApi->GetCheckout($this->context->cookie->paysonCheckoutId);

                        // Update checkout object
                        $checkoutObj = $paysonApi->UpdateCheckout($payson->updatePaysonCheckout($chTempObj, $customer, $this->context->cart, $payson, $address, $cartCurrency));

                        // Update data in Payson order table
                        $payson->updatePaysonOrderEvent($checkoutObj, $this->context->cart->id);
                        if (_PCO_LOG_) {
                            Logger::addLog('Loaded updated PCO.', 1, null, null, null, true);
                        }
                    } else {
                        // Create a new checkout object
                        $checkoutId = $paysonApi->CreateCheckout($payson->createPaysonCheckout($customer, $this->context->cart, $payson, $cartCurrency, $this->context->language->id, $address));

                        // Save PCO ID in cookie
                        $this->context->cookie->__set('paysonCheckoutId', $checkoutId);

                        //Get the new checkout object
                        $checkoutObj = $paysonApi->GetCheckout($checkoutId);

                        // Create data in Payson order table
                        $payson->createPaysonOrderEvent($checkoutObj->id, $this->context->cart->id);
                        if (_PCO_LOG_) {
                            Logger::addLog('Loaded new PCO.', 1, null, null, null, true);
                        }
                    }

                    if ($checkoutObj->id != null) {
                        // Get snippet for template
                        $snippet = $checkoutObj->snippet;
                        if (_PCO_LOG_) {
                            Logger::addLog('PCO ID: ' . $checkoutObj->id, 1, null, null, null, true);
                        }
                    } else {
                        Logger::addLog('Unable to retrive checkout.', 3, null, null, null, true);
                        Tools::redirect('index.php');
                    }
                } catch (Exception $e) {
                    Logger::addLog('Unable to get checkout. Message: ' . $e->getMessage(), 3, null, null, null, true);
                    $this->context->cookie->__set('paysonCheckoutId', null);
                    Tools::redirect('index.php');
                }
            }

            // Refresh cart summary
            $this->context->cart->getSummaryDetails();
            //if (_PCO_LOG_) {
            //Logger::addLog('Cart Summary: ' . print_r($cartSummary, true), 1, null, null, null, true);
            //}
            // AJAX call should have pco_update set to 1, die and return snippet
            if (Tools::getIsset('pco_update') and Tools::getValue('pco_update') == '1') {
                die($snippet);
            }

            $wrapping_fees_tax_inc = $this->context->cart->getGiftWrappingPrice(true);

            // Assign tpl variables
            //$this->context->smarty->assign('payson_checkout', $snippet);
            $this->context->smarty->assign('discounts', $this->context->cart->getCartRules());
            $this->context->smarty->assign('cart_is_empty', false);
            $this->context->smarty->assign('gift', $this->context->cart->gift);
            $this->context->smarty->assign('gift_message', $this->context->cart->gift_message);
            $this->context->smarty->assign('giftAllowed', (int) (Configuration::get('PS_GIFT_WRAPPING')));
            $this->context->smarty->assign('gift_wrapping_price', Tools::convertPrice($wrapping_fees_tax_inc, $cartCurrency));
            $this->context->smarty->assign('message', Message::getMessageByCartId((int) ($this->context->cart->id)));
            $this->context->smarty->assign('pco_checkout_id', $checkoutObj->id);
            $this->context->smarty->assign('id_cart', $this->context->cart->id);


            $free_fees_price = 0;
            $configuration = Configuration::getMultiple(array('PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_FREE_WEIGHT'));

            if (isset($configuration['PS_SHIPPING_FREE_PRICE']) && $configuration['PS_SHIPPING_FREE_PRICE'] > 0) {
                $free_fees_price = Tools::convertPrice((float) $configuration['PS_SHIPPING_FREE_PRICE'], Currency::getCurrencyInstance((int) $this->context->cart->id_currency));
                $orderTotalwithDiscounts = $this->context->cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING, null, null, false);
                $left_to_get_free_shipping = ($free_fees_price - $orderTotalwithDiscounts);
                $this->context->smarty->assign('left_to_get_free_shipping', $left_to_get_free_shipping);
            }

            if (isset($configuration['PS_SHIPPING_FREE_WEIGHT']) && $configuration['PS_SHIPPING_FREE_WEIGHT'] > 0) {
                $free_fees_weight = $configuration['PS_SHIPPING_FREE_WEIGHT'];
                $total_weight = $this->context->cart->getTotalWeight();
                $left_to_get_free_shipping_weight = $free_fees_weight - $total_weight;
                $this->context->smarty->assign('left_to_get_free_shipping_weight', $left_to_get_free_shipping_weight);
            }

            $this->assignSummaryInformations();

            $checkoutSession = $this->getCheckoutSession();

            $delivery_options = $checkoutSession->getDeliveryOptions();
            $delivery_options_finder_core = new DeliveryOptionsFinder($this->context, $this->getTranslator(), $this->objectPresenter, new PriceFormatter());
            $delivery_option = $delivery_options_finder_core->getSelectedDeliveryOption();

            $free_shipping = false;
            foreach ($this->context->cart->getCartRules() as $rule) {
                if ($rule['free_shipping']) {
                    $free_shipping = true;
                    break;
                }
            }

            if (_PCO_LOG_) {
                Logger::addLog('Delivery option: ' . print_r($delivery_options, true), 1, null, null, null, true);
            }

            $this->context->smarty->assign('payson_errors', null);

            if (isset($this->context->cookie->validation_error) && $this->context->cookie->validation_error != null) {
                if (_PCO_LOG_) {
                    Logger::addLog('Redirection error message: ' . $this->context->cookie->validation_error, 1, null, null, null, true);
                }

                $this->context->smarty->assign('payson_errors', $this->context->cookie->validation_error);

                // Delete old messages
                $this->context->cookie->__set('validation_error', null);
            }

            $this->context->smarty->assign(array(
                'payson_checkout' => $snippet,
                'controllername' => 'pconepage',
                'free_shipping' => $free_shipping,
                'id_lang' => $this->context->language->id,
                'token_cart' => $this->context->cart->secure_key,
                'id_address' => $this->context->cart->id_address_delivery,
                'delivery_options' => $delivery_options,
                'delivery_option' => $delivery_option,
                'pcoUrl' => $pcoUrl,
                'validateUrl' => $validateUrl,
                'PAYSONCHECKOUT2_SHOW_OTHER_PAYMENTS' => (int) Configuration::get('PAYSONCHECKOUT2_SHOW_OTHER_PAYMENTS')
            ));
        } else {
            $this->context->smarty->assign('payson_errors', $this->l('Your cart is empty.'));
        }

        // All done, lets checkout!
        //$this->setTemplate('module:paysoncheckout2/views/templates/front/' . Configuration::get('PAYSONCHECKOUT2_TEMPLATE') . '.tpl');
    }

    protected function getCheckoutSession()
    {
        $deliveryOptionsFinder = new DeliveryOptionsFinder($this->context, $this->getTranslator(), $this->objectPresenter, new PriceFormatter());

        $session = new CheckoutSession($this->context, $deliveryOptionsFinder);

        return $session;
    }

    protected function validateDeliveryOption($delivery_option)
    {
        if (!is_array($delivery_option)) {
            return false;
        }

        foreach ($delivery_option as $option) {
            if (!preg_match('/(\d+,)?\d+/', $option)) {
                return false;
            }
        }

        return true;
    }

    protected function updateMessage($messageContent, $cart)
    {
        if ($messageContent) {
            if (!Validate::isMessage($messageContent)) {
                return false;
            } elseif ($oldMessage = Message::getMessageByCartId((int) ($cart->id))) {
                $message = new Message((int) ($oldMessage['id_message']));
                $message->message = $messageContent;
                $message->update();
            } else {
                $message = new Message();
                $message->message = $messageContent;
                $message->id_cart = (int) ($cart->id);
                $message->id_customer = (int) ($cart->id_customer);
                $message->add();
            }
        } else {
            if ($oldMessage = Message::getMessageByCartId((int) ($cart->id))) {
                $message = new Message((int) ($oldMessage['id_message']));
                $message->delete();
            }
        }

        return true;
    }

    protected function assignSummaryInformations()
    {
        $summary = $this->context->cart->getSummaryDetails();
        $customizedDatas = Product::getAllCustomizedDatas($this->context->cart->id);

        // override customization tax rate with real tax (tax rules)
        if ($customizedDatas) {
            foreach ($summary['products'] as &$productUpdate) {
                if (isset($productUpdate['id_product'])) {
                    $productId = (int) $productUpdate['id_product'];
                } else {
                    $productId = (int) $productUpdate['product_id'];
                }

                if (isset($productUpdate['id_product_attribute'])) {
                    $productAttributeId = (int) $productUpdate['id_product_attribute'];
                } else {
                    $productAttributeId = (int) $productUpdate['product_attribute_id'];
                }

                if (isset($customizedDatas[$productId][$productAttributeId])) {
                    $productUpdate['tax_rate'] = Tax::getProductTaxRate($productId, $this->context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')});
                }
            }
            Product::addCustomizationPrice($summary['products'], $customizedDatas);
        }

        $cart_product_context = Context::getContext()->cloneContext();
        foreach ($summary['products'] as $key => &$product) {
            // For older themes
            $product['quantity'] = $product['cart_quantity'];

            if ($cart_product_context->shop->id != $product['id_shop']) {
                $cart_product_context->shop = new Shop((int) $product['id_shop']);
            }
            $specific_price_output = null;
            $product['price_without_specific_price'] = Product::getPriceStatic($product['id_product'], !Product::getTaxCalculationMethod(), $product['id_product_attribute'], 2, null, false, false, 1, false, null, null, null, $specific_price_output, true, true, $cart_product_context);

            if (Product::getTaxCalculationMethod()) {
                $product['is_discounted'] = $product['price_without_specific_price'] != $product['price'];
            } else {
                $product['is_discounted'] = $product['price_without_specific_price'] != $product['price_wt'];
            }
        }

        // Get available cart rules and unset the cart rules already in the cart
        $available_cart_rules = CartRule::getCustomerCartRules($this->context->language->id, (isset($this->context->customer->id) ? $this->context->customer->id : 0), true, true, true, $this->context->cart);

        $cart_cart_rules = $this->context->cart->getCartRules();
        foreach ($available_cart_rules as $key => $available_cart_rule) {
            if (!$available_cart_rule['highlight'] || strpos($available_cart_rule['code'], 'BO_ORDER_') === 0) {
                unset($available_cart_rules[$key]);
                continue;
            }
            foreach ($cart_cart_rules as $cart_cart_rule) {
                if ($available_cart_rule['id_cart_rule'] == $cart_cart_rule['id_cart_rule']) {
                    unset($available_cart_rules[$key]);
                    continue 2;
                }
            }
        }

        $show_option_allow_separate_package = (!$this->context->cart->isAllProductsInStock(true) &&
                Configuration::get('PS_SHIP_WHEN_AVAILABLE'));

        $this->context->smarty->assign($summary);
        $this->context->smarty->assign(array(
            'token_cart' => Tools::getToken(false),
            'isVirtualCart' => $this->context->cart->isVirtualCart(),
            'productNumber' => $this->context->cart->nbProducts(),
            'voucherAllowed' => CartRule::isFeatureActive(),
            'shippingCost' => $this->context->cart->getOrderTotal(true, Cart::ONLY_SHIPPING),
            'shippingCostTaxExc' => $this->context->cart->getOrderTotal(false, Cart::ONLY_SHIPPING),
            'customizedDatas' => $customizedDatas,
            'CUSTOMIZE_FILE' => Product::CUSTOMIZE_FILE,
            'CUSTOMIZE_TEXTFIELD' => Product::CUSTOMIZE_TEXTFIELD,
            'lastProductAdded' => $this->context->cart->getLastProduct(),
            'displayVouchers' => $available_cart_rules,
            'advanced_payment_api' => true,
            'currencySign' => $this->context->currency->sign,
            'currencyRate' => $this->context->currency->conversion_rate,
            'currencyFormat' => $this->context->currency->format,
            'currencyBlank' => $this->context->currency->blank,
            'show_option_allow_separate_package' => $show_option_allow_separate_package,
            'smallSize' => Image::getSize(ImageType::getFormatedName('small')),
        ));

        $this->context->smarty->assign(array(
            'HOOK_SHOPPING_CART' => Hook::exec('displayShoppingCartFooter', $summary),
            'HOOK_SHOPPING_CART_EXTRA' => Hook::exec('displayShoppingCart', $summary),
        ));
    }
}