<?php
require_once 'include.php';
if (!class_exists('DiscountHelper')) {
require_once(__DIR__ . '/DiscountHelper.php');
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\OrderException;
if (!defined('_PS_VERSION_'))
exit;
class SensebankPayment extends PaymentModule
{
const _REGISTERED_BUT_NOT_PAID_FOR_ = 0;
const _PRE_AUTHORIZATION_ = 1;
const _AUTHORIZATION_ = 2;
const _AUTHORIZATION_CANCELED_ = 3;
const _ORDER_RETURN_ = 4;
const _ACS_INITIATED_AUTHORIZATION_THROUGH_THE_ISSUING_BANK_ = 5;
const _AUTHORIZATION_FAILED_ = 6;
static $cache = array();
public $default_order_state;
public $test_mode;
public $enable_cacert;
public $send_order = 0;
public $ext = '.do';
public $write_log;
protected $login;
protected $password;
public function __construct()
{
$this->name = 'sensebankpayment';
$this->tab = 'payments_gateways';
$this->version = '2.8.2';
$this->author = 'SensebankPayment';
$this->controllers = array('payment', 'validation');
$this->currencies = true;
$this->currencies_mode = 'checkbox';
$this->bootstrap = true;
$this->default_vat = 0;
parent::__construct();
$this->displayName = 'Sensebank';
$this->description = $this->l('Allows you to use a payment gateway', 'sensebankpayment') . " " . SENSEBANKPAYMENT_SENSEBANK_PAYMENT_NAME;
$this->confirmUninstall = $this->l('Are you sure to delete this module', 'sensebankpayment');
$this->default_order_state = Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE_DEFAULT');
$this->login = Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_LOGIN');
$this->password = Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_PASSWORD');
$this->test_mode = Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_TEST_MODE');
$this->send_order = Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_SEND_ORDER');
$this->default_vat = Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_VAT');
$this->stage = Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STAGE');
$this->enable_cacert = Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_SSL_VERIFY');
$this->write_log = Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_WRITE_LOG');
$this->backToShopUrl = Configuration::get('IDD_BACK_TO_SHOP_URL');
$this->backToShopUrlName = Configuration::get('IDD_BACK_TO_SHOP_URL_NAME');
$this->ffd_version = Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_VERSION');
$this->ffd_paymentObjectType = Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_PAYMENT_OBJECT_TYPE');
$this->ffd_paymentMethodType = Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_PAYMENT_METHOD_TYPE');
$this->ffd_paymentMethodTypeDelivery = Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_PAYMENT_METHOD_TYPE_DELIVERY');
}
/**
* @param string $pattern
* @param int $flags
* @return array
*/
public static function globRecursive($pattern, $flags = 0)
{
$files = glob($pattern, $flags);
if (!$files)
$files = array();
foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir)
/** @noinspection SlowArrayOperationsInLoopInspection */
$files = array_merge($files, self::globRecursive($dir . '/' . basename($pattern), $flags));
return $files;
}
public static function noEscape($value)
{
return $value;
}
public function install()
{
$sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'sensebankpayment` (
`id_order` int(11) NOT NULL,
`order_number` text COLLATE utf8_unicode_ci NOT NULL,
`gateway_order_id` text COLLATE utf8_unicode_ci NOT NULL,
`form_url` text COLLATE utf8_unicode_ci NOT NULL,
PRIMARY KEY (`id_order`),
UNIQUE KEY `id_order` (`id_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;';
if (!parent::install()
|| !$this->registerHook('payment')
|| !$this->registerHook('header')
|| !$this->registerHook('paymentReturn')
|| !$this->registerHook('paymentOptions')
|| !$this->registerHook('displayAdminOrderTop')
|| !$this->registerHook('displayAdminOrder')
|| !$this->registerHook('actionOrderSlipAdd')
|| !Db::getInstance()->execute($sql)
) {
return false;
}
return true;
}
public function uninstall()
{
return parent::uninstall();
}
public function _getOrderDetails($orderId)
{
$sql = new DbQuery();
$sql->select('*');
$sql->from('sensebankpayment');
$sql->where('id_order = "' . pSQL($orderId) . '"');
$orderDetails = (Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql));
return $orderDetails;
}
public function hookDisplayAdminOrderTop($params)
{
return $this->getPartialRefund($params);
}
public function hookDisplayAdminOrder($params)
{
if (version_compare(_PS_VERSION_, '1.7.7', '>=')) {
return false;
}
return $this->getPartialRefund($params);
}
protected function getPartialRefund($params)
{
$orderDetails = $this->_getOrderDetails($params['id_order']);

if (!defined('SENSEBANKPAYMENT_SENSEBANK_ENABLE_REFUNDS_ACTION') || SENSEBANKPAYMENT_SENSEBANK_ENABLE_REFUNDS_ACTION !== true) {
return '';
}
if (!$orderDetails) {
return '';
}
$this->context->smarty->assign('gateway_order_id', $orderDetails['gateway_order_id']);
$this->context->smarty->assign('chb_sensebankpayment_refund', $this->l('Refund on SensebankPayment'));
return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/hook/partialRefund.tpl');
}
public function hookActionOrderSlipAdd($params)
{
if (Tools::isSubmit('doPartialRefundSensebankPayment')) {
$orderDetails = $this->_getOrderDetails($params['order']->id);
if (!$orderDetails) {
return false;
}
$refundResponse = $this->partialRefund($params);
if (isset($refundResponse['errorCode']) && $refundResponse['errorCode'] == 0) {
return true;
} else {
throw new OrderException($refundResponse["errorMessage"]);
}
}
}
public function partialRefund($params)
{
$orderDetails = $this->_getOrderDetails($params['order']->id);
if ($orderDetails) {
$amount = 0;
foreach ($params['productList'] as $product) {
$amount += Tools::ps_round($product['amount'], 2);
}
if (Tools::getValue('partialRefundShippingCost')) {
$amount += Tools::getValue('partialRefundShippingCost');
}
if ($refundData = Tools::getValue('cancel_product')) {
$amount += (float)(str_replace(',', '.', $refundData['shipping_amount']));
}
$amount = Tools::ps_round($amount, 2);
$orderKey = $orderDetails['gateway_order_id'];
if ($amount > 0 && !empty($orderKey)) {
try {
return $this->doRefund($orderKey, $amount);
} catch (Exception $e) {
}
}
return false;
}
}

public function doRefund($gateway_order_id, $amount = null)
{
$args = array(
'userName' => $this->getLogin(),
'password' => $this->getPassword(),
'orderId' => $gateway_order_id,
'amount' => $amount * 100,
);
$gatewayData = $this->gateway('getOrderStatusExtended' . $this->ext, $args, $this->getLink(), $this->write_log);
if ($gatewayData["orderStatus"] == "2"  || $gatewayData["orderStatus"] == "4") { //DEPOSITED || REFUNDED
return $this->gateway('refund' . $this->ext, $args, $this->getLink(), $this->write_log);
} elseif ($gatewayData["orderStatus"] == "1") { //APPROVED 2x
if ($amount == 0) { //todo Full reverse fix must be here, if total sum equals sum refund only
unset($args['amount']);
}
return $this->gateway('reverse' . $this->ext, $args, $this->getLink(), $this->write_log);
} else {
return array (
'errorCode' => 999,
'errorMessage' => 'Order failed to be refunded. Please contact administrator for more help.'
);
}
}

public function hookDisplayPaymentReturn()
{
$this->hookPayment();
}
public function hookPayment()
{
if (!$this->active)
return;
$this->smarty->assign(array(
'module' => $this,
'this_path' => $this->_path,
'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/'
));
$this->context->controller->addCSS($this->_path . 'views/css/sensebankpayment.css', 'all');
return $this->display(__FILE__, 'payment.tpl');
}
public function hookPaymentOptions($params)
{
if (!$this->active) {
return;
}
if (!$this->checkCurrency($params['cart'])) {
return;
}
$this->smarty->assign(array(
'module' => $this,
'this_path' => $this->_path,
'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/'
));
$newOption = new PaymentOption();
$title = $this->l('Bank Card Payment', 'sensebankpayment') . " (" . SENSEBANKPAYMENT_SENSEBANK_PAYMENT_NAME . ")";
$newOption->setCallToActionText($title)
->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
->setAdditionalInformation($this->fetch('module:sensebankpayment/views/templates/hook/payment.tpl'));
$payment_options = array(
$newOption
);
return $payment_options;
}
public function checkCurrency($cart)
{
$currency_order = new Currency($cart->id_currency);
$currencies_module = $this->getCurrency($cart->id_currency);
if (is_array($currencies_module))
foreach ($currencies_module as $currency_module)
if ($currency_order->id == $currency_module['id_currency'])
return true;
return false;
}
public function checkCartAmount($cart = null)
{
if (empty($cart)) {
return false;
}
if (!(int)Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_SEND_ORDER')) {
return true;
}
$discounts = $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
$shipping = $cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
if (($discounts == $shipping) || ($discounts == 0)) {
return true;
}
return false;
}
public function registerInPaymentGate($id_order)
{
$cache_key = 'getPaymentGateValues_' . $id_order;
if (isset(self::$cache[$cache_key]))
unset(self::$cache[$cache_key]);
$order = new Order((int)$id_order);
$order_number = Db::getInstance()->getValue('SELECT IFNULL(`order_number`, 0) FROM `'
. _DB_PREFIX_ . 'sensebankpayment` WHERE `id_order` = \'' . (int)$order->id . '\'') + 1;
$description = $this->l('Order number #', 'sensebankpayment') . $id_order . " \n " . $order->getFirstMessage();
$jsonParams_array = array(
'CMS:' => 'PrestaShop ' . _PS_VERSION_,
'Module-Version: ' => $this->version
);
if (defined('SENSEBANKPAYMENT_SENSEBANK_ENABLE_BACK_URL_SETTINGS')
&& SENSEBANKPAYMENT_SENSEBANK_ENABLE_BACK_URL_SETTINGS === true
&& !empty($this->backToShopUrl)
) {
$jsonParams_array['backToShopUrl'] = $this->backToShopUrl;
}
$curl_data = array(
'userName' => $this->getLogin(),
'password' => $this->getPassword(),
'amount' => $order->total_paid * 100,
'orderNumber' => (int)$order->id . '_' . $order_number . '.' . time(),
'description' => $description,
'returnUrl' => $this->context->link->getModuleLink($this->name, 'paymentsuccess', array(), true),
'failUrl' => $this->context->link->getModuleLink($this->name, 'paymentfail', array(), true),
'jsonParams' => json_encode($jsonParams_array),
);
$id_customer = $order->id_customer;
$customer = new Customer((int)$id_customer);
if (!empty($id_customer && $id_customer > 0 && $customer->is_guest == "0")) {
$client_email = !empty($customer->email) ? $customer->email : "";
$curl_data['clientId'] = md5($id_customer  .  $client_email  . $this->context->link->getModuleLink($this->name, 'fakelink', array(), true));
}
if ($this->send_order) {
$ProductDetailObject = new OrderDetail;
$product_detail = $ProductDetailObject->getList($id_order);
$items = array();
$itemsCnt = 1;
$amountFix = 0;
foreach ($product_detail as $value) {
$item = array();
$item['positionId'] = $itemsCnt++;
$item['name'] = $value['product_name'];
$item['quantity'] = array(
'value' => $value['product_quantity'],
'measure' => ($this->ffd_version == 'v1_05') ? SENSEBANKPAYMENT_SENSEBANK_MEASUREMENT_NAME : SENSEBANKPAYMENT_SENSEBANK_MEASUREMENT_CODE
);
$item['itemAmount'] = round(($value['unit_price_tax_incl']) * 100) * $value['product_quantity'];
$item['itemCode'] = $value['product_id'] . "_" . $itemsCnt;
$item['itemPrice'] = round(($value['unit_price_tax_incl']) * 100);
if ($value['id_tax_rules_group'] > 0) {
if (Validate::isLoadedObject(new TaxRulesGroup($value['id_tax_rules_group']))) {
$address = $this->context->shop->getAddress();
$tax_manager = TaxManagerFactory::getManager($address, $value['id_tax_rules_group']);
$product_tax_calculator = $tax_manager->getTaxCalculator();
$item_rate = $product_tax_calculator->getTotalRate();
}
if ($item_rate == 20) {
$tax_type = 6;
} else if ($item_rate == 18) {
$tax_type = 3;
} else if ($item_rate == 10) {
$tax_type = 2;
} else if ($item_rate == 0) {
$tax_type = 1;
} else {
$tax_type = $this->default_vat;
}
} else {
$tax_type = $this->default_vat;
}
$item['tax'] = array(
'taxType' => $tax_type
);
$amountFix += $item['itemAmount'];

$attributes = array();
$attributes[] = array(
"name" => "paymentMethod",
"value" => $this->ffd_paymentMethodType
);
$attributes[] = array(
"name" => "paymentObject",
"value" => $this->ffd_paymentObjectType
);
$item['itemAttributes']['attributes'] = $attributes;

$items[] = $item;
}
if ($order->total_shipping > 0) {
$itemShipment['positionId'] = $itemsCnt;
$itemShipment['name'] = $this->l('Delivery', 'sensebankpayment');
$itemShipment['quantity'] = array(
'value' => 1,
'measure' => ($this->ffd_version == 'v1_05') ? SENSEBANKPAYMENT_SENSEBANK_MEASUREMENT_NAME : SENSEBANKPAYMENT_SENSEBANK_MEASUREMENT_CODE
);
$itemShipment['itemAmount'] = $itemShipment['itemPrice'] = $order->total_shipping * 100;
$itemShipment['itemCode'] = 'Delivery';

$amountFix += $itemShipment['itemAmount'];

$attributes = array();
$attributes[] = array(
"name" => "paymentMethod",
"value" => $this->ffd_paymentMethodTypeDelivery
);
$attributes[] = array(
"name" => "paymentObject",
"value" => 4
);
$itemShipment['itemAttributes']['attributes'] = $attributes;

$items[] = $itemShipment;
}
$order_bundle = array(
'orderCreationDate' => strtotime($order->date_add),
'customerDetails' => array(
'email' => $customer->email
),
'cartItems' => array('items' => $items)
);

$discountHelper = new DiscountHelper();
$am = $order->total_paid * 100;
$discount = $discountHelper->discoverDiscount($am, $order_bundle['cartItems']['items']);
if ($discount > 0) {
$discountHelper->setOrderDiscount($discount);
$recalculatedPositions = $discountHelper->normalizeItems($order_bundle['cartItems']['items']);
$recalculatedAmount = $discountHelper->getResultAmount();
$order_bundle['cartItems']['items'] = $recalculatedPositions;
}
$curl_data['orderBundle'] = json_encode($order_bundle);
$curl_data['amount'] = $am;
}
if ($this->getStage() == 1) {
$result_gate = $this->gateway('registerPreAuth' . $this->ext, $curl_data, $this->getLink(), $this->write_log);
} else {
$result_gate = $this->gateway('register' . $this->ext, $curl_data, $this->getLink(), $this->write_log);
}
$result = $this->noCamelCase($result_gate);

if (!isset($result['errorCode']) || !$result['errorCode']) {
$sql = 'INSERT INTO `' . _DB_PREFIX_ . 'sensebankpayment` (`id_order`, `order_number`, `gateway_order_id`, `form_url`)
VALUES (\'' . (int)$order->id . '\', \'' . pSQL($order_number) . '\' , \'' . pSQL($result['orderid']) . '\', \'' . pSQL($result['formurl']) . '\')
ON DUPLICATE KEY UPDATE
`order_number` = \'' . pSQL($order_number) . '\',
`gateway_order_id` = \'' . pSQL($result['orderid']) . '\',
`form_url` = \'' . pSQL($result['formurl']) . '\'';
if (Db::getInstance()->execute($sql) == false) {
return array('errorCode' => __LINE__, "errorMessage" => "Database error");
}
}
return $result; //$jj_rest
}
public function getLogin()
{
return $this->login;
}
public function getPassword()
{
return $this->password;
}
public function getStage()
{
return $this->stage;
}
public function callback_addresses_update($action_adr)
{
if ($this->test_mode) {
$gate_url = str_replace("payment/rest", "mportal/mvc/public/merchant/update", $action_adr);
} else {
$gate_url = str_replace("payment/rest", "mportal/mvc/public/merchant/update", $action_adr);
if (defined('SENSEBANKPAYMENT_SENSEBANK_PROD_URL_ALTERNATIVE_DOMAIN')) {
$pattern = '/^https:\/\/[^\/]+/';
$gate_url = preg_replace($pattern, rtrim(SENSEBANKPAYMENT_SENSEBANK_PROD_URL_ALTERNATIVE_DOMAIN, '/'), $gate_url);
}
}
$gate_url .= substr($this->login, 0, -4); // we guess username = login w/o "-api"
$callback_addresses_string = $this->context->link->getModuleLink($this->name, 'paymentcallback', array(), true);
$headers = array(
'Content-Type:application/json',
'Authorization: Basic ' . base64_encode($this->login . ":" . $this->password)
);
$data['callbacks_enabled'] = true;
$data['callback_type'] = "STATIC";
$data['callback_addresses'] = $callback_addresses_string;
$data['callback_http_method'] = "GET";
$data['callback_operations'] = "deposited,approved,declinedByTimeout";
$response = $this->_sendGatewayData(json_encode($data), $gate_url, $headers);
$this->logger("[REQUEST]: " . $gate_url . "\n[callback_addresses_string]: " . $callback_addresses_string . "\n" . print_r($data, true) . "\n[RESPONSE]: " . print_r($response, true));
}
public function gateway($method, $data, $url, $log = true)
{
if ($method == "register.do") {
$url = $this->getLink();
$this->callback_addresses_update($url);
}
$headers = array('CMS: PrestaShop ' . _PS_VERSION_, 'Module-Version: ' . $this->version);
$response = $this->_sendGatewayData(http_build_query($data, '', '&'), $url . $method, $headers);
$response = json_decode($response, true);
if ($log == true)
$this->logger('Request: ' . $url . $method . ': ' . print_r($data, true) . 'Response: ' . print_r($response, true));
return $response;
}
public function _sendGatewayData($data, $action_address, $headers = array())
{
$curl_opt = array(
CURLOPT_HTTPHEADER => $headers,
CURLOPT_VERBOSE => true,
CURLOPT_SSL_VERIFYHOST => false,
CURLOPT_URL => $action_address,
CURLOPT_RETURNTRANSFER => true,
CURLOPT_POST => true,
CURLOPT_POSTFIELDS => $data,
CURLOPT_HEADER => true,
);
$ssl_verify_peer = false;
if ($this->enable_cacert == true && (file_exists(dirname(__FILE__) . "/cacert.cer"))) {
$ssl_verify_peer = true;
$curl_opt[CURLOPT_CAINFO] = realpath(dirname(__FILE__) . "/cacert.cer");
}
$curl_opt[CURLOPT_SSL_VERIFYPEER] = $ssl_verify_peer;
$ch = curl_init();
curl_setopt_array($ch, $curl_opt);
$response = curl_exec($ch);
if ($response === false) {
$error = array('errorCode' => 999, "errorMessage" => curl_error($ch));
return json_encode($error);
}
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);
return substr($response, $header_size);
}
public function logger($message)
{
$logger = new FileLogger();
$dir = version_compare(_PS_VERSION_, '1.7', '<') ? '/log/' : '/app/logs/';
$dir = version_compare(_PS_VERSION_, '8.0', '>') ? '/var/logs/' : $dir;
$logger->setFilename(_PS_ROOT_DIR_ . $dir . date('Y-m') . '_sensebankpayment.log');
$logger->logInfo(date('H:i:s') . "\n" . $message);
}
public function getLink()
{
$test_url = SENSEBANKPAYMENT_SENSEBANK_TEST_URL;
$prod_url = SENSEBANKPAYMENT_SENSEBANK_PROD_URL;
if (defined('SENSEBANKPAYMENT_SENSEBANK_PROD_URL_ALTERNATIVE_DOMAIN') && defined('SENSEBANKPAYMENT_SENSEBANK_PROD_URL_ALT_PREFIX')) {
if (substr($this->login, 0, strlen(SENSEBANKPAYMENT_SENSEBANK_PROD_URL_ALT_PREFIX)) == SENSEBANKPAYMENT_SENSEBANK_PROD_URL_ALT_PREFIX) {
$pattern = '/^https:\/\/[^\/]+/';
$prod_url = preg_replace($pattern, rtrim(SENSEBANKPAYMENT_SENSEBANK_PROD_URL_ALTERNATIVE_DOMAIN, '/'), $prod_url);
} else {
}
}
return ((int)$this->test_mode) ? $test_url : $prod_url;
}
public function noCamelCase($obj)
{
$return = array();
if (is_object($obj) || is_array($obj)) {
foreach ($obj as $key => $value) {
$k = Tools::strtolower($key);
$return[$k] = $value;
}
return $return;
}
return false;
}
public function getAdditionalActions($params)
{
if ($params['id_order_state'] != Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE'))
if ($params['id_order_state'] != Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE_DEFAULT'))
return;
$info = $this->getPaymentGateValues($params['id_order']);
$this->context->smarty->assign(array(
'payment_link' => $this->context->link->getModuleLink($this->name, 'PaymentGate'),
'id_order' => $params['id_order'],
'order_status' => $info['order_status']
));
return $this->display(__FILE__, 'views/templates/front/additional_action.tpl');
}
public function getPaymentGateValues($id_order)
{
$cache_key = 'getPaymentGateValues_' . $id_order;
if (!isset(self::$cache[$cache_key])) {
$result = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'sensebankpayment` WHERE `id_order` = \'' . (int)$id_order . '\'');
if ($result) {
$curl_data = array('userName' => $this->getLogin(), 'password' => $this->getPassword(), 'orderId' => $result['gateway_order_id']);
$return_gate = $this->gateway('getOrderStatus' . $this->ext, $curl_data, $this->getLink(), $this->write_log);
$return = $this->noCamelCase($return_gate);
if (isset($return['orderstatus'])) {
$result['order_status'] = $return['orderstatus'];
self::$cache[$cache_key] = $result;
}
} else
self::$cache[$cache_key] = false;
}
return self::$cache[$cache_key];
}
public function getRedirectLink()
{
return $this->context->link->getModuleLink($this->name, 'PaymentGate', array(
'id_order' => $this->currentOrder
));
}
public function getContent()
{
$this->registerSmartyFunctions();
$this->context->controller->addJS('http://#'
. Context::getContext()->language->iso_code
);
$this->context->smarty->assign(array(
'content_tab' => $this->getContentWrap(),
));
return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/admin/content.tpl');
}
public function registerSmartyFunctions()
{
$smarty = $this->context->smarty;
if (!array_key_exists('get_image_lang', $smarty->registered_plugins['function']))
smartyRegisterFunction($smarty, 'function', 'get_image_lang', array($this, 'getImageLang'));
if (!array_key_exists('no_escape', $smarty->registered_plugins['modifier']))
smartyRegisterFunction($smarty, 'modifier', 'no_escape', array(__CLASS__, 'noEscape'));
if (class_exists('TransModSensebankPayment')) {
if (!array_key_exists('ld', $smarty->registered_plugins['modifier']))
smartyRegisterFunction($smarty, 'modifier', 'ld', array(TransModSensebankPayment::getInstance(), 'ld'));
}
}
/**
* @return string
*/
public function getContentWrap()
{
$this->context->controller->addCSS($this->_path . 'views/css/sensebankpayment.css', 'all');
if (Tools::isSubmit('saveSettings')) {
$this->login = Tools::getValue('api-login');
$this->password = Tools::getValue('password');
$this->stage = Tools::getValue('stage');
$this->enable_cacert = Tools::getValue('enable_cacert');
$this->test_mode = Tools::getValue('test_mode');
$this->send_order = Tools::getValue('send_order');
$this->write_log = Tools::getValue('write_log');
$this->default_vat = Tools::getValue('default_vat');
$this->backToShopUrl = Tools::getValue('backToShopUrl');
$this->backToShopUrlName = Tools::getValue('backToShopUrlName');
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_LOGIN', $this->login);
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_PASSWORD', $this->password);
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_STAGE', $this->stage);
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_SSL_VERIFY', $this->enable_cacert);
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_TEST_MODE', $this->test_mode);
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_STATE', Tools::getValue('authorization_state'));
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_STATE_DEFAULT', Tools::getValue('default_state'));
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_STATE_ERROR', Tools::getValue('error_state'));
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_SEND_ORDER', $this->send_order);
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_TAX_SYSTEM', Tools::getValue('default_taxSystem'));
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_VAT', $this->default_vat);
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_VERSION', Tools::getValue('ffd_version'));
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_PAYMENT_OBJECT_TYPE', Tools::getValue('ffd_paymentObjectType'));
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_PAYMENT_METHOD_TYPE', Tools::getValue('ffd_paymentMethodType'));
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_PAYMENT_METHOD_TYPE_DELIVERY', Tools::getValue('ffd_paymentMethodTypeDelivery'));
Configuration::updateValue('PS_SENSEBANKPAYMENT_SENSEBANK_WRITE_LOG', $this->write_log);
Configuration::updateValue('IDD_BACK_TO_SHOP_URL', $this->backToShopUrl);
Configuration::updateValue('IDD_BACK_TO_SHOP_URL_NAME', $this->backToShopUrlName);
}
$query = 'SELECT `id_order_state`, `name` FROM `' . _DB_PREFIX_ . 'order_state_lang` WHERE `id_lang` = "' . (int)$this->context->language->id . '"';
$order_state = DB::getInstance()->executeS($query);
return $this->adminForm($order_state);
}
public function adminForm($order_state)
{
$fields_form = array(
array(
'label' => $this->l('Login API', 'sensebankpayment'), //'',
'required' => true,
'type' => 'text',
'name' => 'api-login',
'tab' => 'basic',
),
array(
'label' => $this->l('Password', 'sensebankpayment'), //'',
'required' => true,
'type' => 'text',
'name' => 'password',
'tab' => 'basic',
),
array(
'label' => $this->l('Test mode', 'sensebankpayment'),
'type' => 'switch',
'name' => 'test_mode',
'values' => array(
array(
'value' => 1
),
array(
'value' => 0
)
),
'tab' => 'basic',
),
array(
'label' => $this->l('Payments mode', 'sensebankpayment'),
'name' => 'stage',
'type' => 'select',
'class' => "custom-select form-control",
'options' => array(
'id' => 'id',
'name' => 'name',
'query' => array(
array('id' => 1, 'name' => $this->l('Two-phase payments', 'sensebankpayment')),
array('id' => 0, 'name' => $this->l('One-phase payments', 'sensebankpayment')),
),
array(
'value' => 0
)
),
'tab' => 'basic',
),
array(
'label' => $this->l('Default state', 'sensebankpayment'),
'desc' => $this->l('Default order status for this payment method', 'sensebankpayment'),
'type' => 'select',
'class' => "custom-select form-control",
'name' => 'default_state',
'options' => array(
'id' => 'id_order_state',
'name' => 'name',
'query' => $order_state
),
'tab' => 'basic',
),
array(
'label' => $this->l('Success payment state', 'sensebankpayment'),
'type' => 'select',
'class' => "custom-select form-control",
'name' => 'authorization_state',
'options' => array(
'id' => 'id_order_state',
'name' => 'name',
'query' => $order_state
),
'tab' => 'basic',
),
array(
'label' => $this->l('Error payment state', 'sensebankpayment'),
'desc' => $this->l('Order status in case of payment error', 'sensebankpayment'),
'type' => 'select',
'class' => "custom-select form-control",
'name' => 'error_state',
'options' => array(
'id' => 'id_order_state',
'name' => 'name',
'query' => $order_state
),
'tab' => 'basic',
),
array(
'label' => $this->l('Keep a log of requests', 'sensebankpayment'),
'type' => 'switch',
'name' => 'write_log',
'values' => array(
array(
'value' => 1
),
array(
'value' => 0
),
),
'tab' => 'basic',
)
);
if (defined('SENSEBANKPAYMENT_SENSEBANK_ENABLE_BACK_URL_SETTINGS') && SENSEBANKPAYMENT_SENSEBANK_ENABLE_BACK_URL_SETTINGS === true) {
$fields_form_backToShopUrl = array(
array(
'label' => $this->l('Back to shop URL', 'sensebankpayment'),
'desc' => $this->l('Adds URL for checkout page button that will take a cardholder back to the assigned merchant web-site URL', 'sensebankpayment'),
'required' => false,
'type' => 'text',
'name' => 'backToShopUrl',
'tab' => 'basic',
),
);
$fields_form = array_merge($fields_form, $fields_form_backToShopUrl);
}
if (file_exists(dirname(__FILE__) . "/cacert.cer")) {
$fields_form_ssl = array(
array(
'label' => $this->l('Verify SSL certificate', 'sensebankpayment'),
'desc' => "",
'type' => 'switch',
'name' => 'enable_cacert',
'values' => array(
array(
'value' => 1
),
array(
'value' => 0
)
),
'tab' => 'basic',
),
);
$fields_form = array_merge($fields_form, $fields_form_ssl);
}
$fields_form_ext = array(
array(
'label' => $this->l('Send cart data (including customer info)', 'sensebankpayment'),
'desc' => $this->l('If this option is enabled order receipts will be created and sent to your customer and to the revenue service. This is a paid option, contact your bank to enable it. If you use it, configure VAT settings. VAT is calculated according to the Russian legislation. VAT amounts calculated by your store may differ from the actual VAT amounts that can be applied.', 'sensebankpayment'),
'type' => 'switch',
'name' => 'send_order',
'values' => array(
array(
'value' => 1
),
array(
'value' => 0
)
),
'tab' => 'fl54',
),
array(
'label' => $this->l('Tax System', 'sensebankpayment'),
'type' => 'select',
'name' => 'default_taxSystem',
'options' => array(
'id' => 'id',
'name' => 'name',
'query' => array(
array('id' => 0, 'name' => $this->l('General', 'sensebankpayment')),
array('id' => 1, 'name' => $this->l('Simplified, income', 'sensebankpayment')),
array('id' => 2, 'name' => $this->l('Simplified, income minus expences', 'sensebankpayment')),
array('id' => 3, 'name' => $this->l('Unified tax on imputed income', 'sensebankpayment')),
array('id' => 4, 'name' => $this->l('Unified agricultural tax', 'sensebankpayment')),
array('id' => 5, 'name' => $this->l('Patent taxation system', 'sensebankpayment')),
)
),
'tab' => 'fl54',
),
array(
'label' => $this->l('Fiscal document format', 'sensebankpayment'),
'type' => 'select',
'name' => 'ffd_version',
'options' => array(
'id' => 'id',
'name' => 'name',
'query' => array(
array('id' => 'v1_05', 'name' => 'v1.05'),
array('id' => 'v1_2', 'name' => 'v1.2'),
),
),
'tab' => 'fl54',
),
array(
'label' => $this->l('Default Vat', 'sensebankpayment'),
'type' => 'select',
'name' => 'default_vat',
'options' => array(
'id' => 'id',
'name' => 'name',
'query' => array(
array('id' => 0, 'name' => $this->l('No VAT', 'sensebankpayment')),
array('id' => 1, 'name' => $this->l('VAT 0%', 'sensebankpayment')),
array('id' => 2, 'name' => $this->l('VAT 10%', 'sensebankpayment')),
array('id' => 3, 'name' => $this->l('VAT 18%', 'sensebankpayment')),
array('id' => 6, 'name' => $this->l('VAT 20%', 'sensebankpayment')),
array('id' => 4, 'name' => $this->l('VAT applicable rate 10/110', 'sensebankpayment')),
array('id' => 5, 'name' => $this->l('VAT applicable rate 18/118', 'sensebankpayment')),
array('id' => 7, 'name' => $this->l('VAT applicable rate 20/120', 'sensebankpayment'))
),
),
'tab' => 'fl54',
),
array(
'label' => $this->l('Payment type', 'sensebankpayment'),
'type' => 'select',
'name' => 'ffd_paymentMethodType',
'options' => array(
'id' => 'id',
'name' => 'name',
'query' => array(
array('id' => 1, 'name' => $this->l('Full prepayment', 'sensebankpayment')),
array('id' => 2, 'name' => $this->l('Partial prepayment', 'sensebankpayment')),
array('id' => 3, 'name' => $this->l('Advance payment', 'sensebankpayment')),
array('id' => 4, 'name' => $this->l('Full payment', 'sensebankpayment')),
array('id' => 5, 'name' => $this->l('Partial payment with further credit', 'sensebankpayment')),
array('id' => 6, 'name' => $this->l('No payment with further credit', 'sensebankpayment')),
array('id' => 7, 'name' => $this->l('Payment on credit', 'sensebankpayment')),
),
),
'tab' => 'fl54',
),
array(
'label' => $this->l('Payment type Delivery', 'sensebankpayment'),
'type' => 'select',
'name' => 'ffd_paymentMethodTypeDelivery',
'options' => array(
'id' => 'id',
'name' => 'name',
'query' => array(
array('id' => 1, 'name' => $this->l('Full prepayment', 'sensebankpayment')),
array('id' => 2, 'name' => $this->l('Partial prepayment', 'sensebankpayment')),
array('id' => 3, 'name' => $this->l('Advance payment', 'sensebankpayment')),
array('id' => 4, 'name' => $this->l('Full payment', 'sensebankpayment')),
array('id' => 5, 'name' => $this->l('Partial payment with further credit', 'sensebankpayment')),
array('id' => 6, 'name' => $this->l('No payment with further credit', 'sensebankpayment')),
array('id' => 7, 'name' => $this->l('Payment on credit', 'sensebankpayment')),
),
),
'tab' => 'fl54',
),
array(
'label' => $this->l('Type of goods and services', 'sensebankpayment'),
'type' => 'select',
'name' => 'ffd_paymentObjectType',
'options' => array(
'id' => 'id',
'name' => 'name',
'query' => array(
array('id' => 1, 'name' => $this->l('Goods', 'sensebankpayment')),
array('id' => 2, 'name' => $this->l('Excised goods', 'sensebankpayment')),
array('id' => 3, 'name' => $this->l('Job', 'sensebankpayment')),
array('id' => 4, 'name' => $this->l('Service', 'sensebankpayment')),
array('id' => 5, 'name' => $this->l('Stake in gambling', 'sensebankpayment')),
array('id' => 7, 'name' => $this->l('Lottery ticket', 'sensebankpayment')),
array('id' => 9, 'name' => $this->l('Intellectual property provision', 'sensebankpayment')),
array('id' => 10, 'name' => $this->l('Payment', 'sensebankpayment')),
array('id' => 11, 'name' => $this->l("Agent's commission", 'sensebankpayment')),
array('id' => 12, 'name' => $this->l('Combined', 'sensebankpayment')),
array('id' => 13, 'name' => $this->l('Other', 'sensebankpayment')),
),
),
'tab' => 'fl54',
),
);
if (SENSEBANKPAYMENT_SENSEBANK_ENABLE_CART_OPTIONS) {
$fields_form = array_merge($fields_form, $fields_form_ext);
}
$fields_value = array(
'api-login' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_LOGIN') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_LOGIN') : null,
'password' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_PASSWORD') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_PASSWORD') : null,
'test_mode' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_TEST_MODE') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_TEST_MODE') : '0',
'stage' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STAGE') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STAGE') : '0',
'enable_cacert' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_SSL_VERIFY') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_SSL_VERIFY') : '0',
'default_state' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE_DEFAULT') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE_DEFAULT') : null,
'authorization_state' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE') : null,
'error_state' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE_ERROR') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_STATE_ERROR') : null,
'send_order' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_SEND_ORDER') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_SEND_ORDER') : '0',
'default_taxSystem' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_TAX_SYSTEM') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_TAX_SYSTEM') : null,
'default_vat' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_VAT') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_VAT') : null,
'ffd_version' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_VERSION') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_VERSION') : null,
'ffd_paymentObjectType' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_PAYMENT_OBJECT_TYPE') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_PAYMENT_OBJECT_TYPE') : null,
'ffd_paymentMethodType' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_PAYMENT_METHOD_TYPE') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_PAYMENT_METHOD_TYPE') : null,
'ffd_paymentMethodTypeDelivery' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_PAYMENT_METHOD_TYPE_DELIVERY') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_DEFAULT_FFD_PAYMENT_METHOD_TYPE_DELIVERY') : null,
'write_log' => Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_WRITE_LOG') ? Configuration::get('PS_SENSEBANKPAYMENT_SENSEBANK_WRITE_LOG') : '0',
'backToShopUrl' => Configuration::get('IDD_BACK_TO_SHOP_URL') ? Configuration::get('IDD_BACK_TO_SHOP_URL') : NULL,
'backToShopUrlName' => Configuration::get('IDD_BACK_TO_SHOP_URL_NAME') ? Configuration::get('IDD_BACK_TO_SHOP_URL_NAME') : NULL,
);
$fields[0]['form'] = array(
'legend' => array(
'title' => $this->l('Allow customers to conveniently checkout directly with ', 'sensebankpayment') . "SensebankPayment"
),
'tabs' => array(
'basic' => $this->l('General settings ', 'sensebankpayment'),
'fl54' => $this->l('Other settings ', 'sensebankpayment'),
),
'input' => $fields_form,
'submit' => array(
'title' => $this->l('Save'),
'name' => 'saveSettings',
'class' => 'button btn btn-default pull-right',
'desc' => ''
)
);
$helper_form = new HelperForm();
$helper_form->fields_value = $fields_value;
$helper_form->token = Tools::getValue('token');
$helper_form->currentIndex = 'index.php?controller=AdminModules&configure=' . $this->name
. '&tab_module=front_office_features&module_name=' . $this->name;
return $helper_form->generateForm($fields);
}
public function getImageLang($smarty)
{
if (_PS_VERSION_ < 1.5)
$cookie = &$GLOBALS['cookie'];
else {
$cookie = $this->context->cookie;
$cookie->id_lang = $this->context->language->id;
}
$path = $smarty['path'];
$module_path = $this->name . '/views/img/';
$current_language = new Language($cookie->id_lang);
$module_lang_path = $module_path . $current_language->iso_code . '/';
$module_lang_default_path = $module_path . 'en/';
$path_image = false;
if (file_exists(_PS_MODULE_DIR_ . $module_lang_path . $path))
$path_image = _MODULE_DIR_ . $module_lang_path . $path;
elseif (file_exists(_PS_MODULE_DIR_ . $module_lang_default_path . $path))
$path_image = _MODULE_DIR_ . $module_lang_default_path . $path;
if ($path_image)
return '<img class="thumbnail" src="' . $path_image . '">';
else
return '[can not load image "' . $path . '"]';
}
}