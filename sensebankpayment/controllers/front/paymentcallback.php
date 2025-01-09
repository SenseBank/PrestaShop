<?php
class SensebankPaymentPaymentCallbackModuleFrontController extends ModuleFrontController
{
public function initContent()
{
parent::initContent();
if (Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE') != Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE_DEFAULT'))
{
$order_id = Tools::getValue('mdOrder');
$curl_data = array('userName' => $this->module->getLogin(), 'password' => $this->module->getPassword(), 'orderId' => $order_id);
$return_gate = $this->module->gateway('getOrderStatus'.$this->module->ext, $curl_data, $this->module->getLink(), $this->module->write_log);
$response = $this->module->noCamelCase($return_gate);
$id_order = Db::getInstance()->getValue('SELECT `id_order` FROM `'
._DB_PREFIX_.'sensebankpayment` WHERE `gateway_order_id` = \''.pSQL($order_id).'\'');
$order = new Order($id_order);
if ($id_order && isset($response['orderstatus']) && ($response['orderstatus'] == 1 || $response['orderstatus'] == 2))
{
$this->module->logger("Case when change orderState (PS_SENSEBANKPAYMENT_SENSEBANK_STATE_STATE)\n");
if ($order->current_state != (int)Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE')) {
$order->setCurrentState((int)Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE'));
}
}
else {
$this->module->logger("Case when change orderState (PS_SENSEBANKPAYMENT_SENSEBANK_STATE_ERROR)\n");
$order->setCurrentState((int)Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE_ERROR'));
}
}
exit;
}
}