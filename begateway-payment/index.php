<?php
/*
Plugin Name: beGateway
Description: Плагин для оплаты через процессинговое решение beGateway
Author: eComCharge
Version: 1.0.0
*/

require_once dirname(__FILE__) . '/lib/BeGateway.php';

new BeGatewayPayment;

class BeGatewayPayment {
  const TEST_SHOP      = 361;
  const TEST_KEY       = 'b8647b68898b084b836474ed8d61ffe117c9a01168d867f24953b776ddcb134d';
  const TEST_DOMAIN    = 'checkout.begateway.com';
  const PLUGIN_HASH    = 'b8d75e37be44b46d8c15f5d0fb6a8826'; // md5('begateway')

  private static $pluginName = 'Оплата банковской картой онлайн'; // название плагина
  private static $path = PLUGIN_DIR . 'begateway-payment'; //путь до файлов плагина.

  public function __construct() {
    mgActivateThisPlugin(__FILE__, array(__CLASS__, 'activate')); //Инициализация  метода выполняющегося при активации
    mgDeactivateThisPlugin(__FILE__, array(__CLASS__, 'deactivate'));
  }

  static function activate(){
    USER::AccessOnly('1,4','exit()');
    self::setDefultPluginOption();
    $cmd = "cd " . dirname(__FILE__) . "/../../ && `which patch` -p1 < ./mg-plugins/begateway-payment/mg-patches/begateway.patch";
    shell_exec($cmd);
  }

  static function deactivate(){
    USER::AccessOnly('1,4','exit()');
    self::removePluginOption();
    $cmd = "cd " . dirname(__FILE__) . "/../../ && `which patch` -p1 -R < ./mg-plugins/begateway-payment/mg-patches/begateway.patch";
    shell_exec($cmd);
  }

  private static function removePluginOption(){
    USER::AccessOnly('1,4','exit()');
    $paymentId = self::getPaymentForPlugin();
    if($paymentId){
      $paymentId = self::removePaymentForPlugin();
    }
  }

  private static function setDefultPluginOption(){
    USER::AccessOnly('1,4','exit()');
    $paymentId = self::getPaymentForPlugin();

    if(empty($paymentId)){
      $paymentId = self::setPaymentForPlugin();
    }
  }

  /**
  * Возвращает идентификатор записи доставки из БД для плагина, по полю 'name'
  */
  static function getPaymentForPlugin(){

    if($result = self::getPluginData()){
      $sql = '
      UPDATE `'.PREFIX.'payment`
      SET `activity` = 1
      WHERE `add_security` = '.DB::quote(self::PLUGIN_HASH);
      DB::query($sql);

      return $result['id'];
    }
  }

  static function getPluginData() {
    $result = array();
    $dbRes = DB::query('
      SELECT *
      FROM `'.PREFIX.'payment`
      WHERE `add_security` = '.DB::quote(self::PLUGIN_HASH)
    );

    return DB::fetchAssoc($dbRes);
  }

  static function getPluginOptions() {
    $result = array();
    $decoded_params = array();

    if ($result = self::getPluginData()) {
      $params = json_decode($result['paramArray'], true);

      if ($params) {
        foreach ($params as $key => $value) {
          $decoded_params []= CRYPT::mgDecrypt($value);
        }
      }
    }

    return $decoded_params;
  }

  static function getPluginId() {
    if ($result = self::getPluginData()) {
      return $result['id'];
    }
  }

  static function getPaymentLastId() {
    $result = array();

    $dbRes = DB::query('
      SELECT MAX(id) AS max_id
      FROM `'.PREFIX.'payment`'
    );

    $result = DB::fetchAssoc($dbRes);
    return $result['max_id'];
  }

  static function setPaymentForPlugin(){
    USER::AccessOnly('1,4','exit()');
    $options = DB::quote(
      '{"ID Магазина":"' . CRYPT::mgCrypt(self::TEST_SHOP) . '",' .
      '"Секретный ключ":"' . CRYPT::mgCrypt(self::TEST_KEY) . '",' .
      '"Домен страницы оплаты":"' . CRYPT::mgCrypt(self::TEST_DOMAIN) . '",' .
      '"Включить оплату банковскими картами":"' . CRYPT::mgCrypt("1") . '",' .
      '"Включить оплату картой Халва":"' . CRYPT::mgCrypt("0") . '",' .
      '"Включить оплату через ЕРИП":"' . CRYPT::mgCrypt("0") . '",' .
      '"Тестовый режим":"' . CRYPT::mgCrypt("true") . '"}'
    );

    $insert_id = self::getPaymentLastId() + 1;

    $data = [
      'id'           => $insert_id,
      'name'         => DB::quote(self::$pluginName),
      'activity'     => 1,
      'paramArray'   => $options,
      'urlArray'     => DB::quote(""),
      'rate'         => 0,
      'sort'         => $insert_id,
      'add_security' => DB::quote(self::PLUGIN_HASH)
    ];

    $sql = "
      INSERT INTO " . PREFIX . "payment(" . implode(',', array_keys($data)) . ") VALUES
      (" . implode(',', array_values($data)) . ")";

    if(DB::query($sql)){
      return $thisId;
    }
  }

