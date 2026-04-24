<?php

namespace App\Connector\Components;

use Phalcon\Logger\Logger;

class Suggestor extends \App\Core\Components\Base
{

    public function getSuggestion($attributes, $userId = false, $source = false, $target = 'connector')
    {
        $systemMappings = $this->getSystemMappings($source, $target); /* getSystemMappings return all the source                                                                     attributes in key(target-attribute) =>                                                                      value(source-attribute) format whose                                                                        isSystem is true */
        $customerMappings = $this->getCustomerMappings($source, $target, $userId); /* getCustomerMappings return all     the source attributes in key(target-attribute) => value(source-attribute) format which has been mapped by that customer before */
        $suggestions = [];
        $systemAttrOrCustomerAttr = [];
        /* Saving suggestions for system attributes */
        foreach ($systemMappings as $targetAttr => $sourceAttr) {
            if (array_search($sourceAttr, $attributes) !== false) {
                $suggestions[] = [
                    'source_attr' => $sourceAttr,
                    'base_attr' => [
                        0 => [
                            'id' => $targetAttr,
                            'text' => $targetAttr
                        ]
                    ],
                    'isSystem' => true
                ];
                $systemAttrOrCustomerAttr[$sourceAttr] = '';
            }
        }

        /* Saving suggestions for customer mapped attributes */
        foreach ($customerMappings as $targetAttr => $sourceAttr) {
            if (array_search($sourceAttr, $attributes) !== false) {
                if (!isset($systemAttrOrCustomerAttr[$sourceAttr])) {
                    $suggestions[] = [
                        'source_attr' => $sourceAttr,
                        'base_attr' => [
                            0 => [
                                'id' => $targetAttr,
                                'text' => $targetAttr
                            ]
                        ],
                        'isSystem' => false
                    ];
                    $systemAttrOrCustomerAttr[$sourceAttr] = '';
                }
            }
        }

        /* Saving rest of the mapping suggestions */
        foreach ($attributes as $attr) {
            if (!isset($systemAttrOrCustomerAttr[$attr])) {
                $attrMappingModel = \App\Connector\Models\AttributeMapping::find(
                    [
                        "product_source='{$source}' AND target='{$target}' AND source_attr='{$attr}'",
                        'order' => 'occurence DESC',
                        'column' => 'target_attr, occurence'
                    ]
                )->toArray();
                if (isset($attrMappingModel[0])) {
                    $suggestedAttr = $attrMappingModel[0]['target_attr'];
                    unset($attrMappingModel[0]);
                } else {
                    $suggestedAttr = '';
                }

                $suggestions[] = [
                    'source_attr' => $attr,
                    'base_attr' => [
                        0 => [
                            'id' => $suggestedAttr,
                            'text' => $suggestedAttr
                        ]
                    ],
                    'isSystem' => false,
                    'matching_suggestions' => $attrMappingModel
                ];
            }
        }

        return $suggestions;
    }

    public function getUploadMappingSuggestions($userId = false, $targetAttrs = [], $sourceAttrs = [], $target = '')
    {
        $mappingSuggestions = [
            'basic_attributes' => [],
            'required_attributes' => [],
            'optional_attributes' => []
        ];
        foreach ($targetAttrs as $key => $targetAttrValues) {
            switch ($key) {
                case 'basic_attributes':
                    foreach ($targetAttrValues as $targetAttribute) {
                        $suggestion = $this->getTopMappingSuggestionForUpload($targetAttribute['code'], $sourceAttrs, 'connector', $target);
                        if ($suggestion) {
                            $mappingSuggestions['basic_attributes'][] = [
                                'source_attribute' => $suggestion,
                                'target_attribute' => $targetAttribute
                            ];
                        }
                    }

                    break;

                case 'required_attributes':
                    foreach ($targetAttrValues as $targetAttribute) {
                        $suggestion = $this->getTopMappingSuggestionForUpload($targetAttribute['code'], $sourceAttrs, 'connector', $target);
                        if ($suggestion) {
                            $mappingSuggestions['required_attributes'][] = [
                                'source_attribute' => $suggestion,
                                'target_attribute' => $targetAttribute
                            ];
                        }
                    }

                    break;

                case 'optional_attributes':
                    foreach ($targetAttrValues as $targetAttribute) {
                        $suggestion = $this->getTopMappingSuggestionForUpload($targetAttribute['code'], $sourceAttrs, 'connector', $target);
                        if ($suggestion) {
                            $mappingSuggestions['optional_attributes'][] = [
                                'source_attribute' => $suggestion,
                                'target_attribute' => $targetAttribute
                            ];
                        }
                    }

                    break;
            }
        }

        return $mappingSuggestions;
    }

