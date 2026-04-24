<?php

namespace App\Amazon\Components\SupplySource;

use App\Core\Models\BaseMongo;
use App\Core\Components\Base;
use App\Core\Models\User\Details as CoreUserDetailsModel;
use App\Amazon\Components\Common\Helper as CommonHelper;
use App\Amazon\Components\SupplySource\Helpers\CreateHelper;
use App\Amazon\Components\SupplySource\Helpers\UpdateHelper;

class SupplySource extends Base
{
    private const MARKETPLACE = 'amazon';
    private const SUPPLY_SOURCE_COLLECTION = 'amazon_supply_sources';
    private const VALID_STATUSES = ['Active', 'Inactive'];

    public function create($data)
    {
        try {
            if (empty($data['params'])) {
                return ['success' => false, 'message' => 'data missing'];
            }

            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $amazonShopId = $data['shop_id'] ?? null;

            if (empty($amazonShopId)) {
                return ['success' => false, 'message' => 'Shop ID is required'];
            }

            $amazonShop = $this->getShop($amazonShopId, $userId);
            if (empty($amazonShop)) {
                return ['success' => false, 'message' => 'Shop not found'];
            }

            $createHelper = $this->di
                ->getObjectManager()
                ->get(CreateHelper::class);

            $validationResult = $createHelper->validateCreateInput($data['params']);
            if (!$validationResult['success']) {
                return $validationResult;
            }

            $preparedData = $createHelper->prepareSupplySourceData($data['params']);

            $remoteParams = [
                'shop_id' => $amazonShop['remote_shop_id'],
                'params' => $preparedData
            ];

            $commonHelper = $this->di->getObjectManager()->get(CommonHelper::class);
            $remoteResponse = $commonHelper->sendRequestToAmazon('supply-source', $remoteParams, 'POST');

            if (empty($remoteResponse)) {
                return ['success' => false, 'message' => 'Something went wrong', 'code' => 'empty_remote_response'];
            }

            if (!isset($remoteResponse['success'])) {
                //add log here
                return ['success' => false, 'message' => 'Something went wrong', 'code' => 'unexpected_remote_response'];
            }

            if (!$remoteResponse['success']) {
                //add log here
                $remoteResponse['code'] ??= "remote_error";
                return $remoteResponse;
            }
            $preparedSupplySourceData = $this->prepareSupplySourceForDb($remoteResponse, $data);
            $this->addSupplySourceToDb($preparedSupplySourceData);
            if (!empty($data['configure_supply_source'])) {
                $prepData = [
                    "user_id" => $userId,
                    "shop_id" => $amazonShopId,
                    "params" => $data['configure_supply_source'],
                    "supply_source_id" => $remoteResponse['data']['supplySourceId']
                ];

                $prepData['params']['supply_source_id'] = $remoteResponse['data']['supplySourceId'];
                $prepData['params']['alias'] = $preparedData['alias'];

                $updateStore = $this->update($prepData, $amazonShop);
                if (!$updateStore['success']) {
                    //add here that ss created but error in update
                    return $updateStore;
                }
            }

            if (!empty($data['update_supply_source_status'])) {
                $prepData = [
                    "user_id" => $userId,
                    "shop_id" => $amazonShopId,
                    "params" => $data['update_supply_source_status'],
                    "supply_source_id" => $remoteResponse['response']['supply_source_id']
                ];

                $prepData['params']['supply_source_id'] = $remoteResponse['data']['supplySourceId'];

                $updateStore = $this->updateStatusOnAmazon($prepData);
                if (!$updateStore['success']) {
                    //add here that ss created, updated/configured but error in status update
                    return $updateStore;
                }
            }

            return ['success' => true, 'message' => 'Supply source created successfully'];

        } catch (\Exception $e) {
            $this->addLog(['Supply source creation failed', $e], 'supply_source_creation_error.log');
            return ['success' => false, 'message' => 'An error occurred while creating the supply source: ' . $e->getMessage()];
        }
    }

