<?php
class SensebankPaymentPaymentFailModuleFrontController extends ModuleFrontController
{
public $ssl = true;
public $display_column_left = false;
public $display_column_right = false;
public function initContent()
{
if (Tools::version_compare(_PS_VERSION_, '1.6', '<')) {
$this->display_column_right = true;
}
parent::initContent();
$this->setTemplate('module:sensebankpayment/views/templates/front/paymentfail.tpl');
}
}