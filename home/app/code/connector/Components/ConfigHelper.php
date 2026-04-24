<?php

namespace App\Connector\Components;

class ConfigHelper extends \App\Core\Components\Base
{
    public $table = 'config';

    public $mongo;

    public $collection;

    public $sourceName = false;

    public $sourceShopId = false;

    public $targetName = false;

    public $targetShopId = false;

    public function init($rawBody = false): void
    {
        $this->mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->collection = $this->mongo->getCollectionForTable($this->table);

        if (isset($rawBody['source']['marketplace']) || isset($rawBody['source']['shopId']) || isset($rawBody['target']['marketplace']) || isset($rawBody['target']['shopId'])) {
            $this->sourceName = $rawBody['source']['marketplace'] ?? false;
            $this->sourceShopId = $rawBody['source']['shopId'] ?? false;
            $this->targetName = $rawBody['target']['marketplace'] ?? false;
            $this->targetShopId = $rawBody['target']['shopId'] ?? false;
        } else {
            $this->sourceName = $this->di->getRequester()->getSourceName() ?? false;
            $this->sourceShopId = $this->di->getRequester()->getSourceId() ?? false;
            $this->targetName = $this->di->getRequester()->getTargetName() ?? false;
            $this->targetShopId = $this->di->getRequester()->getTargetId() ?? false;
        }
    }

    /**
     * Fetch the config details
     * @param [type] $rawBody, group_code, key
     */
    public function getConfigData($rawBody)
    {
        if (empty($rawBody['key']) && empty($rawBody['group_code'])) {
            return ([
                'success' => false, 'message' => 'Data is Missing'
            ]);
        }

        if (isset($rawBody['source']['marketplace']) || isset($rawBody['source']['shopId']) || isset($rawBody['target']['marketplace']) || isset($rawBody['target']['shopId'])) {
            $source = $rawBody['source']['marketplace'] ?? false;
            $sourceShopId = $rawBody['source']['shopId'] ?? false;
            $target = $rawBody['target']['marketplace'] ?? false;
            $targetShopId = $rawBody['target']['shopId'] ?? false;
        } else {
            $source = $this->di->getRequester()->getSourceName() ?? false;
            $sourceShopId = $this->di->getRequester()->getSourceId() ?? false;
            $target = $this->di->getRequester()->getTargetName() ?? false;
            $targetShopId = $this->di->getRequester()->getTargetId() ?? false;
        }

        if ($source || $sourceShopId || $target || $targetShopId) {
            $configModel = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
            $user_id = $rawBody['user_id'] ?? $this->di->getUser()->id;
            $configModel->setUserId($user_id);

            if ($sourceShopId) {
                $configModel->setSourceShopId($sourceShopId);
            }

            if ($targetShopId) {
                $configModel->setTargetShopId($targetShopId);
            }

            if ($source) {
                $configModel->sourceSet($source);
            }

            if ($target) {
                $configModel->setTarget($target);
            }

            $output = [];
            if (!empty($rawBody['group_code'])) {
                foreach ($rawBody['group_code'] as $groupCode) {
                    $rawData = [
                        'key' => $groupCode
                    ];
                    $configModel->setGroupCode($groupCode);
                    $response = $configModel->getConfig();
                    if (count($response) > 0) {
                        $rawData['value'] = $response;
                        $output[] = $rawData;
                    }
                }

                $result = $this->formatGroupCodeData($output, $rawBody);
            } else if (!empty($rawBody['key'])) {
                foreach ($rawBody['key'] as $keyValue) {
                    $configModel->setGroupCode(null);
                    $response = $configModel->getConfig($keyValue);
                    if (count($response) > 0) {
                        $rawData = $response;
                        $output[] = $rawData;
                    }
                }

                $result = $this->formatKeyData($output);
            } else {
                return ([
                    'success' => false,
                    'message' => 'Group Code or Key is Missing'
                ]);
            }

            return ([
                'success' => true,
                'data' => $result ?? []
            ]);
        }

        return ([
            'success' => false, 'message' => 'Required Key Source or Group_code Missing'
        ]);
    }

    /**
     * Format the data in group_code Format
     */
     public function formatGroupCodeData($data, $rawBody = [])
    {

        $resultConf = [];
        $count = 0;
        foreach ($data as $ans) {
            if (isset($ans['value']) && isset($ans['key'])) {
                $resultConf[$count]['group_code'] = $ans['key'];
                foreach ($ans['value'] as $val) {
                    if (!empty($rawBody) && isset($rawBody['includeUpdatedAt']) && $rawBody['includeUpdatedAt'] === true) {
                        // Include updated_at for each individual key
                        $resultConf[$count]['value'][$val['key']] = [
                            'value' => $val['value'],
                            'updated_at' => $val['updated_at'] ?? $val['created_at'] ?? ''
                        ];
                    } else {
                        // Default behavior - just the value
                        $resultConf[$count]['value'][$val['key']] = $val['value'];
                    }
                }

                $count++;
            }
        }

        return $resultConf;
    }

