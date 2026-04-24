<?php

namespace App\Connector\Models\Profile;

use App\Core\Models\BaseMongo;

class GetQueryConverted extends BaseMongo
{

    public function extractRuleFromProfileData($profileData, $type = 'find')
    {
        $userId = $this->di->getUser()->id;

        $finalQuery = [];
        $mainQuery = $this->convertQueryFromMysqlToMongo($profileData['query']);
        $finalQuery['$and'][] = $mainQuery;
        if ($finalQuery !== []) {
            $finalQuery['$and'][] = ['user_id' => $userId];
            if (isset($data['overWriteExistingProducts']) && !$profileData['overWriteExistingProducts']) {
                $finalQuery['$and'][] = ['profile_id' => ['$exists' => false]];

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

        foreach ($conditions as $value) {
            if (strpos($andCondition, $value) !== false) {
                $keyValue = explode($value, $andCondition);
                $isNumeric = false;
                $valueOfProduct = trim(addslashes($keyValue[1]));
                if (trim($keyValue[0]) == 'collections') {
                    $productIds = $this->di->getObjectManager()->get('\App\Shopify\Models\SourceModel')->getProductIdsByCollection($valueOfProduct);
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
                            } else {
                                $preparedCondition[trim($keyValue[0])] = [
                                    '$regex' => '^' . $valueOfProduct . '$',
                                    '$options' => 'i',
                                ];
                            }
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
                        if (trim($keyValue[0]) == 'variant_attributes') {
                            $this->formatCondForVariantAttr($preparedCondition, trim($keyValue[0]), $valueOfProduct);
                        } else {
                            $preparedCondition[trim($keyValue[0])] = [
                                '$regex' => ".*" . $valueOfProduct . ".*",
                                '$options' => 'i',
                            ];
                        }

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

    public function formatCondForVariantAttr(&$preparedCondition, $keyValue, $valueOfProduct): void
    {
        $var = explode(",", $valueOfProduct);
        $i = 0;
        foreach ($var as $key => $value) {
            $createIndex = $keyValue . ".{$key}.key";
            $preparedCondition[$createIndex] = trim($value);
            $i++;
        }
        $preparedCondition[$keyValue] = ['$size' => $i];
    }
}
