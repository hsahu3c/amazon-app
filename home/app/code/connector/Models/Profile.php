<?php

namespace App\Connector\Models;

use App\Core\Models\Base;
use Phalcon\Validation;
use Phalcon\Mvc\Model\Query;

class Profile extends Base
{
    protected $table = 'profiles';

    const IS_EQUAL_TO = 1;

    const IS_NOT_EQUAL_TO = 2;

    const IS_CONTAINS = 3;

    const IS_NOT_CONTAINS = 4;

    const START_FROM = 5;

    const END_FROM = 6;

    const RANGE = 7;

    const productChunkSize = 2;

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());

        $this->hasMany(
            'id',
            'App\Connector\Models\ProfileProduct',
            'profile_id',
            [
                'alias' => 'products'
            ]
        );
    }

    public function loadAreaConfigurations(): void {
        $helper = $this->di->getObjectManager()->get('App\Core\Components\Helper');
        $config = $this->di->getCache()->get('config');
        foreach ($helper->getAllModules() as $module => $active) {
            if ($active) {
                $filePath = CODE . DS . $module . DS . 'etc' . DS . 'template.php';
                if (file_exists($filePath)) {
                    $array = new \Phalcon\Config\Adapter\Php($filePath);
                    $config->merge($array);
                }
            }
        }

        $this->di->set('config', $config);
    }

    public function getTemplate($data)
    {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);

            if ($connectorHelper) {
                return $connectorHelper->getTemplate($data);
            }
        }
    }

    public function validation()
    {
        $validator = new Validation();
        return $this->validate($validator);
    }

    public static function search($filterParams = [])
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim($key);
                if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                    $conditions[$key] = [
                        '$regex' => '^' . $value[self::IS_EQUAL_TO] . '$',
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[$key] = [
                        '$not' => [
                            '$regex' => '^' . trim(addslashes($value[self::IS_NOT_EQUAL_TO])) . '$',
                            '$options' => 'i'
                        ]
                    ];
                } elseif (array_key_exists(self::IS_CONTAINS, $value)) {
                    $conditions[$key] = [
                        '$regex' =>  trim(addslashes($value[self::IS_CONTAINS])),
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::IS_NOT_CONTAINS, $value)) {
                    $conditions[$key] = [
                        '$regex' => "^((?!" . trim(addslashes($value[self::IS_NOT_CONTAINS])) . ").)*$",
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::START_FROM, $value)) {
                    $conditions[$key] = [
                        '$regex' => "^" . trim(addslashes($value[self::START_FROM])),
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::END_FROM, $value)) {
                    $conditions[$key] = [
                        '$regex' => trim(addslashes($value[self::END_FROM])) . "$",
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::RANGE, $value)) {
                    if (trim($value[self::RANGE]['from']) && !trim($value[self::RANGE]['to'])) {
                        $conditions[$key] =  ['$gte' => trim($value[self::RANGE]['from'])];
                    } elseif (trim($value[self::RANGE]['to']) &&
                        !trim($value[self::RANGE]['from'])) {
                        $conditions[$key] =  ['$lte' => trim($value[self::RANGE]['to'])];
                    } else {
                        $conditions[$key] =  [
                            '$gte' => trim($value[self::RANGE]['from']),
                            '$lte' => trim($value[self::RANGE]['to'])
                        ];
                    }
                }
            }
        }

        if (isset($filterParams['search'])) {
            $conditions['$text'] = ['$search' => trim(addslashes($filterParams['search']))];
        }

        return $conditions;
    }

    public function getAllProfiles($params)
    {
        $userId = $this->di->getUser()->id;
        $conditionalQuery = [];
        if (isset($params['filter']) || isset($params['search'])) {
            $conditionalQuery = self::search($params);
        }

        $limit = false;
        $offset = false;
        if(isset($params['count']) && isset($params['activePage'])){
            $limit = $params['count'] * $params['activePage'] ?? self::$defaultPagination;
            $offset = $limit - $params['count'];
        }

        $collection = $this->getCollectionForTable("profiles");
        $conditionalQuery['user_id'] = (string)$userId;
        $aggregation = [
            [
                '$project' => [
                    'targets' => 0,
                    'marketplaceAttributes' => 0,
                ]
            ],
            ['$match' => $conditionalQuery],
        ];

        if($limit){
            $aggregation[] = ['$limit' => (int)$limit];
        }

        if($offset){
            $aggregation[] = ['$skip' => (int)$offset];
        }

        $aggregationForCount = [
            ['$match' => $conditionalQuery],
            ['$count' => 'count']
        ];

        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $rows = $collection->aggregate($aggregation, $options);
        $totalRows = $collection->aggregate($aggregationForCount, $options);
        $rows = $rows->toArray();
        $totalRows = $totalRows->toArray();
        foreach ($rows as $key => $value) {
            $profile = $value;
            try {
                $query = $profile['query'];
                $query = ltrim($query,"||");
                $query = ltrim($query," ||");
                $query = ltrim($query,"&&");
                $query = ltrim($query," &&");
                $count = $this->getProductsCountByQuery($query, $profile['source'], $userId);
                $profile['product_count'] = $count ? $count : 0;
                $rows[$key] = $profile;
            } catch(\Exception) {
                $profile['product_count'] = 0;
                $rows[$key] = $profile;
            }
        }

        $totalCount = count($totalRows) ? $totalRows[0]['count'] : 0;
        return ['success' => true, 'data' => ['rows' => $rows, 'count' => $totalCount]];
    }

    public function getProductsCountByQuery($query, $sourceMarketplace, $userId = false) {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $filterQuery = $this->di->getObjectManager()->get('\App\Connector\Models\Product')->prepareQuery($query);
        $finalQuery = [
            [
                '$unwind' => '$variants'
            ],
            [
                '$match' => $filterQuery
            ],
            [
                '$count' => 'count'
            ]
        ];
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $mongo->setSource('product_container_' . $userId);

        $collection = $mongo->getCollection();
        $response = $collection->aggregate($finalQuery);
        $response = $response->toArray();
        if (count($response)) {
            $count = isset($response[0]) ? $response[0]['count'] : 0;
            return $count;
        }

        return false;
    }

    public function getUploadOptions()
    {
        $connectorsHelpler = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $connectors = $connectorsHelpler->getConnectorsWithFilter(['installed'=>1], $this->di->getUser()->getId());
        $connectorOptions = [];
        foreach ($connectors as $connector) {
            $connectorOptions[] = [
                'code' => $connector['code'],
                'title' => $connector['title'],
                'shops' => $connectorsHelpler->getConnectorModelByCode($connector['code'])->getShops()
            ];
        }

        return ['success' => true, 'data' => $connectorOptions];
    }

    public function getSourceAttributeType($profileDetails)
    {
        $fileData = [
            'profile_name' => $profileDetails['name'],
            'target' => $profileDetails['code'],
            'shop' => $profileDetails['shop']
        ];
        $filename = 'upload_products_' . $this->di->getUser()->id . '_' . time() . '.php';
        $folderPath = BP . DS . 'var' . DS . 'upload' . DS . $this->di->getUser()->id;
        if (!file_exists($folderPath)) {
            $oldmask = umask(0);
            mkdir($folderPath, 0777, true);
            umask($oldmask);
        }

        $filePath = '';
        $filePath .= $folderPath . DS . $filename;
        $handle = fopen($filePath, 'w+');
        fwrite($handle, '<?php return ' . var_export($fileData, true) . ';');
        fclose($handle);
        $categories = $this->di->getObjectManager()->get('App\Connector\Components\MarketplaceHelper')->getAttributes($this->di->getUser()->id, $profileDetails['code'], $profileDetails['shop']);
        $toReturnResponse = [
            'hasAttributes' => true,
            'fields' => $categories['target_attr'],
            'baseAttributes' => $categories['base_attr'],
            'suggestions' => $categories['suggestions'],
            'fileName' => $filename
        ];
        return ['success' => true, 'data' => $toReturnResponse];
    }

    public function saveAttributeMapping($rawBody)
    {
        $filePath = BP . DS . 'var' . DS . 'upload' . DS . $this->di->getUser()->id . DS . $rawBody['filename'];
        if (!file_exists($filePath)) {
            return ['success' => false, 'code' => 'enter_profile_details_first', 'message' => 'Enter profile details first'];
        }
        $fileData = require $filePath;
        $fileData['mapping_data'] = $rawBody['mapped_attributes'];
        if (isset($rawBody['category'])) {
            $fileData['category'] = $rawBody['category'];
        }

        $handle = fopen($filePath, 'w+');
        fwrite($handle, '<?php return ' . var_export($fileData, true) . ';');
        fclose($handle);
        return ['success' => true, 'message' => 'Assign products to profile'];
    }

    public function assignProductsToProfile($rawBody)
    {
        $filePath = BP . DS . 'var' . DS . 'upload' . DS . $this->di->getUser()->id . DS . $rawBody['filename'];
        if (!file_exists($filePath)) {
            return ['success' => false, 'code' => 'enter_profile_details_first', 'message' => 'Enter profile details first'];
        }
        $fileData = require $filePath;
        $fileData['quantity'] = $rawBody['quantity'];
        $fileData['products'] = $rawBody['products'];
        $handle = fopen($filePath, 'w+');
        fwrite($handle, '<?php return ' . var_export($fileData, true) . ';');
        fclose($handle);
        $profileModel = new Profile();
        $toSaveProfile = [];
        $toSaveProfile['name'] = $fileData['profile_name'];
        $toSaveProfile['merchant_id'] = $this->di->getUser()->id;
        $toSaveProfile['qty_from_warehouse'] = $fileData['quantity'];
        $toSaveProfile['target'] = $fileData['target'];
        $toSaveProfile['shop'] = $fileData['shop'];
        if (isset($toSaveProfile['category'])) {
            $toSaveProfile['category'] = $fileData['category'];
        }

        $toSaveProfile['attribute_mapping'] = json_encode($fileData['mapping_data']);
        $profileModel->set($toSaveProfile);
        $status = $profileModel->save();
        if ($status) {
            $this->di->getObjectManager()->get('App\Connector\Components\Suggestor')->submitUploadMapping($fileData['mapping_data'], $this->di->getUser()->id, 'connector', $fileData['target']);
            foreach ($fileData['products'] as $prodId) {
                $profileProductModel = new ProfileProduct();
                $profileProduct = [];
                $profileProduct['profile_id'] = $profileModel->id;
                $profileProduct['product_id'] = $prodId;
                $profileProductModel->set($profileProduct);
                $profileProdStatus = $profileProductModel->save();
                if (!$profileProdStatus) {
                    $errors = [];
                    foreach ($profileProductModel->getMessages() as $message) {
                        $errors[] = $message->getMessage();
                    }

                    return ['success' => false, 'code' => 'error_in_save', 'message' => 'Error occur in saving', 'data' => $errors];
                }
            }

            return ['success' => true, 'code' => 'save_success', 'message' => 'Profile saved succesfully', 'data' => $profileModel->id];
        }
        $errors = [];
        foreach ($profileModel->getMessages() as $message) {
            $errors[] = $message->getMessage();
        }
        return ['success' => false, 'code' => 'error_in_save', 'message' => 'Error occur in saving', 'data' => $errors];
    }

    public function uploadProducts($profileId)
    {
        $profile = Profile::findFirst(["id='{$profileId}'"]);
        if ($profile === false) {
            return ['success' => false, 'code' => 'enter_profile_details_first', 'message' => 'Enter profile details first'];
        }
        $profileData = $profile->toArray();
        $profileProducts = ProfileProduct::find(["profile_id = '{$profileData['id']}'"])->toArray();
        foreach ($profileProducts as $prods) {
            $productIds[] = $prods['product_id'];
        }

        $mappingData = json_decode($profileData['attribute_mapping'], true);
        $variantProds = Product::find(["id IN ({ids:array})", 'bind' => [ 'ids' => $productIds]])->toArray();
        $optionAttributes = ProductAttribute::find(["frontend_type IN ({type:array})", 'bind' => [ 'type' => ['dropdown', 'radio', 'checkbox']], 'column' => 'code'])->toArray();
        foreach ($optionAttributes as $key => $value) {
            $optionAttributes[$key] = $value['code'];
        }

        $attrOptionValue = new ProductAttributeOption();
        $containerModel = new ProductContainer();
        $variantModel = new Product();
        $totalContainer = [];
        foreach ($variantProds as $key => $varProds) {
            if ($additionalInfo = $variantModel->getJson($varProds['additional_info'])) {
                if (!is_array($additionalInfo)) {
                    $additionalInfo = [$additionalInfo];
                }

                foreach ($additionalInfo as $varKey => $varValue) {
                    $variantProds[$key][$varKey] = $varValue;
                }
            }

            foreach ($optionAttributes as $optionValue) {
                if (isset($varProds[$optionValue])) {
                    $isJson = $variantModel->getJson($varProds[$optionValue]);
                    if ($isJson !== false) {
                        $optValues = [];
                        $decodedData = [];
                        if (!is_array(json_decode($varProds[$optionValue], true))) {
                            $decodedData = [json_decode($varProds[$optionValue], true)];
                        } else {
                            $decodedData = json_decode($varProds[$optionValue], true);
                        }

                        foreach ($decodedData as $optValue) {
                            if ($attrOptionValue::findFirst(["id='{$optValue}'"])) {
                                $optValues[] = $attrOptionValue::findFirst(["id='{$optValue}'"])->toArray()['value'];
                            }
                        }

                        $variantProds[$key][$optionValue] = implode(',', $optValues);
                    } else {
                        $optValue = $attrOptionValue::findFirst(["id='{$varProds[$optionValue]}'"]);
                        if ($optValue !== false) {
                            $optValue = $optValue->toArray();
                        }

                        $variantProds[$key][$optionValue] = $optValue['value'];
                    }
                }
            }

            $totalContainer[$varProds['product_id']][] = $variantProds[$key];
        }

        $formattedProducts = [];
        foreach ($totalContainer as $key => $value) {
            $formattedProducts[] = $containerModel::findFirst(["id='{$key}'"])->toArray();
            $formattedProducts[count($formattedProducts) - 1]['variants'] = $value;
        }

        $marketplaceProducts = $this->di->getObjectManager()->get('App\Connector\Components\MarketplaceHelper')->getProductsForUpload($this->di->getUser()->id, $profileData['target'], $formattedProducts, $mappingData);
        $productUploadArray = [];
        $count = 0;
        $toReturnIndex = 0;
        foreach ($marketplaceProducts as $key => $value) {
            if ($count < Profile::productChunkSize) {
                $productUploadArray[$toReturnIndex]['data'][] = $value;
                $count += 1;
            } else {
                $toReturnIndex += 1;
                $productUploadArray[$toReturnIndex]['data'][] = $value;
                $count = 1;
            }

            $productUploadArray[$toReturnIndex]['source_product_id'][] = $formattedProducts[$key]['id'];
        }

        $weight = 0;
        // @TODO Will have to handle the case of maintaining logs of upload products in history.php
        foreach ($productUploadArray as $key => $value) {
            $weight += 100 / count($productUploadArray);
            $productUploadArray[$key]['weight'] = $weight;
            $productUploadArray[$key]['target'] = $profileData['target'];
            $productUploadArray[$key]['shop'] = $profileData['shop'];
        }

        $productUploadFileName  = 'product_upload_' . $this->di->getUser()->id . '_' . time() . '.php';
        $filePath = BP . DS . 'var' . DS . 'upload' . DS . $productUploadFileName;
        $handle = fopen($filePath, 'w+');
        fwrite($handle, '<?php return ' . var_export($productUploadArray, true) . ';');
        fclose($handle);
        return ['success' => true, 'message' => 'Products added in queue for upload', 'code' => 'send_request_for_upload', 'data' => ['fileName' => $productUploadFileName , 'url' => 'connector/profile/uploadProductChunk']];
    }

    public function uploadProductChunk($productChunkDetails)
    {
        $filePath = BP . DS . 'var' . DS . 'upload' . DS . $productChunkDetails['sessionName'];
        if (file_exists($filePath)) {
            $fileData = require $filePath;
            $productsToUpload = $fileData[$productChunkDetails['index']]['data'];
            $uploadCount = 0;
            foreach ($productsToUpload as $singleProduct) {
                $uploadStatus = $this->di->getObjectManager()->get('App\Connector\Components\MarketplaceHelper')->uploadProductstoMarketplace($this->di->getUser()->id, $fileData[$productChunkDetails['index']]['target'], $singleProduct, $fileData[$productChunkDetails['index']]['shop']);
                if (!isset($uploadStatus['errors'])) {
                    $this->makeEntryInMarketplaceTable($fileData[$productChunkDetails['index']]['source_product_id'][$uploadCount], $fileData[$productChunkDetails['index']]['target'], $uploadStatus);
                }

                $uploadCount++;
            }

            return ['success' => true, 'weight' => $fileData[$productChunkDetails['index']]['weight'], 'error_link' => ''];
        }
        return ['success' => false, 'message' => 'Upload data not found'];
    }

    public function makeEntryInMarketplaceTable($productId, $marketplace, $productCreated): void
    {
        $this->di->getObjectManager()->get('App\\' . ucfirst($marketplace) . '\Components\MarketplaceTables')->makeEntryInMarketplaceTableForUpload($productId, $productCreated);
    }

    public function getProfile($data, $userId = false) {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        if (isset($data['id'])) {
            $profileId = $data['id'];
            $collection = $this->getCollectionForTable('profiles');
            $data = $collection->findOne(['$or' => [
                ['profile_id' => (string)$profileId],
                ['profile_id' => (int)$profileId],
            ]]);
            if ($data) {
                return ['success' => true, 'data' => $data];
            }
        } else {
            $filePath = BP . DS . 'var' . DS . 'profile' . DS . $userId . '.json';
            $profileData = $this->getDataFromFile($filePath, 'json');
            if ($profileData) {
                return ['success' => true, 'data' => $profileData];
            }
        }

        return ['success' => false, 'code' => 'no_profile_found', 'message' => 'No profile found'];
    }

    public function deleteProfile($data, $userId = false) {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        if (isset($data['id'])) {
            $profileId = $data['id'];
            $collection = $this->getCollectionForTable('profiles');
            $data = $collection->deleteMany(['$or' => [
                ["profile_id" => (string)$profileId],
                ["profile_id" => (int)$profileId],
            ]]);
            if ($data) {
                return ['success' => true, 'message' => 'Profile deleted succesfully.'];
            }

            return ['success' => false, 'message' => 'Profile not found.'];
        }

        return ['success' => false, 'message' => 'Profile not found. Invalid request.'];
    }

    public function setProfile($data) {
        $userId = $this->di->getUser()->id;
        if (isset($data['data'])) {
            $profileData = $data['data'];

            if (isset($profileData['profile_id'])) {
                $profileId = $profileData['profile_id'];
                $profileData['user_id'] = $userId;
                $collection = $this->getCollectionForTable('profiles');
                $collection->replaceOne(
                    [
                        'profile_id' => (int)$profileId
                    ],
                    $profileData,
                    ['upsert'=>true]
                );
            } else {
                $profileData['state'] = isset($data['step']) ? $data['step'] : 1;
                $filePath = BP . DS . 'var' . DS . 'profile' . DS . $userId . '.json';
                $this->saveDataInFile($profileData, $filePath, 'json');
                if (isset($data['saveInTable']) &&
                    $data['saveInTable']) {
                    $collection = $this->getCollectionForTable('profiles');
                    $profileData['user_id'] = $userId;
                    if (isset($profileData['state'])) {
                        unset($profileData['state']);
                    }

                    $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
                    $profileId = $mongo->getCounter('profile_id');
                    $profileData['profile_id'] = $profileId;
                    $collection->replaceOne(
                        [
                            'profile_id' => $profileId
                        ],
                        $profileData,
                        ['upsert'=>true]
                    );
                    unlink($filePath);
                }
            }

            return ['success' => true, 'code' => 'profile_data_saved', 'message' => 'Profile data saved succesfully'];
        }

        return ['success' => false, 'code' => 'invalid_data_format', 'message' => 'Invalid data format'];
    }

    public function getMatchingProfiles($profileDetails)
    {
        $aggregation = [
                        "source" => isset($profileDetails['source']) ? $profileDetails['source'] : '',
                        "target" => isset($profileDetails['target']) ? $profileDetails['target'] : '',
                        "user_id" => isset($profileDetails['user_id']) ? $profileDetails['user_id'] : (string)$this->di->getUser()->id
                    ];
        $collection = $this->getCollectionForTable("profiles");
        $response = $collection->find($aggregation)->toArray();
        $matchingProfiles = [];
        foreach ($response as $value) {
            $profileData = iterator_to_array($value);
            $matchingProfiles[] = [
                "name" => $profileData['name'],
                "id" => $profileData['profile_id']
            ];
        }

        return ["success" => true, "data" => $matchingProfiles];
    }

    public function saveDataInFile($data, $path, $format = 'php') {
        if (!file_exists($path)) {
            $oldmask = umask(0);
            mkdir(substr($path, 0, strrpos($path, DS)), 0777, true);
            umask($oldmask);
        }

        $fp = fopen($path, 'w+');
        switch ($format) {
            case 'php':
                fwrite($fp, '<?php return ' . var_export($data, true) . ';');
                break;
            case 'json':
                fwrite($fp, json_encode($data));
                break;
        }

        fclose($fp);
        return true;
    }

    public function getDataFromFile($path, $format = 'php') {
        if (file_exists($path)) {
            $data = [];
            switch ($format) {
                case 'php':
                    $data = require $path;
                    break;
                case 'json':
                    $data = file_get_contents($path);
                    $data = json_decode($data, true);
                    break;
            }

            return $data;
        }

        return false;
    }

    public function getCollectionForTable($table) {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource($table);

        $collection = $mongo->getCollection();
        return $collection;
    }

    public function getProfileById($params) {
        $profileId = $params['id'];
        $activePage = isset($params['activePage']) ? $params['activePage'] : 1;
        $count = isset($params['count']) ? $params['count'] : 5;
        $sendProducts = isset($params['sendProducts']) ? $params['sendProducts'] : true;
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('profiles');
        $response = $collection->findOne([
                '$or' => [
                    ["profile_id" => (string)$profileId],
                    ["profile_id" => (int)$profileId],
                ]
            ], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if ($response) {
            if(isset($response['prepareQuery'])){
                $query = $response['prepareQuery']['query'];
            }else{
                $query = $response['query'];
            }

            $query = ltrim($query,"||");
            $query = ltrim($query," ||");
            $query = ltrim($query,"&&");
            $query = ltrim($query," &&");
            $marketplace = $response['marketplace'];

            $sourceShop = false;
            $data = [
                'marketplace' => $marketplace,
                'query' => $query
            ];
            if ($sourceShop) {
                $shopModel = $this->di->getObjectManager()->get('\App\\' . ucfirst($marketplace) . '\Models\Shop\Details');
                switch ($marketplace) {
                    case 'amazonimporter':
                        $shopData = $shopModel::findFirst(["account_name='{$sourceShop}'"]);
                        break;

                    default:
                        $shopData = $shopModel::findFirst(["shop_url='{$sourceShop}'"]);
                        break;
                }

                if ($shopData) {
                    $sourceShop = $shopData->id;
                } else {
                    $sourceShop = false;
                }
            } else {
                $sourceShop = false;
            }

            if ($sendProducts) {
                $productsDataCount = $this->getProductsCountByQuery($data['query'], 'shopify', $userId);
                $data['activePage'] = $activePage;
                $data['count'] = $count;
                $productsData = $this->di->getObjectManager()->get('\App\Connector\Models\Product')->getProductsByQuery($data, $userId, $sourceShop);
                if ($productsData['success']) {
                    $products = $productsData['data'];
                    $response['products_data'] = $products;
                    $response['products_data_count'] = $productsDataCount;
                    return ['success' => true, 'data' => $response];
                }

                return ['success' => false, 'message' => 'Failed to fetch profile products', 'code' => 'failed_to_fetch_profile_products'];
            }

            return ['success' => true, 'data' => $response];
        }

        return ['success' => false, 'message' => 'Profile not found', 'code' => 'profile_not_found'];
    }
}