    public function update($data, $amazonShop = null)
    {
        try {
            if (empty($data['params'])) {
                return ['success' => false, 'message' => 'data missing'];
            }

            $userId = $data['user_id'] ?? $this->di->getUser()->id;
            $amazonShopId = $data['shop_id'] ?? null;

            if (empty($amazonShopId)) {
                return ['success' => false, 'message' => 'Shop ID is required'];
            }

            $amazonShop ??= $this->getShop($amazonShopId, $userId);
            if (empty($amazonShop)) {
                return ['success' => false, 'message' => 'Shop not found'];
            }

            if (empty($data['params']['supply_source_id'])) {
                return ['success' => false, 'message' => 'Supply Source ID is required'];
            }

            if (empty($data['params']['alias'])) {
                return ['success' => false, 'message' => 'Alias is required'];
            }

            $updateHelper = $this->di
                ->getObjectManager()
                ->get(UpdateHelper::class);

            $preparedData = $updateHelper->prepareSupplySourceUpdateData($data['params']);
            if (!$preparedData['success']) {
                return $preparedData;
            }

            $params = [
                'shop_id' => $amazonShop['remote_shop_id'],
                'params' => $preparedData['data'],
                'user_id' => $userId
            ];

            $commonHelper = $this->di->getObjectManager()->get(CommonHelper::class);
            $remoteResponse = $commonHelper->sendRequestToAmazon('supply-source', $params, 'PUT');

            if (empty($remoteResponse)) {
                return ['success' => false, 'message' => 'Something went wrong', 'code' => 'empty_remote_response'];
            }

            if (!isset($remoteResponse['success'])) {
                //add log here
                return ['success' => false, 'message' => 'Something went wrong', 'code' => 'unexpected_remote_response'];
            }

            if (!$remoteResponse['success']) {
                //add log here
                $remoteResponse['code'] ??= "remote_error";
                return $remoteResponse;
            }

            $prepData = [
                "user_id" => $userId,
                "shop_id" => $amazonShopId,
                "params" => $data['params'],
                "supplySourceId" => $data['supplySourceId']
            ];
            $filter = ['supply_source_id' => $data['params']['supply_source_id'], 'user_id' => $userId];
            $updateData = $this->prepareUpdateDataForDb($params['params']);

            $updateStore = $this->updateSupplySourceInDb($filter, $updateData);
            if (!$updateStore['success']) {
                return $updateStore;
            }

            return ['success' => true, 'message' => 'Supply source updated successfully'];
            
        } catch (\Exception $e) {
            error_log('Supply source update failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while updating the supply source: ' . $e->getMessage()];
        }
    }

    public function fetchSupplySourcesFromAmazon($data)
    {
        try {
            if (!isset($data['user_id'], $data['shop_id'])) {
                return ['success' => false, 'message' => 'user_id and shop_id not found'];
            }

            $amazonShop = $this->getShop($data['shop_id'], $data['user_id']);
            if (empty($amazonShop)) {
                return ['success' => false, 'message' => 'Shop not found'];
            }

            $params = [
                'shop_id' => $amazonShop['remote_shop_id']
            ];

            $commonHelper = $this->di->getObjectManager()->get(CommonHelper::class);
            $response = $commonHelper->sendRequestToAmazon('supply-sources', $params, 'GET');

            if (empty($response)) {
                return ['success' => false, 'message' => 'Something went wrong on remote'];
            }

            if (!$response['success']) {
                return $response;
            }

            $supplySourceColl = $this->getMongoCollection(self::SUPPLY_SOURCE_COLLECTION);

            foreach ($response['data']['supplySources'] as $supplySource) {
                $existing = $supplySourceColl->findOne([
                        'supply_source_id' => $supplySource['supplySourceId'],
                        'user_id' => $data['user_id']
                    ]);

                if (empty($existing)) {
                    $supplySourceData = $this->prepareSupplySourceForDb(
                        ['data' => $supplySource],
                        $data
                    );
                    $this->addSupplySourceToDb($supplySourceData);
                } else {
                    $filter = [
                        'supply_source_id' => $supplySource['supplySourceId'],
                        'user_id' => $data['user_id']
                    ];
                    $updateData = $this->prepareUpdateDataForDb($supplySource);
                    $this->updateSupplySourceInDb($filter, $updateData);
                }
            }

            return ['success' => true, 'data' => $response['data'] ?? []];
            
        } catch (\Exception $e) {
            error_log('Fetch supply sources failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while fetching supply sources: ' . $e->getMessage()];
        }
    }

