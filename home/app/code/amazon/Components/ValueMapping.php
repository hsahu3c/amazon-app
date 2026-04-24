<?php

namespace App\Amazon\Components;

use MongoDB\BSON\ObjectId;
use Exception;
use App\Amazon\Components\Template\Category;
use App\Core\Components\Base as Base;
use App\Amazon\Components\Common\Helper;

class ValueMapping extends Base
{
    public const RESTRICTED_VALUE_MAPPING_FIELDS = ['title', 'description', 'handle', 'type', 'sku', 'barcode', 'tags', 'price', 'quantity', 'brand'];

    public function getContainerId($params, $SourceVal)
    {
        try {
            $sourceCoreAttributes = ['weight', 'weight_unit', 'grams', 'product_type'];
            $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(Helper::PRODUCT_CONTAINER);
            $containerIds = $collection->distinct('container_id', ['user_id' => $params['user_id'], 'profile.profile_id' => new ObjectId($params['id'])]);
            #for variant_title of child products

            if ($SourceVal == 'variant_title') {
                $sourceValues = $collection->distinct($SourceVal, ['user_id' => $params['user_id'], 'container_id' => ['$in' => $containerIds], '$and' => [["visibility" => "Not Visible Individually"], ["type" => "simple"]]]);
            } elseif (in_array($SourceVal, $sourceCoreAttributes)) {
                $sourceValues = $collection->distinct($SourceVal, ['user_id' => $params['user_id'], 'container_id' => ['$in' => $containerIds]]);
            } else {
                $sourceValues = $collection->aggregate([
                    [
                        '$match' => [
                            'user_id' => $params['user_id'],
                            'container_id' => ['$in' => $containerIds],
                            '$and' => [["visibility" => "Not Visible Individually"], ["type" => "simple"]]
                        ]
                    ],
                    [
                        '$unwind' => '$variant_attributes'
                    ],
                    [
                        '$match' => [
                            "variant_attributes.key" => $SourceVal
                        ]
                    ],
                    [
                        '$group' => [
                            '_id' => '$variant_attributes.value'
                        ]
                    ]
                ])->toArray();
                $sourceValues = json_decode(json_encode($sourceValues), true);
                $sourceValues = array_column($sourceValues, '_id');
            }

            // check and return non empty values between $sourceValue and $sourceValue
            if (!empty($sourceValues)) {
                $sourceValues = array_map(fn($value): string => "{$value}", $sourceValues);
                #check non-empty string/value and return only non-empty value
                $sourceValues = array_filter($sourceValues, fn($value): bool => !empty($value));
                return array_unique($sourceValues);
            }

            return [];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function valueMapping($rawBody)
    {
        try {
            #Core Source Attributes have to be restricted for Value Mapping
            // $restrict_source_attributes = ['title', 'description', 'handle', 'type', 'sku', 'barcode', 'tags', 'price', 'quantity', 'brand'];

            $params['target_marketplace'] = $rawBody['target']['marketplace'];
            $params['id'] = $rawBody['profile_id'];
            if (isset($rawBody['user_id'])) {
                $userId = $rawBody['user_id'];
            } else {
                $userId = $this->di->getUser()->id;
            }

            $source = $rawBody['source']['marketplace'];
            $params['user_id'] = $userId;

            $profileData = $this->di->getObjectManager()->get('\App\Connector\Components\Profile\ProfileHelper')->getProfileData($params);
            $profileData = json_decode(json_encode($profileData), true);
            $valueMappingProducts = $profileData['data'][0] ?? ($profileData['data'] ?? []);
            if (empty($valueMappingProducts)) {
                $this->deleteValueMappingData($userId, $rawBody['profile_id']);
                return ['success' => false, 'message' => 'No data found for profile!'];
            }

            if (!empty($valueMappingProducts['category_id']['product_type'])) {
                //to handle json value mapping
                return $this->di->getObjectManager()->get(\App\Amazon\Components\Listings\ValueMapping::class)->jsonValueMapping($valueMappingProducts, $rawBody);
            }

            if (
                empty($valueMappingProducts['manual_product_ids']) && empty($valueMappingProducts['query']) &&
                empty($valueMappingProducts['attributes_mapping']['_id']['$oid'])
            ) {
                // products are not assgined or removed in updated-template, check if value_mapping is assigned true
                $this->deleteValueMappingData($userId, $rawBody['profile_id']);
                return ['success' => false, 'response' => 'Products are not assigned in template.'];
            }

            $profileCollection = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->getCollectionForTable(Helper::PROFILE);

            $params2 = [];
            $params2['source_marketplace'] = $rawBody['source']['marketplace'];
            $params2['target_marketplace'] = $rawBody['target']['marketplace'];
            $params2['target']['shopId'] = $rawBody['target']['shopId'];
            $params2['source']['shopId'] = $rawBody['source']['shopId'];
            $params2['data']['barcode_exemption'] = $valueMappingProducts['category_id']['barcode_exemption'] ?? '';
            $params2['data']['browser_node_id'] = $valueMappingProducts['category_id']['browser_node_id'] ?? '';
            $params2['data']['category'] = $valueMappingProducts['category_id']['primary_category'] ?? '';
            $params2['data']['sub_category'] = $valueMappingProducts['category_id']['sub_category'] ?? '';
            $params2['user_id'] = $userId;
            #category Attribute call
            $data = $this->di->getObjectManager()->get(Category::class)->init($params2)->getAmazonAttributes($params2);

            // create amazon recommendations with amazon attributes as key and recommendation as value in array
            $amazonRecommendations = [];
            $arrayRecommendation = [];
            if (!empty($data) && isset($data['data'])) {
                foreach ($data['data'] as $dataAttributes) {
                    foreach ($dataAttributes as $dataAttributeskey => $dataAttributesvalue) {
                        if ($dataAttributeskey != 'variation_theme' && !empty($dataAttributesvalue['amazon_recommendation'])) {
                            $arrayRecommendation = [
                                $dataAttributeskey => array_values($dataAttributesvalue['amazon_recommendation'])
                            ];
                            $amazonRecommendations = array_merge($amazonRecommendations, $arrayRecommendation);
                            // $amazonRecommendation =$dataAttributesvalue['amazon_recommendation'];
                        }
                    }
                }
            }

            //if no amazon recommendation found
            if (empty($amazonRecommendations)) {
                $profileCollection->updateOne(['_id' => new ObjectId($params['id']), 'user_id' => $userId, 'type' => 'profile'], ['$set' => ['value_mapping' => false]]);
                return ['success' => false, 'message' => 'No Amazon Recommendations are present for this category', 'data' => []];
            }

            if ((isset($valueMappingProducts['attributes_mapping']['required_attribute']) && !empty($valueMappingProducts['attributes_mapping']['required_attribute'])) && (isset($valueMappingProducts['attributes_mapping']['optional_attribute']) && !empty($valueMappingProducts['attributes_mapping']['optional_attribute']))) {
                $valueMappingProducts['attributes_mapping']['required_attribute'] = array_merge($valueMappingProducts['attributes_mapping']['required_attribute'], $valueMappingProducts['attributes_mapping']['optional_attribute']);
            }

            #Required Attribute
            $countOfSourceAttribute = 0;
            $valueMappingArray['value_mapping']['required_attribute'] = [];
            foreach ($valueMappingProducts['attributes_mapping']['required_attribute'] as $attributesValue) {
                #Check if 'source_select' is 'Search' and 'source_attribute' is not Core Source Attribute
                if (isset($attributesValue[$source . '_select']) && $attributesValue[$source . '_select'] == 'Search' && !in_array($attributesValue[$source . '_attribute'], self::RESTRICTED_VALUE_MAPPING_FIELDS) && in_array($attributesValue['amazon_attribute'], array_keys($amazonRecommendations))) {
                    $sourceValues = $this->getContainerId($params, $attributesValue[$source . '_attribute']);
                    if (!empty($sourceValues)) {
                        // $amazonRecommendations[$attributesValue['amazon_attribute']] = array_diff($amazonRecommendations[$attributesValue['amazon_attribute']], $sourceValue);
                        array_push($valueMappingArray['value_mapping']['required_attribute'], ['amazon_attribute' => $attributesValue['amazon_attribute'], $source . '_attribute' => $attributesValue[$source . '_attribute'], 'amazon_recommendation' => array_values($amazonRecommendations[$attributesValue['amazon_attribute']]), $source . '_value' => array_values($sourceValues), 'CountofSourceValues' => count($sourceValues)]);
                        $countOfSourceAttribute++;
                    }
                }
            }

            $valueMappingArray['value_mapping']['_id'] = $valueMappingProducts['attributes_mapping']['_id']['$oid'] ?? '';
            $valueMappingArray['value_mapping']['countofAllAttributes'] = $countOfSourceAttribute;
            if (!empty($valueMappingArray['value_mapping']['required_attribute'])) {
                $profileCollection->updateOne(['_id' => new ObjectId($params['id']), 'user_id' => $userId, 'type' => 'profile'], ['$set' => ['value_mapping' => true]]);
                $this->saveValueMapping($rawBody, $userId, $valueMappingArray);
                $this->compareFromProfile($rawBody, $userId, $valueMappingArray);
                return ['success' => true, 'message' => 'Attribute Fetched Successfully', 'count' => $countOfSourceAttribute];
            }
            $profileCollection->updateOne(
                ['_id' => new ObjectId($params['id']), 'user_id' => $userId, 'type' => 'profile'],
                ['$set' => ['value_mapping' => false]]
            );
            $this->saveValueMapping($rawBody, $userId, $valueMappingArray);
            $this->compareFromProfile($rawBody, $userId, $valueMappingArray);
            return ['success' => false, 'message' => 'No Amazon Recommendations are present for mapped attributes.', 'count' => $countOfSourceAttribute];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteValueMappingData($userId, $profileId): void
    {
        $attributeContainer = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->getCollectionForTable('amazon_value_mapping');
        $attributeContainer->deleteMany(['user_id' => $userId, 'profile_id' => (string)$profileId]);
    }

    public function saveValueMapping($params, $userId, $data)
    {
        try {
            $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(Helper::Value_Mapping);
            $res = $collection->findOne(["user_id" => $userId, "profile_id" => $params["profile_id"]]);
            if (!empty($res)) {
                $collection->updateOne(["user_id" => $userId, "profile_id" => $params["profile_id"]], ['$set' => ["valueMapping" => $data['value_mapping']]]);
            } else {
                $collection->insertOne(["user_id" => $userId, "profile_id" => $params["profile_id"], 'source' => $params['source'], 'target' => $params['target'], "valueMapping" => $data['value_mapping']]);
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getAllAttributesofValueMappingCount($data)
    {
        try {
            $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(Helper::Value_Mapping);
            $data = $collection->findOne(
                ["user_id" => $data['user_id'], "profile_id" => $data['profile_id']],
                ['projection' => ['valueMapping.countofAllAttributes' => 1, '_id' => 0], 'typeMap' => ['root' => 'array', 'document' => 'array']]
            );
            return ['success' => true, 'message' => 'Count of All mapping attributes.', 'data' => $data['valueMapping']['countofAllAttributes']];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getAllAttributesofValueMapping($data)
    {
        try {
            $source = $data['source_marketplace'] ?? $this->di->getRequester()->getSourceName();
            $page = $data['activePage'] ?? 1;
            $limit = $data['limit'] ?? 4;
            $skip = ($page - 1) * $limit;
            $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(Helper::Value_Mapping);
            $data = $collection->findOne(
                ["user_id" => $data['user_id'], "profile_id" => $data['profile_id']],
                ['projection' => ['valueMapping' => 1, '_id' => 0], 'typeMap' => ['root' => 'array', 'document' => 'array']]
            );
            $getData = array_slice($data['valueMapping']['required_attribute'], $skip, $limit);
            $getAttributes['Attributes'] = [];
            foreach ($getData as $attribute) {
                array_push($getAttributes['Attributes'], ['amazon_attribute' => $attribute['amazon_attribute'], $source . '_attribute' => $attribute[$source . '_attribute']]);
            }

            return ['success' => true, 'message' => 'Get data for mapping attributes.', 'data' => $getAttributes];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getTotalCountSourceValuesofAttribute($data)
    {
        try {
            $source = $data['source_marketplace'] ?? $this->di->getRequester()->getSourceName();
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::Value_Mapping);
            $getdata = $collection->findOne(
                ["user_id" => $data['user_id'], "profile_id" => $data['profile_id']],
                [
                    'projection' => ['valueMapping.required_attribute' => 1, '_id' => 0],
                    'typeMap' => ['root' => 'array', 'document' => 'array']
                ]
            );
            $getdata = json_decode(json_encode($getdata), true);
            if (isset($data['data[search]'])) {
                $specialChars = "?/\*^+()[]$";
                $data['data[search]'] = addcslashes((string) $data['data[search]'], $specialChars);
            }

            foreach ($getdata['valueMapping']['required_attribute'] as $attribute) {
                if ($attribute['amazon_attribute'] == $data["data[amazon_attribute]"] && $attribute[$source . '_attribute'] == $data["data[" . $source . "_attribute]"]) {
                    if (isset($data['data[search]']) && !empty($data['data[search]'])) {
                        $result = preg_grep('/' . $data['data[search]'] . '/i', $attribute[$source . '_value']);
                        if (count($result) > 0) {

                            return ['success' => true, 'message' => 'Total count of source values.', 'data' => count($result)];
                        }
                        return ['success' => false, 'message' => 'No source values for mapping', 'data' => count($result)];
                    }
                    if ($attribute['CountofSourceValues'] > 0) {
                        return ['success' => true, 'message' => 'Total count of source values.', 'data' => $attribute['CountofSourceValues']];
                    }
                    return ['success' => false, 'message' => 'No source values for mapping', 'data' => $attribute['CountofSourceValues']];
                }
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getSourceValuesofAttribute($data)
    {
        try {
            $source = $data['source_marketplace'] ?? $this->di->getRequester()->getSourceName();
            $page = $data['data[activePage]'] ?? 1;
            $limit = $data['data[limit]'] ?? 5;
            $skip = ($page - 1) * $limit;
            $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable(Helper::Value_Mapping);
            $getdata = $collection->findOne(
                ["user_id" => $data['user_id'], "profile_id" => $data['profile_id']],
                [
                    'projection' => ['valueMapping.required_attribute' => 1, '_id' => 0],
                    'typeMap' => ['root' => 'array', 'document' => 'array']
                ]
            );
            $getdata = json_decode(json_encode($getdata), true);
            if (isset($data['data[search]'])) {
                $specialChars = "?/\*^+()[]$";
                $data['data[search]'] = addcslashes((string) $data['data[search]'], $specialChars);
            }

            foreach ($getdata['valueMapping']['required_attribute'] as $attribute) {
                if ($attribute['amazon_attribute'] == $data["data[amazon_attribute]"] && $attribute[$source . '_attribute'] == $data["data[" . $source . "_attribute]"]) {
                    if (isset($data['data[search]']) && !empty($data['data[search]'])) {
                        $attribute[$source . '_value'] = preg_grep('/' . $data['data[search]'] . '/i', $attribute[$source . '_value']);
                    }

                    $SourceValue[$source . '_values'] = array_slice($attribute[$source . '_value'], $skip, $limit);
                    $SourceValue['amazon_recommendation'] = $attribute['amazon_recommendation'];
                    if (!empty($SourceValue[$source . '_values'])) {
                        return ['success' => true, 'message' => 'Get source values for mapping.', 'data' => $SourceValue];
                    }
                    return ['success' => false, 'message' => 'No source values for mapping.', 'data' => $SourceValue];
                }
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function valueMappingSaveInProfile($data)
    {
        try {
            $userId = $this->di->getUser()->id;
            $source = $data['source_marketplace'] ?? $this->di->getRequester()->getSourceName();
            $profileCollection = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->getCollectionForTable(Helper::PROFILE);
            $res = $profileCollection->findOne(['_id' => new ObjectId($data['_id']['$oid']), 'user_id' => $userId, 'type' => 'attribute'], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
            // $res = json_decode(json_encode($res), true);
            $previous_mapped_values = [];
            if (!empty($res['data']['value_mapping'])) {
                $previous_mapped_values = $res['data']['value_mapping'];
            }

            $new_mapping_attributes = $data['data'];
            if (isset($previous_mapped_values[$data['amazon_attribute']][$data[$source . '_attribute']])) {
                foreach ($new_mapping_attributes as $key => $value) {
                    // $SourceValue = $key;
                    // $amazonValue = $value;
                    $previous_mapped_values[$data['amazon_attribute']][$data[$source . '_attribute']][$key] = $value;
                }
            } else {
                $previous_mapped_values[$data['amazon_attribute']][$data[$source . '_attribute']] = $data['data'];
            }

            $params['data']['_id']['$oid'] =  $data['_id']['$oid'] ?? '';
            $params['data']['type'] = 'attribute';
            $params['data']['data.value_mapping'] = $previous_mapped_values;
            $this->di->getObjectManager()->get('\App\Connector\Components\Profile\PartialChunkHelper')->savePartialChunk($params);
            return ['success' => true, 'message' => 'Mapped Attributes Save Successfully.', 'data' => $previous_mapped_values];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function compareFromProfile($params,$userId, $data){
        try{
            $source = $params['source']['marketplace'];
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(Helper::PROFILE);
            $saveValueMapping = $collection->findOne(
                ["user_id"=>$userId, "profile_id"=>new ObjectId($params['profile_id']), "type"=>"attribute"],
                ['projection' => ['data.value_mapping'=>1,"value_mapping"=>'$data.value_mapping', '_id'=>0] , 'typeMap' => ['root' => 'array', 'document' => 'array']]
            );
            $saveValueMapping = $saveValueMapping['data'];
            $data = $data['value_mapping']['required_attribute'];
            if(isset($saveValueMapping['value_mapping'])){
                foreach($data as $attribute){
                    if(isset($saveValueMapping['value_mapping'][$attribute['amazon_attribute']][$attribute[$source.'_attribute']])){
                        $path = $saveValueMapping['value_mapping'][$attribute['amazon_attribute']][$attribute[$source.'_attribute']];
                        foreach($path as $prevAttrbuteKey =>$prevAttributeVal){
                            if(!in_array($prevAttrbuteKey,$attribute[$source.'_value'])){
                                $saveValueMapping['value_mapping'][$attribute['amazon_attribute']][$attribute[$source.'_attribute']][$prevAttrbuteKey]= '';
                            }
                        }

                        $saveValueMapping['value_mapping'][$attribute['amazon_attribute']]['found'] = true;
                    }
                }

                foreach($saveValueMapping['value_mapping'] as $amazon_attribute => $Source_attribute){
                    if(!isset($Source_attribute['found'])){
                        unset($saveValueMapping['value_mapping'][$amazon_attribute]);
                    }
                    else{
                        unset($saveValueMapping['value_mapping'][$amazon_attribute]['found']);
                    }
                }

                $result = $collection->updateOne(
                    ["user_id"=>$userId, "profile_id"=>new ObjectId($params['profile_id']), "type"=>"attribute"],
                    ['$set'=>["data.value_mapping"=>$saveValueMapping['value_mapping']]]);

                return $result;
            }
            return false;
        }
        catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}