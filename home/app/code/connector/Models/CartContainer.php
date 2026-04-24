<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;
use Phalcon\Validation;
use Phalcon\Validation\Validator\InvalidValue;

class CartContainer extends BaseMongo
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

    protected $table = 'cart_container';

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
        $token = $this->di->getRegistry()->getDecodedToken();
        $this->setSource($this->table . '_' . $token['user_id']);
        $this->initializeDb($this->getMultipleDbManager()->getDb());
    }

    public function importCarts($data)
    {

        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);
            if ($connectorHelper) {
                return $connectorHelper->initiateCartImport($data);
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
                return $connectorHelper->initiateCartUpload($data);
            }
        }
    }

    public function getCartByID($data) {
        $collection = $this->getCollection();
        if ( isset($data['source_id']) ) {
            $data['source_cart_id'] = $data['source_id'];
        }

        if ( !isset($data['cart_id']) ) return ['success' => false, 'message' => 'Required Param "cart_id" missing.'];

        $res = $collection->findOne(["source_cart_id" => (string)$data['cart_id']],["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if ( count($res) ) {
            return ['success' => true,'data' => $res, 'message' => 'Cart Found'];
        }

        return ['success' => false, 'message' => 'Cart not found.'];
    }

    public function removeCartByID($data) {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $uploadCollection = $mongo->getCollectionForTable("shopify_cart_upload_".$this->_shop_id);
        $collection = $this->getCollection();
        if ( isset($data['source_id']) ) {
            $data['cart_id'] = $data['source_id'];
        }

        if ( !isset($data['cart_id']) ) return ['success' => false, 'message' => 'Required Param "cart_id" missing.'];

        $res = $collection->updateOne(['$or' => [
            ['source_id' => (string)$data['cart_id']],
            ['source_id' => (int)$data['cart_id']],
        ]], ['$set' => ['shopifyUpload' => []]]);
        $uploadCollection->deleteOne(['$or' => [
            ['source_id' => (string)$data['cart_id']],
            ['source_id' => (int)$data['cart_id']],
        ]]);
        if ( count($res) ) {
            return ['success' => true,'data' => $res, 'message' => 'Cart Found'];
        }

        return ['success' => false, 'message' => 'Cart not found.'];
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

    public function getCarts($params)
    {
        $this->di->getUser()->id;
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

        if(isset($params['app_code'])) {
            $aggregation[] = [
                '$match' => ['app_codes' => ['$in' => [$params['app_code']]]],
            ];
        }

        if(count($conditionalQuery) > 0){
            $aggregation[] = ['$match' => $conditionalQuery];
        }

        if ( isset($uploaded) ) {
            if ( $uploaded == 1 ) {
                $conditionalQuery["cart_uploaded"] = [
                    '$nin' => []
                ];
            } else {
                $conditionalQuery["cart_uploaded"] = [
                    '$eq' => []
                ];
            }
        }

//        $aggregation[] = [
//            '$replaceRoot' => ['newRoot'=> [ '$mergeObjects'=> [ [ '$arrayElemAt'=> [ '$fromItems', 0 ] ], '$$ROOT' ] ] ]
//        ];
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
     * Here we can find the product by key value
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

    public function createCarts($cart , $marketplace){
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("cart_container");

        $cart['source_marketplace'] = $marketplace;

        if ( $collection->count() == 0 ) {
            $collection->createIndex([
                'source_cart_id' => "text",
            ]);
        }

        $getCart = $collection->count(['$and'=>[['source_cart_id' => $cart['source_cart_id']],['user_id'=>$cart['user_id']]]]);
        $getCart = json_decode(json_encode($getCart), true);
        if (!empty($getCart)) {
            print_r("Update");
            try {
                if(isset($cart['app_code'])) {
                    $app_code = $cart['app_code'];
                    if(isset($getCart['app_codes'])) {
                        if(!in_array($app_code, $getCart['app_codes'])) {                        
                            $getCart['app_codes'][] = $app_code;
                            $cart['app_codes'] = $getCart['app_codes'];
                        }else{
                            $cart['app_codes'] = [$app_code];
                        }
                    }
                    else {
                        $cart['app_codes'] = [$app_code];
                    }
                }

                $res = $collection->updateOne(['$and'=>[['source_cart_id' => $cart['source_cart_id']],['user_id'=>$cart['user_id']]]], ['$set' => $cart]);
            } catch (\Exception $e) {
                return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while update'];
            }

            return ['key' => 'updated','message' => 'Updated' , 'source_cart_id' => $cart['source_cart_id'],'user_id'=>$cart['user_id'], 'res' => $res->getModifiedCount()];
        }
        print_r("Created");
        try {
            if(isset($cart['app_code'])) {
                $app_code = $cart['app_code'];
                $cart['app_codes'] = [$app_code];
            }

            $res = $collection->insertOne($cart);
        } catch (\Exception $e) {
            return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while insert'];
        }

        return ['key' => 'inserted','message' => 'Inserted' , 'source_cart_id' => $cart['source_cart_id'],'user_id'=>$cart['user_id'], 'res' => $res->getInsertedCount()];
    }

    public function createCartsAndAttributes($carts , $marketplace = '') {
        $response = [];

        foreach ($carts as $cartData) {
            $response[] = $this->createCarts($cartData, $marketplace);
        }

        return $response;
    }

    public function validateCart($cart) {
        if ( !isset($cart['details']['email']) ) return ['errorFlag' => true, 'message' => '"email" not exits'];

        return true;
    }

    public function createUploadedData($carts , $marketplace = '', $shop_id = false) {
        $totalNew = 0;

        if (!$shop_id) return ['errorFlag' => 'true', 'message' => 'shopId not found!', 'status' => 'exception while update'];


        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("shopify_cart_upload_".$shop_id);

//        if ( $collection->count() == 0 ) {
        try {
            $collection->createIndex([
                'source_id' => "text",
                'cart.id' => 1,
                "cart.admin_graphql_api_id" => 1
            ]);
        } catch (\Exception $e) {
            return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while creating index'];
        }

//        }

        if ( $collection->count(['source_id' => (string)$carts['source_id']]) ) {
            $carts['updated_at'] = date("l jS \of F Y h:i:s A");
            try {
                $collection->updateOne(['source_id' => (string)$carts['source_id']], ['$set' => $carts]);
            } catch (\Exception $e) {
                return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while update'];
            }
        } else {
            $carts['is_new'] = 1;
            $carts['inserted_at'] = date("l jS \of F Y h:i:s A");
            try {
                $collection->insertOne($carts);
            } catch (\Exception $e) {
                return ['errorFlag' => 'true','message' => $e->getMessage(), 'status' => 'exception while insert'];
            }

            $totalNew++;
        }

        $cartData = $this->getCartByID($carts);
        $this->createCartsAndAttributes([$cartData], $marketplace);

        return $totalNew;
    }

    /**
     * Delete the product from ,merchant specific product table
     * @param $productIds
     * @return array
     */
    public function deleteCart($cartIds)
    {
        $collection = $this->getCollection();
        if (isset($cartIds['id'])) {
            $containerValues = $this->loadByField(["_id" => $cartIds['id']]);
        } elseif (isset($cartIds['source_cart_id'])) {
            $containerValues = $this->loadByField(["details.source_cart_id" => $cartIds['source_cart_id']]);
        } else {
            return [
                'success' => false,
                'message' => 'Please input id or source_cart_id',
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
                ->create('\App\Connector\Models\MarketplaceCart');
            if (isset($cartIds['marketplace'])) {
                $marketplaceProduct->deleteCart(
                    $merchantId,
                    $cartIds['marketplace'],
                    ['_id' => $containerId]
                );
                return [
                    'success' => true,
                    'message' =>  $cartIds['marketplace'].' Cart Deleted Successfully',
                    'data' => [$containerId]
                ];
            }
            $status = $collection->deleteOne(['_id' => $containerId], ['w' => true]);

            if ($status->isAcknowledged()) {
                $allMarketplace = $this->di->getObjectManager()
                    ->get('\App\Connector\Components\Connectors')
                    ->getConnectorsWithFilter(['installed' => 1], $merchantId);
                foreach ($allMarketplace as $marketplace) {
                    $marketplaceProduct->deleteCart(
                        $merchantId,
                        $marketplace['code'],
                        ['_id' => $containerId]
                    );
                }

                return [
                    'success' => true,
                    'message' => 'Cart Deleted Successfully',
                    'data' => [$containerId]
                ];
            }
            return [
                'success' => false,
                'code' => 'error_in_delete',
                'message' => 'Error occur in cart deletion',
                'data' => []
            ];
        }
        return [
            'success' => false,
            'code' => 'cart_not_found',
            'message' => 'No cart found'
        ];
    }

    public function getCart($cartIds)
    {
        if (isset($cartIds['id'])) {
            $containerValues = $this->loadByField(["_id" => $cartIds['id']]);
        } elseif (isset($cartIds['source_cart_id'])) {
            $containerValues = $this->loadByField(["details.source_product_id" => $cartIds['source_cart_id']]);
        } else {
            return [
                'success' => false,
                'message' => 'Please input id or source_cart_id',
                'code' => 'invalid_data'
            ];
        }

        if ($containerValues) {
            return [
                'success' => true,
                'message' => 'Cart Data',
                'data' => $containerValues
            ];
        }
        return [
            'success' => false,
            'message' => 'Cart not found',
            'code' => 'not_found'
        ];
    }


    public function updateCart($data, $userId = false) {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);
            if ($connectorHelper) {
                return $connectorHelper->updateCart($data, $userId);
            }
        }
    }
}