    public function fetchSupplySourceFromAmazon($data)
    {
        try {
            if (empty($data['params']['supply_source_id'])) {
                return ['success' => false, 'message' => 'Supply source ID is required'];
            }

            if (!isset($data['user_id'], $data['shop_id'])) {
                return ['success' => false, 'message' => 'user_id and shop_id not found'];
            }

            $amazonShop = $this->getShop($data['shop_id'], $data['user_id']);
            if (empty($amazonShop)) {
                return ['success' => false, 'message' => 'Shop not found'];
            }

            $params = [
                'shop_id' => $amazonShop['remote_shop_id'],
                'params' => [
                    'supplySourceId' => $data['params']['supply_source_id']
                ]
            ];

            $commonHelper = $this->di->getObjectManager()->get(CommonHelper::class);
            $response = $commonHelper->sendRequestToAmazon('supply-source-detail', $params, 'GET');

            if (empty($response)) {
                return ['success' => false, 'message' => 'Something went wrong on remote'];
            }

            if (!$response['success']) {
                return $response;
            }

            return ['success' => true, 'data' => $response['data'] ?? []];
            
        } catch (\Exception $e) {
            error_log('Fetch supply source detail failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while fetching supply source detail: ' . $e->getMessage()];
        }
    }

    /**
     * Update supply source status
     */
    public function updateStatusOnAmazon($data)
    {
        try {
            if (empty($data['params']['status'])) {
                return ['success' => false, 'message' => 'Status is required'];
            }

            if (!in_array($data['params']['status'], self::VALID_STATUSES)) {
                return ['success' => false, 'message' => 'Invalid status value'];
            }

            if (empty($data['params']['supply_source_id'])) {
                return ['success' => false, 'message' => 'Supply source ID is required'];
            }

            if (!isset($data['user_id'], $data['shop_id'])) {
                return ['success' => false, 'message' => 'user_id and shop_id not found'];
            }

            $amazonShop = $this->getShop($data['shop_id'], $data['user_id']);
            if (empty($amazonShop)) {
                return ['success' => false, 'message' => 'Shop not found'];
            }

            $params = [
                'shop_id' => $amazonShop['remote_shop_id'],
                'params' => [
                    'supplySourceId' => $data['params']['supply_source_id'],
                    'status' => $data['params']['status']
                ]
            ];

            $commonHelper = $this->di->getObjectManager()->get(CommonHelper::class);
            $response = $commonHelper->sendRequestToAmazon('supply-source-status', $params, 'PUT');

            if (empty($response)) {
                return ['success' => false, 'message' => 'Something went wrong on remote'];
            }

            if (!$response['success']) {
                return $response;
            }

            return ['success' => true, 'message' => 'Supply source status updated successfully', 'data' => $response];
            
        } catch (\Exception $e) {
            error_log('Update supply source status failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while updating supply source status: ' . $e->getMessage()];
        }
    }

    /**
     * Archive supply source
     */
    public function archiveSupplySource($data)
    {
        try {
            if (empty($data['params']['supply_source_id'])) {
                return ['success' => false, 'message' => 'Supply source ID is required'];
            }

            if (!isset($data['user_id'], $data['shop_id'])) {
                return ['success' => false, 'message' => 'user_id and shop_id not found'];
            }

            $amazonShop = $this->getShop($data['shop_id'], $data['user_id']);
            if (empty($amazonShop)) {
                return ['success' => false, 'message' => 'Shop not found'];
            }

            $params = [
                'shop_id' => $amazonShop['remote_shop_id'],
                'params' => [
                    'supplySourceId' => $data['params']['supply_source_id']
                ]
            ];

            $commonHelper = $this->di->getObjectManager()->get(CommonHelper::class);
            $response = $commonHelper->sendRequestToAmazon('supply-source', $params, 'DELETE');

            if (empty($response)) {
                return ['success' => false, 'message' => 'Something went wrong on remote'];
            }

            if (!$response['success']) {
                return $response;
            }

            // Optionally, you can also remove the supply source from your local DB
            $filter = ['supply_source_id' => $data['params']['supply_source_id'], 'user_id' => $data['user_id']];
            $supplySourceColl = $this->getMongoCollection(self::SUPPLY_SOURCE_COLLECTION);
            $supplySourceColl->updateOne($filter, ['$set' => ['status' => 'Archived', 'updated_at' => $this->getCurrentTime()]]);

            return ['success' => true, 'message' => 'Supply source archived successfully', 'data' => $response];
            
        } catch (\Exception $e) {
            error_log('Archive supply source failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while archiving supply source: ' . $e->getMessage()];
        }
    }

