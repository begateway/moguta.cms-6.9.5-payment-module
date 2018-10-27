<?php
/*
Plugin Name: beGateway
Description: Плагин для оплаты через процессинговое решение beGateway
Author: eComCharge
Version: 1.0.0
*/

new BeGatewayPayment;

class BeGatewayPayment {
  private static $pluginName = ''; // название плагина (соответствует названию папки)
  private static $path = '';
  private static $options = '';

  public function __construct() {
    mgActivateThisPlugin(__FILE__, array(__CLASS__, 'activate')); //Инициализация  метода выполняющегося при активации
    mgAddShortcode('begateway-payment', array(__CLASS__, 'addPaymentForm'));

    self::$pluginName = PM::getFolderPlugin(__FILE__);
    self::$path = PLUGIN_DIR.self::$pluginName;

    if(URL::isSection('order')){
      if ( $_POST['payment'] ){
        mgAddMeta('
        <p style="display:none">
        <input type="submit" id="begateway-submit" value="Оплатить" />
        </p>
        <script type="text/javascript">
        $(document).ready( function(){
          if($("input[name=phone]").val() == "" || $("input[name=email]").val() == ""){
            return false;
          }
          $("#begateway-submit").click( function(){
            $.ajax({
              type: "POST",
              async: false,
              url: mgBaseDir+"/ajaxrequest",
              dataType: \'json\',
              data:{
                mguniqueurl: "action/getPayLink",
                pluginHandler: "begateway-payment",
                paymentId: '.$_POST['payment'].',
                mgBaseDir: mgBaseDir,
              },
              cache: false,
              success: function(response){
                if(response.data.status ==\'ok\'){
                  window.location.href = response.data.result;
                } else {
                  errors.showErrorBlock(\'Oшибка получения токена платежа. Причина: \' + response.data.message);
                }
              }
            });
          })
          setTimeout(function() {$( "#begateway-submit" ).trigger( "click" )}, 100);
        })
        </script>');
      }
    }
  }

  static function activate(){
    USER::AccessOnly('1,4','exit()');
    self::setDefultPluginOption();
  }

  static function addPaymentForm() {
    return "<div id='begateway-payment-container'></div>";
  }

  private static function setDefultPluginOption(){
    USER::AccessOnly('1,4','exit()');
    $paymentId = self::getPaymentForPlugin();

    if(MG::getSetting(self::$pluginName.'-option') == null || empty($paymentId)){
      if(empty($paymentId)){
        $paymentId = self::setPaymentForPlugin();
      }
    }
  }

  /**
  * Возвращает идентификатор записи доставки из БД для плагина, по полю 'name'
  */
  static function getPaymentForPlugin(){
    $result = array();
    $dbRes = DB::query('
    SELECT id
    FROM `'.PREFIX.'payment`
    WHERE `name` = '.DB::quote('BeGateway'));

    if($result = DB::fetchAssoc($dbRes)){
      $sql = '
      UPDATE `'.PREFIX.'payment`
      SET `activity` = 1
      WHERE `name` = '.DB::quote('BeGateway');
      DB::query($sql);

      return $result['id'];
    }
  }

  static function setPaymentForPlugin(){
    USER::AccessOnly('1,4','exit()');
    $options = DB::quote(
      '{"ID Магазина":"",' .
      '"Секретный ключ":"",' .
      '"Домен страницы оплаты":"",' .
      '"Тестовый режим":""}'
    );
    $sql = "INSERT INTO ".PREFIX."payment(name, activity, paramArray) VALUES
    ('BeGateway', 1, {$options});";

    if(DB::query($sql)){
      return $thisId;
    }
  }
}