    public function getTopMappingSuggestionForUpload($targetAttr, $sourceAttrs, $source = '', $target = '')
    {
        $sourceAttributes = [];
        foreach ($sourceAttrs as $value) {
            $sourceAttributes[] = $value['code'];
        }

        $getMappingWithTopOccurrence = \App\Connector\Models\AttributeMapping::find(
            [
                "target_attr='{$targetAttr}' AND product_source='{$source}' AND target='{$target}' AND source_attr IN  ({source_attrs:array})",
                'bind' => [
                    'source_attrs' => $sourceAttributes
                ],
                'order' => 'occurence DESC'
            ]
        );
        if ($getMappingWithTopOccurrence && count($getMappingWithTopOccurrence)) {
            $getMappingWithTopOccurrence = $getMappingWithTopOccurrence->toArray();
            $sourceAttrIndex = array_search($getMappingWithTopOccurrence[0]['source_attr'], $sourceAttributes);
            return $sourceAttrs[$sourceAttrIndex];
        }

        return false;
    }

    public function getValueMappingSuggestion($source = false, $target = 'connector', $sourceAttr = '', $targetAttr = '', $targetAttrOption = '')
    {
        $checkForSuggestion = \App\Connector\Models\ValueMapping::find(["product_source = '{$source}' AND target = '{$target}' AND source_attr = '{$sourceAttr}' AND target_attr = '{$targetAttr}' AND target_attr_option = '{$targetAttrOption}'", 'order' => 'occurence DESC']);
        if ($checkForSuggestion) {
            $checkForSuggestion = $checkForSuggestion->toArray();
            return $checkForSuggestion[0]['source_attr_option'];
        }

        return false;
    }

    public function submitMapping($mappedData, $userId = false, $source = false, $target = 'connector')
    {
        $this->checkUserEntryExist($source, $target, $userId); /* It is done to update the no. of mappings done between this source and target (user-wise)  */
        $systemMappings = $this->getSystemMappings($source, $target); /* getSystemMappings return all the source                                                                     attributes in key(target-attribute) =>                                                                      value(source-attribute) format whose                                                                        isSystem is true */
        $customerMappings = $this->getCustomerMappings($source, $target, $userId);
        foreach ($mappedData as $sourceAttr => $targetAttributes) {
            foreach ($targetAttributes as $targetAttribute) {
                /* This is handling of value mapping */
                if (is_array($targetAttribute)) {
                    $targetAttribute = $targetAttribute['base_attr_value'];
                }

                /* Skipping system mappings to get submitted */
                if (isset($systemMappings[$targetAttribute])) {
                    continue;
                }

                /* Checking if the new mapping is already done by this customer or not */
                if (isset($customerMappings[$targetAttribute])) {
                    /* If the mapping is already done then we just update the hits */
                    if ($customerMappings[$targetAttribute] != $sourceAttr) {
                        $updateHit = \App\Connector\Models\AttributeMapping::findFirst(["product_source='{$source}' AND target='{$target}' AND target_attr='{$targetAttribute}' AND source_attr='{$customerMappings[$targetAttribute]}'"]);
                        $this->updateHitsOccurence($updateHit->hits - 1, $updateHit);
                        $this->createNewMapping($source, $target, $sourceAttr, $targetAttribute);
                        $updateCustomerSource = \App\Connector\Models\CustomerAttributeMapping::findFirst(["product_source='{$source}' AND target='{$target}' AND target_attr='{$targetAttribute}' AND source_attr='{$customerMappings[$targetAttribute]}' AND customer_id='{$userId}'"]);
                        $updateCustomerSource->source_attr = $sourceAttr;
                        $updateCustomerSource->save();
                    }
                } else {
                    /* If there is no such mapping before then new mapping is created */
                    $this->createNewMapping($source, $target, $sourceAttr, $targetAttribute);
                    $this->createNewCustomerMapping($source, $target, $sourceAttr, $targetAttribute, $userId);
                }
            }
        }

        return true;
    }