    public function fetchSupplySources($params)
    {
        try {
            if (!isset($params['user_id'], $params['shop_id'])) {
                return ['success' => false, 'message' => 'user_id and shop_id not found'];
            }

            $amazonShop = $this->getShop($params['shop_id'], $params['user_id']);
            if (empty($amazonShop)) {
                return ['success' => false, 'message' => 'Shop not found'];
            }

            $response = $this->getMongoCollection('amazon_supply_sources')
                ->find(['shop_id' => $amazonShop['remote_shop_id']]);
            
            return ['success' => true, 'data' => $response];
            
        } catch (\Exception $e) {
            error_log('Fetch supply sources failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while fetching supply sources'];
        }
    }

    public function getAllSupplySources($params)
    {
        try {
            if (!isset($params['user_id'], $params['shop_id'])) {
                return ['success' => false, 'message' => 'user_id and shop_id not found'];
            }

            $amazonSupplySourcesCollection = $this->getMongoCollection('amazon_supply_sources');
            $response = $amazonSupplySourcesCollection->find([
                'user_id' => $params['user_id'],
                'shop_id' => $params['shop_id']
            ]);

            return ['success' => true, 'data' => $response];
            
        } catch (\Exception $e) {
            error_log('Get all supply sources failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while getting all supply sources'];
        }
    }

    private function prepareSupplySourceForDb($amazonData, $requestData)
    {
        return [
            'user_id' => $requestData['user_id'],
            'shop_id' => $requestData['shop_id'],
            'created_at' => $this->getCurrentTime(),
            'supply_source_id' => $amazonData['data']['supplySourceId'],
            'supply_source_code' => $amazonData['data']['supplySourceCode'],
            'alias' => $amazonData['request_data']['params']['alias'] ?? $amazonData['data']['alias'] ?? '',
            'address' => $amazonData['request_data']['params']['address'] ?? $amazonData['data']['details'] ?? '',
            'status' => $amazonData['data']['status'] ?? 'Inactive',
        ];
    }

    private function prepareUpdateDataForDb($requestData)
    {
        $updateData = [
            'updated_at' => $this->getCurrentTime()
        ];
        if (isset($requestData['alias'])) {
            $updateData['alias'] = $requestData['alias'];
        }

        if (isset($requestData['configuration'])) {
            $updateData['configuration'] = $requestData['configuration'];
        }

        return $updateData;
    }

    private function updateSupplySourceInDb($filter, $data)
    {
        $supplySourceColl = $this->getMongoCollection(self::SUPPLY_SOURCE_COLLECTION);
        $res = $supplySourceColl->updateOne(
            $filter,
            ['$set' => $data]
        );
        return ['success' => true, 'message' => 'Supply source updated'];
    }

    private function addSupplySourceToDb($data)
    {
        $data['cif_warehouse_id'] = $this->getWarehouseId();
        $supplySourceColl = $this->getMongoCollection(self::SUPPLY_SOURCE_COLLECTION);
        $supplySourceColl->insertOne($data);
    }

    public function getAllSupplySourcesFromDb($params)
    {
        $supplySourceColl = $this->getMongoCollection(self::SUPPLY_SOURCE_COLLECTION);
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];

        $limit = $params['count'] ?? 10;
        $page = $params['activePage'] ?? 1;
        $offset = ($page - 1) * $limit;

        $aggregation = [];

        $conditionalQuery = [
            'user_id' => $params['user_id'],
            'shop_id' => $params['shop_id']
        ];

        $baseMongo = $this->getBaseMongo();

        if (!empty($params['filter'])) {
            $conditionalQuery = array_merge($conditionalQuery, $baseMongo::search($params));
        }

        if (!isset($conditionalQuery['status'])) {
            $conditionalQuery['status'] = ['$ne' => "archived"];
        }

        $aggregation[] = ['$match' => $conditionalQuery];

        $countAggregation = $aggregation;
        $countAggregation[] = [
            '$count' => 'count'
        ];