  static function removePaymentForPlugin(){
    USER::AccessOnly('1,4','exit()');
    $sql = '
      DELETE FROM `' . PREFIX . 'payment`
      WHERE `add_security` = ' . DB::quote(self::PLUGIN_HASH);

    DB::query($sql);
  }

  static function getPaymentToken($order_id) {
    $model = new Models_Order();
    $order = $model->getOrder(" id = " . DB::quote($order_id, 1));

    if (isset($order[$order_id]['delivery_cost']) && $order[$order_id]['delivery_cost'] > 0){
      $summ = $order[$order_id]['summ'] + $order[$order_id]['delivery_cost'];
    }else{
      $summ = $order[$order_id]['summ'];
    }

    $currency = MG::getSetting('currencyShopIso');
    if ($currency == "RUR") {
      $currency = "RUB";
    }

    $transaction = new \BeGateway\GetPaymentToken;

    $params = self::getPluginOptions();
    \BeGateway\Settings::$shopId = $params[0];
    \BeGateway\Settings::$shopKey = $params[1];
    \BeGateway\Settings::$checkoutBase = 'https://' .$params[2];

    $transaction->setTestMode($params[6] == 'true');

    if (intval($params[3]) == 1) {
      $transaction->addPaymentMethod(new \BeGateway\PaymentMethod\CreditCard);
    }

    if (intval($params[4]) == 1) {
      $transaction->addPaymentMethod(new \BeGateway\PaymentMethod\CreditCardHalva);
    }

    if (intval($params[5]) == 1) {
      $transaction->addPaymentMethod(new \BeGateway\PaymentMethod\Erip(
        array(
          'order_id' => $order_id,
          'account_number' => $order[$order_id]['number']
        )
      ));
    }

    $successUrl      = SITE . "/payment?id=" . self::getPluginId() . "&pay=success&order_id={$order_id}";
    $failUrl         = SITE . "/payment?id=" . self::getPluginId() . "&pay=fail&order_id={$order_id}";
    $notificationUrl = SITE . "/payment?id=" . self::getPluginId() . "&pay=result&order_id={$order_id}";


    $transaction->money->setAmount($summ);
    $transaction->money->setCurrency($currency);
    $transaction->setDescription("Оплата заказа # {$order[$order_id]['number']}");
    $transaction->setTrackingId(implode('|', array($order_id, $order[$order_id]['number'])));
    $transaction->setLanguage('ru');


    $transaction->setNotificationUrl($notificationUrl);
    $transaction->setSuccessUrl($successUrl);
    $transaction->setDeclineUrl($failUrl);
    $transaction->setFailUrl($failUrl);

    try {
      $response = $transaction->submit();

      if ($response->isSuccess() ) {
        return $response->getRedirectUrl();
      } else {
        throw new Exception($response->getMessage());
      }
    } catch (Exception $e) {
      throw $e;
    }
  }

  public static function processWebhook($order_id){
    $model = new Models_Order();
    $order = $model->getOrder(" id = " . DB::quote($order_id, 1));

    if (empty($order)) {
      throw new Exception("НЕКОРРЕКТНЫЕ ДАННЫЕ 1");
    }

    if ($order[$order_id]['id'] != $order_id) {
      throw new Exception("НЕКОРРЕКТНЫЕ ДАННЫЕ 2");
    }

    $webhook = new \BeGateway\Webhook;
    list($webhook_order_id, $webhook_order_number) = explode('|', $webhook->getTrackingId());

    if ($order[$order_id]['id'] != $webhook_order_id || $order[$order_id]['number'] != $webhook_order_number) {
      throw new Exception("НЕКОРРЕКТНЫЕ ДАННЫЕ 3");
    }

    $params = self::getPluginOptions();
    \BeGateway\Settings::$shopId = $params[0];
    \BeGateway\Settings::$shopKey = $params[1];

    if (!$webhook->isAuthorized()) {
      throw new Exception("НЕКОРРЕКТНЫЕ ДАННЫЕ 4");
    }

    $paymentAmount = new \BeGateway\Money;
    $paymentAmount->setCents($webhook->getResponse()->transaction->amount);
    $paymentAmount->setCurrency($webhook->getResponse()->transaction->currency);

    $currency = MG::getSetting('currencyShopIso');
    if ($currency == "RUR") {
      $currency = "RUB";
    }

    $orderAmount = new \BeGateway\Money;
    $orderAmount->setAmount($order[$order_id]['summ'] + $order[$order_id]['delivery_cost']);
    $orderAmount->setCurrency($currency);

    if ($paymentAmount->getCents() != $orderAmount->getCents()) {
      throw new Exception("НЕКОРРЕКТНЫЕ ДАННЫЕ 5");
    }

    return $webhook->isSuccess();
  }
}
