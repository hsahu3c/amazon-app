<?php
namespace App\Shopifyhome\Components\Product\SalesChannel\Route;

use App\Shopifyhome\Components\Product\SalesChannel\Import;
use App\Shopifyhome\Components\Shop\Shop;
use App\Core\Components\Base;
use App\Shopifyhome\Components\Product\Quickimport\Helper;
use App\Shopifyhome\Components\Product\SalesChannel\Inventory;
use App\Shopifyhome\Components\Product\SalesChannel\Collection;
class Requestcontrol extends Base
{

    public const SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG = 'SHOPIFY_PRODUCT_IMPORT';

    public const SHOPIFY_QUEUE_INV_IMPORT_MSG = 'SHOPIFY_INVENTORY_SYNC';

    public function fetchShopifySalesChannelProduct($data)
    {
        $userId = $data['user_id'];

        $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0000 | Requestcontrol | Processing Start : '.print_r($data, true));

        if(!isset($data['data']['cursor']))
        {
            $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0001 | Requestcontrol | Fetching product(s) count from Shopify');

            $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
            $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                            ->init($target, 'true')
                            ->call('/product/count',[],['shop_id'=> $data['data']['remote_shop_id']], 'GET');
            if ($remoteResponse['success'] && $remoteResponse['data'] == 0) {
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
                if ($progress && $progress == 100) {
                    $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['data']['user_id'], 'No Product Found on your Shopify Store', 'critical');
                }
                $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0002 | Requestcontrol | Process stopped because no product(s) found on Shopify store : '.print_r($remoteResponse, true));
                return true;
            }

            if ($remoteResponse['success']) {
                $individualWeight = (98 / ceil($remoteResponse['data'] / 250));
                $data['data']['individual_weight'] = round($individualWeight,4,PHP_ROUND_HALF_UP);
                $data['data']['cursor'] = 0;
                $data['data']['total'] = $remoteResponse['data'];
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 0, Requestcontrol::SHOPIFY_QUEUE_PRODUCT_IMPORT_MSG.' : '.$remoteResponse['data'].  ' product(s) found. Please wait while products are being imported.');
                $resetData = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\Helper::class)->resetImportFlag();
                $this->pushToQueue($data);
                $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, "Process 0002 | Requestcontrol | {$remoteResponse['data']} product(s) found on Shopify Store, pushed request on sqs for processing : ".print_r($remoteResponse, true));
                return true;
            }
            else {
                $progress = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
                if ($progress && $progress == 100) {
                    $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->addNotification($data['data']['user_id'], 'Failed to fetch Product(s) from Shopify. Please try again later.', 'critical');
                }

                $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0002 | Requestcontrol | Process stopped due to error in response from Shopify : '.print_r($remoteResponse, true));

                return true;
            }          
        }
        $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0011 | Requestcontrol | Fetching product_ids of SalesChannel listings from Shopify');
        $filter = ['shop_id' => $data['data']['remote_shop_id']];
        if(isset($data['data']['next'])) $filter['next'] = $data['data']['next'];
        $filter['limit'] = 250;
        $target = $this->di->getObjectManager()->get(Shop::class)->getUserMarkeplace();
        $apiClientObj = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")->init($target, 'true');
        $saleschannelProductIds = $apiClientObj->call('/saleschannel/product/product_ids', [], $filter, 'GET');
        if(isset($saleschannelProductIds['success']))
        {
            if($saleschannelProductIds['success']===true) 
            {
                $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0012 | Requestcontrol | Successfully fetched product_ids of SalesChannel listings from Shopify');

                $product_ids = $saleschannelProductIds['data']['product_ids'] ?? [];

                if(!empty($product_ids))
                {
                    $filter = [
                        'shop_id'   => $data['data']['remote_shop_id'],
                        'ids'       => implode(',', $product_ids),
                        'sales_channel'=>0
                    ];

                    $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0013 | Requestcontrol | Fetching products data from Shopify for given product_ids.');

                    $saleschannelProducts = $apiClientObj->call('/product', ['Response-Modifier'=>'0'], $filter, 'GET');

                    $quickImportHelper = $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\SalesChannel\Helper::class);
                    $formattedProductResponse = $quickImportHelper->shapeProductResponse($saleschannelProducts);
                    if ($formattedProductResponse['success']) {
                        $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0014 | Requestcontrol | Fetched products data from Shopify and shaped its response.');
                        $quickImport = $this->di->getObjectManager()->get(Import::class);
                        $quickImportResponse = $quickImport->saveProduct($formattedProductResponse);
                        // $this->di->getLog()->logContent(print_r($quickImportResponse,true), 'info', 'shopify-saleschannel'.DS.'Requestcontrol'.DS.date("Y-m-d").DS.'import_response.log');
                        $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0015 | Requestcontrol | Saving Products.');
                        if(isset($quickImportResponse['success']) && $quickImportResponse['success'])
                        {
                            $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0016 | Requestcontrol | Products Saved Successfully.');

                            if(isset($saleschannelProductIds['cursors']['next'])) {
                                $data['data']['next'] = $saleschannelProductIds['cursors']['next'];
                                
                                $data['data']['cursor'] = $data['data']['cursor']+1;
                                $this->pushToQueue($data);

                                $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0017 | Requestcontrol | Product import partially completed, now pushing data in sqs for reprocessing.');
                            }
                            else {
                                $data['method'] = 'fetchAndSaveInventory';

                                unset($data['data']['next']);
                                unset($data['data']['cursor']);
                                unset($data['data']['total']);

                                $this->pushToQueue($data);

                                // All products imported successfully, Now push inventory fetch method on sqs.
                                $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0017 | Requestcontrol | All products imported successfully, Now pushed inventory fetch method on sqs.');
                            }
                        }
                        else
                        {
                            //ToDo : need to log response and return the same.
                            $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0016 | Requestcontrol | Failed to Save Products : '.print_r($quickImportResponse, true));
                        }
                        return true;
                    }
                    
                    if (isset($response['msg']) || isset($response['message'])) {
                        $message = $response['msg'] ?? $response['message'];
                        $realMsg = '';
                        if(is_string($message)) $realMsg = $message;
                        elseif(is_array($message)) {
                            if(isset($message['errors'])) $realMsg = $message['errors'];
                        }
                        if(empty($realMsg)) $error = true;
                        else {
                            if(strpos((string) $realMsg, "Reduce request rates") || strpos((string) $realMsg, "Try after sometime")) {
                                $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\SalesChannel\Route\Requestcontrol::class)->pushToQueue($this->sqsData, time() + 8);

                                $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\SalesChannel\Helper::class)->createUserWiseLogs($this->userId, 'Process 0014 | Product | Failed due to API throttle, pushing data again in sqs : '.print_r($response, true));

                                return true;
                            }

                            $error = true;
                        }
                    }
                    else {
                        $error = true;
                    }

                    if($error) {
                        $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\SalesChannel\Helper::class)->createUserWiseLogs($this->userId, 'Process 0014 | Product | Stopping due to Failed to fetch Product(s) from Shopify : ' . PHP_EOL . print_r($response, true));

                        $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);

                        return false;
                    }

                    return true;
                }
                // TODO : need to indetify why product_ids are missing
                $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0013 | Requestcontrol | Stopping due to product_ids are empty in reponse from Shopify : '.print_r($saleschannelProductIds,true));
                $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
            }
            else 
            {
                // TODO : save the response and take appropriate action based on the error message.
                $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0012 | Requestcontrol | Stopping due to Failed to fetch product_ids of SalesChannel listings from Shopify : '.print_r($saleschannelProductIds,true));

                $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
            }
        }
        else
        {
            // TODO : save the response and return error.
            $this->di->getObjectManager()->get(Helper::class)->createUserWiseLogs($userId, 'Process 0012 | Requestcontrol | Stopping due to Failed to fetch product_ids of SalesChannel listings from Shopify : '.print_r($saleschannelProductIds,true));

            $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
        }

        return true;
    }

    public function fetchAndSaveInventory($data)
    {
        $userId = $data['user_id'];

        $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\SalesChannel\Helper::class)->createUserWiseLogs($userId, 'Process 0300 | Requestcontrol | Starting fetchAndSaveInventory');

        $invResp = $this->di->getObjectManager()->get(Inventory::class)->importInventory($userId, $data);

        if($invResp['success']===true) {
            if(isset($invResp['completed']) && $invResp['completed']===true) {
                $data['method'] = 'fetchAndSaveCollection';

                unset($data['cursor']);
                unset($data['is_last']);
                $this->pushToQueue($data);

                $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\SalesChannel\Helper::class)->createUserWiseLogs($userId, 'Process 0310 | Requestcontrol | All inventory imported successfully, Now pushed Collection fetch method on sqs.');
            }
            else {
                $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\SalesChannel\Helper::class)->createUserWiseLogs($userId, 'Process 0310 | Requestcontrol | Inventory import partially completed, now pushing data in sqs for reprocessing.');
            }

            return true;
        }
        $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\SalesChannel\Helper::class)->createUserWiseLogs($userId, 'Process 0310 | Requestcontrol | Failed to import inventory due to : '.print_r($invResp, true));
        return false;
    }

    public function fetchAndSaveCollection($data)
    {
        $userId = $data['user_id'];

        $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\SalesChannel\Helper::class)->createUserWiseLogs($userId, 'Process 0400 | Requestcontrol | Starting fetchAndSaveCollection');

        $colResp = $this->di->getObjectManager()->get(Collection::class)->importCollection($userId, $data);

        if($colResp['success']===true) {
            if(isset($colResp['completed']) && $colResp['completed']===true) {
                $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\SalesChannel\Helper::class)->createUserWiseLogs($userId, 'Process 0410 | Requestcontrol | All collections imported successfully');

                $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->updateFeedProgress($data['data']['feed_id'], 100);
            }
            else {
                $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\SalesChannel\Helper::class)->createUserWiseLogs($userId, 'Process 0410 | Requestcontrol | Collection import partially completed, now pushing data in sqs for reprocessing.');
            }

            return true;
        }
        $this->di->getObjectManager()->get(\App\Shopifyhome\Components\Product\SalesChannel\Helper::class)->createUserWiseLogs($userId, 'Process 0410 | Requestcontrol | Failed to import collections due to : '.print_r($colResp, true));
        return false;
    }

    // public function pushToQueue($data, $time = 0, $queueName = 'temp')
    // {
    //     $data['run_after'] = $time;
    //     if($this->di->getConfig()->get('enable_rabbitmq_internal')) {
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