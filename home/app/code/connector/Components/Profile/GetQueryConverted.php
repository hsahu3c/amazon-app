<?php

namespace App\Connector\Components\Profile;

use App\Core\Models\BaseMongo;

class GetQueryConverted extends BaseMongo
{

    public $shopIds = [];

    // public $customRegexChar = ['!','@','#','%','^','$','&','*','(',')','+','-','=','[',']','{','}','|',';','.','/','<','>','?','~','`','¿'];
    public $customRegexChar =  ['+', '¿', '?', '(', ')'];

    public function init(): void
    {
        $this->customRegexChar = $this->di->getConfig()->custom_regex_char ? $this->di->getConfig()->custom_regex_char->toArray() : ['+', '¿', '?', '(', ')'];
    }

    public function extractRuleFromProfileData($profileData, $type = 'find', $shopIds = [])
    {
        $this->init();
        $userId = isset($profileData['user_id']) ? $profileData['user_id'] :  $this->di->getUser()->id;
        $this->shopIds = $shopIds;
        $finalQuery = [];
        $mainQuery = $this->convertQueryFromMysqlToMongo($profileData['query']);
        $finalQuery['$and'][] = $mainQuery;
        if ($finalQuery !== []) {
            $finalQuery['$and'][] = ['user_id' => $userId];
            $finalQuery['$and'][] = ['visibility' => 'Catalog and Search'];

            if (isset($shopIds['sources'])) {
                $sources = [];

                foreach ($shopIds['sources'] as $value) {
                    $sources[] = ['shop_id' => $value];
                }

                $finalQuery['$and'][] = ['$or' => $sources];
            }

            //query we need to omit products from profile query which are uploaded

            if (isset($profileData['overWriteExistingProducts']) && !$profileData['overWriteExistingProducts'] && isset($shopIds['targets'])) {
                foreach ($shopIds['targets'] as $value) {
                    if (isset($profileData['check_profile_id']) && $profileData['check_profile_id']) {
                        $finalQuery['$and'][] = [
                            '$or' => [
                                ['profile.target_shop_id' => ['$ne' => $value]],
                                ['profile.profile_id' => new \MongoDB\BSON\ObjectId($profileData['check_profile_id'])]
                            ]
                        ];
                    } else {
                        $finalQuery['$and'][] = ['profile.target_shop_id' => ['$ne' => $value]];
                    }
                }
            }

            if ($type == 'find') {
                return $finalQuery;
            }

            return [
                ['$match' => $finalQuery]
            ];
        }

        return false;
    }

    public function extractRuleForRefineData($profileData, $type = 'find', $shopIds = [], $shopIdsForQuery = [])
    {
        $this->init();
        $userId = isset($profileData['user_id']) ? $profileData['user_id'] :  $this->di->getUser()->id;
        $this->shopIds = $shopIds;
        $finalQuery = [];
        $finalQuery['$and'][] = ['user_id' => $userId];
        $finalQuery['$and'][] = $shopIdsForQuery;

        if (isset($profileData['overWriteExistingProducts']) && !$profileData['overWriteExistingProducts'] && isset($shopIds['targets'])) {
            foreach ($shopIds['targets'] as $value) {
                if (isset($profileData['check_profile_id']) && $profileData['check_profile_id']) {
                    $finalQuery['$and'][] = [
                        '$or' => [
                            ['profile.profile_id' => ['$exists' => false]],
                            ['profile.profile_id' => new \MongoDB\BSON\ObjectId($profileData['check_profile_id'])]
                        ]
                    ];
                } else {
                    $finalQuery['$and'][] = ['profile.profile_id' => ['$exists' => false]];
                }
            }
        }

        $mainQuery = $this->convertQueryFromMysqlToMongo($profileData['query'], true);
        if (isset($profileData['global_and']) && $profileData['global_and'] !== '') {
            // $globalQuery = $this->convertQueryFromMysqlToMongo($profileData['global_and'], true);
            // $container['$and'] = $globalQuery['$or'][0]['$and'];
            // array_unshift($container['$and'], $mainQuery);
            // $finalQuery['$and'][] = $container;
            $finalQuery['$and'][] = [
                '$and' => [
                    $mainQuery,
                    $this->convertQueryFromMysqlToMongo($profileData['global_and'], true),
                ],
            ];
        } else {
            $finalQuery['$and'][] = $mainQuery;
        }

        if ($type == 'find') {
            return $finalQuery;
        }

        return [
            ['$match' => $finalQuery]
        ];
    }

