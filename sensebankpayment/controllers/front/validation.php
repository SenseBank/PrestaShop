<?php
/**
* @since 1.5.0
*/
class SensebankPaymentValidationModuleFrontController extends ModuleFrontController
{
/** @var SensebankPayment Z*/
public $module;
/**
* @see FrontController::postProcess()
*/
public function postProcess()
{
$cart = $this->context->cart;
if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active)
Tools::redirect('index.php?controller=order&step=1');
$authorized = false;
foreach (Module::getPaymentModules() as $module)
if ($module['name'] == 'sensebankpayment')
{
$authorized = true;
break;
}
if (!$authorized)
die($this->module->l('This payment method is not available.', 'validation'));
$customer = new Customer($cart->id_customer);
if (!Validate::isLoadedObject($customer))
Tools::redirect('index.php?controller=order&step=1');
$currency = $this->context->currency;
$total = (float)$cart->getOrderTotal(true, Cart::BOTH);
$this->module->validateOrder($cart->id,
Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE_DEFAULT'),
$total, $this->module->displayName,
null,
null,
(int)$currency->id, false, $customer->secure_key);
Tools::redirect($this->module->getRedirectLink());
}
}
