<?php
class SensebankPaymentPaymentGateModuleFrontController extends ModuleFrontController
{
/** @var SensebankPaymentPayment */
public $module;
public function initContent()
{
parent::initContent();
$id_order = Tools::getValue('id_order');
$order = new Order($id_order);
if ($this->context->customer->id != $order->id_customer)
Tools::redirect($this->context->link->getPageLink('history'));
$order_module = Module::getInstanceByName($order->module);
if (!$id_order || $order_module->id != $this->module->id)
Tools::redirect($this->context->link->getPageLink('history'));
$check_db_values = $this->module->getPaymentGateValues($id_order);
$auth_canceled = $check_db_values['order_status'] == SensebankPayment::_AUTHORIZATION_CANCELED_;
$auth_failed = $check_db_values['order_status'] == SensebankPayment::_AUTHORIZATION_FAILED_;
if (!$check_db_values || $auth_canceled || $auth_failed) {
$request_values = $this->module->registerInPaymentGate($id_order);
if ( !empty($request_values['orderid']) ) {
$db_values = $this->module->getPaymentGateValues($id_order);
if (!$db_values) {
$order_error_code = !empty($request_values['errorcode']) ? $request_values['errorcode'] : __LINE__;
$order_error_message = !empty($request_values['errormessage']) ? $request_values['errormessage'] : __LINE__;
$this->context->smarty->assign('order_error_message', $order_error_message);
$this->context->smarty->assign('order_error_code', $order_error_code);
$this->setTemplate('module:sensebankpayment/views/templates/front/paymentfail.tpl');
} else {
Tools::redirect($db_values['form_url'], '');
}
} else {
$order_error_message = $request_values['errormessage'];
$this->context->smarty->assign('order_error_message', $order_error_message);
$this->context->smarty->assign('order_error_code', $request_values['errorcode']);
$this->setTemplate('module:sensebankpayment/views/templates/front/paymentfail.tpl');
}
} else {
Tools::redirect($check_db_values['form_url'], '');
}
}
}