    /**
     * Format the data in key Format
     */
    public function formatKeyData($data)
    {
        $resultConf = [];
        foreach ($data as $event) {
            if (isset($event)) {
                foreach ($event as $val) {
                    $resultConf[] = [
                        'key' => $val['key'],
                        'value' => $val['value']
                    ];
                }
            }
        }

        return $resultConf;
    }

    /**
     * Save the config details
     * @param [type] $rawBody, group_code, key
     */
    public function saveConfigData($rawBody)
    {
        if (empty($rawBody['data'])) {
            return ([
                'success' => false, 'message' => 'Data is Missing'
            ]);
        }

        if (isset($rawBody['source']['marketplace']) || isset($rawBody['source']['shopId']) || isset($rawBody['target']['marketplace']) || isset($rawBody['target']['shopId'])) {
            $source = $rawBody['source']['marketplace'] ?? false;
            $sourceShopId = $rawBody['source']['shopId'] ?? false;
            $target = $rawBody['target']['marketplace'] ?? false;
            $targetShopId = $rawBody['target']['shopId'] ?? false;
        } else {
            $source = $this->di->getRequester()->getSourceName() ?? false;
            $sourceShopId = $this->di->getRequester()->getSourceId() ?? false;
            $target = $this->di->getRequester()->getTargetName() ?? false;
            $targetShopId = $this->di->getRequester()->getTargetId() ?? false;
        }


        $sourceSourceModel = $source ? $this->di->getObjectManager()->get($this->di->getConfig()->connectors
            ->get($source)->get('source_model')) : false;

        $targetSourceModel = $target ? $this->di->getObjectManager()->get($this->di->getConfig()->connectors
            ->get($target)->get('source_model')) : false;

        if ($source || $sourceShopId || $target || $targetShopId) {
            $configModel = $this->di->getObjectManager()->get('\App\Core\Models\Config\Config');
            $user_id = $rawBody['user_id'] ?? $this->di->getUser()->id;
            $configModel->setUserId($user_id);

            if ($sourceShopId) {
                $configModel->setSourceShopId($sourceShopId);
            }

            if ($targetShopId) {
                $configModel->setTargetShopId($targetShopId);
            }

            if ($source) {
                $configModel->sourceSet($source);
            }

            if ($target) {
                $configModel->setTarget($target);
            }

            if (isset($rawBody['appTag'])) {
                $configModel->setAppTag($rawBody['appTag']);
            }

            foreach ($rawBody['data'] as $event) {
                if (isset($event['group_code']) && !empty($event['data'] && $this->stringValidator($event['group_code']))) {
                    $configModel->setGroupCode($event['group_code']);
                    foreach ($event['data'] as $eventKey => $eventValue) {
                        $query = [];
                        $prepareData = [
                            'user_id' => $this->di->getUser()->id,
                            'app_tag' => $this->di->getAppCode()->getAppTag(),
                            'key' => $eventKey,
                            'value' => $eventValue,
                            'group_code' => $event['group_code']
                        ];

                        if ($sourceSourceModel && method_exists($sourceSourceModel, 'getMarketplaceConfigRes')) {
                            $response = $sourceSourceModel->getMarketplaceConfigRes($prepareData);
                            $prepareData = $response['data'];
                        }

                        if ($targetSourceModel && method_exists($targetSourceModel, 'getMarketplaceConfigRes')) {
                            $response = $targetSourceModel->getMarketplaceConfigRes($prepareData);
                            $prepareData = $response['data'];
                        }

                        if (isset($response['error_code']) && $response['error_code'] === "skip") {
                            $result[] = [
                                'success' => $response['success'],
                                'message' => $response['message']
                            ];
                            continue;
                        }

                        if ($source) {
                            $prepareData['source'] = $source;
                        }

                        if ($sourceShopId) {
                            $prepareData['source_shop_id'] = (string)$sourceShopId;
                        }

                        if ($target) {
                            $prepareData['target'] = $target;
                        }

                        if ($targetShopId) {
                            $prepareData['target_shop_id'] = (string)$targetShopId;
                        }

                        !empty($prepareData) && $query[] = $prepareData;

                        if (!empty($query)) {
                            $message = $configModel->setConfig($query);
                            $result[] = $message['data'];
                        }
                    }
                } else {
                    $result[] = [
                        'success' => false,
                        'message' => "Group Code is Missing"
                    ];
                }
            }

            $response = $this->configUpdateStatus($result);
            return $response;
        }

        return ([
            'success' => false, 'message' => 'Required Key Source or Group_code Missing'
        ]);
    }


    public function stringValidator($value)
    {
        $type = gettype($value);
        if ($type === 'string') {
            if (ctype_space($value) || strlen($value) == 0 || ctype_space($value) || strlen($value) == 0) {
                return false;
            }

            return true;
        }

        return true;
    }

