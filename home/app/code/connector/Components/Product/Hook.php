<?php

namespace App\Connector\Components\Product;

use App\Core\Models\BaseMongo;
use App\Core\Components\Message\Handler\Sqs;
use App\Connector\Models\ProductContainer;
use App\Connector\Models\Product;

class Hook extends \App\Core\Components\Base
{
    private $ignoreKeys = ['_id', "updated_at"];
    const PRODUCT_UPDATE_ACTION = 'product_update';
    const PRODUCT_CREATE_ACTION = 'product_create';

    public function productCreateOrUpdate($sqsData)
    {
        if (isset ($sqsData['app_codes'])) {
            $appCodes = $sqsData['app_codes'];
        } elseif (isset ($sqsData['app_code'])) {
            $appCodes[] = $sqsData['app_code'];
        }

        $data = $this->prepareDbData($sqsData);
        $productData = $this->di->getObjectManager()->get('\App\Connector\Components\Product\Data');
        $productRequestControl = $this->di->getObjectManager()->get('\App\Connector\Components\Route\ProductRequestcontrol');

        if ($sqsData['action'] == "product_update" && !isset ($sqsData['forceUpdateCreate'])) {
            $productImportActive = $productData->isImportingActive($sqsData);
            if ($productImportActive)
                return true;
        }

        $originalSqsData = $sqsData;

        if (isset ($sqsData['data']) && !empty ($sqsData['data'])) {
            $marketplace = $sqsData['marketplace'];

            $moduleHook = $this->getModuleHook($marketplace);

            foreach ($appCodes as $appCode) {
                $sqsData['app_code'] = $appCode;
                $isSaleChannel = $this->di->getConfig()->apiconnector->$marketplace->$appCode->get('is_saleschannel') ? true : false;
                $sqsData['is_saleschannel'] = $isSaleChannel;
                $sqsData['product_db_data'] = $data;
                $formattedProduct = $moduleHook->formatProductInMarketplace($sqsData);
                //Check product exists in db before update it
                $foundProduct = $this->findProductInContainer($formattedProduct, $sqsData);
                if ($sqsData['action'] === Hook::PRODUCT_UPDATE_ACTION && !$foundProduct) {
                    $this->addProductToTempWebhookContainer($sqsData, $originalSqsData);
                    return true;
                }

                $updatedAt = $sqsData['data'][0]['source_updated_at']
                ?? $sqsData['data'][0]['updated_at'] ??(new \DateTime())->format(\DateTime::ISO8601);

                $lastUpdatedAt = isset ($foundProduct->source_updated_at) ? $foundProduct->source_updated_at : null;

                if ($this->shouldAvoidUpdate($lastUpdatedAt, $updatedAt)) {
                    return true;
                }

                if (!empty ($formattedProduct['pushToMaindb'])) {
                    $sqsData['data'] = $formattedProduct['pushToMaindb'];
                    if (!isset ($data['db_data']) || empty ($data['db_data'])) {
                        $data = $this->prepareDbData($sqsData);
                    }
                    $additionalData['shop_id'] = $sqsData['shop_id'];
                    $additionalData['marketplace'] = $sqsData['marketplace'];
                    $additionalData['isWebhook'] = true;
                    $additionalData['app_code'] = $appCode;
                    $updateOnly = $this->di->getConfig()->get('apiconnector')
                        ->get($marketplace)
                        ->get($appCode)->get('update_only') ?? false;
                    $additionalData['update_only'] = $updateOnly;
                    $productRequestControl->pushToProductContainer($formattedProduct['pushToMaindb'], $additionalData);
                    $productRequestControl->deleteIsExistsKeyAndVariants($sqsData); //vikas
                }
                if (!empty ($formattedProduct['pushToTempdb'])) {
                    $sqsData['data'] = $formattedProduct['pushToTempdb'];
                    if (!isset ($data['db_data']) || empty ($data['db_data'])) {
                        $data = $this->prepareDbData($sqsData);
                    }
                    $productRequestControl->pushInTempContainer($formattedProduct['pushToTempdb'], $marketplace);
                }
                $productData->moveProductsTmpToMain($sqsData);
            }
        }

        // Process product update messages
        if ($sqsData['action'] === Hook::PRODUCT_CREATE_ACTION) {
            $userId = $sqsData['user_id'];
            $shopId = (string) $sqsData['shop_id'];
            $containerId = (string) $sqsData['data'][0]['container_id'];
            $tempWebhook = $this->getProductDataFromWebhookContainer(
                $userId,
                $shopId,
                $containerId
            );
            if ($tempWebhook) {
                $this->processLastProductUpdateWebhook($tempWebhook['webhook_data']);
                $this->deleteTempWebhook($userId, $shopId, $containerId);
            }
        }
        $data = $this->prepareUpdatedData($sqsData, $data);
        $data['sqs_data'] = $sqsData;
        return $data;
    }