        try {
            $totalRows = $supplySourceColl->aggregate($countAggregation, $options);

            $sortBy = $params['sort']['by'] ?? '_id';
            $sortOrder = isset($params['sort']['order']) && is_numeric($params['sort']['order'])
                ? intval($params['sort']['order']) : -1;

            $aggregation[] = [
                '$sort' => [
                    $sortBy => $sortOrder
                ]
            ];

            $aggregation[] = ['$skip' => (int)$offset];
            $aggregation[] = ['$limit' => (int)$limit];

            if (!empty($params['projection'])) {
                $aggregation[] = ['$project' => array_map(function($value) {
                    return is_numeric($value) ? intval($value) : $value;
                }, $params['projection'])];
            }

            $rows = $supplySourceColl->aggregate($aggregation, $options)->toArray();
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $responseData = [];
        $responseData['success'] = true;
        $responseData['data']['rows'] = $rows;
        $totalRows = $totalRows->toArray();

        $totalRows = $totalRows[0]['count'] ?? 0;
        $responseData['data']['count'] = $totalRows;

        return $responseData;
    }

    public function linkSupplySourceToWarehouse($params)
    {
        if (empty($params['supply_source_id']) || empty($params['marketplace_warehouse_id']) || empty($params['marketplace'])) {
            return ['success' => false, 'message' => 'Supply source ID, warehouse ID and marketplace are required'];
        }

        $supplySourceColl = $this->getMongoCollection(self::SUPPLY_SOURCE_COLLECTION);

        // Check if any of the warehouses are already linked to another supply source
        $existing = $supplySourceColl->findOne(
            ['linked_warehouses.cif_warehouse_id' => ['$in' => (array)$params['marketplace_warehouse_id']]],
            ['projection' => ['_id' => 1]]
        );

        if ($existing) {
            return ['success' => false, 'message' => 'One or more warehouses are already linked to another supply source'];
        }

        $warehousesToLink = [];
        foreach ($this->di->getUser()->getShops() as $shop) {
            if ($shop['marketplace'] === $params['marketplace'] && !empty($shop['warehouses'])) {
                foreach ($shop['warehouses'] as $warehouse) {
                    if (in_array($warehouse['_id'], (array)$params['marketplace_warehouse_id'])) {
                        $addressParts = array_filter([
                            $warehouse['address1'] ?? '',
                            $warehouse['city'] ?? '',
                            $warehouse['province'] ?? ''
                        ]);

                        $warehousesToLink[] = [
                            'name' => $warehouse['name'],
                            'cif_warehouse_id' => $warehouse['_id'],
                            'marketplace' => $params['marketplace'],
                            'marketplace_warehouse_id' => $params['marketplace_warehouse_id'],
                            'address' => implode(', ', $addressParts),
                            'linked_at' => $this->getCurrentTime()
                        ];
                    }
                }
            }
        }

        if (empty($warehousesToLink)) {
            return ['success' => false, 'message' => 'No matching warehouses found'];
        }

        $supplySourceColl->updateOne(
            ['supply_source_id' => $params['supply_source_id']],
            ['$push' => ['linked_warehouses' => ['$each' => $warehousesToLink]]]
        );

        // TODO: Also add in config collection for use during MLI inventory sync
        return ['success' => true, 'message' => 'Warehouses linked to supply source successfully'];
    }

    public function unlinkSupplySourceFromWarehouse($params)
    {
        if (empty($params['supply_source_id']) || empty($params['marketplace_warehouse_id']) || empty($params['marketplace'])) {
            return ['success' => false, 'message' => 'Supply source ID, warehouse ID and marketplace are required'];
        }

        $supplySourceColl = $this->getMongoCollection(self::SUPPLY_SOURCE_COLLECTION);

        $warehouseIds = (array)$params['marketplace_warehouse_id'];

        $supplySource = $supplySourceColl->findOne(
            [
                'supply_source_id' => $params['supply_source_id'],
                'linked_warehouses.cif_warehouse_id' => ['$in' => $warehouseIds]
            ],
            ['projection' => ['_id' => 1]]
        );

        if (empty($supplySource)) {
            return ['success' => false, 'message' => 'No matching warehouses found for this supply source'];
        }

        // Prepare $pull operation for multiple warehouses
        $updateData = [
            '$pull' => [
                'linked_warehouses' => [
                    'cif_warehouse_id' => ['$in' => $warehouseIds],
                    'marketplace' => $params['marketplace']
                ]
            ]
        ];

        $result = $supplySourceColl->updateOne(
            ['supply_source_id' => $params['supply_source_id']],
            $updateData
        );

        if ($result->getModifiedCount() > 0) {
            // TODO: Also remove from config collection for MLI inventory sync
            return ['success' => true, 'message' => 'Warehouses unlinked from supply source successfully'];
        }

        return ['success' => false, 'message' => 'No warehouses were unlinked'];
    }

