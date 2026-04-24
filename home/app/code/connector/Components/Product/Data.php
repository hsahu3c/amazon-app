<?php

namespace App\Connector\Components\Product;

class Data extends \App\Core\Components\Base
{

    public function isImportingActive($sqsData)
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $queueCollection = $mongo->getCollection('queued_tasks');
        $webhookCollection = $mongo->getCollection("temp_webhook_container");
        $productContainerCol = $mongo->getCollection("product_container");
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
        $filter = [
            'user_id' => $sqsData['user_id'] ?? $this->di->getUser()->id,
            'shop_id' => $sqsData['shop_id'],
            'process_code' => 'product_import'
        ];
        $productImportQueuedTask = $queueCollection->findOne($filter, $options);
        if (empty($productImportQueuedTask)) {
            $filter['process_code'] = 'variants_delete';
            $variantDeleteQueuedTask = $queueCollection->findOne($filter, $options);
        }

        if (!empty($productImportQueuedTask) || !empty($variantDeleteQueuedTask)) {
            $tempWebhookData['user_id'] = $sqsData['user_id'];
            $tempWebhookData['shop_id'] = $sqsData['shop_id'];
            $tempWebhookData['marketplace'] = $sqsData['marketplace'];

            // preparing product data to store in temp_webhook_container START
            $tempWebhookData['source_product_ids'] = [];
            if (!isset($sqsData['data']['id'])) {
                foreach ($sqsData['data'] as $key => $variant) {
                    if (isset($variant['id'])) {
                        $sqsData['data'][$key]['id'] = (string) $sqsData['data'][$key]['id'];
                        $tempWebhookData['source_product_ids'][$sqsData['data'][$key]['id']] = $sqsData['data'][$key]['id'];
                    }

                    if (isset($variant['container_id'])) {
                        $sqsData['data'][$key]['container_id'] = (string) $sqsData['data'][$key]['container_id'];
                    }
                }
            } else {
                $sqsData['data']['id'] = (string) $sqsData['data']['id'];
                $productToBeDeleted = $productContainerCol->findOne([
                    '$or' => [
                        ['source_product_id' => $sqsData['data']['id']],
                        ['container_id' => $sqsData['data']['id']]
                    ]
                ], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
                if (!empty($productToBeDeleted)) {
                    $sqsData['data']['container_id'] = $productToBeDeleted['container_id'];
                } else {
                    return true;
                }
            }

            $tempWebhookData['container_id'] = isset($sqsData['data']['container_id']) ? $sqsData['data']['container_id'] : $sqsData['data'][0]['container_id'];
            // preparing product data to store in temp_webhook_container END

            $tempWebhookData['webhook_data'] = $sqsData;
            $filter = [
                'user_id' => $tempWebhookData['user_id'],
                'shop_id' => $tempWebhookData['shop_id'],
                'container_id' => $tempWebhookData['container_id'],
            ];
            $webhookCollection->updateOne($filter, ['$set' => $tempWebhookData], ['upsert' => true]);

            return true;
        }

        return false;
    }

    public function moveProductsTmpToMain($sqsData)
    {
        $productRequestControl = $this->di->getObjectManager()->get('\App\Connector\Components\Route\ProductRequestcontrol');
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $tempCollection = $mongo->getCollectionForTable($sqsData['marketplace'] . '_product_container');
        $filter = [
            'user_id' => $sqsData['user_id'],
            'shop_id' => $sqsData['shop_id'],
        ];
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $simpleProduct = $tempCollection->find($filter, $options)->toArray();
        if (count($simpleProduct) > 0) {
            $additionalData['app_code'] = $sqsData['app_code'];
            $additionalData['shop_id'] = $sqsData['shop_id'];
            $additionalData['marketplace'] = $sqsData['marketplace'];
            $additionalData['isWebhook'] = true;
            return $productRequestControl->pushToProductContainer($simpleProduct, $additionalData);
        }
    }

}