    public function submitUploadMapping($mappingData, $userId = false, $source = 'connector', $target = false): void
    {
        $this->checkUserEntryExist($source, $target, $userId); /* It is done to update the no. of mappings done between this source and target (user-wise)  */
        $systemMappings = $this->getSystemMappings($source, $target); /* getSystemMappings return all the source                                                                     attributes in key(target-attribute) =>                                                                      value(source-attribute) format whose                                                                        isSystem is true */
        $customerMappings = $this->getCustomerMappings($source, $target, $userId); /* getCustomerMappings return all     the source attributes in key(target-attribute) => value(source-attribute) format which has been mapped by that customer before */
        $mappedData = [];
        foreach ($mappingData as $mappings) {
            foreach ($mappings as $singleMap) {
                $mappedData[$singleMap['target_attribute']['code']] = $singleMap['source_attribute']['code'];
            }
        }

        foreach ($mappedData as $targetAttr => $sourceAttr) {
            if (isset($systemMappings[$targetAttr])) {
                continue;
            }

            if (isset($customerMappings[$targetAttr])) {
                /* If the mapping is already done then we just update the hits */
                if ($customerMappings[$targetAttr] != $sourceAttr) {
                    $updateHit = \App\Connector\Models\AttributeMapping::findFirst(["product_source='{$source}' AND target='{$target}' AND target_attr='{$targetAttr}' AND source_attr='{$customerMappings[$targetAttr]}'"]);
                    $this->updateHitsOccurence($updateHit->hits - 1, $updateHit);
                    $this->createNewMapping($source, $target, $sourceAttr, $targetAttr);
                    $updateCustomerSource = \App\Connector\Models\CustomerAttributeMapping::findFirst(["product_source='{$source}' AND target='{$target}' AND target_attr='{$targetAttr}' AND source_attr='{$customerMappings[$targetAttr]}' AND customer_id='{$userId}'"]);
                    $updateCustomerSource->source_attr = $sourceAttr;
                    $updateCustomerSource->save();
                }
            } else {
                /* If there is no such mapping before then new mapping is created */
                $this->createNewMapping($source, $target, $sourceAttr, $targetAttr);
                $this->createNewCustomerMapping($source, $target, $sourceAttr, $targetAttr, $userId);
            }
        }
    }

    public function checkUserEntryExist($source, $target, $userId): void
    {
        $checkEntry = \App\Connector\Models\CustomerAttributeMapping::findFirst(["product_source='{$source}' AND target='{$target}' AND customer_id='{$userId}'"]); /* Checking whether this same user has done mapping between this same source and target before or not */
        if (!$checkEntry) {
            $addMappingEntry = new \App\Connector\Models\TotalMapping();
            $mapEntry = [];
            $mapEntry['product_source'] = $source;
            $mapEntry['target'] = $target;
            $mapEntry['user_id'] = $userId;
            $addMappingEntry->set($mapEntry);
            $addMappingEntry->save();
            $totalCount = $this->getConfigTotalCount($source, $target);
            $this->di->getCoreConfig()->set('mappingcount/' . $source . '/' . $target, $totalCount + 1);
        }
    }

    // This function is just to save the total count of mapping done by users between one source and target
    public function getConfigTotalCount($source, $target)
    {
        $path = 'mappingcount/' . $source . '/' . $target;
        return $this->di->getCoreConfig()->get($path) ? $this->di->getCoreConfig()->get($path) : 0;
    }

    public function createNewMapping($source, $target, $sourceAttr, $targetAttribute): void
    {
        $checkExistenceOfNewMapping = \App\Connector\Models\AttributeMapping::findFirst(["product_source='{$source}' AND target='{$target}' AND target_attr='{$targetAttribute}' AND source_attr='{$sourceAttr}'"]);
        if ($checkExistenceOfNewMapping) {
            $this->updateHitsOccurence($checkExistenceOfNewMapping->hits + 1, $checkExistenceOfNewMapping);
        } else {
            $allPreviousEntries = \App\Connector\Models\AttributeMapping::find(["product_source='{$source}' AND target='{$target}' AND source_attr='{$sourceAttr}'"])->toArray();
            $this->reduceHits($allPreviousEntries);
            $model = new \App\Connector\Models\AttributeMapping();
            $mappingData = [];
            $totalCount = $this->getConfigTotalCount($source, $target);
            if ($totalCount == 0) {
                $totalCount += 1;
            }

            $mappingData['product_source'] = $source;
            $mappingData['target'] = $target;
            $mappingData['source_attr'] = $sourceAttr;
            $mappingData['target_attr'] = $targetAttribute;
            $mappingData['occurence'] = 100 / $totalCount;
            $mappingData['hits'] = 1;
            $model->set($mappingData);
            $model->save();
        }
    }