    private function shouldAvoidUpdate($lastUpdatedAt, $currentUpdatedAt)
    {
        return !empty ($lastUpdatedAt)
            && (strtotime($lastUpdatedAt) > strtotime($currentUpdatedAt));
    }

    public function prepareDbData($sqsData)
    {
        $data = [];
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongoCollection = $mongo->getCollectionForTable('product_container');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $containerId = "";

        if (isset ($sqsData['data'][0]['container_id']))
            $containerId = $sqsData['data'][0]['container_id'];
        elseif (isset ($sqsData['data']['id']))
            $containerId = $sqsData['data']['id'];
        elseif (isset ($sqsData['data']['product_listing']['product_id']))
            $containerId = $sqsData['data']['product_listing']['product_id'];
        $user_id = $sqsData['user_id'];
        $shopId = $sqsData['shop_id'];
        if ($containerId != "") {
            $filter = [
                'user_id' => $user_id,
                'shop_id' => $shopId,
                'container_id' => (string) $containerId,
                "visibility" => ['$exists' => true]
            ];
            $result = $mongoCollection->find($filter, $options)->toArray();
            $data['db_data'] = $result;
        }

        return $data;
    }

    public function getProductDataById($sqsData)
    {
        $data = [];
        if (isset ($sqsData['data'][0]['container_id']))
            $productId = $sqsData['data'][0]['container_id'];
        elseif (isset ($sqsData['data']['id']))
            $productId = $sqsData['data']['id'];
        elseif (isset ($sqsData['data']['product_listing']['product_id']))
            $productId = $sqsData['data']['product_listing']['product_id'];
        else {
            throw new \Exception('Product Id not found');
        }
        $user_id = $sqsData['user_id'];
        $shopId = $sqsData['shop_id'];
        $filter = [
            'user_id' => $user_id,
            'shop_id' => $shopId,
            '$or' => [
                ['container_id' => (string) $productId],
                ['source_product_id' => (string) $productId]
            ],
            "visibility" => ['$exists' => true]
        ];
        $result = ProductContainer::find([
            $filter,
            "typeMap" => [
                'root' => 'array',
                'document' => 'array'
            ]
        ])->toArray();
        $data['db_data'] = $result;
        return $data;
    }

    public function prepareUpdatedData($sqsData, $data)
    {
        if (isset ($sqsData['data'][0]['container_id']))
            $containerId = $sqsData['data'][0]['container_id'];
        elseif (isset ($sqsData['data']['id']))
            $containerId = $sqsData['data']['id'];
        elseif (isset ($sqsData['data']['product_listing']['product_id']))
            $containerId = $sqsData['data']['product_listing']['product_id'];

        $user_id = $sqsData['user_id'];
        $shopId = $sqsData['shop_id'];

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongoCollection = $mongo->getCollectionForTable('product_container');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        if ($containerId) {
            $filter = ['user_id' => $user_id, 'shop_id' => $shopId, 'container_id' => (string) $containerId, "visibility" => ['$exists' => true]];
            $productsInCollection = $mongoCollection->find($filter, $options)->toArray();
        }
        $data['updated_data'] = [];
        $dbData = $data['db_data'];
        foreach ($productsInCollection as $product) {
            $isFoundInDbArray = false;
            foreach ($dbData as $dbKey => $dbProduct) {
                if ($product['source_product_id'] == $dbProduct['source_product_id']) {
                    $fields = [];
                    $isFoundInDbArray = true;
                    unset($dbData[$dbKey]);
                    foreach ($product as $key => $value) {
                        if (in_array($key, $this->ignoreKeys))
                            continue;

                        if (array_key_exists($key, $dbProduct)) {
                            if ($value != $dbProduct[$key])
                                array_push($fields, $key);
                        } else {
                            array_push($fields, $key);
                        }
                    }
                    if (count($fields)) {
                        if ($product['source_product_id'] == $product['container_id']) {
                            if (!isset ($data['updated_data']['update_wrapper']))
                                $data['updated_data']['update_wrapper'] = [];
                            array_push($data['updated_data']['update_wrapper'], ['source_product_id' => $product['source_product_id'], 'fields' => $fields]);
                        } else {
                            if (!isset ($data['updated_data']['update_child']))
                                $data['updated_data']['update_child'] = [];
                            array_push($data['updated_data']['update_child'], ['source_product_id' => $product['source_product_id'], 'fields' => $fields]);
                        }
                    }
                }
            }
            if (!$isFoundInDbArray) {
                if ($product['source_product_id'] == $product['container_id']) {
                    if (!isset ($data['updated_data']['create_wrapper']))
                        $data['updated_data']['create_wrapper'] = [];
                    array_push($data['updated_data']['create_wrapper'], ['source_product_id' => $product['source_product_id'], 'fields' => []]);
                } else {
                    if (!isset ($data['updated_data']['create_child']))
                        $data['updated_data']['create_child'] = [];
                    array_push($data['updated_data']['create_child'], ['source_product_id' => $product['source_product_id'], 'fields' => []]);
                }
            }
        }
        foreach ($dbData as $dbProduct) {
            if ($dbProduct['source_product_id'] == $dbProduct['container_id']) {
                if (!isset ($data['updated_data']['delete_wrapper']))
                    $data['updated_data']['delete_wrapper'] = [];
                array_push($data['updated_data']['delete_wrapper'], ['source_product_id' => $dbProduct['source_product_id'], 'fields' => []]);
            } else {
                if (!isset ($data['updated_data']['delete_child']))
                    $data['updated_data']['delete_child'] = [];
                array_push($data['updated_data']['delete_child'], ['source_product_id' => $dbProduct['source_product_id'], 'fields' => []]);
            }
        }
        return $data;
    }

