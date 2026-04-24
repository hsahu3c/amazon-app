<?php

namespace App\Connector\Components;

use Phalcon\Events\Event;

class Testprofile extends \App\Core\Components\Base
{

    /**
     * @param $myComponent
     */
    public function beforeSave(Event $event,$myComponent , $data): void
    {

        $event->getData();
        try {

        } catch (\Exception $e) {
            $this->di->getLog()->logContent('Errors : ' . $e->getMessage(),\Phalcon\Logger\Logger::CRITICAL,'product_after_save_exception.log');
        }
    }


    public function deleteOrder(): void
    {
        $orderJson = '/var/www/invalidOrderWithAmazonOrderId.json';
      $orderDatas = json_decode(file_get_contents($orderJson),true);
      $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
      $mongo->getCollectionForTable('user_details');

      $orderFile = '/var/www/order.json';

      $satyaSirdatas = json_decode(file_get_contents($orderFile),true);

      $needToWork = [];
      foreach ($satyaSirdatas as $satyaSirdata) {
        if(isset($orderDatas[$satyaSirdata['order_id']]))
        {
          $customers = $satyaSirdata['customers'];
          $userId = $orderDatas[$satyaSirdata['order_id']]['user_id'];
          unset($customers[$userId]);

          $needToWork[$orderDatas[$satyaSirdata['order_id']]['shopifyId']] = $customers;

        }
      }

      $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::CONFIGURATION);
      $_Orders = [];
      foreach ($needToWork as $orderId => $customers) 
      {
        echo $orderId." customer_id:";

        foreach ($customers as $customer => $attempt) {
            echo $customer;
            echo PHP_EOL;
          $remote_shop_id = 0;
          $_Orders[$orderId] = $orderId;
        }

         echo PHP_EOL;
      }

      var_dump(json_encode($_Orders));die;
    }
}