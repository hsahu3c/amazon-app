<?php

namespace App\Amazon\Components\Product;

use Exception;
use App\Core\Components\Base as Base;
use App\Amazon\Components\Common\Helper;

class Hook extends Base
{
    public function updateProductWebhook($webhook)
    {
        $savedData = [];
        $source_product_ids = [];
        foreach ($webhook['saved_data'] as $key => $value) {
            if (isset($value['source_product_id']))
                $savedData[$value['source_product_id']] = $value;
        }

        foreach ($webhook['data']['product_listing']['variants'] as $value) {
            $source_variant_id = (string)$value['id'];

            if (isset($savedData[$source_variant_id])) {
                if (($savedData[$source_variant_id]['inventory_management'] ?? '') != ($value['inventory_management'] ?? '')) {
                    array_push($source_product_ids, $source_variant_id);
                } elseif (strtolower((string) $savedData[$source_variant_id]['inventory_policy']) != strtolower((string) $value['inventory_policy'])) {
                    array_push($source_product_ids, $source_variant_id);
                }
            }
        }

        $inventoryComponent = $this->di->getObjectManager()->get(InventoryWebhook::class);
        if ($source_product_ids !== []) {
            foreach (array_chunk($source_product_ids, 20) as $chunk) {
                if (isset($webhook['user_id']) && !empty($webhook['user_id'])) {
                    $return = $inventoryComponent->init(['user_id' => $webhook['user_id']])->updateNew(['source_product_ids' => $chunk]);
                }
            }
        }

        return true;
    }

    /*public function updatedDataOfWebhook($webhook_data, $channel_data)
    {
        if(isset($webhook_data['updated_data']) && !empty($webhook_data['updated_data']))
        {
            if(isset($webhook_data['updated_data']['update_child']))
            {
                $source_product_ids = [];
                //check the source product ids in which price is updated
                $updated_childs = $webhook_data['updated_data']['update_child'];
                $source_product_ids = [];
                foreach ($updated_childs as $key => $c_fields)
                {
                    $source_product_id = $c_fields['source_product_id'];
                    $fields = $c_fields['fields'];
                    $fields[] = 'price';
                    if(in_array('price',$fields))
                    {
                        $source_product_ids[$source_product_id] = $source_product_id;
                    }
                }

                if(!empty($source_product_ids))
                {
                    if(isset($webhook_data['db_data']) && !empty($webhook_data['db_data']))
                    {
                        $product = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');
                        //look for db data
                        $data_already_in_db = $webhook_data['db_data'];
                        foreach ($data_already_in_db as $d_k => $d_v)
                        {
                            $d_spid = $d_v['source_product_id'];
                            //if source_product_id is update with price in webhook then find the marketplace data for that particular source_product_id
                            if(isset($source_product_ids[$d_spid]))
                            {
                                $marketplace_key_data_in_db = $_v['marketplace'] ?? [];
                                if(!empty($marketplace_key_data_in_db))
                                {
                                    foreach ($marketplace_key_data_in_db as $k => $marketplace_data)
                                    {
                                        //check that if sku is listed on amazon or not from the data in key "marketplace" and its data is active
                                        if(isset($marketplace_data['target_marketplace'], $marketplace_data['status']) && ($marketplace_data['target_marketplace'] == 'amazon') && ($marketplace_data['status'] == 'Active'))
                                        {
                                            $rawBody = [
                                                "source" => [
                                                    "marketplace" => $webhook_data['sqs_data']['marketplace'] ?? false,
                                                    "shopId" => $webhook_data['sqs_data']['shop_id'] ?? false,
                                                ],
                                                "target" => [
                                                    "marketplace" => $marketplace_data['target_marketplace'] ?? false,
                                                    "shopId" => $marketplace_data['shop_id'] ?? false,
                                                ],
                                            ];

                                            $rawBody['operationType'] = 'price_sync';
                                            $rawBody['source_product_ids'] = $source_product_ids;
                                            $update_request = $product->startSync($rawBody);
                                            $this->di->getLog()->logContent(print_r($rawBody, true) , 'info',
                                            'amazon'.DS.'webhook'.DS.$this->di->getUser()->id.DS.date('d-m-Y').
                                            DS.'price_update_via_webhooks.log');


                                        }

                                    }
                                }

                            }
                        }


                    }

                }
            }
        }

        return true;
    }*/

