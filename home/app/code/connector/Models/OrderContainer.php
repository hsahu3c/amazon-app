<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;
use Phalcon\Validation;
use Phalcon\Validation\Validator\InvalidValue;

class OrderContainer extends BaseMongo
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

    protected $table = 'order_container';

    public static $defaultPagination = 20;


    private int $_shop_id = 0;

    /**
     * Initialize the model
     */
    public function onConstruct(): void
    {
        $this->di = $this->getDi();
//        $this->_shop_id = $this->di->getUser()->getShop()->id;
        $this->sqlConfig = $this->di->getObjectManager()->get('\App\Connector\Components\Data');
        $this->di->getRegistry()->getDecodedToken();
        $this->setSource($this->table);
        $this->initializeDb($this->getMultipleDbManager()->getDb());
    }

    public function importOrders($data)
    {

        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);
            if ($connectorHelper) {
                return $connectorHelper->initiateOrderImport($data);
            }
        }
    }

    public function uploadProducts($data)
    {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);

            if ($connectorHelper) {
                return $connectorHelper->initiateOrderUpload($data);
            }
        }
    }


    public function getOrderByID($data) {
        $collection = $this->getCollection();
        if ( isset($data['source_id']) ) {
            $data['source_order_id'] = $data['source_id'];
        }

        if ( !isset($data['order_id']) ) return ['success' => false, 'message' => 'Required Param "order_id" missing.'];

        // return ['collection'=>$data['order_id'],''=>$data];
        // die;
        $res = $collection->findOne(["source_order_id" => (string)$data['order_id'], 'user_id' => $this->di->getUser()->id],["typeMap" => ['root' => 'array', 'document' => 'array']]);

        if (!empty($res) && !is_null($res) && count($res)) {
            return ['success' => true,'data' => $res, 'message' => 'Order Found'];
        }

        return ['success' => false, 'message' => 'Order not found.'];
    }

    public function removeOrderByID($data) {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $uploadCollection = $mongo->getCollectionForTable("shopify_order_upload_".$this->_shop_id);
        $collection = $this->getCollection();
        if ( isset($data['source_id']) ) {
            $data['order_id'] = $data['source_id'];
        }

        if ( !isset($data['order_id']) ) return ['success' => false, 'message' => 'Required Param "order_id" missing.'];

        $res = $collection->updateOne(['$or' => [
            ['source_id' => (string)$data['order_id']],
            ['source_id' => (int)$data['order_id']],
        ]], ['$set' => ['shopifyUpload' => []]]);
        $uploadCollection->deleteOne(['$or' => [
            ['source_id' => (string)$data['order_id']],
            ['source_id' => (int)$data['order_id']],
        ]]);
        if ( count($res) ) {
            return ['success' => true,'data' => $res, 'message' => 'Order Found'];
        }

        return ['success' => false, 'message' => 'Order not found.'];
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

    /**
     * orderOnly = boolean
     */
    public function getOrdersCount($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("order_container")->getCollection();

        $this->di->getUser()->id;

        $countAggregation = $this->buildAggregateQuery($params, 'orderCount');

        $countAggregation[] = [
            '$count' => 'count',
        ];

        try {
            $totalVariantsRows = $collection->aggregate($countAggregation)->toArray();
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                // 'data' => $aggregation
            ];
        }

        $responseData = [
            'success' => true,
            // 'query' => $countAggregation,
            'data' => [],
        ];

        $totalVariantsRows = $totalVariantsRows[0]['count'] ? $totalVariantsRows[0]['count'] : 0;
        $responseData['data']['count'] = $totalVariantsRows;


        return $responseData;
    }

    public function getOrders($params)
    {
        $userId = $this->di->getUser()->id;
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

        $conditionalQuery['user_id'] = $userId; //merchant_id

        if(count($conditionalQuery) > 0){
            $aggregation[] = ['$match' => $conditionalQuery];
        }

        if ( isset($uploaded) ) {
            if ( $uploaded == 1 ) {
                $conditionalQuery["order_uploaded"] = [
                    '$nin' => []
                ];
            } else {
                $conditionalQuery["order_uploaded"] = [
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
    public function getOrdersPhp($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("order_container")->getCollection();

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
            // $rows = $collection->aggregate($aggregation)->toArray();
            $cursor = $collection->aggregate($aggregation);
            $it = new \IteratorIterator($cursor);
            $it->rewind(); // Very important

            $rows = [];
            while ($limit > 0 && $doc = $it->current()) {
                $rows[] = $doc;
                $limit--;
                $it->next();
            }

            // var_dump($it->isDead());die;
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
                // 'data' => $aggregation
            ];
        }

        $responseData = [
            'success' => true,
            // 'query' =>  $aggregation,
            'data' => [
                'totalPageRead' => $page ?? $totalPageRead,
                'current_count' => count($rows),
                'rows' => $rows,
                'next' => $next ?? null,
                'prev' => (string)$rows[0]['_id'] ?? null,
            ],
        ];

        return $responseData;
    }

    /**
     * Here we can find the order by key value
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

                /*if($key == 'variants.quantity'){
                    $conditions[$key] = null;
                }*/
            }
        }

        if (isset($filterParams['search'])) {
            $conditions['$text'] = ['$search' => self::checkInteger($key, trim(addslashes($filterParams['search'])))];
        }

        return $conditions;
    }

    public static function checkInteger($key, $value) {
        if ($key == 'price' ||
            $key == 'quantity') {
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

    public function createOrders($order , $marketplace){
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("order_container");

        $order['source_marketplace'] = $marketplace;

        if ( $collection->count() == 0 ) {
            $collection->createIndex([
                'source_id' => "text",
            ]);
        }

        if ( $collection->count(['source_id' => $order['source_id']]) ) {
            try {
                $res = $collection->updateOne(['source_id' => $order['source_id']], ['$set' => $order]);
            } catch (\Exception $e) {
                return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while update'];
            }

            return ['key' => 'updated','message' => 'Updated' , 'source_id' => $order['source_id'], 'res' => $res->getModifiedCount()];
        }
        try {
            $res = $collection->insertOne($order);
        } catch (\Exception $e) {
            return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while insert'];
        }

        return ['key' => 'inserted','message' => 'Inserted' , 'source_id' => $order['source_id'], 'res' => $res->getInsertedCount()];
    }

    public function multivendorCreateOrders($order , $marketplace){
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("order_container");

        $order['source_marketplace'] = $marketplace;

        if ( $collection->count() == 0 ) {
            $collection->createIndex([
                'source_order_id' => "text",
            ]);
        }

        if ( $collection->count(['$and'=>[['source_order_id' => $order['source_order_id']],['user_id'=>$order['user_id']]]]) ) {
            print_r("Update");
            try {
                $res = $collection->updateOne(['$and'=>[['source_order_id' => $order['source_order_id']],['user_id'=>$order['user_id']]]], ['$set' => $order]);
            } catch (\Exception $e) {
                return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while update'];
            }

            return ['key' => 'updated','message' => 'Updated' , 'source_order_id' => $order['source_order_id'],'user_id'=>$order['user_id'], 'res' => $res->getModifiedCount()];
        }
        print_r("Created");
        try {
            $res = $collection->insertOne($order);
        } catch (\Exception $e) {
            return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while insert'];
        }

        return ['key' => 'inserted','message' => 'Inserted' , 'source_order_id' => $order['source_order_id'],'user_id'=>$order['user_id'], 'res' => $res->getInsertedCount()];
    }

    public function createOrdersAndAttributes($orders , $marketplace = '') {
        $response = [];

        foreach ($orders as $orderData) {
            /*$validation = $this->validateOrder($orderData);
            if ( isset($validation['errorFlag']) ) {
                $response[] = $validation;
                continue;
            }*/
//            $orderData = $this->getAttributes($orderData);
            $response[] = $this->createOrders($orderData, $marketplace);
        }

        return $response;
    }


    public function multivendorCreateOrdersAndAttributes($orders , $marketplace = '') {
        $response = [];

        foreach ($orders as $orderData) {
            $validation = $this->validateOrder($orderData);
            if ( isset($validation['errorFlag']) ) {
                $response[] = $validation;
                continue;
            }

            $response[] = $this->multivendorCreateOrders($orderData, $marketplace);
        }

        return $response;
    }

    public function validateOrder($order) {
        if ( !isset($order['email']) ) return ['errorFlag' => true, 'message' => '"email" not exits'];

        if ( !isset($order['line_items']) || count($order['line_items']) < 1 ) return ['errorFlag' => true, 'message' => '"line_items" is not proper'];

        return true;
    }

    public function createUploadedData($orders , $marketplace = '', $shop_id = false) {
        $totalNew = 0;

        if ( !$shop_id )
        return ['errorFlag' => 'true', 'message' => 'shopId not found', 'status' => 'exception while creating index'];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("shopify_order_upload_".$shop_id);
//        if ( $collection->count() == 0 ) {
        try {
            $collection->createIndex([
                'source_id' => "text",
                'order.id' => 1,
                "order.admin_graphql_api_id" => 1
            ]);
        } catch (\Exception $e) {
            return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while creating index'];
        }

//        }

        if ( $collection->count(['source_id' => (string)$orders['source_id']]) ) {
            $orders['updated_at'] = date("l jS \of F Y h:i:s A");
            try {
                $collection->updateOne(['source_id' => (string)$orders['source_id']], ['$set' => $orders]);
            } catch (\Exception $e) {
                return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while update'];
            }
        } else {
            $orders['is_new'] = 1;
            $orders['inserted_at'] = date("l jS \of F Y h:i:s A");
            try {
                $collection->insertOne($orders);
            } catch (\Exception $e) {
                return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while insert'];
            }

            $totalNew++;
        }

        $orderData = $this->getOrderByID($orders);
        $this->createOrdersAndAttributes([$orderData], $marketplace);

        return $totalNew;
    }

    public function createUserOrdersAndAttributes($orders , $marketplace = '') {
        $response = [];

        foreach ($orders as $orderData) {
            $response[] = $this->UserCreateOrders($orderData, $marketplace);
        }

        return $response;
    }

    public function UserCreateOrders($order , $marketplace){
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("order_container");

        $order['source_marketplace'] = $marketplace;

        if ( $collection->count() == 0 ) {
            $collection->createIndex([
                'source_order_id' => "text",
            ]);
        }

        $getOrder = $collection->findOne(['$and'=>[['source_order_id' => $order['source_order_id']],['user_id'=>$order['user_id']]]]);
        $getOrder = json_decode(json_encode($getOrder), true);
        if(!empty($getOrder)) {
            print_r("Updated");
            if(isset($order['app_code'])) {
                $app_code = $order['app_code'];
                if(isset($getOrder['app_codes'])) {
                    if(!in_array($app_code, $getOrder['app_codes'])) {                        
                        $getOrder['app_codes'][] = $app_code;
                        $order['app_codes'] = $getOrder['app_codes'];
                    }else{
                        $order['app_codes'] = [$app_code];
                    }
                }
                else {
                    $order['app_codes'] = [$app_code];
                }
            }

            try {
                $res = $collection->updateOne(['$and'=>[['source_order_id' => $order['source_order_id']],['user_id'=>$order['user_id']]]], ['$set' => $order]);
            } catch (\Exception $e) {
                return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while update'];
            }

            return ['key' => 'updated','message' => 'Updated' , 'source_order_id' => $order['source_order_id'],'user_id'=>$order['user_id'], 'res' => $res->getModifiedCount()];
        }
        print_r("Created");
        try {
            if(isset($order['app_code'])) {
                $app_code = $order['app_code'];
                $order['app_codes'] = [$app_code];
            }

            $res = $collection->insertOne($order);
        } catch (\Exception $e) {
            return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while insert'];
        }
        return ['key' => 'inserted','message' => 'Inserted' , 'source_order_id' => $order['source_order_id'],'user_id'=>$order['user_id'], 'res' => $res->getInsertedCount()];
    }

    /**
     * Initiate Product Uploading
     * @param $data
     * @return mixed
     */
    public function uploadOrders($data)
    {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);

            if ($connectorHelper) {
                return $connectorHelper->initiateOrderUpload($data);
            }
        }
    }

    private function buildAggregateQuery(array $params, string $callType = 'getOrder'): array
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

        if (isset($params['next']) && $callType === 'getOrder') {
            $nextDecoded = json_decode(base64_decode($params['next']), true);
            $aggregation[] = [
                '$match' => ['_id' => [
                    '$gt' =>  new \MongoDB\BSON\ObjectId($nextDecoded['cursor']),
                ]],
            ];
        } else if (isset($params['prev']) && $callType === 'getOrder') {
            $aggregation[] = [
                '$match' => ['_id' => [
                    '$lt' =>  new \MongoDB\BSON\ObjectId($params['prev']),
                ]],
            ];
        }

        if(isset($params['source_order_ids'])) {
            $aggregation[] = [
                '$match' => ['source_order_id' => [
                    '$in' => $params['source_order_ids']
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