    public function submitValueMapping($userId = false, $source = '', $target = '', $sourceAttr = '', $targetAttr = '', $sourceAttrOption = '', $targetAttrOption = '')
    {
        $this->checkUserEntryExist($source, $target, $userId);
        $customerMappings = $this->getCustomerValueMappings($source, $target, $sourceAttr, $targetAttr, $sourceAttrOption, $targetAttrOption);
        if (isset($customerMappings[$sourceAttrOption])) {
            $customerValueMappings = \App\Connector\Models\CustomerValueMapping::findFirst(["customer_id = '{$userId}' AND product_source = '{$source}' AND target = '{$target}' AND source_attr = '{$sourceAttr}' AND target_attr = '{$targetAttr}' AND source_attr_option = '{$sourceAttrOption}' AND target_attr_option = '{$targetAttrOption}'"]);
            if (!$customerValueMappings) {
                $this->saveCustomerValueMapping($userId, $source, $target, $sourceAttr, $targetAttr, $sourceAttrOption, $targetAttrOption);
            }
        } else {
            $this->checkReductionOfHits($source, $target, $sourceAttr, $targetAttr, $sourceAttrOption);
            $this->saveCustomerValueMapping($userId, $source, $target, $sourceAttr, $targetAttr, $sourceAttrOption, $targetAttrOption);
        }

        return true;
    }

    /* This function is for reduction of hits all the previous mapping with same source, target, source attribute, target attribute and source attribute option */

    public function checkReductionOfHits($source = '', $target = '', $sourceAttr = '', $targetAttr = '', $sourceAttrOption = ''): void
    {
        $customerValueMappings = \App\Connector\Models\CustomerValueMapping::findFirst(["product_source = '{$source}' AND target = '{$target}' AND source_attr = '{$sourceAttr}' AND target_attr = '{$targetAttr}' AND source_attr_option = '{$sourceAttrOption}'"]);
        if ($customerValueMappings) {
            $valueMappings = \App\Connector\Models\ValueMapping::find(["product_source = '{$source}' AND target = '{$target}' AND source_attr = '{$sourceAttr}' AND target_attr = '{$targetAttr}' AND source_attr_option = '{$sourceAttrOption}'"])->toArray();
            if (!$totalCount) {
                $totalCount += 1;
            }

            foreach ($valueMappings as $value) {
                $valueMapModel = new \App\Connector\Models\ValueMapping();
                $value['hits'] -= 1;
                $value['occurence'] = ($value['hits'] / $totalCount) * 100;
                $valueMapModel->set($value);
                $valueMapModel->save();
            }
        }
    }

    public function getCustomerValueMappings($source = '', $target = '', $sourceAttr = '', $targetAttr = '', $sourceAttrOption = '', $targetAttrOption = '')
    {
        $customerValueMappings = \App\Connector\Models\CustomerValueMapping::find(["product_source = '{$source}' AND target = '{$target}' AND source_attr = '{$sourceAttr}' AND target_attr = '{$targetAttr}' AND source_attr_option = '{$sourceAttrOption}' AND target_attr_option = '{$targetAttrOption}'"]);
        if ($customerValueMappings) {
            $customerMappings = [];
            foreach ($customerValueMappings as $value) {
                $customerMappings[$value->source_attr_option] = $value->target_attr_option;
            }

            return $customerMappings;
        }

        return [];
    }

    public function saveCustomerValueMapping($userId = '', $source = '', $target = '', $sourceAttr = '', $targetAttr = '', $sourceAttrOption = '', $targetAttrOption = ''): void
    {
        $customerValueMappings = new \App\Connector\Models\CustomerValueMapping();
        $customerValueMappings->customer_id = $userId;
        $customerValueMappings->product_source = $source;
        $customerValueMappings->target = $target;
        $customerValueMappings->source_attr = $sourceAttr;
        $customerValueMappings->target_attr = $targetAttr;
        $customerValueMappings->source_attr_option = $sourceAttrOption;
        $customerValueMappings->target_attr_option = $targetAttrOption;
        $customerValueMappings->save();
        $this->saveValueMapping($source, $target, $sourceAttr, $targetAttr, $sourceAttrOption, $targetAttrOption);
    }

