<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;
use Phalcon\Validation;
use Phalcon\Validation\Validator\InvalidValue;

class CustomerContainer extends BaseMongo
{
    const RANGE = 7;

    const END_FROM = 6;

    const START_FROM = 5;

    const IS_EQUAL_TO = 1;

    const IS_CONTAINS = 3;

    const IS_NOT_EQUAL_TO = 2;

    const IS_NOT_CONTAINS = 4;

    protected $sqlConfig;

    protected $implicit = false;

    protected $newAttributes = [];

    protected $existingAttributes = [];

    protected $table = 'customer_container';

    public static $defaultPagination = 20;


    private int $_shopId = 0;

    /**
     * Initialize the model
     */
    public function onConstruct(): void
    {
        $this->di = $this->getDi();
        $this->sqlConfig = $this->di->getObjectManager()->get('\App\Connector\Components\Data');
        $this->di->getRegistry()->getDecodedToken();
        $this->setSource($this->table);
        $this->initializeDb($this->getMultipleDbManager()->getDb());
    }

    public function importCustomers($data)
    {

        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);
            if ($connectorHelper) {
                return $connectorHelper->initiateCustomerImport($data);
            }
        }
    }

    public function uploadCustomers($data)
    {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);

            if ($connectorHelper) {
                return $connectorHelper->initiateCustomerUpload($data);
            }
        }
    }

    public function getCustomerByID($data) {
        $collection = $this->getCollection();

        if(!isset($data['customer_id'])){
            return ['success' => false, 'message' => 'Customer Identifier index `customer_id` seems missng. Please check and try again.'];
        }

        $filter = [];

        if(isset($data['customer_id'])){
            $filter['source_customer_id'] = (string)$data['customer_id'];
        }

        if(isset($data['shop_id'])){
            $filter['shop_id'] = (string)$data['shop_id'];
        }

        if(isset($data['source_marketplace'])){
            $filter['source_marketplace'] = (string)$data['source_marketplace'];
        }

        if(isset($data['user_id'])){
            $filter['user_id'] = (string)$data['user_id'];
        }

        $response = $collection->findOne($filter,["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if (count($response) ) {
            return ['success' => true, 'data' => $response, 'message' => 'Customer Found'];
        }

        return ['success' => false, 'message' => 'Customer not found.'];
    }

    public function removeCustomerByID($data) {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $uploadCollection = $mongo->getCollectionForTable("shopify_customer_upload_".$this->_shopId);
        $collection = $this->getCollection();
        if ( isset($data['source_id']) ) {
            $data['customer_id'] = $data['source_id'];
        }

        if ( !isset($data['customer_id']) ) return ['success' => false, 'message' => 'Required Param "customer_id" missing.'];

        $res = $collection->updateOne(['$or' => [
            ['source_id' => (string)$data['customer_id']],
            ['source_id' => (int)$data['customer_id']],
        ]], ['$set' => ['shopifyUpload' => []]]);
        $uploadCollection->deleteOne(['$or' => [
            ['source_id' => (string)$data['customer_id']],
            ['source_id' => (int)$data['customer_id']],
        ]]);
        if ( count($res) ) {
            return ['success' => true,'data' => $res, 'message' => 'Customer Found'];
        }

        return ['success' => false, 'message' => 'Customer not found.'];
    }

    public function selectProductAndUpload($data)
    {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);

            if ($connectorHelper) {
                return $connectorHelper->selectProductAndUpload($data);
            }
        }
    }

    /**
     * @return mixed
     */
    public function validation()
    {
        $validator = new Validation();
        return $this->validate($validator);
    }

    public function getCustomers($params)
    {
        $userId = $this->di->getUser()->id;
        $getRequester = $this->di->getRequester();
        $sourceMarketplace = $getRequester->getSourceName() ?: false;
        $sourceShopId = $getRequester->getSourceId() ?: false;

        $conditionalQuery = [];
        if (isset($params['filter']) || isset($params['search'])) {
            $conditionalQuery = self::search($params);
        }

        if ( isset($params['uploaded']) ) {
            $uploaded = $params['uploaded'];
        }

        $limit = $params['count'] ?? self::$defaultPagination;
        $page = $params['activePage'] ?? 1;
        $offset = ($page - 1) * $limit;

        $collection = $this->getCollection();
        $aggregation = [];
        $aggregation[] = [
            '$project' => [
                'newAttributes' => 0,
                'existingAttributes' => 0,
                'di' => 0,
                'implicit' => 0,
                'read_connection_service'=> 0,
                'sqlConfig' => 0,
                'table'=> 0,
                'updated_at' => 0,
            ]
        ];


        if (isset($params['app_code'])) {
            $conditionalQuery['app_codes'] = ['$in' => [$params['app_code']]];
        }

        $conditionalQuery['user_id']= $userId;
        if($sourceMarketplace){
            $conditionalQuery['source_marketplace']= $sourceMarketplace;
        }

        if($sourceShopId){
            $conditionalQuery['shop_id']= $sourceShopId;
        }

        if(count($conditionalQuery) > 0){
            $aggregation[] = ['$match' => $conditionalQuery];
        }

        if ( isset($uploaded) ) {
            if ( $uploaded == 1 ) {
                $conditionalQuery["customer_uploaded"] = [
                    '$nin' => []
                ];
            } else {
                $conditionalQuery["customer_uploaded"] = [
                    '$eq' => []
                ];
            }
        }

        $countAggregation = $aggregation;
        $countAggregation[] = [
            '$count' => 'count'
        ];
        $totalRows = $collection->aggregate($countAggregation, ['typeMap'=>['root'=> 'array', 'document' => 'array']]);
        $aggregation[] = [
            '$sort' => [
                '_id' => -1
            ]
        ];
        $aggregation[] = ['$skip' => (int)$offset];
        $aggregation[] = ['$limit' => (int)$limit];

        $rows = $collection->aggregate($aggregation)->toArray();
        $responseData = [];
        $responseData['success'] = true;

        $responseData['data']['rows'] = $rows;
        $totalRows = $totalRows->toArray();
        $totalRows = $totalRows[0]['count'] ? $totalRows[0]['count'] : 0;
        $responseData['data']['count'] = $totalRows;
        if (isset($count)) {
            $responseData['data']['mainCount'] = $count;
        }

        return $responseData;
    }

    /**
     * customerOnly = boolean
     */
    public function getCustomersCount($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("customer_container")->getCollection();

        $this->di->getUser()->id;

        $countAggregation = $this->buildAggregateQuery($params, 'customerCount');

        $countAggregation[] = [
            '$count' => 'count',
        ];

        try {
            $totalVariantsRows = $collection->aggregate($countAggregation)->toArray();
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        $responseData = [
            'success' => true,
            'query' => $countAggregation,
            'data' => [],
        ];

        $totalVariantsRows = $totalVariantsRows[0]['count'] ? $totalVariantsRows[0]['count'] : 0;
        $responseData['data']['count'] = $totalVariantsRows;
        return $responseData;
    }

    /**
     * count : number
     * activePage: number
     * type : admin | user
     * filterType : or | and
     * filter : Array
     * or_filter : Array
     * search : string
     * next: string
     * target_marketplace = string e.g shopify, facebook
     */
    public function getCustomersPhp($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("customer_container")->getCollection();

        $totalPageRead = 1;
        $limit = $params['count'] ?? self::$defaultPagination;

        if (isset($params['next'])) {
            $nextDecoded = json_decode(base64_decode($params['next']), true);
            $totalPageRead = $nextDecoded['totalPageRead'];
        }

        if (isset($params['activePage'])) {
            $page = $params['activePage'];
            $offset = ($page - 1) * $limit;
        }

        $this->di->getUser()->id;

        $aggregation = $this->buildAggregateQuery($params);
        if (isset($offset)) {
            $aggregation[] = ['$skip' => (int) $offset];
        }

        $aggregation[] = ['$limit' => (int) $limit + 1];

        try {
            /** use this below code get to slow down */
            //TODO: TEST Needed here
            $cursor = $collection->aggregate($aggregation);
            $it = new \IteratorIterator($cursor);
            $it->rewind(); // Very important

            $rows = [];
            while ($limit > 0 && $doc = $it->current()) {
                $rows[] = $doc;
                $limit--;
                $it->next();
                if (isset($params['prev'])) {
                    $next = base64_encode(json_encode([
                        'cursor' => (string)$doc['_id'],
                        'totalPageRead' => $totalPageRead + 1,
                    ]));
                }
            }

            if (!$it->isDead()) {
                $next = base64_encode(json_encode([
                    'cursor' => (string)$doc['_id'],
                    'totalPageRead' => $totalPageRead + 1,
                ]));
            } 
         } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        $responseData = [
            'success' => true,
            'data' => [
                'totalPageRead' => $page ?? $totalPageRead,
                'current_count' => count($rows),
                'rows' => $rows,
                'next' => $next ?? null,
                'doc' => $doc['_id'],
                'prev' => $nextDecoded['cursor'] ?? '',                                   
            ],
        ];

        return $responseData;
    }

    /**
     * Here we can find the customer by key value
     * @param $condition
     * @return mixed
     */
    public function loadByField($condition, $options=[])
    {
        $collection = $this->getCollection();
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $containerValues = $collection->findOne($condition, $options);
        return $containerValues;
    }

    /**
     * prepare conditions for where clause
     * @param array $filterParams
     * @return array
     */
    public static function search($filterParams = [])
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim($key);
                if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                    $conditions[$key] = self::checkInteger($key, $value[self::IS_EQUAL_TO]);
                } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[$key] =  ['$ne' => self::checkInteger($key, trim(addslashes($value[self::IS_NOT_EQUAL_TO])))];
                } elseif (array_key_exists(self::IS_CONTAINS, $value)) {
                    $conditions[$key] = [
                        '$regex' =>  self::checkInteger($key, trim(addslashes($value[self::IS_CONTAINS]))),
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::IS_NOT_CONTAINS, $value)) {
                    $conditions[$key] = [
                        '$regex' => "^((?!" . self::checkInteger($key, trim(addslashes($value[self::IS_NOT_CONTAINS]))) . ").)*$",
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::START_FROM, $value)) {
                    $conditions[$key] = [
                        '$regex' => "^" . self::checkInteger($key, trim(addslashes($value[self::START_FROM]))),
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::END_FROM, $value)) {
                    $conditions[$key] = [
                        '$regex' => self::checkInteger($key, trim(addslashes($value[self::END_FROM]))) . "$",
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::RANGE, $value)) {
                    if (trim($value[self::RANGE]['from']) && !trim($value[self::RANGE]['to'])) {
                        $conditions[$key] =  ['$gte' => self::checkInteger($key, trim($value[self::RANGE]['from']))];
                    } elseif (trim($value[self::RANGE]['to']) &&
                        !trim($value[self::RANGE]['from'])) {
                        $conditions[$key] =  ['$lte' => self::checkInteger($key, trim($value[self::RANGE]['to']))];
                    } else {
                        $conditions[$key] =  [
                            '$gte' => self::checkInteger($key, trim($value[self::RANGE]['from'])),
                            '$lte' => self::checkInteger($key, trim($value[self::RANGE]['to']))
                        ];
                    }
                }

            }
        }

        if (isset($filterParams['search'])) {
            $conditions['$text'] = ['$search' => self::checkInteger($key, trim(addslashes($filterParams['search'])))];
        }

        return $conditions;
    }

    public static function checkInteger($key, $value) {
        if ($key == 'variants.price' ||
            $key == 'variants.quantity') {
            $value = trim($value);
            return (float)$value;
        }

        return trim($value);
    }

    public function syncWithSource($data) {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);

            if ($connectorHelper) {
                return $connectorHelper->syncWithSource($data);
            }
        }
    }

    public function importCSV($data){
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);

            if ($connectorHelper) {
                return $connectorHelper->importCSV($data);
            }
        }
    }

    public function createCustomers($customer , $marketplace){
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("customer_container");

        $customer['source_marketplace'] = $marketplace;

        if ( $collection->count() == 0 ) {
            $collection->createIndex([
                'source_customer_id' => "text",
            ]);
        }

        $getCustomer = $collection->count(['$and'=>[['source_customer_id' => $customer['source_customer_id']],['user_id'=>$customer['user_id']]]]);
        $getCustomer = json_decode(json_encode($getCustomer), true);

        if (!empty($getCustomer)) {
            print_r("Update");
            try {
                if(isset($customer['app_code'])) {
                    $appCode = $customer['app_code'];
                    if(isset($getCustomer['app_codes'])) {
                        if(!in_array($appCode, $getCustomer['app_codes'])) {                        
                            $getCustomer['app_codes'][] = $appCode;
                            $customer['app_codes'] = $getCustomer['app_codes'];
                        }else{
                            $customer['app_codes'] = [$appCode];
                        }
                    }
                    else {
                        $customer['app_codes'] = [$appCode];
                    }
                }

                $res = $collection->updateOne(['$and'=>[['source_customer_id' => $customer['source_customer_id']],['user_id'=>$customer['user_id']]]], ['$set' => $customer]);
            } catch (\Exception $e) {
                return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while update'];
            }

            return ['key' => 'updated','message' => 'Updated' , 'source_customer_id' => $customer['source_customer_id'],'user_id'=>$customer['user_id'], 'res' => $res->getModifiedCount()];
        }
        print_r("Created");
        try {
            if(isset($customer['app_code'])) {
                $appCode = $customer['app_code'];
                $customer['app_codes'] = [$appCode];
            }

            $res = $collection->insertOne($customer);
        } catch (\Exception $e) {
            return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while insert'];
        }
        return ['key' => 'inserted','message' => 'Inserted' , 'source_customer_id' => $customer['source_customer_id'],'user_id'=>$customer['user_id'], 'res' => $res->getInsertedCount()];
    }

    public function createCustomersAndAttributes($customers , $marketplace = '') {
        $response = [];

        foreach ($customers as $customerData) {
            $response[] = $this->createCustomers($customerData, $marketplace);
        }

        return $response;
    }

    public function validateCustomer($customer) {
        if ( !isset($customer['details']['email']) ) return ['errorFlag' => true, 'message' => '"email" not exits'];

        return true;
    }

    public function createUploadedData($customers , $marketplace = '', $shop_id = false) {
        $totalNew = 0;

        if ( !$shop_id )
        return ['errorFlag' => 'true', 'message' => 'shopId not found!', 'status' => 'exception while creating index'];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("shopify_customer_upload_".$shop_id);

        try {
            $collection->createIndex([
                'source_id' => "text",
                'customer.id' => 1,
                "customer.admin_graphql_api_id" => 1
            ]);
        } catch (\Exception $e) {
            return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while creating index'];
        }

        $customers['customer_id'] = $customers['source_id'];
        if ( $collection->count(['source_id' => (string)$customers['source_id']]) ) {
            $customers['updated_at'] = date("l jS \of F Y h:i:s A");
            try {
                $collection->updateOne(['source_id' => (string)$customers['source_id']], ['$set' => $customers]);
            } catch (\Exception $e) {
                return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while update'];
            }
        } else {
            $customers['is_new'] = 1;
            $customers['inserted_at'] = date("l jS \of F Y h:i:s A");
            try {
                $collection->insertOne($customers);
            } catch (\Exception $e) {
                return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while insert'];
            }

            $totalNew++;
        }

        $customerData = $this->getCustomerByID($customers);
        $this->createCustomersAndAttributes([$customerData], $marketplace);

        return $totalNew;
    }

    /**
     * Delete the customer from ,merchant specific customer table
     * @param $customerIds
     * @return array
     */
    public function deleteCustomer($customerIds)
    {
        $collection = $this->getCollection();
        if (isset($customerIds['id'])) {
            $containerValues = $this->loadByField(["_id" => $customerIds['id']]);
        } elseif (isset($customerIds['source_customer_id'])) {
            $containerValues = $this->loadByField(["details.source_customer_id" => $customerIds['source_customer_id']]);
        } else {
            return [
                'success' => false,
                'message' => 'Please input id or source_customer_id',
                'code' => 'invalid_data'
            ];
        }

        $merchantId = $this->di->getUser()->id;
        if ($containerValues) {
            $containerId = false;
            if (is_array($containerValues)) {
                $containerId = $containerValues['_id'];
            } else {
                $containerId = $containerValues->_id;
            }

            $marketplaceProduct = $this->di->getObjectManager()
                ->create('\App\Connector\Models\MarketplaceCustomer');
            if (isset($customerIds['marketplace'])) {
                $marketplaceProduct->deleteCustomer(
                    $merchantId,
                    $customerIds['marketplace'],
                    ['_id' => $containerId]
                );
                return [
                    'success' => true,
                    'message' =>  $customerIds['marketplace'].' Customer Deleted Successfully',
                    'data' => [$containerId]
                ];
            }
            $status = $collection->deleteOne(['_id' => $containerId], ['w' => true]);

            if ($status->isAcknowledged()) {
                $allMarketplace = $this->di->getObjectManager()
                    ->get('\App\Connector\Components\Connectors')
                    ->getConnectorsWithFilter(['installed' => 1], $merchantId);
                foreach ($allMarketplace as $marketplace) {
                    $marketplaceProduct->deleteCustomer(
                        $merchantId,
                        $marketplace['code'],
                        ['_id' => $containerId]
                    );
                }

                return [
                    'success' => true,
                    'message' => 'Customer Deleted Successfully',
                    'data' => [$containerId]
                ];
            }
            return [
                'success' => false,
                'code' => 'error_in_delete',
                'message' => 'Error occur in customer deletion',
                'data' => []
            ];
        }
        return [
            'success' => false,
            'code' => 'customer_not_found',
            'message' => 'No customer found'
        ];
    }

    public function getCustomer($customerIds)
    {
        if (isset($customerIds['id'])) {
            $containerValues = $this->loadByField(["_id" => $customerIds['id']]);
        } elseif (isset($customerIds['source_customer_id'])) {
            $containerValues = $this->loadByField(["details.source_customer_id" => $customerIds['source_customer_id']]);
        } else {
            return [
                'success' => false,
                'message' => 'Please input id or source_customer_id',
                'code' => 'invalid_data'
            ];
        }

        if ($containerValues) {
            return [
                'success' => true,
                'message' => 'Customer Data',
                'data' => $containerValues
            ];
        }
        return [
            'success' => false,
            'message' => 'Customer not found',
            'code' => 'not_found'
        ];
    }

    public function updateCustomer($data, $userId = false) {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);
            if ($connectorHelper) {
                return $connectorHelper->updateCustomer($data, $userId);
            }
        }
    }

    private function buildAggregateQuery(array $params, string $callType = 'getCustomer'): array
    {
        $aggregation = [];
        $andQuery = [];
        $orQuery = [];
        $filterType = '$and';
        $userId = $this->di->getUser()->id;

        // type is used to check user Type, either admin or some customer
        if (isset($params['type'])) {
            $type = $params['type'];
        } else {
            $type = '';
        }

        if (isset($params['filterType'])) {
            $filterType = '$' . $params['filterType'];
        }

        if (isset($params['filter']) || isset($params['search'])) {
            $andQuery = self::search($params);
        }

        if (isset($params['or_filter'])) {
            $orQuery = self::search(['filter' => $params['or_filter']]);
            $temp = [];
            foreach ($orQuery as $optionKey => $orQueryValue) {
                $temp[] = [
                    $optionKey => $orQueryValue,
                ];
            }

            $orQuery = $temp;
        }

        if ($type != 'admin') {
            $aggregation[] = ['$match' => ['user_id' => $userId]];
        }

        if (isset($params['next']) && !empty($params['next']) && $callType === 'getCustomer') {
            $nextDecoded = json_decode(base64_decode($params['next']), true);
            if (!empty($nextDecoded['cursor'])) {
                $aggregation[] = [
                    '$match' => ['_id' => [
                        '$gt' => new \MongoDB\BSON\ObjectId($nextDecoded['cursor']),
                    ]],
                ];
            }
        } else if (isset($params['prev']) && !empty($params['prev']) && $callType === 'getCustomer') {
            $aggregation[] = [
                '$match' => ['_id' => [
                    '$gt' => new \MongoDB\BSON\ObjectId($params['prev']),
                ]],
            ];
        }

        if(isset($params['source_customer_ids'])) {
            $aggregation[] = [
                '$match' => ['source_customer_id' => [
                    '$in' => $params['source_customer_ids']
                ]],
            ];
        }

        if(isset($params['app_code'])) {
            $aggregation[] = [
                '$match' => ['app_codes' => ['$in' => [$params['app_code']]]],
            ];
        }

        if (count($andQuery)) {
            $aggregation[] = ['$match' => [
                $filterType => [
                    $andQuery,
                ],
            ]];
        }

        if ($orQuery !== []) {
            $aggregation[] = ['$match' => [
                '$or' => $orQuery,
            ]];
        }

        return $aggregation;
    }

    public function syncData($data) {
        if (isset($data['source'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['source']);
            if ($connectorHelper) {
                return $connectorHelper->initiateSelectAndSync($data);
            }
        }

        return false;
    }
}