    public function getStatusWiseCount($params)
    {
        $supplySourceColl = $this->getMongoCollection(self::SUPPLY_SOURCE_COLLECTION);
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];

        $aggregation = [];

        $conditionalQuery = [
            'user_id' => $params['user_id'],
            'shop_id' => $params['shop_id']
        ];

        $baseMongo = $this->getBaseMongo();

        if (!empty($params['filter'])) {
            $conditionalQuery = array_merge($conditionalQuery, $baseMongo::search($params));
        }

        $aggregation[] = ['$match' => $conditionalQuery];

        $aggregation[] = [
            '$group' => [
                '_id' => '$status',
                'count' => ['$sum' => 1]
            ]
        ];

        try {
            $statusCounts = $supplySourceColl->aggregate($aggregation, $options)->toArray();
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $statusWiseCounts = [];
        $total = 0;

        foreach ($statusCounts as $item) {
            $status = $item['_id'] ?? 'Inactive';
            $count = $item['count'] ?? 0;
            $statusWiseCounts[$status] = $count;    
            $total += $count;
        }

        return [
            'success' => true,
            'data' => [
                'total' => $total,
                'status_wise' => $statusWiseCounts
            ]
        ];
    }

    public function getAllSourceWarehouses($params)
    {
        if (empty($params['user_id']) || empty($params['shop_id'] || empty($params['source']['shop_id']))) {
            return ['success' => false, 'message' => 'User ID, shop ID and source shop ID are required'];
        }

        $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        $sourceShop = $userDetails->getShop($params['source']['shop_id'], $params['user_id']);
        if (empty($sourceShop)) {
            return ['success' => false, 'message' => 'Source shop not found'];
        }

        $warehouses = $sourceShop['warehouses'] ?? [];

        $linkedWarehouseQuery = [
            'user_id' => $params['user_id'],
            'shop_id' => $params['shop_id'],
            'linked' => true
        ];

        $supplySourcesWithLinkedWarehouses = $this->getMongoCollection(self::SUPPLY_SOURCE_COLLECTION)->find($linkedWarehouseQuery, ['projection' => ['linked_warehouses.cif_warehouse_id' => 1]]);

        $linkedWarehouseIds = [];
        foreach ($supplySourcesWithLinkedWarehouses['linked_warehouses'] as $linkedWarehouse) {
            $linkedWarehouseIds[$linkedWarehouse['cif_warehouse_id']] = 1;
        }

        foreach ($warehouses as $index => $warehouse) {
            if (isset($linkedWarehouseIds[$warehouse['_id']])) {
                $warehouses[$index]['linked'] = true;
            } else {
                $warehouses[$index]['linked'] = false;
            }
        }

        return ['success' => true, 'data' => $warehouses];
    }

    
    public function getMongoCollection($collectionName)
    {
        return $this->getBaseMongo()->getCollection($collectionName);
    }

    private function getWarehouseId()
    {
        return $this->getBaseMongo()->getCounter('warehouse_id');
    }

    private function getBaseMongo()
    {
        return $this->di->getObjectManager()->create(BaseMongo::class);
    }

    private function getCurrentTime($mongoObject = true)
    {
        return $mongoObject ? new \MongoDB\BSON\UTCDateTime() : date('c');
    }

    public function getShop($shopId, $userId = null)
    {
        if (is_null($userId)) {
            $userId = $this->di->getUser()->id;
        }
        $userDetails = $this->di->getObjectManager()->get(CoreUserDetailsModel::class);
        return $userDetails->getShop($shopId, $userId);
    }

    private function addLog($data, $path): void
    {
        $this->di->getLog()->logContent('data = ' . print_r($data, true), 'info', $path);
    }
}