    public function saveValueMapping($source = '', $target = '', $sourceAttr = '', $targetAttr = '', $sourceAttrOption = '', $targetAttrOption = ''): void
    {
        $checkValueMapping = \App\Connector\Models\ValueMapping::findFirst(["product_source = '{$source}' AND target = '{$target}' AND source_attr = '{$sourceAttr}' AND target_attr = '{$targetAttr}' AND source_attr_option = '{$sourceAttrOption}' AND target_attr_option = '{$targetAttrOption}'"]);
        $totalCount = $this->getConfigTotalCount($source, $target);
        if (!$totalCount) {
            $totalCount += 1;
        }

        if ($checkValueMapping) {
            $checkValueMapping->hits += 1;
            $checkValueMapping->occurence = ($checkValueMapping->hits / $totalCount) * 100;
            $checkValueMapping->save();
        } else {
            $valueMappings = new \App\Connector\Models\ValueMapping();
            $valueMappings->product_source = $source;
            $valueMappings->target = $target;
            $valueMappings->source_attr = $sourceAttr;
            $valueMappings->target_attr = $targetAttr;
            $valueMappings->source_attr_option = $sourceAttrOption;
            $valueMappings->target_attr_option = $targetAttrOption;
            $valueMappings->hits = 1;
            $valueMappings->occurence = ($valueMappings->hits / $totalCount) * 100;
            $valueMappings->save();
        }
    }

    // It is called when any new mapping is done of a source attribute between a source and target and that entry is not already is present in the table
    public function reduceHits($mappingEntries): void
    {
        foreach ($mappingEntries as $mappedValue) {
            $reduceHit = \App\Connector\Models\AttributeMapping::findFirst(["product_source='{$mappedValue['product_source']}' AND target='{$mappedValue['target']}' AND source_attr='{$mappedValue['source_attr']}' AND target_attr='{$mappedValue['target_attr']}'"]);
            $reduceHit->hits -= 1;
            $reduceHit->save();
        }
    }

    // It is called when a new user comes and mapped his/her data
    public function createNewCustomerMapping($source, $target, $sourceAttr, $targetAttribute, $userId): void
    {
        $mappingData = [];
        $mappingData['customer_id'] = $userId;
        $mappingData['product_source'] = $source;
        $mappingData['target'] = $target;
        $mappingData['source_attr'] = $sourceAttr;
        $mappingData['target_attr'] = $targetAttribute;
        $model = new \App\Connector\Models\CustomerAttributeMapping();
        $model->set($mappingData);
        $model->save();

        $this->di->getLog()->logContent(print_r($model->getMessages(), true), Logger::CRITICAL, 'debug.log');
    }

    // It is only done to update the hits of the mapping
    public function updateHitsOccurence($hitValue, $model): void
    {
        $model->hits = $hitValue;
        $totalCount = $this->getConfigTotalCount($model->source, $model->target);
        if (!$totalCount) {
            $totalCount += 1;
        }

        $model->occurence = ($model->hits / $totalCount) * 100;
        $model->save();
    }

    /*
     * return ['target_attr'=>'soruce_attr','target_attr1'=>'source_attr1']
     */
    public function getSystemMappings($source, $target)
    {
        $systemMappings = [];
        $mappings = \App\Connector\Models\AttributeMapping::find(
            [
                "product_source='{$source}' AND target='{$target}' AND is_system='1'"
            ]
        );
        foreach ($mappings as $mapping) {
            $systemMappings[$mapping->target_attr] = $mapping->source_attr;
        }

        return $systemMappings;
    }

    /*
     * return ['target_attr'=>'soruce_attr','target_attr1'=>'source_attr1']
     */
    public function getCustomerMappings($source, $target, $userId)
    {
        $systemMappings = [];
        $mappings = \App\Connector\Models\CustomerAttributeMapping::find(["product_source='{$source}' AND target='{$target}' AND customer_id='{$userId}'"]);
        foreach ($mappings as $mapping) {
            $systemMappings[$mapping->target_attr] = $mapping->source_attr;
        }

        return $systemMappings;
    }
}
