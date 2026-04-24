<?php
namespace App\Shopifyhome\Components\Product\Quickimport\Route;

use App\Core\Components\Base;
use App\Shopifyhome\Components\Product\Quickimport\Product;
use App\Shopifyhome\Components\Product\Quickimport\Collection;
use App\Shopifyhome\Components\Product\Quickimport\Inventory;
use App\Shopifyhome\Components\Product\Quickimport\Helper;

class Requestcontrol extends Base
{
    /*public function handleImport($sqsData)
    {
        @todo: use handleImport function of connector -> components/route/productrequestcontrol from this shopify -> components/product/vistart/route/requestcontrol handle import is called
        $userId = $userId ?? $sqsData['user_id'];

        if($userId) 
        {
            $quickImportHelper = $this->di->getObjectManager()->get('\App\Shopifyhome\Components\Product\Quickimport\Helper')->createUserWiseLogs($userId, 'Process 0001 | Requestcontrol | Processing Start : '.print_r($sqsData, true));
            
            if(isset($sqsData['data']['operation']))
            {
                $operation = $sqsData['data']['operation'];

                switch($operation)
                {
                    case 'product_import' :
                        $response = $this->fetchAndSaveProducts($userId, $sqsData);
                        break;

                    case 'collection_import' :
                        $response = $this->fetchAndSaveCollection($userId, $sqsData);
                        break;

                    case 'inventory_import' :
                        $response = $this->fetchAndSaveInventory($userId, $sqsData);
                        break;

                    default :
                        $errorMessage = 'App\Shopifyhome\Components\Product\Quickimport\Route\Requestcontrol::handleImport() : Unknown operation is passed.' . PHP_EOL . print_r($sqsData, true);
                        $this->di->getObjectManager()->get("\App\Shopifyhome\Components\Product\Quickimport\Helper")->createQuickImportLog($errorMessage, 'error');
                        
                        $response = ['success'=>false, 'message'=>"Unknown operation '{$operation}' is passed"];
                        break;
                }

                return $response;
            }
            else {
                $errorMessage = 'App\Shopifyhome\Components\Product\Quickimport\Route\Requestcontrol::handleImport() : operation is missing.' . PHP_EOL . print_r($sqsData, true);
                $this->di->getObjectManager()->get("\App\Shopifyhome\Components\Product\Quickimport\Helper")->createQuickImportLog($errorMessage, 'error');
            
                return ['success' => false, 'message' => 'operation is missing'];
            }
        }
        else {
            $errorMessage = 'App\Shopifyhome\Components\Product\Quickimport\Route\Requestcontrol::fetchAndSaveInventory() : Invalid User. No userId given.' . PHP_EOL . print_r($sqsData, true);
            $this->di->getObjectManager()->get("\App\Shopifyhome\Components\Product\Quickimport\Helper")->createQuickImportLog($errorMessage, 'error');

            return ['success' => false, 'message' => 'Invalid User. No userId given.'];
        }
    }*/

    public function fetchAndSaveProducts($userId, $data)
    {
        $quickImportHelper = $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0100 | Requestcontrol | Starting fetchAndSaveProducts');

        return $this->di->getObjectManager()->get(Product::class)->importProduct($userId, $data);
    }

    public function fetchAndSaveCollection($userId, $data)
    {
        $quickImportHelper = $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0200 | Requestcontrol | Starting fetchAndSaveCollection');

        return $this->di->getObjectManager()->get(Collection::class)->importCollection($userId, $data);
    }

    public function fetchAndSaveInventory($userId, $data)
    {
        $quickImportHelper = $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0300 | Requestcontrol | Starting fetchAndSaveInventory');

        return $this->di->getObjectManager()->get(Inventory::class)->importInventory($userId, $data);
    }

    // public function pushToQueue($data, $time = 0, $queueName = 'temp')
    // {
    //     $data['run_after'] = $time;
    //     if($this->di->getConfig()->get('enable_rabbitmq_internal')){
    //         $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
    //         return $helper->createQueue($data['queue_name'],$data);
    //     }

    //     return true;
    // }
    public function pushToQueue($data, $time = 0, $queueName = 'temp')
    {
        $data['run_after'] = $time;
        $push = $this->di->getMessageManager()->pushMessage($data);
        return true;
    }
}