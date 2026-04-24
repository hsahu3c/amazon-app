<?php

namespace App\Connector\Models\Profile;

use App\Core\Models\BaseMongo;

class Helper extends BaseMongo
{
    protected $profileId;

    protected $marketplace;

    protected $profileData;

    public $processData;

    public $userId;

    public $sourceMarketplace;

    public function processData()
    {
        if (isset($this->processData['source_marketplace'])) {
            $this->sourceMarketplace = $this->processData['source_marketplace'];
        }
        if (isset($this->processData['profile_id'], $this->processData['marketplace'])) {
            if (is_null($this->userId)) {
                $this->userId = $this->di->getUser()->id;
            }
            $this->profileId = $this->processData['profile_id'];
            $this->marketplace = $this->processData['marketplace'];
            return ['success' => true];
        }

        if (isset($this->processData['profile_data'], $this->processData['marketplace'])) {
            if (is_null($this->userId)) {
                $this->userId = $this->di->getUser()->id;
            }
            
            $this->marketplace = $this->processData['marketplace'];
            $this->profileData = $this->processData['profile_data'];
            return ['success' => true];
        }
        else {
            return ['success' => false, 'message' => $this->di->getLocale()->_('profile_id_marketplace_missing')];
        }
    }

    public function getProducts()
    {
        $res = $this->processData();

        if (!$res['success']) {
            return $res;
        }

        $profileData = $this->getProfileData();

        $getAllRule = $this->extractRuleFromProfileData($profileData);

        if (!$getAllRule['success']) {
            return $getAllRule['message'];
        }
        $customizedFilterQuery = $getAllRule['data'];
        if ($customizedFilterQuery) {
            $finalQuery = [];

            $finalQuery[] = [
                '$match' => [
                    'user_id' => $this->di->getUser()->id
                ]
            ];

            $finalQuery[] = [
                '$match' => $customizedFilterQuery,
            ];

            if (isset($this->processData['skip'], $this->processData['limit'])) {
                $limit = (int) $this->processData['limit'];
                $skip = (int) $this->processData['skip'];
            } else {
                $skip = 0;
                $limit = 250;
            }

            $finalQuery[] = [
                '$graphLookup' => [
                    "from" => "product_container",
                    "startWith" => '$container_id',
                    "connectFromField" => "container_id",
                    "connectToField" => "group_id",
                    "as" => "variants",
                    "maxDepth" => 1,
                ],
            ];
            if (!is_null($this->sourceMarketplace)) {
                $finalQuery[] = [
                    '$match' => ["source_marketplace" => ['$eq' => $this->sourceMarketplace]],
                ];
            }

            $finalQuery[] = [
                '$skip' => $skip,
            ];

            $finalQuery[] = [
                '$limit' => $limit,
            ];

            $finalQuery[] = [
                '$lookup' => [
                    'from' => $this->processData['marketplace'] . '_product',
                    'let' => ['product_container_id' => '$source_product_id', 'marketplace' => '$marketplace'],
                    'pipeline' => [
                        ['$match' =>
                            ['$expr' => [
                                '$and' => [
                                    ['$eq' => ['$source_product_id', '$$product_container_id']],
                                ],
                            ],
                            ],
                        ],
                        ['$project' => ['_id' => 0]],
                    ],
                    'as' => $this->processData['marketplace'] . "_marketplace",
                ],
            ];

            $collection = $this->getCollectionForTable('product_container');
            $response = $collection->aggregate($finalQuery, ["typeMap" => ['root' => 'array', 'document' => 'array', 'object' => 'array', 'array' => 'array']]);
            $response = $response->toArray();
            if (count($response)) {
                return ['success' => true, 'data' => $response, 'next' => 'mnbgkjinjnk'];
            }
            return ['success' => false, 'message' => "No data found as per applied rule"];
        }

        return ['success' => false, 'message' => "invalid query"];
    }

    public function getProfileData()
    {
        $profileParams = [];
        if (!is_null($this->profileId)) {
            $profileParams['filters'] = ['id' => $this->profileId];
            $obj = new Model();
            $profileData = $obj->getProfile($profileParams['filters']['id']);

            $this->profileData = $profileData['data'];
            $this->profileId = null;
        } else {
            $profileData = $this->profileData;
        }

        return $profileData;
    }

    public function extractRuleFromProfileData($profileData)
    {
        $finalquery = [];
        if (!empty($profileData['query'])) {
            $mainquery = $this->convertQueryFromMysqlToMongo($profileData['query']);
        }

        if (isset($profileData['targets']) && !empty($profileData['targets'][$this->marketplace]['skip_query'])) {
            $subQuery = $this->convertQueryFromMysqlToMongo($profileData['targets'][$this->marketplace]['skip_query']);
            $finalquery['$and'][] = $mainquery;
        } else {
            $finalquery['$and'][] = $mainquery;
        }

        if (!is_null($this->sourceMarketplace)) {
            $sourceQuery = $this->convertQueryFromMysqlToMongo("(source_marketplace == $this->sourceMarketplace)");
            $finalquery['$and'][] = $sourceQuery;
        }

        if ($finalquery !== []) {
            return ['success' => true, 'data' => $finalquery];
        }
        return ['success' => false, 'message' => "No query found"];
    }