    public function configUpdateStatus($result)
    {
        $successCount = 0;
        $configDataCount = count($result);

        if ($configDataCount > 1) {
            foreach ($result as $data) {
                if ($data['success'] == true) {
                    $successCount = $successCount + 1;
                }
            }
        } else {
            return [
                'success' => $result[0]['success'],
                'message' => $result[0]['message']
            ];
        }

        if ($successCount == 0) {
            return [
                'success' => false,
                'message' => 'Error, Config Not Updated'
            ];
        }

        if ($successCount == $configDataCount) {
            return [
                'success' => true,
                'message' => 'Config Updated Successfully'
            ];
        }

        return [
            'success' => true,
            'message' => 'Config Updated Partially'
        ];
    }


    public function saveConfigForAll($data)
    {
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $sourceShopId = $this->di->getRequester()->getSourceId() ?? false;
        $source = $this->di->getRequester()->getSourceName() ?? false;
        if (!$sourceShopId) {
            return [
                'success' => false,
                'message' => 'Source shop not set!'
            ];
        }

        $userDetails = $this->getUserDetail($userId);
        if (empty($userDetails) || !isset($userDetails['shops'])) {
            return [
                'success' => false,
                'message' => 'User not found!'
            ];
        }

        $targets = [];
        foreach ($userDetails['shops'] as $shop) {
            if ($shop['_id'] == $sourceShopId) {
                $targets = $shop['targets'] ?? [];
            } elseif ($shop['marketplace'] == $source) {
                $targets = $shop['targets'] ?? [];
            }
        }

        $configData = [];
        $setData = [
            'data' => $data['data'] ?? [],
            'group_code' => $data['group_code']
        ];
        $configData['data'][] = $setData;
        $configData['user_id'] = $userId;
        $configData['source']['marketplace'] = $source;
        $configData['source']['shopId'] = $sourceShopId;
        $result = [];
        if (!empty($targets)) {
            foreach ($targets as $target) {
                $configData['target']['marketplace'] = $target['code'];
                $configData['target']['shopId'] = $target['shop_id'];
                $result = $this->saveConfigData($configData);
            }
        }

        return $result;
    }

    public function getUserDetail($userId = '')
    {
        $baseMongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $baseMongo->getCollection('user_details');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        return $collection->findOne(["user_id" => (string)$userId], $options);
    }

    public function deleteConfigData($rawBody)
    {
        if (empty($rawBody['data'])) {
            return ([
                'success' => false, 'message' => 'Data is Missing'
            ]);
        }

        $this->init($rawBody);
        if ($this->sourceName || $this->sourceShopId || $this->targetName || $this->targetShopId) {
            $userId = $rawBody['user_id'] ?? $this->di->getUser()->id;
            foreach ($rawBody['data'] as $event) {
                if (isset($event['group_code']) && !empty($event['key'] && $this->stringValidator($event['group_code']))) {
                    $prepareDocument = [
                        'user_id' => $userId,
                        'group_code' => $event['group_code']
                    ];
                    if ($this->sourceName) $prepareDocument['source'] = $this->sourceName;

                    if ($this->sourceShopId) $prepareDocument['source_shop_id'] = $this->sourceShopId;

                    if ($this->targetName) $prepareDocument['target'] = $this->targetName;

                    if ($this->targetShopId) $prepareDocument['target_shop_id'] = $this->targetShopId;

                    if (isset($event['key'])) {
                        $prepareDocument['key'] = [
                            '$in' => $event['key']
                        ];
                    }

                    $output[] = $this->performDeleteOperation($prepareDocument);
                }
            }

            return [
                'success' => $output[0]['success'],
                'message' => $output[0]['message'],
                'deletedCount' => $output[0]['deletedCount']
            ];
        }

        return ([
            'success' => false, 'message' => 'Required Key Source or Group_code Missing'
        ]);
    }

    public function performDeleteOperation($query = [])
    {
        $aggregate = [];
        $aggregate[] = [
            '$match' => $query
        ];
        $aggregateResult = $this->collection->aggregate($aggregate)->toArray();
        if (count($aggregateResult) > 0) {
            $result = $this->collection->deleteMany($query);
            return ([
                'success' => true, 'message' => 'Key Deleted Successfully', 'deletedCount' => $result->getDeletedCount()
            ]);
        }

        return ([
            'success' => false, 'message' => 'Did not match any document', 'deletedCount' => 0
        ]);
    }

    public function deleteKeyStatus($result)
    {
        $successCount = 0;
        $configDataCount = count($result);

        if ($result > 1) {
            foreach ($result as $data) {
                if ($data['success'] == true) {
                    $successCount = $result + 1;
                }
            }
        } else {
            return [
                'success' => $result[0]['success'],
                'message' => $result[0]['message']
            ];
        }

        if ($successCount == 0) {
            return [
                'success' => false,
                'message' => 'Error, Config Not Updated'
            ];
        }

        if ($successCount == $configDataCount) {
            return [
                'success' => true,
                'message' => 'Config Updated Successfully'
            ];
        }

        return [
            'success' => true,
            'message' => 'Config Updated Partially'
        ];
    }
}