    public function convertQueryFromMysqlToMongo($query, $refine = false)
    {
        if ($query != '') {
            $filterQuery = [];
            $orConditions = explode('||', $query);
            $orConditionQueries = [];
            foreach ($orConditions as $value) {
                $andConditionQuery = trim($value);
                $andConditionQuery = $this->removeOuterParentheses($andConditionQuery);
                $andConditions = explode('&&', $andConditionQuery);
                $andConditionSet = [];
                foreach ($andConditions as $andValue) {
                    $andConditionSet[] = $refine ? $this->getAndConditionsForRefine($andValue) : $this->getAndConditions($andValue);
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

    private function removeOuterParentheses($string)
    {
        if (str_starts_with($string, '(') && str_ends_with($string, ')')) {
            // Remove both front and back parentheses
            $string = substr($string, 1, -1);
        } elseif (str_starts_with($string, '(')) {
            // Remove only the front parenthesis
            $string = substr($string, 1);
        } elseif (str_ends_with($string, ')')) {
            // Remove only the back parenthesis
            $string = substr($string, 0, -1);
        }
        return $string;
    }

    public function createMarketplaceCond(&$preparedCondition, $regex, $keyValue): void
    {
        $sourceName = $this->di->getRequester()->getSourceName();
        $targetName = $this->di->getRequester()->getTargetName();
        $config = $this->di->getConfig()->connectors->toArray();
        $allowed_filters = (isset($config[$sourceName]['allowed_filters']) ? $config[$sourceName]['allowed_filters']  : []) + (isset($config[$targetName]['allowed_filters'])  ? $config[$targetName]['allowed_filters'] : []);

        if (count($allowed_filters) == 0) {
            // $allowed_filters = $this->di->getConfig()->allowed_filters ? $this->di->getConfig()->allowed_filters->toArray() : [];
        }

        $key = trim($keyValue[0], " ");
        $preparedCondition[$key] = $regex;
    }

    public function getAndConditions($andCondition)
    {
        $preparedCondition = [];
        $conditions = ['==', '!=', '!%LIKE%', '%LIKE%', '>=', '<=', '>', '<'];
        $andCondition = trim($andCondition);
        $splittingArray = $this->di->getConfig()->get("multiselect_splitters");
        $multiSelectSeparator = ($splittingArray && !empty($splittingArray[$this->di->getAppCode()->getAppTag()])) ? $splittingArray[$this->di->getAppCode()->getAppTag()] : ",";
        foreach ($conditions as $key => $value) {
            if (strpos($andCondition, $value) !== false) {
                $keyValue = explode($value, $andCondition);
                $isNumeric = false;

                $valueOfProduct = trim(addslashes($keyValue[1]));
                foreach ($this->customRegexChar as $val) {
                    $trimmedValueOfProduct = str_replace($val, "\\" . $val, $valueOfProduct);
                    $valueOfProduct = $trimmedValueOfProduct;
                }

                if (trim($keyValue[0]) == 'collections') {
                    $productIds = $this->di->getObjectManager()->get('\App\Shopifyhome\Models\SourceModel')->getProductIdsByCollection((string) $valueOfProduct);
                    if ($productIds && count($productIds)) {
                        if ($value == '==' || $value == '%LIKE%') {
                            $preparedCondition['source_product_id'] = [
                                '$in' => $productIds,
                            ];
                        } elseif (
                            $value == '!=' ||
                            $value == '!%LIKE%'
                        ) {
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
                            if (trim($keyValue[0]) == 'variant_attributes') {
                                $this->formatCondForVariantAttr($preparedCondition, trim($keyValue[0]), $valueOfProduct);
                            } else if (trim($keyValue[0]) == 'price' || trim($keyValue[0]) == 'quantity') {
                                $valueOfProduct  = $this->checkIntegerCases($valueOfProduct);
                                $this->createMarketplaceCond($preparedCondition, $valueOfProduct, $keyValue);
                            } else {
                                $key = trim($keyValue[0]);
                                $keyArr = explode("_", $key);
                                if ($keyArr[0] == 'multiselect') {
                                    $arr = [];
                                    unset($keyArr[0]);
                                    $keyVal = implode('_', $keyArr);
                                    $var = explode($multiSelectSeparator, $valueOfProduct);
                                    foreach ($var as $v) {
                                        $regex = [
                                            '$regex' => '^' . $v . '$',
                                            '$options' => 'i',
                                        ];
                                        $arr[] = [
                                            $keyVal => $regex
                                        ];
                                    }

                                    $preparedCondition['$or'] = $arr;
                                } else {
                                    $this->createMarketplaceCond($preparedCondition, [
                                        '$regex' => '^' . $valueOfProduct . '$',
                                        '$options' => 'i',
                                    ], $keyValue);
                                }
                            }
                        }

                        break;
                    case '!=':
                        if (trim($keyValue[0]) == 'price' || trim($keyValue[0]) == 'quantity') {
                            $valueOfProduct  = $this->checkIntegerCases($valueOfProduct);
                            $this->createMarketplaceCond($preparedCondition, [
                                '$ne' => (float)$valueOfProduct,
                            ], $keyValue);
                        } else {
                            $key = trim($keyValue[0]);
                            $keyArr = explode("_", $key);
                            if ($keyArr[0] == 'multiselect') {
                                $arr = [];
                                $narr = [];
                                unset($keyArr[0]);
                                $keyVal = implode('_', $keyArr);
                                $var = explode(",", $valueOfProduct);
                                foreach ($var as $v) {
                                    $regex = [
                                        '$not' => [
                                            '$regex' => '^' . $v . '$',
                                            '$options' => 'i',
                                        ],
                                    ];
                                    $arr[] =
                                        [
                                            $keyVal => $regex
                                        ];
                                }

                                $preparedCondition['$and'] = $arr;
                            } else {
                                $this->createMarketplaceCond($preparedCondition, [
                                    '$not' => [
                                        '$regex' => '^' . $valueOfProduct . '$',
                                        '$options' => 'i',
                                    ],
                                ], $keyValue);
                            }
                        }

                        break;
                    case '%LIKE%':
                        if (trim($keyValue[0]) == 'variant_attributes') {
                            $this->formatCondForVariantAttr($preparedCondition, trim($keyValue[0]), $valueOfProduct);
                        } else {
                            $key = trim($keyValue[0]);
                            $keyArr = explode("_", $key);
                            if ($keyArr[0] == 'multiselect') {
                                $arr = [];

                                unset($keyArr[0]);
                                $keyVal = implode('_', $keyArr);
                                $var = explode(",", $valueOfProduct);
                                foreach ($var as $v) {
                                    $regex = [
                                        '$regex' => '.*' . $v . '.*',
                                        '$options' => 'i',
                                    ];
                                    $arr[] =
                                        [
                                            $keyVal => $regex
                                        ];
                                }

                                $preparedCondition['$or'] = $arr;
                            } else {
                                $this->createMarketplaceCond($preparedCondition,  [
                                    '$regex' => ".*" . $valueOfProduct . ".*",
                                    '$options' => 'i',
                                ], $keyValue);
                            }
                        }

                        break;
                    case '!%LIKE%':
                        $key = trim($keyValue[0]);
                        $keyArr = explode("_", $key);
                        if ($keyArr[0] == 'multiselect') {
                            $arr = [];

                            unset($keyArr[0]);
                            $keyVal = implode('_', $keyArr);
                            $var = explode(",", $valueOfProduct);
                            foreach ($var as $v) {
                                $regex = [
                                    '$regex' => '^((?!' . $v . ').)*$',
                                    '$options' => 'i',
                                ];
                                $arr[] =
                                    [
                                        $keyVal => $regex
                                    ];
                            }

                            $preparedCondition['$and'] = $arr;
                        } else {
                            $this->createMarketplaceCond($preparedCondition, [
                                '$not' => [
                                    '$regex' => ".*" . $valueOfProduct . ".*",
                                    '$options' => 'i',
                                ]
                            ], $keyValue);
                        }

                        break;
                    case '>':
                        $this->createMarketplaceCond($preparedCondition, [
                            '$gt' => $this->checkIntegerCases($valueOfProduct),
                        ], $keyValue);
                        break;
                    case '<':
                        $this->createMarketplaceCond($preparedCondition, [
                            '$lt' => $this->checkIntegerCases($valueOfProduct),
                        ], $keyValue);
                        break;
                    case '>=':
                        $this->createMarketplaceCond($preparedCondition, [
                            '$gte' => $this->checkIntegerCases($valueOfProduct),
                        ], $keyValue);
                        break;
                    case '<=':
                        $this->createMarketplaceCond($preparedCondition, [
                            '$lte' => $this->checkIntegerCases($valueOfProduct),
                        ], $keyValue);
                        break;
                }

                break;
            }
        }

        return $preparedCondition;
    }

    public function checkIntegerCases($valueOfProduct)
    {
        return $valueOfProduct == '0' ? 0 : ((float)$valueOfProduct == 0 ? $valueOfProduct : (float)$valueOfProduct);
    }

    public function getAndConditionsForRefine($andCondition)
    {
        $preparedCondition = [];
        $conditions = ['==', '!=', '!%LIKE%', '%LIKE%', '>=', '<=', '>', '<'];
        $andCondition = trim($andCondition);

        foreach ($conditions as $key => $value) {
            if (strpos($andCondition, $value) !== false) {
                $keyValue = explode($value, $andCondition);
                $isNumeric = false;

                $valueOfProduct = trim(addslashes($keyValue[1]));
                foreach ($this->customRegexChar as $val) {
                    $trimmedValueOfProduct = str_replace($val, "\\" . $val, $valueOfProduct);
                    $valueOfProduct = $trimmedValueOfProduct;
                }

                if (trim($keyValue[0]) == 'collections') {
                    $productIds = $this->di->getObjectManager()->get('\App\Shopifyhome\Models\SourceModel')->getProductIdsByCollection((string) $valueOfProduct);
                    if ($productIds && count($productIds)) {
                        if ($value == '==' || $value == '%LIKE%') {
                            $preparedCondition['source_product_id'] = [
                                '$in' => $productIds,
                            ];
                        } elseif (
                            $value == '!=' ||
                            $value == '!%LIKE%'
                        ) {
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
                            if (trim($keyValue[0]) == 'variant_attributes' || trim($keyValue[0]) == 'variant_attribute') {
                                $this->formatCondForVariantAttr($preparedCondition, 'variant_attributes', $valueOfProduct);
                            } else if (trim($keyValue[0]) == 'price' || trim($keyValue[0]) == 'quantity') {
                                $valueOfProduct = $this->checkIntegerCases($valueOfProduct);
                                $this->createRefineItemsCond($preparedCondition, $valueOfProduct, $keyValue);
                            } else {
                                $key = trim($keyValue[0]);
                                $keyArr = explode("_", $key);
                                if ($keyArr[0] == 'multiselect') {
                                    $arr = [];
                                    unset($keyArr[0]);
                                    $keyVal = implode('_', $keyArr);
                                    $var = explode(",", $valueOfProduct);
                                    foreach ($var as $v) {
                                        $regex = [
                                            '$regex' => '^' . $v . '$',
                                            '$options' => 'i',
                                        ];
                                        $arr[] = [
                                            'items.' . $keyVal => $regex
                                        ];
                                        $arr[] = [
                                            $keyVal => $regex
                                        ];
                                    }

                                    $preparedCondition['$or'] = $arr;
                                } else {
                                    if ($key == 'status' && $valueOfProduct == 'Available for Offer') {
                                        $valueOfProduct = 'Not Listed: Offer';
                                    }

                                    if ($key == 'status' && $valueOfProduct == 'Not_listed') {
                                        $preparedCondition['$or'] = [
                                            [
                                                'items.status' => ['$exists' => false]
                                            ],
                                            [
                                                'items.status' => 'Not Listed'
                                            ]
                                        ];
                                    } elseif ($key == 'barcode_exists') {
                                        if ($valueOfProduct == 'true') {
                                            $preparedCondition['$and'] = [
                                                [
                                                    'items.barcode' => [
                                                        '$exists' => true,
                                                        '$ne' => null
                                                    ]
                                                ],
                                                [ 'items.barcode' => [ '$ne' => "" ] ],
                                            ];
                                        } else {
                                            $preparedCondition['$or'] = [
                                                [
                                                    'items.barcode' => ['$exists' => false]
                                                ],
                                                [
                                                    'items.barcode' => ['$eq' => null]
                                                ],
                                                [
                                                    'items.barcode' => ""

                                                ],
                                            ];
                                        }
                                    }else {
                                        $regex = [
                                            '$regex' => '^' . $valueOfProduct . '$',
                                            '$options' => 'i',
                                        ];
                                        $this->createRefineItemsCond($preparedCondition, $regex, $keyValue);
                                    }
                                }
                            }
                        }

                        break;
                    case '!=':
                        if (trim($keyValue[0]) == 'price' || trim($keyValue[0]) == 'quantity') {
                            $valueOfProduct = $this->checkIntegerCases($valueOfProduct);
                            $this->createRefineItemsCond($preparedCondition, [
                                '$ne' => $valueOfProduct,
                            ], $keyValue);
                        } else {
                            $key = trim($keyValue[0]);
                            $keyArr = explode("_", $key);
                            if ($keyArr[0] == 'multiselect') {
                                $arr = [];
                                $narr = [];
                                unset($keyArr[0]);
                                $keyVal = implode('_', $keyArr);
                                $var = explode(",", $valueOfProduct);
                                foreach ($var as $v) {
                                    $regex = [
                                        '$not' => [
                                            '$regex' => '^' . $v . '$',
                                            '$options' => 'i',
                                        ],
                                    ];
                                    $arr[] =
                                        [
                                            'items.' . $keyVal => $regex
                                        ];
                                    $arr[] =
                                        [
                                            $keyVal => $regex
                                        ];
                                }

                                $preparedCondition['$and'] = $arr;
                            } else {
                                $this->createRefineItemsCond($preparedCondition, [
                                    '$not' => [
                                        '$regex' => '^' . $valueOfProduct . '$',
                                        '$options' => 'i',
                                    ],
                                ], $keyValue);
                            }
                        }

                        break;
                    case '%LIKE%':
                        if (trim($keyValue[0]) == 'variant_attributes') {
                            $this->formatCondForVariantAttr($preparedCondition, trim($keyValue[0]), $valueOfProduct);
                        } else {
                            $key = trim($keyValue[0]);
                            $keyArr = explode("_", $key);
                            if ($keyArr[0] == 'multiselect') {
                                $arr = [];

                                unset($keyArr[0]);
                                $keyVal = implode('_', $keyArr);
                                $var = explode(",", $valueOfProduct);
                                foreach ($var as $v) {
                                    $regex = [
                                        '$regex' => '.*' . $v . '.*',
                                        '$options' => 'i',
                                    ];
                                    $arr[] =
                                        [
                                            'items.' . $keyVal => $regex
                                        ];
                                }

                                $preparedCondition['$or'] = $arr;
                            } else {
                                $this->createRefineItemsCond($preparedCondition,  [
                                    '$regex' => ".*" . $valueOfProduct . ".*",
                                    '$options' => 'i',
                                ], $keyValue);
                            }
                        }

                        break;
                    case '!%LIKE%':
                        $key = trim($keyValue[0]);
                        $keyArr = explode("_", $key);
                        if ($keyArr[0] == 'multiselect') {
                            $arr = [];

                            unset($keyArr[0]);
                            $keyVal = implode('_', $keyArr);
                            $var = explode(",", $valueOfProduct);
                            foreach ($var as $v) {
                                $regex = [
                                    '$regex' => '^((?!' . $v . ').)*$',
                                    '$options' => 'i',
                                ];
                                $arr[] =
                                    [
                                        'items.' . $keyVal => $regex
                                    ];
                            }

                            $preparedCondition['$and'] = $arr;
                        } else {
                            $this->createRefineItemsCond($preparedCondition, [
                                '$regex' => "^((?!" . $valueOfProduct . ").)*$",
                                '$options' => 'i',
                            ], $keyValue);
                        }

                        break;
                    case '>':
                        $valueOfProduct = $this->checkIntegerCases($valueOfProduct);
                        $key = gettype($valueOfProduct) == 'string' ? '$eq' : '$gt';
                        $this->createRefineItemsCond($preparedCondition, [
                            $key   => $valueOfProduct,
                        ], $keyValue);
                        break;
                    case '<':
                        $valueOfProduct = $this->checkIntegerCases($valueOfProduct);
                        $key = gettype($valueOfProduct) == 'string' ? '$eq' : '$lt';
                        $this->createRefineItemsCond($preparedCondition, [
                            $key => $this->checkIntegerCases($valueOfProduct),
                        ], $keyValue);
                        break;
                    case '>=':
                        $valueOfProduct = $this->checkIntegerCases($valueOfProduct);
                        $key = gettype($valueOfProduct) == 'string' ? '$eq' : '$gte';
                        $this->createRefineItemsCond($preparedCondition, [
                            $key  => $this->checkIntegerCases($valueOfProduct),
                        ], $keyValue);
                        break;
                    case '<=':
                        $valueOfProduct = $this->checkIntegerCases($valueOfProduct);
                        $key = gettype($valueOfProduct) == 'string' ? '$eq' : '$lte';
                        $this->createRefineItemsCond($preparedCondition, [
                            $key  => $this->checkIntegerCases($valueOfProduct),
                        ], $keyValue);
                        break;
                }

                break;
            }
        }

        return $preparedCondition;
    }

    public function formatCondForVariantAttr(&$preparedCondition, $keyValue, $valueOfProduct): void
    {
        $var = explode(",", $valueOfProduct);
        $i = 0;
        foreach ($var as $key => $value) {
            $createIndex = $keyValue . ".{$key}";
            $preparedCondition[$createIndex] = trim($value);
            $i++;
        }

        $preparedCondition[$keyValue] = ['$size' => $i];
    }

    public function createRefineItemsCond(&$preparedCondition, $regex, $keyValue): void
    {
        $allowed_filters = $this->di->getConfig()->allowed_filters ? $this->di->getConfig()->allowed_filters->toArray() : [];
        $key = trim($keyValue[0], " ");
        $preparedCondition[in_array($key, $allowed_filters) ? 'items.' . $key :  $key] = $regex;
    }
}