    public function productDelete($sqsData)
    {
        $data = $this->getProductDataById($sqsData);
        if (!isset ($data['db_data']) || empty ($data['db_data'])) {
            return [
                'sqs_data' => $data,
                'message' => 'Product not found'
            ];
        }
        $productData = $this->di->getObjectManager()->get('\App\Connector\Components\Product\Data');
        $productContainer = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');
        $productContainer->setSource("product_container");
        #TODO: handle product deletion from refine_product collection
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable('product_container');
        $marketplaceObject = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        // $this->di->getLog()->logContent("INSIDE PRODUCT DELETE FUNCTION:\nWEBHOOK DATA:\n user_id => " . print_r($sqsData['user_id'], true) . "\n shop_id => " . print_r($sqsData['shop_id'], true) . "\n container_id=>" . $sqsData['data']['id'], 'info', 'webhook' . DS . 'product_delete' . DS . date("Y-m-d") . DS . $sqsData['user_id'] . 'sqs.log');
        $productImportActive = $productData->isImportingActive($sqsData);
        if ($productImportActive)
            return true;

        $aggregation = [
            ['$match' => ['container_id' => (string) $sqsData['data']['id'], 'user_id' => $sqsData['user_id'], 'shop_id' => $sqsData['shop_id'], 'visibility' => ['$exists' => true]]],
            ['$project' => ['_id' => 0, 'source_product_id' => 1, 'visibility' => 1, 'shop_id' => 1]]
        ];

        $deleteArrays = $productCollection->aggregate($aggregation, $options)->toArray();

        if (empty ($deleteArrays))
            return true;
        $modifiedDeleteArrays = [];
        foreach ($deleteArrays as $deleteArray) {
            array_push($modifiedDeleteArrays, [
                'type' => $deleteArray['visibility'],
                "source_product_id" => $deleteArray['source_product_id'],
                'source_shop_id' => $deleteArray['shop_id'] ?? ''
            ]);
        }
        if (isset ($sqsData['data']['id'])) {
            $sqsData['deleteArray'] = $modifiedDeleteArrays;
            $fieldToMatch = $this->isSimpleProduct($data['db_data']) ? 'container_id' : 'source_product_id';
            $productId = $this->findProductId($data['db_data'], $fieldToMatch, (string) $sqsData['data']['id']);
            if (!$productId) {
                return [
                    'sqs_data' => $sqsData,
                    'message' => 'Product _id not found'
                ];
            }
            $marketplaceObject->marketplaceDelete($sqsData);
            $response = $productContainer->deleteProduct(['id' => $productId]);
            // $this->di->getLog()->logContent("PRODUCT DELETE RESPONSE => " . print_r($response, true), 'info', 'webhook' . DS . 'product_delete' . DS . date("Y-m-d") . DS . $sqsData['user_id'] . 'response.log');

        }
        $data['sqs_data'] = $sqsData;
        return $data;
    }