    public function convertQueryFromMysqlToMongo($query)
    {
        if ($query != '') {
            $filterQuery = [];
            $orConditions = explode('||', $query);
            $orConditionQueries = [];
            foreach ($orConditions as $value) {
                $andConditionQuery = trim($value);
                $andConditionQuery = trim($andConditionQuery, '()');
                $andConditions = explode('&&', $andConditionQuery);
                $andConditionSet = [];
                foreach ($andConditions as $andValue) {
                    $andConditionSet[] = $this->getAndConditions($andValue);
                }

                $orConditionQueries[] = [
                    '$and' => $andConditionSet,
                ];
            }

            $orConditionQueries = [
                '$or' => $orConditionQueries,
            ];
            return $orConditionQueries;
        }

        return false;
    }

    public function getAndConditions($andCondition)
    {
        $preparedCondition = [];
        $conditions = ['==', '!=', '!%LIKE%', '%LIKE%', '>=', '<=', '>', '<'];
        $andCondition = trim($andCondition);
        if (!is_null($this->sourceMarketplace)) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($this->sourceMarketplace);
            //TODO get attributes work pending
        }

        foreach ($conditions as $value) {
            if (strpos($andCondition, $value) !== false) {
                $keyValue = explode($value, $andCondition);
                $isNumeric = false;
                $valueOfProduct = trim(addslashes($keyValue[1]));
                if (trim($keyValue[0]) == 'collections') {
                    $productIds = $this->di->getObjectManager()->get('\App\Shopify\Models\SourceModel')->getProductIdsByCollection($valueOfProduct);
                    if ($productIds &&
                        count($productIds)) {
                        if ($value == '==' ||
                            $value == '%LIKE%') {
                            $preparedCondition['source_product_id'] = [
                                '$in' => $productIds,
                            ];
                        } elseif ($value == '!=' ||
                            $value == '!%LIKE%') {
                            $preparedCondition['source_product_id'] = [
                                '$nin' => $productIds,
                            ];
                        }
                    } else {
                        $preparedCondition['source_product_id'] = [
                            '$in' => $productIds,
                        ];
                    }

                    continue;
                }

                switch ($value) {
                    case '==':
                        if ($isNumeric) {
                            $preparedCondition[trim($keyValue[0])] = $valueOfProduct;
                        } else {
                            $preparedCondition[trim($keyValue[0])] = [
                                '$regex' => '^' . $valueOfProduct . '$',
                                '$options' => 'i',
                            ];
                        }

                        break;
                    case '!=':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$not' => [
                                '$regex' => '^' . $valueOfProduct . '$',
                                '$options' => 'i',
                            ],
                        ];
                        break;
                    case '%LIKE%':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$regex' => ".*" . $valueOfProduct . ".*",
                            '$options' => 'i',
                        ];
                        break;
                    case '!%LIKE%':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$regex' => "^((?!" . $valueOfProduct . ").)*$",
                            '$options' => 'i',
                        ];
                        break;
                    case '>':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$gt' => (float) $valueOfProduct,
                        ];
                        break;
                    case '<':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$lt' => (float) $valueOfProduct,
                        ];
                        break;
                    case '>=':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$gte' => (float) $valueOfProduct,
                        ];
                        break;
                    case '<=':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$lte' => (float) $valueOfProduct,
                        ];
                        break;
                }

                break;
            }
        }

        return $preparedCondition;
    }

    public function getProfileAttribute($path)
    {
        $res = $this->processData();
        if (!$res['success']) {
            return $res;
        }

        $profileData = json_decode(json_encode($this->getProfileData()));
        $attributeDefinePath = $this->defineAttributePath();
        $pathDepth = explode('.', $path);
        $fetchedKey = [];
        $realMatch = 1;

        foreach ($attributeDefinePath as $key => $value) {
            if (count($pathDepth) >= $key) {
                if (count($pathDepth) == $key) {
                    $realMatch = $key;
                }

                $fetchedKey[] = $value;
            }
        }

        $givenPathFormate = $attributeDefinePath[$realMatch];
        $pathFormateArr = explode('.', $givenPathFormate);

        foreach ($pathDepth as $key => $value) {
            if (isset($pathFormateArr[$key])) {
                if ((strpos($pathFormateArr[$key], '$') !== false)) {
                    $name = str_replace('$', '', $pathFormateArr[$key]);
                    $$name = $value;
                }
            }
        }

        $attributes = [];

        foreach ($fetchedKey as $value) {
            if (isset($value)) {
                $string = '';
                eval('$string = "' . $value . '";');
                $strArr = explode('.', $string);
                $dyProfileData = $profileData;
                $fetched = true;

                foreach ($strArr as $strval) {
                    if (isset($dyProfileData->{$strval})) {
                        $dyProfileData = $dyProfileData->$strval;
                    } else {
                        $fetched = false;
                        break;
                    }
                }

                if ($fetched) {
                    $dyProfileData = json_decode(json_encode($dyProfileData), true);

                    $attributes = array_merge($attributes, $dyProfileData);
                }

                $dyProfileData = $profileData;
            }
        }

        if ($attributes !== []) {
            return ['success' => true, 'data' => $attributes];
        }
        return ['success' => false, 'message' => 'attribute not found'];
    }

    public function defineAttributePath()
    {
        return [
            1 => 'attributes_mapping',
            3 => 'targets.$target_marketplace.attributes_mapping',
            7 => 'targets.$target_marketplace.shops.$target_shop_id.warehouses.$target_warehouse_id.attributes_mapping',
            9 => 'targets.$target_marketplace.shops.$target_shop_id.warehouses.$target_warehouse_id.sources.$source_marketplace_id.attributes_mapping',
            13 => 'targets.$target_marketplace.shops.$target_shop_id.warehouses.$target_warehouse_id.sources.$source_marketplace_id.shops.$source_marketplace_shop_id.warehouses.$source_marketplace_warehouse_id.attributes_mapping',
        ];
    }
}
