<?php
require_once __DIR__ . '/lib/BeGateway.php';

class Pactioner extends Actioner {
  private static $pluginName = 'begateway-payment';

  /**
  * Сохраняет  опции плагина
  * @return boolean
  */
  public function saveBaseOption(){
    USER::AccessOnly('1,4','exit()');
    $this->messageSucces = $this->lang['SAVE_BASE'];
    $this->messageError = $this->lang['NOT_SAVE_BASE'];
    unset($_SESSION['begateway-paymentAdmin']);
    unset($_SESSION['begateway-payment']);

    if(!empty($_POST['data'])) {
      MG::setOption(array('option' => self::$pluginName.'-option', 'value' => addslashes(serialize($_POST['data']))));
    }

    return true;
  }

  public function test(){
    $this->data["result"] = "ok";
    return true;
  }

  public function notification(){

    $webhook = new \BeGateway\Webhook;

    list($o_id, $p_id) = explode(':', $webhook->getTrackingId());
    $result_payment = array();
    $dbRes = DB::query('
      SELECT *
      FROM `'.PREFIX.'payment`
      WHERE `id` = '.DB::quote(intval($p_id)));

    $result_payment = DB::fetchArray($dbRes);
    $paymentParamDecoded = json_decode($result_payment[3]);
    foreach ($paymentParamDecoded as $key => $value) {
      if ($key == "ID Магазина") {
        $BeGatewaySettings['shop_id'] = CRYPT::mgDecrypt($value);
      } elseif ($key == "Секретный ключ") {
        $BeGatewaySettings['secret_key'] = CRYPT::mgDecrypt($value);
      }
    }
    $this->data["result"] = $o_id;

    # TODO: проверить суммы оплаты
    if ($this->isPaymentValid($BeGatewaySettings, $webhook) == true){
      $result_order = array();
      $dbRes = DB::query('
        SELECT *
        FROM `'.PREFIX.'order`
        WHERE `id`= '.DB::quote(intval($o_id))
      );

      $result_order = DB::fetchAssoc($dbRes);
      if ($result_order["paided"] == 0 && $result_order["status_id"] != 2){
        $sql = '
          UPDATE `'.PREFIX.'order`
          SET `paided` = 1, `status_id` = 2
          WHERE `id` = '.DB::quote(intval($o_id));
        DB::query($sql);

        $this->data["result"] = "ok";
      }
      $this->data["message"] = "ok";

      return true;
    } else {
      return false;
    }
  }

  public function getPayLink(){
    $p_id = $_POST['paymentId'];
    $mgBaseDir = $_POST['mgBaseDir'];

    $result_payment = array();
    $dbRes = DB::query('
      SELECT *
      FROM `'.PREFIX.'payment`
      WHERE `id` = '.DB::quote(intval($p_id)));
    $result_payment = DB::fetchArray($dbRes);

    $result_order = array();
    $dbRes = DB::query('
      SELECT *
      FROM `'.PREFIX.'order`
      WHERE `payment_id`='.DB::quote(intval($p_id)).'
      ORDER BY id DESC LIMIT 1
    ');

    $result_order = DB::fetchAssoc($dbRes);

    $paymentParamDecoded = json_decode($result_payment[3]);
    $o_id = $result_order['id'];
    if (isset($result_order['delivery_cost']) and $result_order['delivery_cost'] > 0){
      $summ = $result_order['summ'] + $result_order['delivery_cost'];
    }else{
      $summ = $result_order['summ'];
    }

    $curr = MG::getSetting('currencyShopIso');
    if ($curr == "RUR") {
      $curr = "RUB";
    }

    foreach ($paymentParamDecoded as $key => $value) {
      if ($key == "ID Магазина") {
        \BeGateway\Settings::$shopId = CRYPT::mgDecrypt($value);
      } elseif ($key == "Секретный ключ") {
        \BeGateway\Settings::$shopKey = CRYPT::mgDecrypt($value);
      } elseif ($key == "Домен страницы оплаты") {
        \BeGateway\Settings::$checkoutBase = 'https://' . CRYPT::mgDecrypt($value);
      } elseif ($key == "Тестовый режим") {
        $test_mode = CRYPT::mgDecrypt($value);
      }
    }

    $successURL = "/payment?id={$p_id}.'&pay=success";
    $failURL = "/payment?id={$p_id}&pay=fail";

    $notificationURL = "/ajaxrequest?mguniqueurl=action/notification&pluginHandler=begateway-payment&orderID=".$o_id."&payment=".$p_id;

    $transaction = new \BeGateway\GetPaymentToken;

    $transaction->money->setAmount($summ);
    $transaction->money->setCurrency($curr);
    $transaction->setDescription("Оплата заказа: #{$o_id}");
    $transaction->setTrackingId($o_id . ':' . $p_id);
    $transaction->setLanguage('ru');
    $transaction->setTestMode($test_mode == 'true');

    $transaction->setNotificationUrl($mgBaseDir.$notificationURL);
    $transaction->setSuccessUrl($mgBaseDir.$successURL);
    $transaction->setDeclineUrl($mgBaseDir.$failURL);
    $transaction->setFailUrl($mgBaseDir.$failURL);

    try {
      $response = $transaction->submit();
    } catch (Exception $e) {
      $this->data["status"] = 'error';
      $this->data["message"] = $e->getMessage();
    }

    if ($response->isSuccess() ) {
      $this->data["status"] = 'ok';
      $this->data["result"] = $response->getRedirectUrl();
    } else {
      $this->data["status"] = 'error';
      $this->data["message"] = $response->getMessage();
    }
    return true;
  }

  public function isPaymentValid($settings, $webhook){
    \BeGateway\Settings::$shopId = $settings['shop_id'];
    \BeGateway\Settings::$shopKey = $settings['shop_key'];

    if (!$webhook->isAuthorized()) {
      return false;
    }

    if (!$webhook->isSuccess()) {
      return false;
    }

    return true;
  }
}