    /**
     * Finds the _id value in an array of arrays based on a specified field and value.
     *
     * @param array  $data          The array of arrays to search.
     * @param string $fieldToMatch  The field to use for matching.
     * @param mixed  $valueToMatch  The value to match in the specified field.
     *
     * @return mixed|null The _id value from the first matching array, or null if no match is found.
     */
    private function findProductId($data, $fieldToMatch, $valueToMatch)
    {
        $matchingArray = array_filter($data, function ($item) use ($fieldToMatch, $valueToMatch) {
            return $item[$fieldToMatch] === $valueToMatch;
        });
        return !empty ($matchingArray) ? reset($matchingArray)['_id'] : null;
    }

    private function isSimpleProduct($data)
    {
        return count($data) === 1 && $data[0]['visibility'] === Product::VISIBILITY_CATALOG_AND_SEARCH;
    }

    private function findProductInContainer($data, $sqsData)
    {
        $data = $data['pushToMaindb'] ?? $data['pushToTempdb'];
        if (empty ($data))
            return true;

        $filters = [
            'user_id' => (string) $sqsData['user_id'],
            'shop_id' => (string) $sqsData['shop_id'],
            'container_id' => (string) $data[0]['container_id']
        ];

        $response = ProductContainer::findFirst([
            $filters,
            'typeMap' => [
                'root' => 'array',
                'document' => 'array'
            ]
        ]);
        return $response;
    }

    private function getModuleHook($marketplace)
    {
        $moduleHome = ucfirst($marketplace);
        $classPath = '\App\\' . $moduleHome . '\Components\Product\Hook';

        if (class_exists($classPath)) {
            return $this->di->getObjectManager()->get($classPath);
        }

        $moduleHome .= "home";
        $classPath = '\App\\' . $moduleHome . '\Components\Product\Hook';

        if (class_exists($classPath)) {
            return $this->di->getObjectManager()->get($classPath);
        }

        throw new \Exception("Class not found for marketplace: $marketplace");
    }

    private function addProductToTempWebhookContainer($tempWebhookData, $originalSqsData)
    {
        $tempWebhookContainer = $this->di->getObjectManager()->create(BaseMongo::class)
            ->getCollectionForTable('temp_webhook_container');

        $tempWebhookData['container_id'] = isset ($sqsData['data']['container_id']) ?
            $tempWebhookData['data']['container_id']
            : $tempWebhookData['data'][0]['container_id'];
        $updatedAt = isset ($tempWebhookData['data'][0]['updated_at']) ?
            $tempWebhookData['data'][0]['updated_at']
            : (new \DateTime())->format(\DateTime::ISO8601);

        $filter = [
            'user_id' => "webhook_{$tempWebhookData['user_id']}",
            'shop_id' => "webhook_{$tempWebhookData['shop_id']}",
            'container_id' => (string) $tempWebhookData['container_id'],
            'event' => Hook::PRODUCT_UPDATE_ACTION,
        ];

        $tempWebhookData['container_id'] = (string) $tempWebhookData['container_id'] ?? "";
        $currentDocument = $tempWebhookContainer->findOne($filter);

        $lastUpdatedAt = isset ($currentDocument['updated_at']) ? $currentDocument['updated_at'] : null;

        if ($this->shouldAvoidUpdate($lastUpdatedAt, $updatedAt)) {
            // If the current 'updated_at' is not greater, do not update
            return;
        }

        // If the current 'updated_at' is greater, update the document
        $tempWebhookContainer->updateOne(
            $filter,
            [
                '$set' => [
                    'webhook_data' => $originalSqsData,
                    'updated_at' => $updatedAt,
                ]
            ],
            ['upsert' => true]
        );
    }


    private function getProductDataFromWebhookContainer($userId, $shopId, $containerId)
    {
        $tempWebhookContainer = $this->di->getObjectManager()->create(BaseMongo::class)
            ->getCollectionForTable('temp_webhook_container');
        $filter = [
            'user_id' => "webhook_$userId",
            'shop_id' => "webhook_$shopId",
            'container_id' => (string) $containerId,
            'event' => Hook::PRODUCT_UPDATE_ACTION
        ];
        return $tempWebhookContainer->findOne($filter, [
            'typeMap' => [
                'root' => 'array',
                'document' => 'array'
            ]
        ]);
    }

    private function deleteTempWebhook($userId, $shopId, $containerId)
    {
        $tempWebhookContainer = $this->di->getObjectManager()->create(BaseMongo::class)
            ->getCollectionForTable('temp_webhook_container');
        $tempWebhookContainer->deleteOne(
            [
                'user_id' => "webhook_$userId",
                'shop_id' => "webhook_$shopId",
                'container_id' => (string) $containerId,
            ]
        );
    }

    private function processLastProductUpdateWebhook($tempWebhookData)
    {
        return $this->di->getObjectManager()->get(Sqs::class)->processMsg($tempWebhookData);
    }

}