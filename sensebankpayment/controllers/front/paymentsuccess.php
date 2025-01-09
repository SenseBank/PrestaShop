<?php
class SensebankPaymentPaymentSuccessModuleFrontController extends ModuleFrontController
{
public $ssl = true;
public $display_column_left = false;
public $display_column_right = false;
public function initContent()
{
parent::initContent();
$this->setTemplate('module:sensebankpayment/views/templates/front/paymentsuccess.tpl');
}
}