    public function removeProductWebhook($data)
    {
        try {
            $userId = $data['user_id'];
            $logFile = "amazon/removeProductWebhook/$userId/" . date('Y-m-d') . '.log';
            $this->di->getLog()->logContent('removeProductWebhook data: ' . json_encode($data), 'info',$logFile);
            $sourceProductId = (string)$data['data']['product_listing']['product_id'];
            $sourceShopId = $data['shop_id'];
            $sourceMarketplace =  $data['marketplace'];
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $amazonCollection = $mongo->getCollection(Helper::AMAZON_LISTING);
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
            $query = ['user_id' => $userId, 'source_container_id' => $sourceProductId];
            $amazonListing = $amazonCollection->find($query, $options)->toArray();
            if (!empty($amazonListing)) {
                $objectIds = array_column($amazonListing, '_id');
                $configData =  $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
                $appTag = 'amazon_sales_channel';
                $configData->setGroupCode('inventory');
                $configData->setUserId($userId);
                $configData->sourceSet($sourceMarketplace);
                $configData->setTarget('amazon');
                $configData->setAppTag($appTag);
                $configData->setSourceShopId($sourceShopId);
                $configData->setTargetShopId(null);
                $config = $configData->getConfig('inactivate_product');
                $decodedConfig = json_decode(json_encode($config), true);
                if (!empty($decodedConfig)) {
                    foreach ($decodedConfig as $data) {
                        if (!empty($data['target_shop_id']) && $data['value']) {
                            $this->initatetoInactive($amazonListing, $data['target_shop_id']);
                        }
                    }
                }

                $record = [
                    'unmap_record' => [
                        'source' => [
                            'removeProductWebhook' => true
                        ],
                        'unmap_time' => date('c'),
                    ]
                ];
                $amazonCollection->updateMany(['_id' => ['$in' => $objectIds]], ['$set' => $record, '$unset' => [
                    'source_product_id' => 1,
                    'source_variant_id' => 1,
                    'source_container_id' => 1,
                    'matched' => 1,
                    'manual_mapped' => 1,
                    'matchedProduct' => 1,
                    'matchedwith' => 1,
                    'closeMatchedProduct' => 1
                ]]);
            }

            /** delete edited doc if exists (edge case) */
            $productCollection = $mongo->getCollection(Helper::PRODUCT_CONTAINER);
            $productCollection->deleteMany([
                'user_id' => $userId,
                'source_shop_id' => $sourceShopId,
                'container_id' => $sourceProductId
            ]);
            return ['success' => true, 'message' => 'Product unlinked successfully'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception from removeProductWebhook: ' . json_encode($e->getMessage()),'info',$logFile);
            throw $e;
        }
    }

    public function initatetoInactive($listings, $shopId = null)
    {
        try {
            $userId = $listing['user_id'] ?? $this->di->getUser()->id;
            $logfile="amazon/initatetoInactive/$userId/" . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('initatetoInactive ' . json_encode($listings),'info',$logfile);
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $amazonShop = $userDetails->getShop($shopId, $userId);
            if (!empty($listings) && is_array($listings)) {
                foreach ($listings as $listing) {
                    $result = [];
                    $sucess = 0;
                    $failed = 0;
                    $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(Helper::AMAZON_LISTING);
                    if (isset($listing['seller-sku']) && isset($listing['shop_id'])
                        && isset($listing['matchedProduct']['shop_id'])) {
                        if($listing['shop_id'] == $shopId) {
                            $targetShopId = $listing['shop_id'];
                            $sku = $listing['seller-sku'];
                            $remoteShopId = $amazonShop['remote_shop_id'];
                            if (!empty($sku)) {
                                $rawBody['sku'] = rawurlencode($sku);
                                $rawBody['shop_id'] = $remoteShopId;
                                $rawBody['includedData'] = "issues,attributes,summaries,fulfillmentAvailability";
                                $commonHelper = $this->di->getObjectManager()->get(Helper::class);
                                $response = $commonHelper->sendRequestToAmazon('listing', $rawBody, 'GET');
                                $this->di->getLog()->logContent('Response of getListing ' . json_encode($response),'info',$logfile);
                                if (!empty($response['success']) && $response['success']) {
                                    $attributes = $response['response']['attributes'] ?? "";
                                    $purchasableOffer = $attributes['purchasable_offer'] ?? "";
                                    if (!empty($purchasableOffer)) {

                                        foreach ($purchasableOffer as $key => $audience) {
                                            if ($audience['audience']  == "ALL") {
                                                // $purchasableOffer[$key]['start_at']['value'] = date('c');
                                                $toUpdatePrice[$key]['audience'] = $audience['audience'];
                                                $toUpdatePrice[$key]['currency'] = $audience['currency'];
                                                $toUpdatePrice[$key]['end_at']['value'] = date('c');
                                                $toUpdatePrice[$key]['marketplace_id'] = $audience['marketplace_id'];
                                                $toUpdatePrice[$key]['our_price'] = $audience['our_price'];

                                                // $purchasableOffer[$key]['end_at']['value'] = date('c');
                                            }
                                        }
                                        $payload['shop_id'] = $remoteShopId;
                                        $payload['product_type'] = 'PRODUCT';
                                        $payload['patches'] = [
                                            [
                                                "op" => "replace",
                                                "operation_type" => "PARTIAL_UPDATE",
                                                "path" => "/attributes/purchasable_offer",
                                                "value" => $toUpdatePrice
                                            ]
                                        ];
                                        $payload['sku'] = rawurlencode($sku);
    
                                        $this->di->getLog()->logContent(
                                            'Payload of patchListing ' . json_encode($payload, true),
                                            'info',
                                            $logfile
                                        );
                                        $res = $commonHelper->sendRequestToAmazon('listing-update', $payload, 'POST');

                                        $this->di->getLog()->logContent(
                                            'Response of patchListing ' . json_encode($res, true),
                                            'info',
                                            $logfile
                                        );

                                        if (isset($res['success']) && $res['success'] && isset($res['response']['status']) && $res['response']['status'] == "ACCEPTED") {
                                            $result[$sku] = $res['response'];
                                            $sucess++;
                                            $bulkOpArray[] = [
                                                'updateOne' => [
                                                    [
                                                        'user_id' => $userId,
                                                        'shop_id' => $targetShopId,
                                                        'seller-sku' => $sku
                                                    ],
                                                    [
                                                        '$unset' => ['error' => true],
                                                    ],
                                                    ['upsert' => true]
                                                ]
                                            ];
                                            // return ['success' => true , 'res' => $res['response']];
                                        } elseif (isset($res['success']) && $res['success'] && isset($res['response']['issues'])) {
                                            $result[$sku] = $res['response']['issues'];
                                            $error['close'] = $res['response']['issues'];
                                            $bulkOpArray[] = [
                                                'updateOne' => [
                                                    [
                                                        'user_id' => $userId,
                                                        'shop_id' => $targetShopId,
                                                        'seller-sku' => $sku
                                                    ],
                                                    [
                                                        '$set' => ['error' => $error],
                                                    ],
                                                    ['upsert' => true]
                                                ]
                                            ];
                                            $failed++;
                                            // return ['success' => false , 'res' => $res['response']['issues']];
                                        } else {
                                            $result[$sku] = 'Listing cannot be closed,Kindly contact support some error occured';
                                            $failed;

                                            // return ['success'=>false, 'res'=> 'Listing cannot be closed,Kindly contact support some error occured'];
                                        }
                                    }
                                }
                            } else {
                                return ['success' => false,  'response' => 'seller-sku not found'];
                            }
                        }
                    }
                }
                if (!empty($bulkOpArray)) {
                    $response = $collection->BulkWrite($bulkOpArray, ['w' => 1]);
                }
            }
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
            //throw $th;
        }
    }
}
