<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;
use App\Connector\Models\Product as ConnectorProductModel;
use App\Connector\Models\SourceModel as ConnectorSourceModel;
use App\Connector\Components\ProductHelper as ProductHelper;
use Phalcon\Validation;

class ProductContainer extends BaseMongo
{
    use \App\Core\Components\Concurrency;

    const RANGE = 7;

    const END_FROM = 6;

    const START_FROM = 5;

    const IS_EQUAL_TO = 1;

    const IS_CONTAINS = 3;

    const IS_NOT_EQUAL_TO = 2;

    const IS_NOT_CONTAINS = 4;

    const IS_GREATER_THAN = 8;

    const IS_LESS_THAN = 9;

    const CONTAIN_IN_ARRAY = 10;

    const NOT_CONTAIN_IN_ARRAY = 11;

    const KEY_EXISTS = 12;

    const PRODUCT_TYPE_SIMPLE = 'simple';

    const PRODUCT_TYPE_VARIANT = 'variant';

    const VARIANT_FIELDS_REQUIRED = [
        'price' => 'float',
        'quantity' => 'int',
        'source_product_id' => 'string',
        'user_id' => "string",
        "shop_id" => "string",
        "title" => "string",
    ];

    const VI_PRODUCT_FIELDS_REQUIRED = [
        'source_product_id' => 'string',
        'container_id' => 'string',
        'user_id' => "string",
        "shop_id" => "string",
        "title" => "string",
    ];

    const DEFAULT_FIELDS = [
        "description" => "",
        "additional_images" => [],
        "variant_attributes" => [],
        // "marketplace" => [],
        "brand" => "",
    ];

    const DEFAULT_FIELDS_FOR_VARIANT = [
        "description" => "",
        "additional_images" => [],
        "variant_attributes" => [],
        "brand" => "",
        "sku" => "",
    ];

    //protected $sqlConfig;
    protected $implicit = false;

    protected $table = 'product_container';

    public static $defaultPagination = 20;
    private $firedProductCreateEvents = [];
    private $pendingProductsInBulk = 0;
    private $totalNewProducts = 0;


    /**
     * Initialize the model
     */
    public function onConstruct(): void
    {
        $this->di = $this->getDi();

        $this->di->getRegistry()->getDecodedToken();

        $this->setSource($this->table);

        $this->initializeDb($this->getMultipleDbManager()->getDb());
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
     * productOnly = boolean
     */

    public function getProductsCount($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container")->getCollection();

        $this->di->getUser()->id;

        $productCountType = isset($params['product_count_type']) ? $params['product_count_type'] : 'productCountall';
        $countAggregation = $this->buildAggregateQuery($params, $productCountType);


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

        $totalVariantsRows = isset($totalVariantsRows[0]['count']) ? $totalVariantsRows[0]['count'] : 0;
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
    public function getProducts($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container")->getCollection();

        $totalPageRead = 1;
        $limit = $params['count'] ?? self::$defaultPagination;
        $prev = [];

        if (isset($params['next'])) {
            $nextDecoded = json_decode(base64_decode($params['next']), true);
            $prev = $nextDecoded['pointer'] ?? null;
            $totalPageRead = $nextDecoded['totalPageRead'];
        }

        if (isset($params['prev'])) {
            $nextDecoded = json_decode(base64_decode($params['prev']), true);
            $prev = $nextDecoded['cursor'] ?? null;
            $totalPageRead = $nextDecoded['totalPageRead'];
        }

        if (isset($params['prev'])) {
            $prevDecoded = json_decode(base64_decode($params['prev']), true);
        }

        if (isset($params['activePage'])) {
            $page = $params['activePage'];
            $offset = ($page - 1) * $limit;
        }

        $this->di->getUser()->id;
        $aggregation = $this->buildAggregateQuery($params);
        //Code modified by utkarsh for twitter project started
        if (isset($params['sortBy'])) {
            $sort = [
                '$sort' => [
                    $params['sortBy'] => 1
                ]
            ];
            $aggregation[] = $sort;
        }

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
            }

            if (!$it->isDead()) {
                if (!isset($params['prev'])) {
                    $prev[] = $rows[0]['_id'];
                } else if (count($prev) > 1) {
                    array_pop($prev);
                }

                $next = base64_encode(json_encode([
                    'cursor' => ($doc)['_id'],
                    'pointer' => $prev,
                    'totalPageRead' => $totalPageRead + 1,
                ]));
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        $prevCursor = null;
        if (count($prev) >= 1 || isset($params['prev'])) {
            if (count($prev) === 1 && $rows[0]['_id'] === $prev[0]) {
                $prevCursor = null;
            } else {
                $prevCursor = base64_encode(json_encode([
                    'cursor' => $prev,
                    'totalPageRead' => $totalPageRead + 1,
                ]));
            }
        }

        $responseData = [
            'success' => true,
            'data' => [
                'totalPageRead' => $page ?? $totalPageRead,
                'current_count' => count($rows),
                'next' => $next ?? null,
                'prev' => $prevCursor,
                'rows' => $rows,
            ],
        ];

        return $responseData;
    }

    private function buildAggregateQuery($params, $callType = 'getProduct'): array
    {
        $productOnly = false;
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

        if (isset($params['productOnly'])) {
            $productOnly = $params['productOnly'] === "true";
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
            $aggregation[] = ['$match' => ['user_id' => $userId, 'shop_id' => $params['shop_id'] ?? $params['source']['shop_id'] ?? $this->di->getRequester()->getSourceId()]];
        }


        if (!isset($params['productOnly']) && $callType === "productCountall") {
            $aggregation[] = ['$match' => ['visibility' => ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH]];
        }

        if ($callType === "productCount") {
            $aggregation[] = ['$match' => ['type' => ConnectorProductModel::PRODUCT_TYPE_SIMPLE]];
        }


        if (isset($params['next']) && $callType === 'getProduct') {
            $nextDecoded = json_decode(base64_decode($params['next']), true);
            $aggregation[] = [
                '$match' => [
                    '_id' => [
                        '$gt' => $nextDecoded['cursor'],
                    ]
                ],
            ];
        } elseif (isset($params['prev']) && $callType === 'getProduct') {
            $prevDecoded = json_decode(base64_decode($params['prev']), true);
            if (count($prevDecoded['cursor']) != 0) {
                $lastIndex = $prevDecoded['cursor'][count($prevDecoded['cursor']) - 1];
                $aggregation[] = [
                    '$match' => [
                        '_id' => [
                            '$gte' => $lastIndex,
                        ]
                    ],
                ];
            }
        }

        if (isset($params['container_ids'])) {
            $aggregation[] = [
                '$match' => [
                    'container_id' => [
                        '$in' => $params['container_ids']
                    ]
                ],
            ];
        }

        if (isset($params['app_code'])) {
            $aggregation[] = [
                '$match' => ['app_codes' => ['$in' => [$params['app_code']]]],
            ];
        }

        if ($productOnly) {
            $aggregation[] = [
                '$match' => [
                    '$or' => [
                        [
                            '$and' => [
                                ['type' => ConnectorProductModel::PRODUCT_TYPE_VARIATION],
                                ["visibility" => ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH],
                            ]
                        ],
                        [
                            '$and' => [
                                ['type' => ConnectorProductModel::PRODUCT_TYPE_SIMPLE],
                                ["visibility" => ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH],
                            ]
                        ],
                    ],
                ],
            ];
        }



        if (count($andQuery)) {
            $aggregation[] = [
                '$match' => [
                    $filterType => [
                        $andQuery,
                    ],
                ]
            ];
        }

        if ($orQuery !== []) {
            $aggregation[] = [
                '$match' => [
                    '$or' => $orQuery,
                ]
            ];
        }

        return $aggregation;
    }

    /**
     * container_id
     * target_marketplace
     */
    public function getChildProducts($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container")->getCollection();

        $totalPageRead = 1;
        $limit = $params['count'] ?? self::$defaultPagination;
        $prev = [];

        if (isset($params['next'])) {
            $nextDecoded = json_decode(base64_decode($params['next']), true);
            $prev = $nextDecoded['pointer'] ?? null;
            $totalPageRead = $nextDecoded['totalPageRead'];
        }

        if (isset($params['prev'])) {
            $nextDecoded = json_decode(base64_decode($params['prev']), true);
            $prev = $nextDecoded['cursor'] ?? null;
            $totalPageRead = $nextDecoded['totalPageRead'];
        }

        if (isset($params['prev'])) {
            $prevDecoded = json_decode(base64_decode($params['prev']), true);
        }

        if (isset($params['activePage'])) {
            $page = $params['activePage'];
            $offset = ($page - 1) * $limit;
        }

        $userId = $this->di->getUser()->id;
        $aggregation = [];

        $aggregation[] = [
            '$match' => [
                'user_id' => $userId,
                'container_id' => $params['container_id']
            ]
        ];

        if (isset($offset)) {
            $aggregation[] = ['$skip' => (int) $offset];
        }

        $aggregation[] = ['$limit' => (int) $limit + 1];
        try {
            $cursor = $collection->aggregate($aggregation);
            $it = new \IteratorIterator($cursor);
            $it->rewind(); // Very important

            $rows = [];
            $source_child = [];
            $target_child = [];
            while ($limit > 0 && $doc = $it->current()) {
                $rows[] = $doc;
                if (!isset($doc['visibility']) || $doc['visibility'] != ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH) {
                    if (isset($doc['source_marketplace'])) {
                        $source_child[] = $doc;
                    } else if ($doc['target_marketplace'] === $params['target_marketplace']) {
                        $target_child[] = $doc;
                    }
                }

                $limit--;
                $it->next();
            }

            foreach ($target_child as $tchild) {
                foreach ($source_child as $sKey => $sChild) {
                    if ($tchild['source_id'] === $sChild['source_product_id']) {
                        $source_child[$sKey] = json_decode(json_encode($sChild), true)
                            + json_decode(json_encode($tchild), true);
                    }
                }
            }

            if (!$it->isDead()) {
                if (!isset($params['prev'])) {
                    $prev[] = $rows[0]['_id'];
                } else if (count($prev) > 1) {
                    array_pop($prev);
                }

                $next = base64_encode(json_encode([
                    'cursor' => ($doc)['_id'],
                    'pointer' => $prev,
                    'totalPageRead' => $totalPageRead + 1,
                ]));
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => $aggregation
            ];
        }

        $prevCursor = null;
        if (count($prev) > 1 || isset($params['prev'])) {
            if (count($prev) === 1 && $rows[0]['_id'] === $prev[0]) {
                $prevCursor = null;
            } else {
                $prevCursor = base64_encode(json_encode([
                    'cursor' => $prev,
                    'totalPageRead' => $totalPageRead + 1,
                ]));
            }
        }

        $responseData = [
            'success' => true,
            'data' => [
                'totalPageRead' => $page ?? $totalPageRead,
                'current_count' => count($source_child),
                'next' => $next ?? null,
                'prev' => $prevCursor,
                'rows' => $source_child,
            ],
        ];

        return $responseData;
    }

    /**
     * {
     *  [source_product_id : 'ABC', .....],
     *  [source_product_id : 'XYZ' , ... ],
     *  ....
     * }
     */

    public function editedProductNew($data)
    {

        // target_marketplace is marketplace where client want to make changes in it products
        if (!isset($data['target_marketplace'])) {
            return ['success' => false, 'message' => 'Required field target_marketplace missing.'];
        }

        // For Sending Complete Data
        if (isset($data['details']) && isset($data['variants'])) {
            $this->editedProduct($data['details']);
            foreach ($data['variants'] as $variant) {
                $this->editedProduct(['edited' => $variant]);
            }

            return ['success' => true, 'message' => 'Product Saved.'];
        }

        if (!isset($data['source_product_id']) || !isset($data['edited'])) {
            return ['success' => false, 'message' => 'Required field source_product_id | edited missing.'];
        }

        $this->di->getUser()->id;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable($data['target_marketplace'] . '_product_container');
        $mongo->getCollectionForTable('product_container');

        if (isset($data['_id'])) {
            unset($data['_id']);
        }

        $query = ['source_product_id' => $data['source_product_id']];

        if ($collection->findOne($query) === null) {
            $data['_id'] = (string) $this->getCounter('product_id');
            $data['edited']['source_product_id'] = $data['source_product_id'];
            $status = $collection->insertOne($data['edited']);
            $status->getInsertedCount();
        } else {
            $set = ['$set' => $data['edited']];
            $status = $collection->updateOne($query, $set);
            $status->getModifiedCount();
        }

        if ($status) {
            $query = ['source_product_id' => $data['source_product_id']];
            return ['success' => true, 'message' => 'Product saved '];
        }

        return ['success' => false, 'message' => 'no update in product product'];
    }


    public function editedProduct($data)
    {
        // target_marketplace is marketplace where client want to make changes in it products
        if (!isset($data['target_marketplace'])) {
            return ['success' => false, 'message' => 'Required field target_marketplace missing.'];
        }

        // For Sending Complete Data
        if (isset($data['details']) && isset($data['variants'])) {
            $this->editedProduct($data['details']);
            foreach ($data['variants'] as $variant) {
                $this->editedProduct(['edited' => $variant]);
            }

            return ['success' => true, 'message' => 'Product Saved.'];
        }

        if (!isset($data['source_product_id']) || !isset($data['edited'])) {
            return ['success' => false, 'message' => 'Required field source_product_id | edited missing.'];
        }

        $this->di->getUser()->id;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable($data['target_marketplace'] . '_product_container');
        $mongo->getCollectionForTable('product_container');

        if (isset($data['_id'])) {
            unset($data['_id']);
        }

        $query = ['source_product_id' => $data['source_product_id']];

        if ($collection->findOne($query) === null) {
            $data['_id'] = (string) $this->getCounter('product_id');
            $data['edited']['source_product_id'] = $data['source_product_id'];
            $status = $collection->insertOne($data['edited']);
            $status->getInsertedCount();
        } else {
            $set = ['$set' => $data['edited']];
            $status = $collection->updateOne($query, $set);
            $status->getModifiedCount();
        }

        if ($status) {
            $query = ['source_product_id' => $data['source_product_id']];
            return ['success' => true, 'message' => 'Product saved '];
        }

        return ['success' => false, 'message' => 'no update in product product'];
    }

    public function getProductById($productDetails)
    {
        if (isset($productDetails['id']) && isset($productDetails['source_marketplace'])) {
            $sourceProductId = $productDetails['id'];
            if (isset($productDetails['user_id'])) {
                $userId = $productDetails['user_id'];
            } else {
                $userId = $this->di->getUser()->id;
            }

            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('product_container');

            $marketplace_collection = $mongo->getCollectionForTable($productDetails['source_marketplace'] . '_product_container');

            $finalQuery = ['container_id' => (string) $sourceProductId, 'user_id' => (string) $userId];
            $response = $collection->find($finalQuery)->toArray();
            $marketplace_response = $marketplace_collection->findOne($finalQuery, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
            if ($response) {
                $productData = $response;
                return ['success' => true, 'data' => $productData];
            }
        }

        return ['success' => false, 'message' => 'Product not found'];
    }

    public function getMarketplaceProducts($conditionalQuery, $options, $marketplace, $storeId = false, $unwindVariants = false)
    {
        $marketplaceProduct = $this->di->getObjectManager()->create('\App\Connector\Models\MarketplaceProduct');
        $this->getCollection()->createIndex([
            "details.title" => "text",
            "details.short_description" => "text",
            "details.long_description" => "text",
            "variants.source_variant_id" => 1,
        ]);
        $aggregation = [];
        if ($unwindVariants) {
            $aggregation[] = ['$unwind' => '$variants'];
        }

        if (count($conditionalQuery)) {
            $aggregation[] = ['$match' => $conditionalQuery];
        }

        if (isset($options['limit'])) {
            $aggregation[] = ['$limit' => (int) $options['limit']];
            $aggregation[] = ['$skip' => (int) $options['skip']];
        }

        $aggregation[] = [
            '$lookup' => [
                'from' => $marketplaceProduct->getSource(),
                'localField' => "_id",
                // field in the product1 collection
                'foreignField' => "_id",
                // field in the product2 collection
                'as' => "fromItems",
            ],
        ];

        $aggregation[] = [
            '$replaceRoot' => ['newRoot' => ['$mergeObjects' => [['$arrayElemAt' => ['$fromItems', 0]], '$$ROOT']]],
        ];

        $collection = $this->getCollection()->aggregate($aggregation, $options);
        return $collection;
    }

    /**
     * Initiate Product Importing
     * @param $data
     * @return mixed
     */
    public function importProducts($data)
    {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);


            if ($connectorHelper) {
                return $connectorHelper->initiateImport($data);
            }
        }
    }

    /**
     * Sync Products From Source
     * @param $data
     * @return mixed
     */
    public function syncCatalog($data)
    {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);


            if ($connectorHelper) {
                return $connectorHelper->initiateSyncCatalog($data);
            }
        }
    }

    /**
     * Initiate Product Uploading
     * @param $data
     * @return mixed
     */
    public function uploadProducts($data) {}

    /**
     * Initiate Product Uploading By CSV
     * @param $data
     * @return mixed
     */
    public function uploadProductsCSV($data)
    {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);

            if ($connectorHelper) {
                return $connectorHelper->uploadviaCSV($data);
            }
        }
    }

    public function fetchProductsByProfile($profileId, $userId, $sourceShopId = false)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('profiles');
        $profile = $collection->findOne([
            "profile_id" => (int) $profileId,
        ]);

        if ($profile) {
            $productQuery = $profile->query;
            $marketplace = $profile->source;
            $productData = $this->di->getObjectManager()->get('\App\Connector\Models\Product')->getProductsByQuery(['marketplace' => $marketplace, 'query' => $productQuery], $userId, $sourceShopId);
            if ($productData['success']) {
                return $productData['data'];
            }

            return [];
        }
        return false;
    }

    /**
     * @param $data
     * @param $marketplace
     * @param int $storeId
     * @return array
     */
    public function createProductsAndAttributes($data, $marketplace, $homeShopId = 0, $merchantId = false, $targetEventData = [], &$bulkOpArray = [], &$marketplaceAll = [])
    {
        if (!$merchantId) {
            $merchantId = $this->di->getUser()->id;
        }

        $response = [];
        foreach ($data as $productData) 
        {
            $response[] = $this->createProduct($productData, $marketplace, $homeShopId, $merchantId, $targetEventData, $bulkOpArray, $marketplaceAll);
        }

        return $response;
    }

    /**
     * @param $productData
     * @pInvalid Dataaram null $marketplace
     * @return array
     */
    public function createProduct($productData, $marketplace, $homeShopId, $merchantId, $targetEventData, &$bulkOpArray = [], &$marketplaceAll = [])
    {
        $collectResponseData = [];
        if (isset($productData['details']) && isset($productData['variants'])) {
            /*  if (!in_array($productData['details']['type'], [ConnectorProductModel::PRODUCT_TYPE_VARIATION, ConnectorProductModel::PRODUCT_TYPE_SIMPLE])) {
            return ['success' => false, 'message' => 'No Product Type is Given, add variation | simple' . ', Given type is ' . ($productData['details']['type'] ?? null)];
            } */

            //code to stop importing for product in case of product import limit - via plan - start
            $appTag = $this->di->getAppCode()->getAppTag();
            $appCodes = [];
            if ($appTag == 'default') {
                $shops = $this->di->getUser()->shops;
                foreach ($shops as $shop) {
                    if (isset($shop['marketplace']) && ($shop['marketplace'] == $marketplace) && !empty($shop['apps'])) {
                        foreach ($shop['apps'] as $app) {
                            !empty($app['code']) && $appCodes[] = $app['code'];
                        }
                    }
                }
            } else {
                $appCodes[] = $appTag;
            }

            $isRestricted = false;
            foreach ($appCodes as $appCode) {
                $isRestricted = $this->di->getConfig()->get("plan_restrictions")[$appCode]['product']['product_import']['restricted'] ?? false;
            }

            if ($isRestricted) {
                if (class_exists('App\Plan\Models\Plan')) {
                    $planModel = $this->di->getObjectManager()->create('App\Plan\Models\Plan');
                    if (method_exists($planModel, 'isImportSyncAllowed') && ($planModel->isImportSyncAllowed($merchantId, $marketplace) == false)) {
                        $this->di->getLog()->logContent('Product Importing cannot be proceeded for user: ' . $merchantId, 'info', 'product/restrict-create-product/' . date('Y-m-d') . '.log');
                        return [
                            'success' => false,
                            'code' => 'something_went_wrong',
                            'message' => 'Product Importing cannot be proceeded',
                            'data' => [],
                        ];
                    }
                }
            }

            //restriction implementation - end

            if (isset($productData['details']['variant_attribute'])) {
                $productData['details']['variant_attributes'] = $productData['details']['variant_attribute'];
            }

            if (isset($productData['variant_attribute'])) {
                $productData['variant_attributes'] = $productData['variant_attribute'];
            }

            if (isset($productData['variants'][0]['variant_attribute'])) {
                $productData['variants'][0]['variant_attributes'] = $productData['variants'][0]['variant_attribute'];
            }

            if (
                (isset($productData['details']['variant_attributes']) && count($productData['details']['variant_attributes']) > 0) ||
                (isset($productData['variant_attributes']) && count($productData['variant_attributes']) > 0) ||
                (isset($productData['variants'][0]['variant_attributes']) && count($productData['variants'][0]['variant_attributes']) > 0)
            ) {
                $parentExists = false;
                $containerId = isset($productData['details']['container_id']) ? $productData['details']['container_id'] : (isset($productData['variants'][0]['container_id']) ? $productData['variants'][0]['container_id'] : false);
                if (
                    $containerId &&
                    (isset($bulkOpArray['parentInBulkArr'][$containerId]) ||
                        isset($bulkOpArray['parentInDb'][$containerId])
                    )
                ) {
                    $parentExists = true;
                }

                if (!$parentExists || !empty($productData['details']['is_parent'])) {
                    $pData = [
                        'productData' => $productData,
                        'merchantId' => $merchantId,
                        'marketplace' => $marketplace,
                        'home_shop_id' => $homeShopId,
                        'container_id' => $containerId,
                    ];
                    $parentResponseData = $this->createParentProduct($pData, $targetEventData, $bulkOpArray, $marketplaceAll, $parentExists);
                    $collectResponseData[] = $parentResponseData;
                    $parentCreated = true;
                }
            }

            try {
                if (isset($productData['variants'])) {
                    foreach ($productData['variants'] as $value) {
                        if (!isset($value['container_id']) && isset($parentResponseData['source_product_id'])) {
                            $value['container_id'] = $parentResponseData['source_product_id'];
                        }

                        $isolatedProductResponse = $this->createIsolatedProduct($value, $merchantId, $marketplace, $homeShopId, $targetEventData, $bulkOpArray, $marketplaceAll);
                        $collectResponseData[] = $isolatedProductResponse;
                    }
                }
                $eventData = [
                    'productData' => $productData,
                    'user_id' => $merchantId,
                    'marketplace' => $marketplace,
                    'shop_id' => $homeShopId
                ];

                $eventsManager = $this->di->getEventsManager();
                $eventsManager->fire('application:afterProductCreate', $this, $eventData);

                // Only fire event if:
                // 1. A parent product was created (product with variants) OR
                // 2. This is a simple product (no parent, just variant)
                // 3. AND this container_id hasn't already fired the event
                $isSimpleProduct = !isset($productData['details']['variant_attributes']) || count($productData['details']['variant_attributes'] ?? []) === 0;

                $containerId = $productData['details']['container_id'] ?? null;

                // Check if we should fire the event
                $shouldFireEvent = ($parentCreated || $isSimpleProduct) && $containerId && !isset($this->firedProductCreateEvents[$containerId]);
                if ($shouldFireEvent) {
                    // Mark this container_id as fired
                    $this->firedProductCreateEvents[$containerId] = true;
                    $this->pendingProductsInBulk++;
                    $isLast = ($this->totalNewProducts > 0 && $this->pendingProductsInBulk === $this->totalNewProducts);

                    $eventData = [
                        'productData' => $productData,
                        'user_id' => $merchantId,
                        'marketplace' => $marketplace,
                        'shop_id' => $homeShopId,
                        'pending_count' => $this->pendingProductsInBulk, // Pass cumulative count
                        'is_last' => $isLast,
                    ];
                    $eventsManager = $this->di->getEventsManager();
                    $eventsManager->fire('application:afterProductCreateServiceUpdate', $this, $eventData);
                }
                return [
                    'success' => true,
                    'message' => 'Product(s) created successfully.',
                    'data' => $collectResponseData,
                ];
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'code' => 'something_went_wrong',
                    'message' => 'Something went wrong',
                    'data' => [$e->getMessage()],
                ];
            }
        } else {
            return [
                'success' => false,
                'code' => 'invalid_data',
                'message' => 'Invalid Data',
                'data' => [],
            ];
        }
    }

    public function setTotalNewProducts(int $total): void
    {
        $this->totalNewProducts = $total;
    }


    public function resetBulkTracking()
    {
        $this->pendingProductsInBulk = 0;
        $this->firedProductCreateEvents = [];
        $this->totalNewProducts = 0;
    }

    public function checkForUniqueSku(&$product): void
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $productContainer = $mongo->getCollectionForTable('product_container');
        $filter = [
            'user_id' => $this->di->getUser()->id,
            'source_sku' => $product['source_sku']
        ];
        $product_sku_count = $productContainer->count($filter);
        if ($product_sku_count > 0) {
            $counter = (string) $this->getCounter($product['source_sku'], $this->di->getUser()->id);
            $product['sku'] = $product['source_sku'] . '_' . $counter;
        }
    }

    public function createParentProduct($data, $targetEventData, &$bulkOpArray = [], &$marketplaceAll = [], $existing = true)
    {
        $pData = $data['productData'];
        if (!isset($pData['details'], $pData['details']['source_product_id'])) {
            return [
                'success' => false,
                'message' => 'Required Param, Details is missing',
            ];
        }

        try {
            $id = $data['merchantId'];
            $marketplace = $data['marketplace'];
            $homeShopId = $data['home_shop_id'];
            $containerId = $data['container_id'];
            $data = $pData['details'];
            $data['variant_attributes'] = isset($pData['details']['variant_attributes']) ? $pData['details']['variant_attributes'] : (isset($pData['variant_attributes']) ? $pData['variant_attributes'] : []);
            if ($existing && isset($bulkOpArray['parentInDb'][$containerId])) {
                $_id = (string) $bulkOpArray['parentInDb'][$containerId]['_id'] ?? (string) $this->getCounter('product_id');
            } else {
                $_id = (string) $this->getCounter('product_id');
            }

            /*  $data['_id'] = $_id; */
            $data['user_id'] = $id;
            $data['shop_id'] = $homeShopId;
            $data['source_product_id'] ??= $data['container_id'] ?? $data['_id'];
            $data['container_id'] ??= $data['source_product_id'];
            $data['created_at'] = date('c');
            $data['visibility'] = ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH;
            $data['type'] = ConnectorProductModel::PRODUCT_TYPE_VARIATION;
            $data['source_marketplace'] = $marketplace;
            $data = $this->validateDetailsData($data);
            if (isset($data['message']) && $data['success'] === false) {
                return $data;
            }

            $eventsManager = $this->di->getEventsManager();
            $this->di->getObjectManager()->get(ProductHelper::class)->finalizeProduct(['product_data' => &$data, 'bulkOpArray' => &$bulkOpArray]);
            $bulkOpArray[]['updateOne'] = [
                [
                    'user_id' => $id ?? $this->di->getUser()->id,
                    'shop_id' => $data['shop_id'],
                    'container_id' => $data['container_id'],
                    'type' => ConnectorProductModel::PRODUCT_TYPE_VARIATION,
                    'visibility' => ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH
                ],
                [
                    '$setOnInsert' => ['_id' => $_id],
                    '$set' => $data
                ],
                ['upsert' => true]
            ];

            $marketplaceAll[] = $this->prepareMarketplace($data);
            return [
                'success' => true,
                'source_product_id' => $data['source_product_id'],
                'message' => 'Parent Product Created Successfully',
                '_id' => $_id,
                'container_id' => $data['container_id'],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function createIsolatedProduct($data, $id, $marketplace, $homeShopId, $targetEventData, &$bulkOpArray = [], &$marketplaceAll = [])
    {
        try {
            /*   $data['_id'] = */
            $_id = (string) $this->getCounter('product_id');
            $data['user_id'] = $id;
            $data['shop_id'] = (string) $homeShopId;

            if (!isset($data['source_product_id'])) {
                $data['source_product_id'] = $data['_id'];
            }

            if (!isset($data['container_id'])) {
                $data['container_id'] = $data['source_product_id'];
            }

            $data['created_at'] = date('c');
            $data['type'] = ConnectorProductModel::PRODUCT_TYPE_SIMPLE;
            if (!isset($data['variant_attributes']) || count($data['variant_attributes']) === 0) {
                $data['visibility'] = ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH;
            } else {
                $data['visibility'] = ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY;
                if (isset($data['variant_image'])) {
                    $data['main_image'] = $data['variant_image'];
                }
            }

            $data['source_marketplace'] = $marketplace;
            $data = $this->validateVariantData($data);

            if (isset($data['message']) && $data['success'] === false) {
                return $data;
            }

            $eventsManager = $this->di->getEventsManager();
            $this->di->getObjectManager()->get(ProductHelper::class)->finalizeProduct(['product_data' => &$data, 'bulkOpArray' => &$bulkOpArray]);
            $bulkOpArray[]['updateOne'] = [
                [
                    'user_id' => $id,
                    'shop_id' => $data['shop_id'] ?? $homeShopId,
                    'container_id' => $data['container_id'],
                    'source_product_id' => $data['source_product_id'],
                    'type' => ConnectorProductModel::PRODUCT_TYPE_SIMPLE
                ],
                [
                    '$setOnInsert' => ['_id' => $_id],
                    '$set' => $data
                ],
                ['upsert' => true]
            ];
            $marketplaceAll[] = $this->prepareMarketplace($data);
            return [
                'success' => true,
                'message' => 'Variant Created Successfully',
                'data' => $_id,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function validateVariantData($variant)
    {
        foreach (self::VARIANT_FIELDS_REQUIRED as $key => $type) {
            if (!isset($variant[$key])) {
                return ['success' => false, 'message' => 'Required Fields ' . $key . ' is missing'];
            }

            switch ($type) {
                case 'int':
                    $variant[$key] = (int) $variant[$key];
                    break;
                case 'float':
                    $variant[$key] = (float) $variant[$key];
                    break;
                case 'string':
                    $variant[$key] = (string) $variant[$key];
                    break;
                default:
                    break;
            }
        }

        foreach (self::DEFAULT_FIELDS_FOR_VARIANT as $key => $defaultValue) {
            if (!isset($variant[$key])) {
                $variant[$key] = $defaultValue;
            }
        }

        return $variant;
    }

    public function validateDetailsData($details)
    {
        foreach (self::VI_PRODUCT_FIELDS_REQUIRED as $key => $type) {
            if (!isset($details[$key])) {
                return ['success' => false, 'message' => 'Required Fields ' . $key . ' is missing'];
            }

            switch ($type) {
                case 'int':
                    $details[$key] = (int) $details[$key];
                    break;
                case 'float':
                    $details[$key] = (float) $details[$key];
                    break;
                case 'string':
                    $details[$key] = (string) $details[$key];
                    break;
                default:
                    break;
            }
        }

        foreach (self::DEFAULT_FIELDS as $key => $defaultValue) {
            if (!isset($details[$key])) {
                $details[$key] = $defaultValue;
            }
        }

        return $details;
    }

    public function saveMainImage($encodedFile, $count, $merchantId)
    {
        $value = substr($encodedFile['encoded_file'], strpos($encodedFile['encoded_file'], ','), strlen($encodedFile['encoded_file']));
        $decodedImage = base64_decode($value);
        $ext = explode('.', $encodedFile['file_name']);
        $fileName = 'image_' . $count . '_' . time() . '.' . $ext[1];
        $path = 'media' . DS . 'temp' . DS . $fileName;
        $url = 'media' . DS . 'product' . DS . $merchantId . DS . $fileName;
        $handle = fopen($path, 'w');
        fwrite($handle, $decodedImage);
        return $url;
    }

    public function saveAdditionalImages($additionalImgs, $count, $merchantId)
    {
        $additionalImages = '';
        $img_count = 1;
        foreach ($additionalImgs as $varAdditionalImgs) {
            if (isset($varAdditionalImgs['encoded_file'])) {
                $value = substr($varAdditionalImgs['encoded_file'], strpos($varAdditionalImgs['encoded_file'], ','), strlen($varAdditionalImgs['encoded_file']));
                $decodedImage = base64_decode($value);
                $ext = explode('.', $varAdditionalImgs['file_name']);
                $fileName = 'variant_image_' . $count . '' . $img_count . '_' . time() . '.' . $ext[1];
                $path = 'media' . DS . 'temp' . DS . $fileName;
                $url = 'media' . DS . 'product' . DS . $merchantId . DS . $fileName;
                $handle = fopen($path, 'w');
                fwrite($handle, $decodedImage);
                $additionalImages = $additionalImages . $url . ',';
            } else {
                $additionalImages = $additionalImages . substr($varAdditionalImgs, strlen($this->di->getUrl()->get()), strlen($varAdditionalImgs)) . ',';
            }

            $img_count = $img_count + 1;
        }

        return $additionalImages;
    }

    /**
     * Here we can find the product by key value
     * @param $condition
     * @return mixed
     */
    public function loadByField($condition, $id = null)
    {
        if (!$id) {
            $id = $this->di->getUser()->id;
        }

        $filter = array('user_id' => $id) + $condition;
        $collection = $this->getCollection();
        $containerValues = $collection->findOne($filter);

        return $containerValues;
    }


    public function getProduct($productIds)
    {
        if (isset($productIds['id'])) {
            $containerValues = $this->loadByField(["_id" => $productIds['id']]);
        } elseif (isset($productIds['source_product_id'])) {
            $containerValues = $this->loadByField(["details.source_product_id" => $productIds['source_product_id']]);
        } else {
            return [
                'success' => false,
                'message' => 'Please input id or source_product_id',
                'code' => 'invalid_data',
            ];
        }

        if ($containerValues) {
            return [
                'success' => true,
                'message' => 'Product Data',
                'data' => $containerValues,
            ];
        }
        return [
            'success' => false,
            'message' => 'Product not found',
            'code' => 'not_found',
        ];
    }

    public function getFormBuilderJson($attributes, $attributeValues, $configAttribute)
    {
        $configAttribute = $configAttribute ? explode(',', $configAttribute) : [];
        $optionsTable = new \App\Connector\Models\ProductAttributeOption();
        $formJson = [];
        foreach ($attributes as $containerAttrValue) {
            if (in_array($containerAttrValue['code'], $configAttribute)) {
                continue;
            }

            switch ($containerAttrValue['frontend_type']) {
                case 'checkbox':
                    $options = $optionsTable::find(["attribute_id='{$containerAttrValue['id']}'"]);
                    $attrOptions = [];
                    $oldValues = json_decode($attributeValues[$containerAttrValue['code']]);
                    if (!is_array($oldValues)) {
                        $tempVal = $oldValues;
                        $oldValues = [];
                        $oldValues[0] = $tempVal;
                    }

                    $newvalues = [];
                    foreach ($options->toArray() as $optionVal) {
                        if (in_array($optionVal['id'], $oldValues)) {
                            $newvalues[] = $optionVal['value'];
                        }

                        $attrOptions[] = ['id' => $optionVal['value'], 'value' => $optionVal['value']];
                    }

                    array_push($formJson, [
                        'attribute' => $containerAttrValue['label'],
                        'key' => $containerAttrValue['code'],
                        'field' => $containerAttrValue['frontend_type'],
                        'data' => [
                            "value" => $newvalues,
                            "values" => $attrOptions,
                            "required" => $containerAttrValue['is_required'],
                        ],
                    ]);
                    break;
                case 'dropdown':
                    $options = $optionsTable::find(["attribute_id='{$containerAttrValue['id']}'"]);
                    $attrOptions = [];
                    $value = '';
                    foreach ($options->toArray() as $optionValue) {
                        if ($optionValue['id'] == $attributeValues[$containerAttrValue['code']]) {
                            $value = $optionValue['value'];
                        }

                        $attrOptions[] = ['id' => $optionValue['value'], 'value' => $optionValue['value']];
                    }

                    array_push($formJson, [
                        'attribute' => $containerAttrValue['label'],
                        'key' => $containerAttrValue['code'],
                        'field' => $containerAttrValue['frontend_type'],
                        'data' => [
                            "value" => $value,
                            "values" => $attrOptions,
                            "required" => $containerAttrValue['is_required'],
                        ],
                    ]);
                    break;
                case 'fileupload':
                    array_push($formJson, [
                        'attribute' => $containerAttrValue['label'],
                        'key' => $containerAttrValue['code'],
                        'field' => $containerAttrValue['frontend_type'],
                        'data' => [
                            "value" => $this->di->getUrl()->get() . $attributeValues[$containerAttrValue['code']],
                            "required" => $containerAttrValue['is_required'],
                        ],
                    ]);
                    break;
                case 'multipleinputs':
                    $multipleInputsValue = json_decode($attributeValues[$containerAttrValue['code']], true);
                    array_push($formJson, [
                        'attribute' => $containerAttrValue['label'],
                        'key' => $containerAttrValue['code'],
                        'field' => $containerAttrValue['frontend_type'],
                        'data' => [
                            "value" => $multipleInputsValue == null ? [] : $multipleInputsValue,
                            "required" => $containerAttrValue['is_required'],
                        ],
                    ]);
                    break;
                case 'radio':
                    $options = $optionsTable::find(["attribute_id='{$containerAttrValue['id']}'"]);
                    $attrOptions = [];
                    $value = '';
                    foreach ($options->toArray() as $optionValue) {
                        if ($optionValue['id'] == $attributeValues[$containerAttrValue['code']]) {
                            $value = $optionValue['value'];
                        }

                        $attrOptions[] = ['id' => $optionValue['value'], 'value' => $optionValue['value']];
                    }

                    array_push($formJson, [
                        'attribute' => $containerAttrValue['label'],
                        'key' => $containerAttrValue['code'],
                        'field' => $containerAttrValue['frontend_type'],
                        'data' => [
                            "value" => $value,
                            "display" => "inline",
                            "values" => $attrOptions,
                            "required" => $containerAttrValue['is_required'],
                        ],
                    ]);
                    break;
                case 'textarea':
                    array_push($formJson, [
                        'attribute' => $containerAttrValue['label'],
                        'key' => $containerAttrValue['code'],
                        'field' => $containerAttrValue['frontend_type'],
                        'data' => [
                            "value" => $attributeValues[$containerAttrValue['code']],
                            "placeholder" => $containerAttrValue['label'],
                            "required" => $containerAttrValue['is_required'],
                            "maxLength" => 1000,
                        ],
                    ]);
                    break;
                case 'textfield':
                    $fieldType = '';
                    if ($containerAttrValue['backend_type'] == 'varchar' || $containerAttrValue['backend_type'] == 'text') {
                        $fieldType = 'text';
                    } else {
                        $fieldType = 'number';
                    }

                    if (isset($attributeValues[$containerAttrValue['code']])) {
                        array_push($formJson, [
                            'attribute' => $containerAttrValue['label'],
                            'key' => $containerAttrValue['code'],
                            'field' => $containerAttrValue['frontend_type'],
                            'data' => [
                                "type" => $fieldType,
                                "value" => $attributeValues[$containerAttrValue['code']],
                                "placeholder" => $containerAttrValue['label'],
                                "required" => $containerAttrValue['is_required'],
                            ],
                        ]);
                    }

                    break;
                case 'toggle':
                    array_push($formJson, [
                        'attribute' => $containerAttrValue['label'],
                        'key' => $containerAttrValue['code'],
                        'field' => $containerAttrValue['frontend_type'],
                        'data' => [
                            "value" => $attributeValues[$containerAttrValue['code']],
                            "required" => $containerAttrValue['is_required'],
                        ],
                    ]);
                    break;
                case 'calendar':
                    array_push($formJson, [
                        'attribute' => $containerAttrValue['label'],
                        'key' => $containerAttrValue['code'],
                        'field' => $containerAttrValue['frontend_type'],
                        'data' => [
                            "value" => $attributeValues[$containerAttrValue['code']],
                            "required" => $containerAttrValue['is_required'],
                            "minYear" => 1970,
                            "maxYear" => 2045,
                            "displayFormat" => "DD-MM-YYYY",
                        ],
                    ]);
                    break;
            }
        }

        return $formJson;
    }

    /**
     * @param $ids
     * @return array
     */
    public function deleteMultipleProducts($ids)
    {
        $errors = '';
        if ($ids['products'] == 'all') {
            $productIds = [];
            $allProducts = self::find(['columns' => 'id'])->toArray();
            foreach ($allProducts as $value) {
                $productIds[] = [
                    'id' => $value['id'],
                ];
            }

            foreach ($productIds as $value) {
                $response = $this->deleteProduct($value);
                if (!$response['success']) {
                    $errors .= 'Error in product id ' . $value['id'] . ' -> ' . $response['message'] . ', ';
                }
            }
        } else {
            foreach ($ids['products'] as $value) {
                $response = $this->deleteProduct($value);
                if (!$response['success']) {
                    $errors .= 'Error in product id ' . $value['id'] . ' -> ' . $response['message'] . ', ';
                }
            }
        }

        if ($errors == '') {
            $errors = 'All products deleted successfully';
        }

        return ['success' => true, 'message' => $errors];
    }

    /**
     * Delete the product from ,merchant specific product table
     * @param $productIds
     * @return array
     */
    public function deleteProduct($productIds)
    {
        $collection = $this->getCollection();
        $deleteTargetProductDoc = true;

        if (isset($this->di->getConfig()->deleteTargetDocOnWebhook)) {
            $deleteTargetProductDoc = $this->di->getConfig()->deleteTargetDocOnWebhook;
        }

        if (isset($productIds['id'])) {
            $containerValues = $this->loadByField(["_id" => $productIds['id']]);
        } elseif (isset($productIds['source_product_id'])) {
            $containerValues = $this->loadByField(["container_id" => $productIds['source_product_id']]);
        } else {
            $this->di->getLog()->logContent(json_encode([
                'ids' => $productIds,
                'message' => 'Source product ids not found'
            ]), 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'webhook_product_delete.log');

            return [
                'success' => false,
                'message' => 'Please input id or source_product_id',
                'code' => 'invalid_data',
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
                ->create('\App\Connector\Models\MarketplaceProduct');
            if (isset($productIds['marketplace'])) {
                $marketplaceProduct->deleteProduct(
                    $merchantId,
                    $productIds['marketplace'],
                    ['_id' => $containerId]
                );
                return [
                    'success' => true,
                    'message' => $productIds['marketplace'] . ' Product Deleted Successfully',
                    'data' => [$containerId],
                ];
            }
            if ($containerValues['type'] == ConnectorProductModel::PRODUCT_TYPE_VARIATION && $containerValues['visibility'] == ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH) {
                $allProductVariants = $collection->find(['container_id' => (string) $containerValues['source_product_id'], 'user_id' => $merchantId])->toArray();
                $_ids = [];
                foreach ($allProductVariants as $variant) {
                    $_ids[] = $variant['_id'];
                }

                $status = $deleteTargetProductDoc ? $collection->deleteMany(['_id' => ['$in' => $_ids]], ['w' => true]) : $collection->deleteMany(['_id' => ['$in' => $_ids], "target_marketplace" => ['$exists' => false]], ['w' => true]);
            } else {

                $allProducts = $collection->find(['source_product_id' => (string) $containerValues['source_product_id'], 'user_id' => $merchantId])->toArray();
                $_ids = [];
                foreach ($allProducts as $product) {
                    $_ids[] = $product['_id'];
                }

                $status = $deleteTargetProductDoc ? $collection->deleteMany(['_id' => ['$in' => $_ids]], ['w' => true]) : $collection->deleteMany(['_id' => ['$in' => $_ids], "target_marketplace" => ['$exists' => false]], ['w' => true]);
            }

            if ($status->isAcknowledged()) {
                $allMarketplace = $this->di->getObjectManager()
                    ->get('\App\Connector\Components\Connectors')
                    ->getConnectorsWithFilter(['installed' => 1], $merchantId);
                foreach ($allMarketplace as $marketplace) {
                    $marketplaceProduct->deleteProduct(
                        $merchantId,
                        $marketplace['code'],
                        ['_id' => $containerId]
                    );
                }

                if (isset($_ids) && $_ids !== []) {
                    $containerIds = $_ids;
                } else {
                    $containerIds[] = $containerId;
                }

                return [
                    'success' => true,
                    'message' => 'Product Deleted Successfully',
                    'data' => implode(',', $containerIds),
                ];
            }
            $this->di->getLog()->logContent(json_encode([
                'ids' => $containerValues,
                'message' => 'Error occur in product deletion'
            ]), 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'webhook_product_delete.log');
            return [
                'success' => false,
                'code' => 'error_in_delete',
                'message' => 'Error occur in product deletion',
                'data' => [],
            ];
        }
        $this->di->getLog()->logContent(json_encode([
            'ids' => $containerValues,
            'message' => 'Product not found'
        ]), 'info', 'shopify' . DS . $this->di->getUser()->id . DS . 'product' . DS . date("Y-m-d") . DS . 'webhook_product_delete.log');
        return [
            'success' => false,
            'code' => 'product_not_found',
            'message' => 'No product found',
        ];
    }

    public static function search($filterParams = [])
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            $response = self::createFilter($filterParams['filter']);
            $conditions = $response['conditions'];
            $key = $response['key'];
        }

        if (isset($filterParams['global_or_filter'])) {
            // $conditions
            $getOrResponse = self::createFilter($filterParams['global_or_filter']);

            $conditions = [
                '$or' => [
                    $conditions,
                    $getOrResponse['conditions']
                ]
            ];
        }

        if (isset($filterParams['search'])) {
            $conditions['$text'] = ['$search' => self::checkInteger($key, trim(addslashes($filterParams['search'])))];
        }

        return $conditions;
    }

    public static function checkForSpecialCharacters($string)
    {

        $escapedString = preg_quote($string, '/');
        // Check if the escaped string is equal to the original string
        if ($escapedString === $string) {
            return $string; // Return the original string if no special characters were found
        }

        return $escapedString; // Add delimiters outside the escaped string
    }

    public static function createFilter($filter)
    {
        $conditions = [];
        $lastKey = null; // Initialize $lastKey to keep track of the last key processed
        foreach ($filter as $key => $value) {
            $key = trim($key);
            $lastKey = $key;
            if ($key === 'template_name') {
                $conditions["profile.profile_name"] = $value[self::IS_EQUAL_TO];
            } elseif ($key === 'cif_amazon_multi_template_id' && is_string($value[1])) {
                $conditions["profile.profile_id"] = new \MongoDB\BSON\ObjectId($value[self::IS_EQUAL_TO]);
            } elseif ($key === 'cif_amazon_multi_template_id_multi') {
                $conditions["profile.profile_id"] = [
                    '$in' => array_map(fn($id) => new \MongoDB\BSON\ObjectId($id), $value[10])
                ];
            } elseif ($key === 'cif_template_id' && is_string($value[1])) {

                $conditions["profile.profile_id"] = new \MongoDB\BSON\ObjectId($value[self::IS_EQUAL_TO]);
            } elseif ($key === 'cif_amazon_multi_inactive' && is_string($value[1])) {
                $conditions['$and'][] =
                    [
                        '$or' => [
                            ['amazon_multi_parent_filter.status' => $value[1]],
                            ['amazon_multi_parent_filter.status' => null],

                        ]
                    ];
            } elseif ($key === 'cif_amazon_multi_uploaded' && is_string($value[1])) {
                $conditions['$and'][] =
                    [
                        '$or' => [
                            ['amazon_multi_parent_filter.status' => $value[1]],
                        ]
                    ];
            } elseif ($key === 'cif_inactive_product_container' && is_string($value[1])) {
                $conditions['$and'][] =
                    [
                        '$or' => [
                            ['marketplace.status' => $value[1]],
                            ['marketplace.status' => ['$exists' => false]],

                        ]
                    ];
            } elseif ($key == 'cif_amazon_multi_variant_attributes') {
                $temp = [];
                $myArray = explode(',', $value[self::IS_EQUAL_TO]);
                for ($i = 0; $i < count($myArray); $i++) {
                    $temp['variant_attributes.' . $i] = $myArray[$i];
                };
                $temp['variant_attributes'] = [
                    '$size' => count($myArray)
                ];
                $conditions['$and'][] = $temp;
            } elseif ($key === 'cif_amazon_multi_product_inactive' && is_string($value[1])) {
                $conditions['items.status'] =
                    ['$nin' => ['active', 'Inactive', 'Incomplete']];
            } elseif ($key === 'cif_amazon_multi_activity' && is_string($value[1])) {
                $conditions["items." . $value[1]] = [
                    '$exists' => true
                ];
            }  elseif ($key === 'cif_amazon_multi_barcode_exists') {
                if ( $value[self::KEY_EXISTS] == "1" ) {
                    $conditions['$and'][] =
                    [
                        '$and' => [
                            [
                                'items.barcode' => [
                                    '$exists' => true,
                                    '$ne' => null
                                ]
                            ],
                            [ 'items.barcode' => [ '$ne' => "" ] ],
                        ]
                    ];
                } else {
                    $conditions['$and'][] =
                    [
                        '$or' => [
                            ['items.barcode' => ['$exists' => false]],
                            ['items.barcode' => null],
                            ['items.barcode' => ""],

                        ]
                    ];
                }
            } elseif ($key === 'cif_amazon_multi_is_bundle') {
                $conditions['$and'][] =
                    [
                        'is_bundle' => ['$exists' => $value[self::KEY_EXISTS] == "1" ? true : false, '$ne' => ""],
                    ];
            }
            elseif ($key === 'cif_amazon_multi_fulfillment_type') {
                $conditions['$and'][] =
                    [
                        '$or' => [
                            ['items.fulfillment_type' => $value[1]],
                            ['items.fulfillment_type' => null]

                        ]
                    ];
            } elseif ($key === 'cif_amazon_multi_sku') {
                if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                    $conditions['$and'][] = [
                        '$or' => [
                            ['items.sku' => $value[self::IS_EQUAL_TO]],
                            ['items.shopify_sku' => $value[self::IS_EQUAL_TO]]
                        ]
                    ];
                } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                    $conditions['$and'][] = [
                        '$or' => [
                            ['items.sku' => ['$ne' => $value[self::IS_NOT_EQUAL_TO]]],
                            ['items.shopify_sku' => ['$ne' => $value[self::IS_NOT_EQUAL_TO]]]
                        ]
                    ];
                } elseif (array_key_exists(self::IS_CONTAINS, $value)) {
                    $conditions['$and'][] = [
                        '$or' => [
                            ['items.sku' => ['$regex' => $value[self::IS_CONTAINS], '$options' => 'i']],
                            ['items.shopify_sku' => ['$regex' => $value[self::IS_CONTAINS], '$options' => 'i']]
                        ]
                    ];
                } elseif (array_key_exists(self::IS_NOT_CONTAINS, $value)) {
                    $conditions['$and'][] = [
                        '$or' => [
                            ['items.sku' => ['$regex' => "^((?!" . addslashes($value[self::IS_NOT_CONTAINS]) . ").)*$", '$options' => 'i']],
                            ['items.shopify_sku' => ['$regex' => "^((?!" . addslashes($value[self::IS_NOT_CONTAINS]) . ").)*$", '$options' => 'i']]
                        ]
                    ];
                } elseif (array_key_exists(self::START_FROM, $value)) {
                    $conditions['$and'][] = [
                        '$or' => [
                            ['items.sku' => ['$regex' => "^" . addslashes($value[self::START_FROM]), '$options' => 'i']],
                            ['items.shopify_sku' => ['$regex' => "^" . addslashes($value[self::START_FROM]), '$options' => 'i']]
                        ]
                    ];
                } elseif (array_key_exists(self::END_FROM, $value)) {
                    $conditions['$and'][] = [
                        '$or' => [
                            ['items.sku' => ['$regex' => addslashes($value[self::END_FROM]) . "$", '$options' => 'i']],
                            ['items.shopify_sku' => ['$regex' => addslashes($value[self::END_FROM]) . "$", '$options' => 'i']]
                        ]
                    ];
                }
            } else {
                if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                    $conditions[$key] = self::checkInteger($key, $value[self::IS_EQUAL_TO]);
                } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[$key] = ['$ne' => self::checkInteger($key, trim($value[self::IS_NOT_EQUAL_TO]))];
                } elseif (array_key_exists(self::IS_CONTAINS, $value)) {
                    $conditions[$key] = [
                        '$regex' => self::checkInteger($key, self::checkForSpecialCharacters($value[self::IS_CONTAINS])),
                        // '$regex' =>  self::checkInteger($key, preg_replace('/\W/', '\\\\$0', (string)trim($value[self::IS_CONTAINS]))),
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::IS_NOT_CONTAINS, $value)) {
                    $conditions[$key] = [
                        '$regex' => "^((?!" . self::checkInteger($key, trim(addslashes($value[self::IS_NOT_CONTAINS]))) . ").)*$",
                        '$options' => 'i',
                    ];
                } elseif (array_key_exists(self::START_FROM, $value)) {
                    $conditions[$key] = [
                        '$regex' => "^" . self::checkInteger($key, trim(addslashes($value[self::START_FROM]))),
                        '$options' => 'i',
                    ];
                } elseif (array_key_exists(self::END_FROM, $value)) {
                    $conditions[$key] = [
                        '$regex' => self::checkInteger($key, trim(addslashes($value[self::END_FROM]))) . "$",
                        '$options' => 'i',
                    ];
                } elseif (array_key_exists(self::RANGE, $value)) {
                    if (isset($value[self::RANGE]['from']) && !isset($value[self::RANGE]['to'])) {
                        $conditions[$key] = ['$gte' => self::checkInteger($key, trim($value[self::RANGE]['from']))];
                    } elseif (
                        isset($value[self::RANGE]['to']) &&
                        !isset($value[self::RANGE]['from'])
                    ) {
                        $conditions[$key] = ['$lte' => self::checkInteger($key, trim($value[self::RANGE]['to']))];
                    } else {
                        $keyExplode = explode('.', $key);
                        if ($keyExplode[0] == 'items' || $keyExplode[0] == 'marketplace') {
                            $cd = [
                                $keyExplode[0] => [
                                    '$elemMatch' => [
                                        $keyExplode[1] => [
                                            '$gte' => self::checkInteger($key, trim($value[self::RANGE]['from'])),
                                            '$lte' => self::checkInteger($key, trim($value[self::RANGE]['to'])),
                                        ]
                                    ]
                                ]
                            ];
                            $conditions['$and'][] = $cd;
                        } else {
                            $conditions[$key] = [
                                '$gte' => self::checkInteger($key, trim($value[self::RANGE]['from'])),
                                '$lte' => self::checkInteger($key, trim($value[self::RANGE]['to'])),
                            ];
                        }
                    }
                } elseif (array_key_exists(self::IS_GREATER_THAN, $value)) {
                    if (is_numeric(trim($value[self::IS_GREATER_THAN]))) {
                        $conditions[$key] = ['$gte' => self::checkInteger($key, trim($value[self::IS_GREATER_THAN]))];
                    }
                } elseif (array_key_exists(self::IS_LESS_THAN, $value)) {
                    if (is_numeric(trim($value[self::IS_LESS_THAN]))) {
                        $conditions[$key] = ['$lte' => self::checkInteger($key, trim($value[self::IS_LESS_THAN]))];
                    }
                } elseif (array_key_exists(self::CONTAIN_IN_ARRAY, $value)) {
                    $conditions[$key] = [
                        '$in' => $value[self::CONTAIN_IN_ARRAY]
                    ];
                } elseif (array_key_exists(self::NOT_CONTAIN_IN_ARRAY, $value)) {
                    $conditions[$key] = [
                        '$nin' => $value[self::NOT_CONTAIN_IN_ARRAY]
                    ];
                } elseif (array_key_exists(self::KEY_EXISTS, $value)) {
                    $conditions[$key] = [
                        '$exists' => $value[self::KEY_EXISTS] == "0" ? false : true
                    ];
                }
            }
        }

        return ['conditions' => $conditions, 'key' => $lastKey];
    }

    public static function checkInteger($key, $value)
    {
        if (
            $key == 'price' ||
            $key == 'quantity' ||
            $key == 'items.quantity' ||
            $key == "items.price" ||
            $key == "marketplace.quantity" ||
            $key == "marketplace.price"
        ) {
            $value = trim($value);
            return (float) $value;
        }

        if (is_bool($value))
            return $value;

        return trim($value);
    }

    public function updateProduct($data, $userId = false)
    {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);

            if ($connectorHelper) {
                return $connectorHelper->updateProduct($data, $userId);
            }
        }
    }

    public function syncWithSource($data)
    {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);

            if ($connectorHelper) {
                return $connectorHelper->syncWithSource($data);
            }
        }
    }

    public function importCSV($data)
    {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);

            if ($connectorHelper) {
                return $connectorHelper->importCSV($data);
            }
        }
    }

    public function exportProductCSV($data)
    {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);

            if ($connectorHelper) {
                return $connectorHelper->exportProductCSV($data);
            }
        }
    }

    public function enableDisable($data)
    {
        if (isset($data['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($data['marketplace']);

            if ($connectorHelper) {
                return $connectorHelper->enableDisable($data);
            }
        }
    }

    public function syncData($data)
    {
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

    public function createUserCatalogBrands($brands, $userId, $marketplace)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("store_catalog_brand");

        $brands['source_marketplace'] = $marketplace;
        $brands['user_id'] = $userId;

        $getBrands = $collection->findOne(['$and' => [['source_marketplace' => $marketplace], ['user_id' => $userId]]]);
        $getBrands = json_decode(json_encode($getBrands), true);

        if (!empty($getBrands)) {
            $newBrands = [];
            foreach ($brands['data'] as $brandId => $newBrand) {
                if (!array_key_exists($brandId, $getBrands['data'])) {
                    $newBrands[$brandId] = $newBrand;
                } else {
                    $newBrands[$brandId] = $getBrands['data'][$brandId];
                }
            }

            $brandCollection['data'] = $newBrands;
            $brandCollection['user_id'] = $brands['user_id'];
            $brandCollection['source_marketplace'] = $brands['source_marketplace'];
            if (!in_array($brands['app_code'], $getBrands['app_codes'])) {
                $appCode = $brands['app_code'];
                $brandCollection['app_codes'][] = $appCode;
            } else {
                $brandCollection['app_codes'] = $getBrands['app_codes'];
            }

            try {
                $res = $collection->updateOne(['$and' => [['source_marketplace' => $marketplace], ['user_id' => $userId]]], ['$set' => $brandCollection]);
            } catch (\Exception $e) {
                return ['errorFlag' => 'true', 'message' => $e->getMessage(), 'status' => 'exception while update'];
            }

            return ['key' => 'updated', 'message' => 'Updated', 'user_id' => $userId, 'res' => $res->getModifiedCount()];
        }
        try {
            if (isset($brands['app_code'])) {
                $app_code = $brands['app_code'];
                $brands['app_codes'] = [$app_code];
            }

            $res = $collection->insertOne($brands);
        } catch (\Exception $e) {
            return ['errorFlag' => 'true', 'message' => $e->getMessage(), 'status' => 'exception while insert'];
        }

        return ['key' => 'inserted', 'message' => 'Inserted', 'user_id' => $brands['user_id'], 'res' => $res->getInsertedCount()];
    }

    public function createUserCatalogCategory($category, $userId, $marketplace)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("store_catalog_category");

        $category['source_marketplace'] = $marketplace;
        $category['user_id'] = $userId;

        $getCategory = $collection->findOne(['$and' => [['source_marketplace' => $marketplace], ['user_id' => $userId]]]);
        $getCategory = json_decode(json_encode($getCategory), true);

        if (!empty($getCategory)) {
            $newCategories = [];
            foreach ($category['data'] as $categoryId => $newCategory) {
                if (!array_key_exists($categoryId, $getCategory['data'])) {
                    $newCategories[$categoryId] = $newCategory;
                } else {
                    $newCategories[$categoryId] = $getCategory['data'][$categoryId];
                }
            }

            $newCategory['data'] = $newCategories;
            $newCategory['user_id'] = $category['user_id'];
            $newCategory['source_marketplace'] = $category['source_marketplace'];
            if (!in_array($category['app_code'], $getCategory['app_codes'])) {
                $appCode = $category['app_code'];
                $newCategory['app_codes'][] = $appCode;
            } else {
                $newCategory['app_codes'] = $getCategory['app_codes'];
            }

            try {
                $res = $collection->updateOne(['$and' => [['source_marketplace' => $marketplace], ['user_id' => $userId]]], ['$set' => $newCategory]);
            } catch (\Exception $e) {
                return ['errorFlag' => 'true', 'message' => $e->getMessage(), 'status' => 'exception while update'];
            }

            return ['key' => 'updated', 'message' => 'Updated', 'user_id' => $userId, 'res' => $res->getModifiedCount()];
        }
        try {
            if (isset($category['app_code'])) {
                $app_code = $category['app_code'];
                $category['app_codes'] = [$app_code];
            }

            $res = $collection->insertOne($category);
        } catch (\Exception $e) {
            return ['errorFlag' => 'true', 'message' => $e->getMessage(), 'status' => 'exception while insert'];
        }

        return ['key' => 'inserted', 'message' => 'Inserted', 'user_id' => $userId, 'res' => $res->getInsertedCount()];
    }

    /**
     * This function is used to get all the product statuses
     * @param $data
     * @return array
     */
    public function getAllProductStatuses($data)
    {
        $productStatus = [];
        $returnArray = [];

        try {
            if (isset($data['target_marketplace'])) {
                $targetMarketplace = $data['target_marketplace'];
                $model = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($targetMarketplace)->get('source_model'));
                if (method_exists($model, 'getAllProductStatuses')) {
                    $productStatus = $model->getAllProductStatuses();
                    $returnArray = $productStatus;
                } else {
                    $returnArray = ['status' => false, 'message' => 'Method not exist'];
                }
            } else {
                $returnArray = ['status' => false, 'message' => 'Target marketplace is empty'];
            }
        } catch (\Exception $e) {
            $returnArray = ['status' => false, 'message' => $e->getMessage()];
        }

        return $returnArray;
    }

    public function getStatusWiseFilterCount($params)
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("product_container")->getCollection();
        $countAggregation = $this->buildAggregateQuery($params, 'productCount');
        $totalCount = [];
        $totalCount[] = [
            '$match' => [
                'user_id' => $this->di->getUser()->id,
                'type' => ConnectorProductModel::PRODUCT_TYPE_SIMPLE
            ],
        ];
        if (isset($params['filter']) || isset($params['or_filter'])) {
            $totalCount = $this->buildAggregateQuery($params);
        }

        $countAggregation[0]['$match']['marketplace.target_marketplace'] = $params['target_marketplace'];
        $countAggregation[] = [
            '$unwind' => '$marketplace',
        ];
        $countAggregation[] = [
            '$match' =>
            [
                'user_id' => $this->di->getUser()->id,
                'marketplace.target_marketplace' => $params['target_marketplace'],
            ]
        ];


        $totalCount[] = [
            '$count' => 'count',
        ];

        $countAggregation[] = [
            '$group' => ['_id' => '$marketplace.status', 'total' => ['$sum' => 1]]
        ];

        $countAggregation[] = [
            '$match' =>
            [
                '_id' => ['$ne' => null],
            ]
        ];

        try {
            $totalVariantsRows = $collection->aggregate($countAggregation)->toArray();
            $totalQueryCount = $collection->aggregate($totalCount)->toArray()[0]['count'];
            $TotalNotListed = 0;
            foreach ($totalVariantsRows as $idKey => $sVAlue) {
                if ($sVAlue['_id'] == null || $sVAlue['_id'] == "Not Listed" /*|| $idKey === "Unknown"*/)
                    continue;

                $TotalNotListed = $TotalNotListed + $sVAlue['total'];
            }

            $TotalNotListed = $totalQueryCount - $TotalNotListed;
            $flag = false;
            foreach ($totalVariantsRows as $idKey => $sVAlue) {
                if ($sVAlue['_id'] == "Not Listed") {
                    $flag = true;
                    $totalVariantsRows[$idKey]['total'] = $TotalNotListed;
                }
            }

            if (!$flag) {
                $totalVariantsRows[] = [
                    '_id' => 'Not Listed',
                    'total' => $TotalNotListed
                ];
            }

            $responseData = [
                'success' => true,
                'query' => $countAggregation,
                'queryForAll' => $totalCount,
                "param" => $params,
                'data' => $totalVariantsRows,
                "allListing" => $totalQueryCount
            ];
        } catch (\Exception $e) {
            $responseData = [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        return ($responseData);
    }

    /**
     * This function is used to get all the attributes of products
     *
     * @param $data
     * @return array
     */
    public function getProductAttributes($data)
    {
        try {
            if (isset($data['source']['marketplace']) && isset($data['source']['shopId'])) {
                $sourceMarketplace = $data['source']['marketplace'];
                $model = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($sourceMarketplace)->get('source_model'));
                if (method_exists($model, 'getProductAttributes')) {
                    $productAttributes = $model->getProductAttributes($data);
                    $returnArray = $productAttributes;
                } else {
                    $returnArray = ['status' => false, 'message' => 'Method not exist'];
                }
            } else {
                $returnArray = ['status' => false, 'message' => 'Params missing'];
            }
        } catch (\Exception $e) {
            $returnArray = ['status' => false, 'message' => $e->getMessage()];
        }

        return $returnArray;
    }


    /**
     * This function is used to get the single product status as per shop_id
     *
     * @param $data
     * @param $shopId
     * @return array
     */
    public function getProductStatusByShopId($data, $shopId)
    {
        try {
            if (isset($data['source_marketplace'])) {
                $sourceMarketplace = $data['source_marketplace'];
                $model = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($sourceMarketplace)->get('source_model'));
                if (method_exists($model, 'getProductStatusByShopId')) {
                    $productAttributes = $model->getProductStatusByShopId($data, $shopId);
                    $returnArray = $productAttributes;
                } else {
                    $returnArray = ['status' => false, 'message' => 'Method not exist'];
                }
            } else {
                $returnArray = ['status' => false, 'message' => 'Source marketplace is empty'];
            }
        } catch (\Exception $e) {
            $returnArray = ['status' => false, 'message' => $e->getMessage()];
        }

        return $returnArray;
    }


    public function getStatus($rawBody)
    {
        $data = [];
        if (isset($rawBody['source'])) {
            $data['source_marketplace']['marketplace'] = $rawBody['source']['marketplace'];
            $data['source_marketplace']['source_shop_id'] = $rawBody['source']['shopId'];
        }

        if (isset($rawBody['target'])) {
            $data['target_marketplace']['marketplace'] = $rawBody['target']['marketplace'];
            $data['target_marketplace']['target_shop_id'] = $rawBody['target']['shopId'];
        }

        if (isset($rawBody['data']['matchWith'])) {
            $data['matchWith'] = $rawBody['data']['matchWith'];
        }

        if (isset($rawBody['data']['importProduct'])) {
            $data['importProduct'] = $rawBody['data']['importProduct'];
        }

        if (isset($rawBody['data']['initiated'])) {
            $data['initiated'] = $rawBody['data']['initiated'];
        }

        $code = $rawBody['code'];
        if ($model = $this->di->getConfig()->connectors->get($code)->get('source_model')) {
            $data = $this->di->getObjectManager()->get($model)->statusMatch($data);
            return $data;
        }
    }

    public function getLookup($rawBody)
    {
        try {
            $data = [];
            if (isset($rawBody['source'])) {
                if (isset($rawBody['source']['marketplace'])) {
                    $data['source_marketplace']['marketplace'] = $rawBody['source']['marketplace'];
                }

                if (isset($rawBody['source']['shopId'])) {
                    $data['source_marketplace']['source_shop_id'] = $rawBody['source']['shopId'];
                }
            }

            if (isset($rawBody['target'])) {
                if (isset($rawBody['target']['marketplace'])) {
                    $data['target_marketplace']['marketplace'] = $rawBody['target']['marketplace'];
                }

                if (isset($rawBody['target']['shopId'])) {
                    $data['target_marketplace']['target_shop_id'] = $rawBody['target']['shopId'];
                }
            }

            if (isset($rawBody['source_product_ids'])) {
                $data['source_product_ids'] = $rawBody['source_product_ids'];
            }

            if (isset($rawBody['container_id'])) {
                $data['container_ids'] = $rawBody['container_id'];
            }

            if (isset($rawBody['user_id'])) {
                $data['user_id'] = $rawBody['user_id'];
            }

            $code = $rawBody['code'];
            if ($model = $this->di->getConfig()->connectors->get($code)->get('source_model')) {
                $data = $this->di->getObjectManager()->get($model)->lookUp($data);
                return $data;
            }
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }



    public function getStatusWiseCountAtlas($params)
    {
        $userId = $this->di->getUser()->id;
        $shopId = $this->di->getRequester()->getTargetId();
        $shopId_source = $this->di->getRequester()->getSourceId();
        $productType = $params['type'] ?? 'simple';
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("product_container");

        try {
            // Main status facet query
            $query = [[
                '$searchMeta' => [
                    'index' => 'status',
                    'facet' => [
                        'operator' => [
                            'compound' => [
                                'must' => [
                                    ['text' => ['path' => 'user_id', 'query' => $userId]],
                                    ['text' => ['path' => 'shop_id', 'query' => $shopId]]
                                ]
                            ]
                        ],
                        'facets' => [
                            'value' => [
                                'type' => 'string',
                                'path' => 'status',
                                'numBuckets' => 100
                            ],
                            'error_count' => [
                                'type' => 'string',
                                'path' => 'error.code',
                                'numBuckets' => 1000
                            ]
                        ]
                    ],
                    'count' => ['type' => 'total']
                ]
            ]];

            // Not Listed query (items where 'status' does not exist)
            $TotalCountquery = [[
                '$searchMeta' => [
                    'index' => 'status',
                    'compound' => [
                        'must' => [
                            ['text' => ['path' => 'user_id', 'query' => $userId]],
                            ['text' => ['path' => 'shop_id', 'query' => $shopId_source]],
                            ['text' => ['path' => 'type', 'query' => $productType]]
                        ]
                    ],
                    'count' => ['type' => 'total']
                ]
            ]];

            // Execute queries
            $totalCountProducts = $collection->aggregate($TotalCountquery)->toArray();
            $aggregationResult = $collection->aggregate($query)->toArray();
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Search query execution failed: ' . $e->getMessage()
            ];
        }
        $result = $aggregationResult[0] ?? [];

        // Merge Not Listed count
        if (isset($totalCountProducts[0]['count']['total']) && $totalCountProducts[0]['count']['total'] > 0) {
            $result['totalCount'] =  $totalCountProducts[0]['count']['total'];
        }

        // No results fallback
        if (!isset($result['count']['total']) || $result['count']['total'] == 0) {
            return [
                'success' => false,
                'message' => 'Search query execution failed: ' . $e->getMessage()
            ];
        }
        $result = $aggregationResult[0] ?? [];
        // Merge Not Listed count
        if (isset($totalCountProducts[0]['count']['total']) && $totalCountProducts[0]['count']['total'] > 0) {
            $result['totalCount'] =  $totalCountProducts[0]['count']['total'];
        }
        // No results fallback
        if (!isset($result['count']['total']) || $result['count']['total'] == 0) {
            $data['totalCount'] = $result['totalCount'];
            return [
                'success' => true,
                'count' => 0,
                'data' => $data,
                'message' => 'No Data Found'
            ];
        }
        $response = [
            'success' => true,
            'data' => $result
        ];
        if (isset($result['facet']['error_count']['buckets']) && count($result['facet']['error_count']['buckets']) > 0) {
            $buckets = (array) $result['facet']['error_count']['buckets']; // cast BSONArray to PHP array

            // Convert any nested objects (stdClass) to arrays
            $buckets = array_map(function ($item) {
                return (array) $item;
            }, $buckets);

            $totalCount = array_reduce($buckets, function ($carry, $item) {
                return $carry + $item['count'];
            }, 0);

            $response['error_count'] = ["success" => true, 'count' => ['count' => $totalCount]];
        } else {
            $response['error_count'] = ["success" => true, 'count' => ['count' => 0], "message" => "no error found"];
        }

        return $response;
    }

    public function getStatusWiseCount($params)
    {
        $userId = $this->di->getUser()->id;
        $targetShopId = $this->di->getRequester()->getTargetId();
        $sourceShopId = $this->di->getRequester()->getSourceId();
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        if ((isset($params['useRefine']) && $params['useRefine'] == 'true') || !isset($params['useRefine'])) {
            $collection = $mongo->getCollectionForTable("refine_product");
        } else {
            return  $this->getStatusWiseCountAtlas($params);
        }
        $aggregation = [
            [
                '$match' => [
                    'source_shop_id' => $sourceShopId,
                    'target_shop_id' => $targetShopId,
                    'user_id' => $userId
                ]
            ],
            [
                '$unwind' => '$items',
            ]
        ];
        if (isset($params['getErrorCount']) && $params['getErrorCount'] == true) {
            $errorCount = $this->getAllRefineErrorCount($params);
        }

        if (isset($params['productOnly']) && $params['productOnly'] == true) {
            $subQuery = [
                '$match' => [
                    '$or' => [
                        ['type' => 'simple'],
                        [
                            'type' => 'variation',
                            '$expr' => [
                                '$and' => [
                                    '$not' => [
                                        '$eq' => ['$source_product_id', '$items.source_product_id']
                                    ],
                                ]
                            ]
                        ]
                    ]
                ]
            ];
            array_push($aggregation, $subQuery);
        } elseif (isset($params['parent_only']) && $params['parent_only'] == true) {
            $subQuery = [
                '$match' => [
                    '$or' => [
                        ['type' => 'simple'],
                        [
                            'type' => 'variation',
                            '$expr' => [
                                '$and' => [
                                    '$eq' => ['$source_product_id', '$items.source_product_id']
                                ]
                            ]
                        ]
                    ]
                ]
            ];
            array_push($aggregation, $subQuery);
        }

        $groupByStatus = ['$group' => ['_id' => '$items.status', 'total' => ['$sum' => 1]]];
        array_push($aggregation, $groupByStatus);

        try {
            $value = $collection->aggregate($aggregation)->toArray();
            $response = [
                "success" => true,
                "data" => $value,
                'query' => $aggregation
            ];
            if (isset($params['getErrorCount'])) {
                if ($errorCount['success'] == true) {
                    $response['error_count'] = ["success" => $errorCount['success'], "count" => $errorCount['data'][0]];
                } else {
                    $response['error_count'] = ["success" => $errorCount['success'], "message" => $errorCount['message']];
                }
            }

            return $response;
        } catch (\Exception $e) {
            return ['success' => false, "message" => $e->getMessage()];
        }
    }


    public function getAtlasSearchSuggestions($params)
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('refine_product');
        $userId = $this->di->getUser()->id;
        $targetShopId = $this->di->getRequester()->getTargetId();
        $should = [];
        if (!isset($params['query'])) {
            return ['success' => false, 'message' => 'please provide query for search'];
        }


        if (isset($params['searchOpt'])) {
            foreach ($params['searchOpt'] as $value) {
                $should[] = [
                    'autocomplete' => [
                        'query' => $params['query'],
                        'path' => $value,
                    ]
                ];
            }
        } else {
            $should[] = [
                'autocomplete' => [
                    'query' => $params['query'],
                    'path' => 'items.title',
                ]
            ];
        }

        $aggragation = [];
        $aggragation[] = [
            '$search' => [
                'index' => 'default',
                'compound' => [
                    'must' => [
                        [
                            "text" => [
                                "path" => "user_id",
                                "query" => $userId
                            ],
                        ],
                        [
                            "text" => [
                                "path" => "target_shop_id",
                                "query" => $targetShopId
                            ]
                        ],
                        [
                            "text" => [
                                "path" => "source_shop_id",
                                "query" => $this->di->getRequester()->getSourceId()
                            ]
                        ]

                    ],
                    "should" => $should,
                ],
                "highlight" => [
                    "path" => $params['searchOpt'] ?? ['items.title', 'product_type', 'brand', 'items.barcode']
                ]
            ]
        ];
        $aggragation[] = ['$limit' => 10];

        $aggragation[] = [
            '$project' => [
                "score" => ['$meta' => "searchScore"],
                "items" => 1,
                // "items" => [
                //     '$filter' => [
                //         'input' => '$items',
                //         'as' => 'item',
                //         'cond' => [
                //             '$eq' => [
                //                 '$source_product_id',
                //                 '$$item.source_product_id'
                //             ]
                //         ]
                //     ]
                // ],
                "container_id" => 1,
                "main_image" => 1,
                "title" => 1,
                "brand" => 1,
                "product_type" => 1,
                "_id" => 0,
                "highlights" => ['$meta' => "searchHighlights"]
            ]
        ];

        try {
            $pData = $collection->aggregate($aggragation)->toArray();
            return ['success' => true, 'data' => $pData, 'param' => $params, 'query' => $aggragation];
        } catch (\Exception $e) {
            // echo $e->getMessage();
            die;
        }
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


    public function getRefineProducts($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("refine_product")->getCollection();

        $totalPageRead = 0;
        $limit = $params['count'] ?? self::$defaultPagination;
        $prev = [];

        if (isset($params['next'])) {
            $nextDecoded = json_decode(base64_decode($params['next']), true);
            $prev = $nextDecoded['pointer'] ?? null;
            $totalPageRead = $nextDecoded['totalPageRead'];
        }

        if (isset($params['prev'])) {
            $nextDecoded = json_decode(base64_decode($params['prev']), true);
            $prev = $nextDecoded['cursor'] ?? null;
            $totalPageRead = $nextDecoded['totalPageRead'];
        }

        if (isset($params['prev'])) {
            $prevDecoded = json_decode(base64_decode($params['prev']), true);
        }

        if (isset($params['activePage'])) {
            $page = $params['activePage'];
            $offset = ($page - 1) * $limit;
        }

        $allowedShops = $this->di->getConfig()->get("allowAtlassearch");
        if (isset($params['useAtlasSearch']) && $params['useAtlasSearch'] || $allowedShops && in_array($this->di->getUser()->id, $allowedShops->toArray())) {
            $aggregation = $this->buildSearchAggrigation($params);
        } else {
            $aggregation = $this->buildRefineProductAggregateQuery($params);
        }

        if (isset($offset)) {
            $aggregation[] = ['$skip' => (int) $offset];
        }

        if (isset($params['project'])) {
            $project = [];
            foreach ($params['project'] as $value) {
                $project[$value] = 1;
            }

            $aggregation[] = ['$project' => $project];
        }

        if (isset($params['cif_amazon_multi_project'])) {
            $aggregation[] = ['$project' => $params['cif_amazon_multi_project']];
        }

        if (isset($params['getItemsSize'])) {
            $aggregation[] =
                [
                    '$addFields' => [
                        'itemSize' => ['$size' => '$items']
                    ]
                ];
        }
        if (isset($params['getItemsSize'])) {
            $aggregation[] = ['$sort' => ['itemSize' => 1, '_id' => 1]];
        }
        if (isset($params['cif_custom_projection'])) {
            $aggregation[] = ['$project' => $params['cif_custom_projection']];
        }

        $aggregation[] = ['$limit' => (int) $limit + 1];
        $aggregateData = ['totalPageRead' => $totalPageRead, 'prev' => $prev, 'aggregate' => $aggregation, 'limit' => $limit];
        $paginationHelper = new \App\Connector\Components\PaginationHelper;
        $productData = $paginationHelper->getNextPrevData($aggregateData, $params, "refine_product");
        $responseData = [
            'success' => true,
            'data' => [
                'totalPageRead' => $page ?? $productData['activePage'],
                'current_count' => $productData['current_count'],
                'next' => $productData['next'] ?? null,
                'prev' => $productData['prev'],
                'rows' => $productData['rows'],
                'query' => $aggregation
            ],
        ];
        return $responseData;
    }

    /**
     * Builds the search aggregation based on provided parameters.
     * @param array $params The parameters for the search aggregation.
     * @return array The built search aggregation.
     */
    public function buildSearchAggrigation($params)
    {
        $aggregation = [];
        $andQuery = [];
        $userId = $this->di->getUser()->id;

        $aggregation[] = [
            '$search' => [
                'index' => 'default',
                'compound' => [
                    'must' => [
                        [
                            "text" => [
                                "path" => "user_id",
                                "query" => $userId
                            ],
                        ],
                        [
                            "text" => [
                                "path" => "target_shop_id",
                                "query" => $this->di->getRequester()->getTargetId()
                            ]
                        ],
                        [
                            "text" => [
                                "path" => "source_shop_id",
                                "query" => $this->di->getRequester()->getSourceId()
                            ]
                        ]

                    ]
                ]
            ]
        ];

        if (isset($params['filter']) || isset($params['search'])) {

            $andQuery = $this->searchAtlas($params);
        }

        if (count($andQuery['conditions'])) {
            foreach ($andQuery['conditions'] as $key => $value) {
                if ($key === '$match') {
                    $aggregation[][$key] = $andQuery['conditions'][$key];
                } else {
                    foreach ($andQuery['conditions'][$key] as $query => $value) {
                        $aggregation[0]['$search']['compound'][$key][] = $andQuery['conditions'][$key][$query];
                    }
                }
            }
        }

        return $aggregation;
    }

    /**
     * Builds search conditions based on filter parameters.
     * @param array $filterParams The filter parameters for the search.
     * @return array The built search conditions.
     */
    public function searchAtlas($filterParams = [])
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            $conditions = $this->createFilterAtlas($filterParams['filter']);
        }

        return $conditions;
    }



    /**
     * Creates filter conditions for the search based on provided filter.
     * @param array $filter The filter to be applied to the search.
     * @return array The built filter conditions.
     */
    public static function createFilterAtlas($filter)
    {
        // The logic for creating filter conditions is kept here.
        $conditions = [];
        foreach ($filter as $key => $value) {
            $key = trim($key);
            if ($key === 'template_name') {
                $conditions["profile.profile_name"] = $value[self::IS_EQUAL_TO];
            } elseif ($key === 'cif_amazon_multi_template_id' && is_string($value[1])) {

                $conditions['must'][] = [
                    "equals" => [
                        "path" => "profile.profile_id",
                        "value" => new \MongoDB\BSON\ObjectId($value[self::IS_EQUAL_TO])
                    ],
                ];
            } elseif ($key === 'cif_amazon_multi_inactive' && is_string($value[1])) {
                $conditions['$match']['$and'][] =
                    [
                        '$or' => [
                            ['items.status' => $value[1]],
                            ['items.status' => null],

                        ]
                    ];
            } elseif ($key === 'cif_amazon_multi_activity' && is_string($value[1])) {
                $conditions['filter'][] = [
                    "exists" => [
                        "path" => 'items.' . $value[1],
                    ]
                ];
            } elseif ($key == 'cif_amazon_multi_variant_attributes') {
                $temp = [];
                $myArray = explode(',', $value[self::IS_EQUAL_TO]);
                for ($i = 0; $i < count($myArray); $i++) {
                    $temp['variant_attributes.' . $i] = $myArray[$i];
                };
                $temp['variant_attributes'] = [
                    '$size' => count($myArray)
                ];
                $conditions['$match']['$and'][] = $temp;
            } elseif ($key == 'tags') {
                $conditions['$match']['$and'][] = [

                    "tags" => [
                        '$regex' => self::checkInteger($key, self::checkForSpecialCharacters($value[self::IS_CONTAINS])),
                        '$options' => "i"
                    ]

                ];
            } elseif ($key === "items.quantity" || $key === "items.price") {
                if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                    if (is_float(self::checkInteger($key, $value[self::IS_EQUAL_TO]))) {
                        $conditions['must'][] = [
                            "range" => [
                                "path" => $key,
                                "gte" => self::checkInteger($key, $value[self::IS_EQUAL_TO]),
                                "lte" => self::checkInteger($key, $value[self::IS_EQUAL_TO])
                            ]
                        ];
                    }
                } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                    if (is_float(self::checkInteger($key, $value[self::IS_NOT_EQUAL_TO]))) {
                        $conditions['mustNot'][] = [
                            "range" => [
                                "path" => $key,
                                "gte" => self::checkInteger($key, $value[self::IS_NOT_EQUAL_TO]),
                                "lte" => self::checkInteger($key, $value[self::IS_NOT_EQUAL_TO])
                            ]
                        ];
                    }
                } elseif (array_key_exists(self::IS_GREATER_THAN, $value)) {
                    if (is_float(self::checkInteger($key, $value[self::IS_GREATER_THAN]))) {
                        $conditions['must'][] = [
                            "range" => [
                                "path" => $key,
                                "gte" => self::checkInteger($key, $value[self::IS_GREATER_THAN]),
                            ]
                        ];
                    }
                } elseif (array_key_exists(self::IS_LESS_THAN, $value)) {
                    if (is_float(self::checkInteger($key, $value[self::IS_LESS_THAN]))) {
                        $conditions['must'][] = [
                            "range" => [
                                "path" => $key,
                                "lte" => self::checkInteger($key, $value[self::IS_LESS_THAN]),
                            ]
                        ];
                    }
                }
            } else {
                if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                    $conditions['must'][] = [
                        "text" => [
                            "path" => $key,
                            "query" => self::checkInteger($key, $value[self::IS_EQUAL_TO])
                        ],
                    ];
                } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                    $conditions['mustNot'][] = [
                        "text" => [
                            "path" => $key,
                            "query" => self::checkInteger($key, $value[self::IS_NOT_EQUAL_TO])
                        ],
                    ];
                } elseif (array_key_exists(self::IS_CONTAINS, $value)) {
                    $conditions['must'][] = [
                        "wildcard" => [
                            "query" => "*" . self::checkInteger($key, trim(addslashes($value[self::IS_CONTAINS]))) . "*",
                            "path" => $key,
                            "allowAnalyzedField" => true,
                        ]
                    ];
                } elseif (array_key_exists(self::IS_NOT_CONTAINS, $value)) {
                    $conditions['mustNot'][] = [

                        "wildcard" => [
                            "query" => "*" . self::checkInteger($key, trim(addslashes($value[self::IS_NOT_CONTAINS]))) . "*",
                            "path" => $key,
                            "allowAnalyzedField" => true,
                        ]

                    ];
                } elseif (array_key_exists(self::START_FROM, $value)) {
                    $conditions['must'][] = [
                        "wildcard" => [
                            "query" => self::checkInteger($key, trim(addslashes($value[self::START_FROM]))) . "*",
                            "path" => $key,
                            "allowAnalyzedField" => true,
                        ]
                    ];
                } elseif (array_key_exists(self::END_FROM, $value)) {
                    $conditions['must'][] = [
                        "wildcard" => [
                            "query" => "*" . self::checkInteger($key, trim(addslashes($value[self::START_FROM]))),
                            "path" => $key,
                            "allowAnalyzedField" => true,
                        ]
                    ];
                } elseif (array_key_exists(self::RANGE, $value)) {
                    if (trim($value[self::RANGE]['from']) && !trim($value[self::RANGE]['to'])) {
                        $conditions['must'][] = [
                            "range" => [
                                "path" => $key,
                                "gte" => self::checkInteger($key, trim($value[self::RANGE]['from'])),
                            ]
                        ];
                    } elseif (
                        trim($value[self::RANGE]['to']) && !trim($value[self::RANGE]['from'])
                    ) {

                        $conditions['must'][] = [
                            "range" => [
                                "path" => $key,
                                "lte" => self::checkInteger($key, trim($value[self::RANGE]['to']))
                            ]
                        ];
                    } else {
                        $conditions['must'][] = [
                            "range" => [
                                "path" => $key,
                                "gte" => self::checkInteger($key, trim($value[self::RANGE]['from'])),
                                "lte" => self::checkInteger($key, trim($value[self::RANGE]['to']))
                            ]
                        ];
                    }
                } elseif (array_key_exists(self::KEY_EXISTS, $value)) {
                    $conditions['filter'][] = [
                        "exists" => [
                            "path" => $key,
                        ]
                    ];
                }
            }
        }

        return ['conditions' => $conditions,];
    }

    public function buildRefineProductAggregateQuery($params, $callType = 'getProduct')
    {
        $aggregation = [];
        $andQuery = [];
        $orQuery = [];
        $parallel_filter = [];
        $filterType = '$and';
        $userId = $this->di->getUser()->id;
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

        if (isset($params['parallel_filter'])) {
            $parallel_filter = self::search(['filter' => $params['parallel_filter']]);
        }


        if (isset($params['is_only_parent_allow']) && (!$params['is_only_parent_allow'] || $params['is_only_parent_allow'] == 'false')) {
            $aggregation[] = [
                '$match' => [
                    'user_id' => $userId,
                    'source_shop_id' => $this->di->getRequester()->getSourceId(),
                    'target_shop_id' => $this->di->getRequester()->getTargetId(),
                    '$or' => [
                        ['type' => 'simple'],
                        [
                            '$and' => [
                                ['type' => 'variation'],
                                [
                                    'items' => [
                                        '$not' => [
                                            '$size' => 1
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
            ];
        } else {
            $aggregation[] = [
                '$match' => [
                    'user_id' => $userId,
                    'source_shop_id' => $this->di->getRequester()->getSourceId(),
                    'target_shop_id' => $this->di->getRequester()->getTargetId()
                ]
            ];
        }

        $sortBy = isset($params['sortBy']) ? $params['sortBy'] : "_id";
        $sortOrder = isset($params['sortOrder']) ? ($params['sortOrder'] == "desc" ? -1 : 1) : 1;
        if (isset($params['sortBy'])) {
            $aggregation[] = ['$sort' => [$sortBy => $sortOrder]];
        }

        if (isset($params['next']) && $callType === 'getProduct') {
            $nextDecoded = json_decode(base64_decode($params['next']), true);
            $aggregation[] = [
                '$match' => [
                    '_id' => [
                        '$gt' => new \MongoDB\BSON\ObjectId($nextDecoded['cursor']['$oid']),
                    ]
                ],
            ];
        } elseif (isset($params['prev']) && $callType === 'getProduct') {
            $prevDecoded = json_decode(base64_decode($params['prev']), true);
            if (count($prevDecoded['cursor']) != 0) {
                $lastIndex = $prevDecoded['cursor'][count($prevDecoded['cursor']) - 1];
                $aggregation[] = [
                    '$match' => [
                        '_id' => [
                            '$gte' => new \MongoDB\BSON\ObjectId($lastIndex['$oid']),
                        ]
                    ],
                ];
            }
        }

        if (isset($params['container_ids'])) {
            $aggregation[] = [
                '$match' => [
                    'container_id' => [
                        '$in' => $params['container_ids']
                    ]
                ],
            ];
        }

        $befireFilter = $this->di->getConfig()->get("excludeParentData");
        if ($befireFilter !== null) {
            $paramsFilter = $params['filter'] ?? [];
            $paramsOrFilter = $params['or_filter'] ?? [];
            $allfilters = [...array_keys($paramsFilter), ...array_keys($paramsOrFilter)];
            // Check if $allfilters array is not empty
            if (!empty($allfilters)) {
                $befireFilterArray = $befireFilter->toArray();
                // Check if there's an intersection between $allfilters and $befireFilterArray
                if (!empty(array_intersect($allfilters, $befireFilterArray))) {
                    $aggregation[] = [
                        '$addFields' => [
                            'amazon_multi_parent_filter' => [
                                '$filter' => [
                                    'input' => '$items',
                                    'as' => 'item',
                                    'cond' => [
                                        '$ne' => ['$$item.source_product_id', '$container_id']
                                    ]
                                ]
                            ]
                        ]
                    ];
                }
            }
        }


        if (count($andQuery)) {
            $aggregation[] = [
                '$match' => [
                    $filterType => [
                        $andQuery,
                    ],
                ]
            ];
        }

        if ($orQuery !== []) {
            $aggregation[] = [
                '$match' => [
                    '$or' => $orQuery,
                ]
            ];
        }

        if (count($parallel_filter)) {
            $aggregation[] = [
                '$match' => [
                    $filterType => [
                        $parallel_filter,
                    ],
                ]
            ];
        }


        return $aggregation;
    }

    public function getRefineProductCount($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("refine_product")->getCollection();

        $this->di->getUser()->id;
        $allowedShops = $this->di->getConfig()->get("allowAtlassearch");
        if (isset($params['useAtlasSearch']) && $params['useAtlasSearch'] || $allowedShops && in_array($this->di->getUser()->id, $allowedShops->toArray())) {
            $countAggregation = $this->buildSearchAggrigation($params);
        } else {
            $countAggregation = $this->buildRefineProductAggregateQuery($params);
        }

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

        $totalVariantsRows = (isset($totalVariantsRows[0]) && isset($totalVariantsRows[0]['count'])) ? $totalVariantsRows[0]['count'] : 0;
        $responseData['data']['count'] = $totalVariantsRows ?? 0;
        return $responseData;
    }

    public function prepareMarketplace($data)
    {
        $marketplace = [];
        $filters = $this->di->getConfig()->allowed_filters ? $this->di->getConfig()->allowed_filters->toArray() : [];
        foreach ($filters as $filter) {
            if ($filter == 'status')
                continue;

            if (isset($data[$filter])) {
                if ($filter == 'main_image' && !empty($data['variant_image']) && $data['visibility'] == ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY) {
                    $marketplace[$filter] = $data['variant_image'];
                } else {
                    $marketplace[$filter] = $data[$filter];
                }
            }
        }

        if (isset($data['source_marketplace']))
            $marketplace['source_marketplace'] = $data['source_marketplace'];

        $marketplace['direct'] = true;
        $array = [
            'source_product_id' => $data['source_product_id'],
            'container_id' => $data['container_id'],
            'source_shop_id' => $data['shop_id'],
            'childInfo' => $marketplace
        ];
        return $array;
    }

    public function deleteParentWithNoChild($sqsData)
    {
        try {
            $result = [];
            $conIds = [];
            $refineQuery = [];
            $marketplace = $sqsData['data']['shop']['marketplace'];
            $userId = $sqsData['data']['user_id'] ?? $this->di->getUser()->id;
            $shopId = $sqsData['data']['shop']['_id'];
            $filter = [
                [
                    '$match' => [
                        'user_id' => $userId,
                        'shop_id' => $shopId,
                        'source_marketplace' => $marketplace,
                        'visibility' => ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH,
                        'type' => ConnectorProductModel::PRODUCT_TYPE_VARIATION,
                    ]
                ],
                [
                    '$lookup' => [
                        'from' => ConnectorSourceModel::PRODUCT_CONTAINER,
                        'let' => [
                            'parent_container_id' => '$container_id',
                            'parent_user_id' => '$user_id',
                            'parent_shop_id' => '$shop_id',
                            'parent_marketplace' => '$source_marketplace'
                        ],
                        'pipeline' => [
                            [
                                '$match' => [
                                    '$expr' => [
                                        '$and' => [
                                            ['$eq' => ['$user_id', '$$parent_user_id']],
                                            ['$eq' => ['$shop_id', '$$parent_shop_id']],
                                            ['$eq' => ['$source_marketplace', '$$parent_marketplace']],
                                            ['$eq' => ['$container_id', '$$parent_container_id']],
                                            ['$eq' => ['$type', ConnectorProductModel::PRODUCT_TYPE_SIMPLE]],
                                            ['$eq' => ['$visibility', ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY]]
                                        ]
                                    ]
                                ]
                            ],
                            [
                                '$project' => ['_id' => 1]
                            ]
                        ],
                        'as' => 'children'
                    ]
                ],
                [
                    '$project' => [
                        'container_id' => 1,
                        'childCount' => ['$size' => '$children']
                    ]
                ],
                [
                    '$match' => [
                        'childCount' => 0
                    ]
                ]
            ];
            $result = $this->executeAggregateQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $filter);
            $conIds = array_values(array_column($result, 'container_id'));
            $query[]['deleteMany'] = [
                [
                    'user_id' => $userId,
                    'shop_id' => $shopId,
                    'source_marketplace' => $marketplace,
                    'visibility' => ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH,
                    'type' => ConnectorProductModel::PRODUCT_TYPE_VARIATION,
                    'container_id' => [
                        '$in' => $conIds
                    ]
                ]
            ];
            $refineQuery = [
                [
                    'deleteMany' => [
                        [
                            'user_id' => $userId,
                            'source_shop_id' => $shopId,
                            'container_id' => [
                                '$in' => $conIds
                            ]
                        ]
                    ]
                ]
            ];
            $bulkObj = $this->executeBulkQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $query);
            $refineBulkObj = $this->executeBulkQuery('refine_product', $refineQuery);
            if ($bulkObj['success'] && $refineBulkObj['success']) {
                $response = [
                    'success' => true,
                    'acknowledged' => $bulkObj['bulkObj']->isAcknowledged(),
                    'inserted' => $bulkObj['bulkObj']->getInsertedCount(),
                    'modified' => $bulkObj['bulkObj']->getModifiedCount(),
                    'matched' => $bulkObj['bulkObj']->getMatchedCount(),
                    'deleted' => $bulkObj['bulkObj']->getDeletedCount(),
                    'acknowledgedRefine' => $refineBulkObj['bulkObj']->isAcknowledged(),
                    'insertedRefine' => $refineBulkObj['bulkObj']->getInsertedCount(),
                    'modifiedRefine' => $refineBulkObj['bulkObj']->getModifiedCount(),
                    'matchedRefine' => $refineBulkObj['bulkObj']->getMatchedCount(),
                    'deletedRefine' => $refineBulkObj['bulkObj']->getDeletedCount(),
                    'deleteContainer_ids' => $conIds
                ];
            } else {
                $response = [
                    'success' => false,
                    'productContainerWriteError' => $bulkObj['error'],
                    'RefineWriteError' => $refineBulkObj['error'],
                ];
            }

            $this->deleteProductsFromTempDb($sqsData);
            return $response;
        } catch (\Exception $e) {
            $this->di->getLog()->logContent($e->getMessage(), 'info', 'connector/' . date('Y-m-d') . '/' . $userId . '/deleteParentWithNoChild.log');
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function deleteProductsFromTempDb($sqsData): void
    {
        $bulkArray = [
            [
                'deleteMany' => [
                    [
                        'user_id' => $sqsData['user_id'] ?? $this->di->getUser()->id,
                        'shop_id' => $sqsData['data']['shop']['_id'],
                    ]
                ]
            ]
        ];
        $this->executeBulkQuery($sqsData['data']['shop']['marketplace'] . '_product_container', $bulkArray);
    }

    /**
     * perform bulkQuery
     * @return array
     */
    public function executeBulkQuery(string $collectionName, array $bulkArray)
    {
        try {
            if (!empty($bulkArray)) {
                $mongoCollection = $this->getCollection($collectionName);
                $result = $this->handleLock($collectionName, function () use ($mongoCollection, $bulkArray): array {
                    try {
                        $result = $mongoCollection->BulkWrite($bulkArray);
                        return ['success' => true, 'bulkObj' => $result];
                    } catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
                        $this->di->getLog()->logContent(print_r([$e->getWriteResult(), json_encode($bulkArray)], true), 'info', 'connector/' . date('Y-m-d') . '/' . $this->di->getUser()->id . '/executeBulkQuery.log');

                        return [
                            'success' => false,
                            'message' => 'Something went wrong.',
                            'error' => $e->getWriteResult(),
                        ];
                    } catch (\Exception $e) {
                        $this->di->getLog()->logContent(print_r($e->getMessage(), true), 'info', 'connector/' . date('Y-m-d') . '/' . $this->di->getUser()->id . '/executeBulkQuery.log');
                        return [
                            'success' => false,
                            'message' => 'Something went wrong.',
                            'error' => $e->getMessage(),
                        ];
                    }
                }, 3);
                return $result;
            }

            return ['success' => false, 'bulkObj' => 'bulkArray is empty'];
        } catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
            $this->di->getLog()->logContent(print_r($e->getWriteResult(), true), 'info', 'connector/' . date('Y-m-d') . '/' . $this->di->getUser()->id . '/executeBulkQuery.log');
            return [
                'success' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getWriteResult(),
            ];
        } catch (\Exception $e) {
            $this->di->getLog()->logContent(print_r($e->getMessage(), true), 'info', 'connector/' . date('Y-m-d') . '/' . $this->di->getUser()->id . '/executeBulkQuery.log');
            return [
                'success' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * perform aggregate queries
     *
     * @return array
     */
    public function executeAggregateQuery(string $collectionName, array $filter)
    {
        try {
            $result = [];
            $mongoCollection = $this->getCollection($collectionName);
            $result = $this->handleLock($collectionName, function () use ($mongoCollection, $filter) {
                $root = ['typeMap' => ['root' => 'array', 'document' => 'array'], 'allowDiskUse' => true];
                try {
                    return $mongoCollection->aggregate(
                        $filter,
                        $root
                    )->toArray();
                } catch (\Exception $e) {
                    $this->di->getLog()->logContent(print_r([$e->getMessage(), $filter], true), 'info', 'connector/' . date('Y-m-d') . '/' . $this->di->getUser()->id . '/executeAggregateQuery.log');
                    return [
                        'success' => false,
                        'message' => $e->getMessage(),
                    ];
                }
            }, 3);
            return $result;
        } catch (\Exception $e) {
            $this->di->getLog()->logContent(print_r([$e->getMessage(), $filter], true), 'info', 'connector/' . date('Y-m-d') . '/' . $this->di->getUser()->id . '/executeAggregateQuery.log');
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * perform find queries
     * @return mixed
     */
    public function findQuery(string $collectionName, array $filter = [], array $options = [])
    {
        try {
            $mongoCollection = $this->getCollection($collectionName);
            $result = $mongoCollection->find($filter, $options)->toArray();
            return $result;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * perform distinct queries
     * @param mixed $field
     * @return mixed
     */
    public function distinctQuery(string $collectionName, $field, array $filter = [])
    {
        try {
            $mongoCollection = $this->getCollection($collectionName);
            return $mongoCollection->distinct(
                $field,
                $filter
            );
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }


    public function binarySearch($searchValue, $searchKey, $searchArray)
    {
        if (empty($searchArray))
            return ['success' => false];

        $minimum = 0;
        $array_count = count($searchArray);
        while ($array_count >= $minimum) {
            $mid = (int) (($minimum + $array_count) / 2);
            if (isset($searchArray[$mid]) && $searchArray[$mid][$searchKey] == $searchValue)
                return ['success' => true, 'index' => $mid];
            if (isset($searchArray[$mid]) && $searchArray[$mid][$searchKey] < $searchValue)
                $minimum = $mid + 1;
            else
                $array_count = $mid - 1;
        }

        return ['success' => false];
    }

    /**
     * this function will fetch products from product_container using their container_id
     *
     * @param array $additionalData
     * @param string $userId
     */
    public function getProductsById(array $containerIds, $additionalData = [], $userId = null): array
    {
        if (empty($userId)) {
            $userId = $this->di->getUser()->id;
        }

        if (!empty($containerIds) && !empty($additionalData['shop_id']) && !empty($additionalData['marketplace'])) {
            $shopId = $additionalData['shop_id'];
            $marketplace = $additionalData['marketplace'];
            $collection = $this->getCollection();
            if (count($containerIds) > 1) {
                $ids = ['$in' => $containerIds];
            } else {
                $ids = reset($containerIds);
            }

            $findQuery = ['user_id' => (string) $userId, 'shop_id' => $shopId, 'container_id' => $ids];
            $aggregate = [
                'user_id' => 1,
                'shop_id' => 1,
                'source_marketplace' => 1,
                'source_product_id' => 1,
                'container_id' => 1,
                'type' => 1,
                'visibility' => 1
            ];
            if ($additionalData['project']) {
                $aggregate = array_merge($additionalData['project'], $aggregate);
            }

            $productFromProductContainer = $collection->aggregate(
                [
                    [
                        '$match' => $findQuery
                    ],
                    [
                        '$project' => $aggregate,
                    ],
                ],
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
            )->toArray();

            if (empty($productFromProductContainer)) {
                return [
                    'success' => false,
                    'message' => 'Product does not exist in database.'
                ];
            }

            return [
                'success' => true,
                'productFromProductContainer' => $productFromProductContainer
            ];
        }
        return [
            'success' => false,
            'message' => 'Required data missing.'
        ];
    }

    /**
     * this function will delete products on basis of their given ids using delete_container_ids/delete_source_product_ids
     * from product container if product exists in product container
     *
     * @param array $data
     * @param array $additionalData
     * @param [string] $userId
     */
    public function deleteProductById($data, $additionalData = [], $userId = null): array
    {
        $collection = $this->getCollection();
        if (!$userId)
            $userId = $this->di->getUser()->id;

        $marketplace = $additionalData['marketplace'] ?? false;
        $source_shop_id = $additionalData['shop_id'] ?? false;
        $shop = $additionalData['shop'] ?? false;
        if (
            empty($data['products_from_product_container']) &&
            (empty($data['delete_source_product_ids']) || empty($data['delete_container_ids'])) &&
            empty($marketplace) && empty($source_shop_id) && empty($shop)
        ) {
            return [
                'success' => false,
                'message' => 'Required data missing'
            ];
        }

        $productFromProductContainer = $data['products_from_product_container'];
        $productIds = $data['delete_source_product_ids'] ?? $data['delete_container_ids'];
        $deletedSourceProductIds = [];
        $deleteQuery = [];
        $deleteContainerIds = [];
        $childDeletedContainerIds = [];

        foreach ($productFromProductContainer as $deletedProduct) {

            if (isset($deletedProduct['source_product_id']) && isset($productIds[$deletedProduct['source_product_id']])) {
                $deletedProducts[] = $deletedProduct;
                $deletedProductIds[] = $deletedProduct['container_id'];
                $deletedSourceProductIds[] = $deletedProduct['source_product_id'];
                if (isset($data['delete_source_product_ids'])) {
                    if ($deletedProduct['visibility'] == 'Catalog and Search') {
                        $deleteContainerIds[] = $deletedProduct['container_id'];
                        if (isset($productIds[$deletedProduct['container_id']]) && $deletedProduct['type'] == 'variation') {
                            break;
                        }
                    }

                    if ($deletedProduct['type'] == 'simple' && $deletedProduct['visibility'] == 'Not Visible Individually') {
                        $childDeletedContainerIds[] = $deletedProduct['container_id'];
                        $childDeletedSourceProductIds[] = $deletedProduct['source_product_id'];
                    }
                } else {
                    $deleteContainerIds[] = $deletedProduct['container_id'];
                }
            };
        }


        if (!empty($deleteContainerIds)) {
            $deleteQuery[]['deleteMany'] = [
                [
                    'user_id' => $userId,
                    'source_marketplace' => $marketplace,
                    'shop_id' => $source_shop_id,
                    'container_id' => ['$in' => $deleteContainerIds]
                ]
            ];
        }

        if (!empty($childDeletedContainerIds) && !empty($childDeletedSourceProductIds)) {
            $updateCondition = [
                'user_id' => $userId,
                'shop_id' => $source_shop_id,
                'container_id' => ['$in' => $childDeletedContainerIds],
                'type' => 'variation',
                'source_marketplace' => $marketplace,
                'visibility' => ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH
            ];
            $deleteQuery[]['updateOne'] = [
                $updateCondition,
                ['$pull' => ['marketplace' => ['source_product_id' => ['$in' => $childDeletedSourceProductIds]]]]
            ];
            $deleteQuery[]['deleteMany'] = [
                [
                    'user_id' => (string) $userId,
                    'source_marketplace' => $marketplace,
                    'shop_id' => $source_shop_id,
                    'source_product_id' => ['$in' => $childDeletedSourceProductIds],
                ]
            ];
        }


        if (!empty($deletedProducts) && !empty($deleteQuery)) {
            $eventData = [
                'shop' => $shop,
                'deleted_productIds' => $deletedSourceProductIds,
                'existing_products' => $deletedProducts,
                'triggerAction' => __FUNCTION__
            ];
            $eventsManager = $this->di->getEventsManager();
            // $this->di->getLog()->logContent(json_encode($eventData), 'info', 'connector/' . date('Y-m-d') . '/' . $userId . '/singleSync.log');
            $eventsManager->fire('application:beforesourceProductSync', $this, $eventData);
            $response = $collection->BulkWrite($deleteQuery, ['w' => 1]);
            if (($deleteCount = $response->getDeletedCount()) > 0) {
                $returnRes['success'] = true;
                $returnRes['deleted'] = [
                    'acknowledged' => $response->isAcknowledged(),
                    'deleted' => $response->getDeletedCount(),
                    'deleted_ids' => $deletedSourceProductIds,
                    'message' => $this->di->getLocale()->_('no_product_count_deleted', [
                        'deleteCount' => $deleteCount
                    ])
                ];
            } else {
                $returnRes['success'] = false;
                $returnRes['deleted'] = [
                    'message' => $this->di->getLocale()->_('No product was deleted.')
                ];
            }
        }

        return $returnRes;
    }

    public function getAllErrorMsgCount($data)
    {
        $limit = (int) $data['limit'] ?? 5;
        $user_id = $data['user_id'] ?? $this->di->getUser()->id;
        $sourceShop_id = $data['source_shop_id'] ?? $this->di->getRequester()->getSourceId();
        $targetShop_id = $data['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
        $refineCollection = $this->getCollection('refine_product');
        $aggregation =  [
            [
                '$match' =>
                [
                    'user_id' => $user_id,
                    'source_shop_id' => $sourceShop_id,
                    'target_shop_id' => $targetShop_id,
                    'items.error' => ['$exists' => true]
                ]
            ],
            // Unwind the items array
            [
                '$unwind' => '$items'
            ],
            // Unwind the error array inside items
            [
                '$unwind' => '$items.error'
            ],
            // Group by error code and message while counting occurrences
            [
                '$group' => [
                    '_id' => [
                        'errorCode' => '$items.error.code',
                        'message' => '$items.error.message'
                    ],
                    'count' => [
                        '$sum' => 1
                    ]
                ]
            ],
            // Format the output to the desired structure
            [
                '$project' => [
                    '_id' => '$_id.errorCode',
                    'msg' => '$_id.message',
                    'count' => 1
                ]
            ],
            // Sort results by count in descending order
            [
                '$sort' => [
                    'count' => -1
                ]
            ]
        ];
        isset($data['skip']) && $aggregation[] = ['$skip' => (int) $data['skip']];
        $aggregation[] = ['$limit' => $limit];
        $result = $refineCollection->aggregate($aggregation)->toArray();

        if (isset($data['formatted_error']) && $data['formatted_error']) {
            $targetMarketplace = $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();
            $model = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($targetMarketplace)->get('source_model'));
            if (method_exists($model, 'getAllErrors')) {
                $result = $model->getAllErrors($result);
            }
        }

        return ["success" => true, "param" => $data, "data" => $result, 'query' => $aggregation];
    }

    public function getAllErrorCount($data)
    {
        $user_id = $data['user_id'] ?? $this->di->getUser()->id;
        $sourceShop_id = $data['source_shop_id'] ?? $this->di->getRequester()->getSourceId();
        $targetShop_id = $data['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
        $refineCollection = $this->getCollection('refine_product');
        $aggregation = [
            [
                '$match' =>
                [
                    'user_id' => $user_id,
                    'source_shop_id' => $sourceShop_id,
                    'target_shop_id' => $targetShop_id,
                    'items.error' => ['$exists' => true]
                ]
            ],
            // Unwind the items array
            [
                '$unwind' => '$items'
            ],
            // Unwind the error array inside items
            [
                '$unwind' => '$items.error'
            ],
            // Group by error code and message while counting occurrences
            [
                '$group' => [
                    '_id' => [
                        'errorCode' => '$items.error.code',
                        'message' => '$items.error.message'
                    ],
                    'count' => [
                        '$sum' => 1
                    ]
                ]
            ],
            // Format the output to the desired structure
            [
                '$project' => [
                    '_id' => '$_id.errorCode',
                    'msg' => '$_id.message',
                    'count' => 1
                ]
            ],
            [
                '$count' => 'count'
            ]

        ];
        $result = $refineCollection->aggregate($aggregation)->toArray();
        return ["success" => true, "param" => $data, "data" => $result, 'query' => $aggregation];
    }

    public function unsetDuplicateSkuKey($payload)
    {
        if (!isset($payload['shop_id']))
            return ['success' => false, 'message' => 'shop_id is missing'];

        $userId = $payload['user_id'] ?? $this->di->getUser()->id;
        $shopId = $payload['shop_id'];
        $allowSkuToBeUnique = $this->di->getUser()->unique_sku_allow ?? true;

        $match = ['user_id' => $userId, 'shop_id' => $shopId,];
        $operation = ['$unset' => ['duplicate_sku' => '']];

        if (!$allowSkuToBeUnique) {
            $containerId = $payload['container_id'] ?? false;
            $sourceProductId = $payload['source_product_id'] ?? false;
            $visibility = $payload['visibility'] ?? false;
            if (!($containerId && $sourceProductId && $visibility)) {
                return ['success' => false, 'message' => 'container_id,source_product_id or visibility  is missing'];
            }

            $match['container_id'] = $containerId;
            $match['source_product_id'] = $sourceProductId;
            $match['visibility'] = $visibility;
        }

        $match['duplicate_sku'] = true;
        $bulkArray = [['updateMany' => [$match, $operation]]];
        $response = $this->executeBulkQuery(ConnectorSourceModel::PRODUCT_CONTAINER, $bulkArray);
        return ['success' => true, 'message' => $response];
    }

    public function getErrorSolution($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("errorSolutions")->getCollection();
        $errorData = $collection->find(['app_tag' => $this->di->getAppCode()->getAppTag(), "code" => $data['errorCode']])->toArray();
        if (!empty($errorData)) {
            return ["data" => $errorData, "success" => true];
        }
        return ["message" => "no data found", "success" => false];
    }



    public function getAllProductErrors($data)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("errorSolutions")->getCollection();
        $limit = (int) $data['limit'] ?? 10;
        $data['user_id'] ?? $this->di->getUser()->id;
        if (!isset($data['target_marketplace'])) {

            return ['success' => false, 'message' => "please provide value of target marketplace"];
        }

        $target_marketplace = $data['target_marketplace'];
        $aggregation = [
            [
                '$match' =>
                [
                    'marketplace' => $target_marketplace,
                ]
            ]
        ];
        if (isset($data['filter'])) {
            $filtersApplied = [];
            foreach ($data['filter'] as $key => $value) {
                $filtersApplied[$key] = $value;
            }

            $aggregation[] = ['$match' => $filtersApplied];
        }

        $aggregation[] = ['$sort' => ['_id' => -1]];
        $countAggregation = $aggregation;
        $countAggregation[] = ['$count' => 'count'];
        isset($data['skip']) && $aggregation[] = ['$skip' => (int) $data['skip']];
        $aggregation[] = ['$limit' => $limit];
        $result = $collection->aggregate($aggregation)->toArray();
        $count = $collection->aggregate($countAggregation)->toArray();

        return ["success" => true, "param" => $data, "data" => $result, 'query' => $aggregation, 'count' => $count];
    }

    public function createErrorSolution($data)
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("errorSolutions")->getCollection();
        if (!isset($data["code"]) && !isset($data['error']) && !isset($data['marketplace'])) {
            return ['success' => false, 'message' => 'data is missing'];
        }

        $query = ['code' => $data['code']];
        $update = ['code' => $data['code'], 'error' => $data['error'], 'marketplace' => $data['marketplace']];
        if (isset($data["message"])) {
            $update['message'] = $data['message'];
        }

        if (isset($data["solution"])) {
            $update['solution'] = $data['solution'];
        }

        if (isset($data["regex_value"])) {
            $update['regex_value'] = $data['regex_value'];
        }

        if (isset($data["direct"])) {
            $update['direct'] = $data['direct'];
        }

        if (isset($data["index"])) {
            $update['index'] = $data['index'];
        }

        $options = ['upsert' => true];
        $collection->updateOne($query, ['$set' => $update], $options);
        return ['success' => true, 'message' => 'data updated successfully'];
    }

    public function deleteErrorSolution($data)
    {

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("errorSolutions")->getCollection();

        if (!isset($data['code'])) {
            return ['success' => false, 'message' => 'code is missing'];
        }

        $collection->deleteOne(['code' => $data['code']]);
        return ['success' => true, 'message' => 'data deleted successfully'];
    }


    public function getAllRefineErrorCount($data)
    {
        $user_id = $data['user_id'] ?? $this->di->getUser()->id;
        $sourceShop_id = $data['source_shop_id'] ?? $this->di->getRequester()->getSourceId();
        $targetShop_id = $data['target_shop_id'] ?? $this->di->getRequester()->getTargetId();
        $refineCollection = $this->getCollection('refine_product');
        $aggregation = [
            [
                '$match' =>
                [
                    'user_id' => $user_id,
                    'source_shop_id' => $sourceShop_id,
                    'target_shop_id' => $targetShop_id,
                    'items.error' => ['$exists' => true]
                ]
            ],
            [
                '$unwind' => '$items'
            ],
            [
                '$match' =>
                [
                    'items.error' => ['$exists' => true]
                ]
            ],
            [
                '$count' => 'count'
            ]
        ];
        try {
            $result = $refineCollection->aggregate($aggregation)->toArray();
            return ["success" => true, "data" => $result];
        } catch (\Exception $e) {
            return ['success' => false, "message" => $e->getMessage()];
        }
    }

    public function fetchProducts($sourceShopId, $filters = [], $options = null, $targetShopId = null)
    {
        if (!$options) {
            $options = [
                'useRefinProduct' => false,
                'page' => 1,
                'limit' => 100
            ];
        }

        ['useRefinProduct' => $useRefineProducts, 'page' => $page, 'limit' => $limit] = $options;
        $collectionName = $useRefineProducts ? 'refine_product' : 'product_container';
        $collection = $this->getCollection($collectionName);
        $appliedFilters = [];
        if (!$useRefineProducts) {
            $appliedFilters = [
                'user_id' => $this->di->getUser()->id,
                'shop_id' => $sourceShopId,
                'visibility' => [
                    '$in' => [
                        ConnectorProductModel::VISIBILITY_CATALOG_AND_SEARCH,
                        ConnectorProductModel::VISIBILITY_NOT_VISIBLE_INDIVIDUALLY
                    ]
                ]
            ];
        }

        if (!empty($filters)) {
            $generatedFilters = self::search([
                'filter' => $filters
            ]);
            $appliedFilters = array_merge($appliedFilters, $generatedFilters);
        }

        $pipeline = [];
        if (!empty($appliedFilters)) {
            $pipeline[] = [
                '$match' => $appliedFilters
            ];
        }

        if (!$useRefineProducts) {
            $pipeline[] = [
                '$project' => [
                    'marketplace' => 0
                ]
            ];
        }

        $pipeline[] = [
            '$skip' => ($page - 1) * $limit
        ];

        $pipeline[] = [
            '$limit' => $limit
        ];

        $options = [
            'typeMap' => [
                'root' => 'array',
                'document' => 'array'
            ]
        ];

        $products = $collection->aggregate(
            $pipeline,
            $options
        )->toArray();

        if ($collectionName === 'product_container') {
            $this->updateEditedDocs($products, $targetShopId ?? $this->di->getRequester()->getTargetId());
        }

        return $products;
    }

    public function updateEditedDocs(&$products, $targetShopId): void
    {
        $sourceProductIds = array_column($products, 'source_product_id');
        $collection = $this->getCollection('product_container');
        $editedDocs = $collection->find(
            [
                'source_product_id' => [
                    '$in' => $sourceProductIds
                ],
                'target_marketplace' => [
                    '$exists' => true,
                ],
                'shop_id' => $targetShopId
            ],
            [
                'typeMap' => [
                    'root' => 'array',
                    'document' => 'array'
                ]
            ]
        )->toArray();
        $editedSourceProductIds = array_column($editedDocs, 'source_product_id');
        foreach ($sourceProductIds as $i => $sourceProductId) {
            $index = array_search($sourceProductId, $editedSourceProductIds);
            if ($index === false) {
                continue;
            }

            $products[$i]['edited'] = $editedDocs[$index];
        }
    }
    public function getRefineVariant($params)
    {
        $andQuery = [];
        $orQuery = [];
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("refine_product")->getCollection();
        $userId = $this->di->getUser()->id;
        $totalPageRead = 0;
        $limit = $params['count'] ?? self::$defaultPagination;
        if (isset($params['activePage'])) {
            $page = $params['activePage'];
            $offset = ($page - 1) * $limit;
        }
        if (!isset($params['container_id'])) {
            return [
                "success" => false,
                "message" => "container_id is missing"
            ];
        }
        $aggregation[] = [
            '$match' => [
                'user_id' => $userId,
                'source_shop_id' => $this->di->getRequester()->getSourceId(),
                'target_shop_id' => $this->di->getRequester()->getTargetId(),
                'container_id' => $params['container_id']
            ]
        ];
        $aggregation[] = ['$unwind' => ['path' => '$items', 'includeArrayIndex' => 'string', 'preserveNullAndEmptyArrays' => true]];
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
        if (count($andQuery)) {
            $aggregation[] = [
                '$match' => [
                    '$and' => [
                        $andQuery,
                    ],
                ]
            ];
        }
        if ($orQuery !== []) {
            $aggregation[] = [
                '$match' => [
                    '$or' => $orQuery,
                ]
            ];
        }
        if (isset($params['remove_parent'])  && isset($params['container_id'][1])) {
            $aggregation[] = ['$match' => ['items.source_product_id' => ['$ne' => $params['container_id']]]];
        }
        if (isset($offset)) {
            $aggregation[] = ['$skip' => (int) $offset];
        }
        if (isset($params['project'])) {
            $project = [];
            foreach ($params['project'] as $key => $value) {
                $project[$value] = 1;
            }
            $aggregation[] = ['$project' => $project];
        }
        $countAggregation = $aggregation;
        $countAggregation[] = ['$count' => 'count'];
        $totalCount = $collection->aggregate($countAggregation)->toArray();
        $aggregation[] = ['$limit' => (int) $limit];
        $result = $collection->aggregate($aggregation)->toArray();
        // $aggregateData = ['totalPageRead' => $totalPageRead, 'prev' => $prev, 'aggregate' => $aggregation, 'limit' => $limit];
        // $paginationHelper = new \App\Connector\Components\PaginationHelper;
        // $productData = $paginationHelper->getNextPrevData($aggregateData, $params, "refine_product");
        $responseData = [
            'success' => true,
            'data' => [
                // 'totalPageRead' => $page ?? $productData['activePage'],
                // 'current_count' => $productData['current_count'],
                "totalCount" => $totalCount[0]['count'],
                'rows' => $result,
                'query' => $aggregation
            ],
        ];
        return $responseData;
    }


    public function getRefineVariantCount($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("refine_product")->getCollection();
        $userId = $this->di->getUser()->id;
        $andQuery = [];
        $orQuery = [];
        $totalPageRead = 0;
        if (!isset($params['container_id'])) {
            return [
                "success" => false,
                "message" => "container_id is missing"
            ];
        }
        $aggregation[] = [
            '$match' => [
                'user_id' => $userId,
                'source_shop_id' => $this->di->getRequester()->getSourceId(),
                'target_shop_id' => $this->di->getRequester()->getTargetId(),
                'container_id' => $params['container_id']
            ]
        ];




        $aggregation[] = ['$unwind' => ['path' => '$items', 'includeArrayIndex' => 'string', 'preserveNullAndEmptyArrays' => true]];


        if (isset($params['remove_parent'])) {
            $aggregation[] = ['$match' => ['items.source_product_id' => ['$ne' => $params['container_id']]]];
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
        if (count($andQuery)) {
            $aggregation[] = [
                '$match' => [
                    '$and' => [
                        $andQuery,
                    ],
                ]
            ];
        }
        if ($orQuery !== []) {
            $aggregation[] = [
                '$match' => [
                    '$or' => $orQuery,
                ]
            ];
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




        if (isset($offset)) {
            $aggregation[] = ['$skip' => (int) $offset];
        }


        if (isset($params['project'])) {
            $project = [];
            foreach ($params['project'] as $key => $value) {
                $project[$value] = 1;
            }
            $aggregation[] = ['$project' => $project];
        }


        // $aggregation[] = ['$limit' => (int) $limit];
        $aggregation[] = [
            '$count' => 'count',
        ];
        $result = $collection->aggregate($aggregation)->toArray();




        // $aggregateData = ['totalPageRead' => $totalPageRead, 'prev' => $prev, 'aggregate' => $aggregation, 'limit' => $limit];
        // $paginationHelper = new \App\Connector\Components\PaginationHelper;
        // $productData = $paginationHelper->getNextPrevData($aggregateData, $params, "refine_product");


        $responseData = [
            'success' => true,
            'data' => [
                // 'totalPageRead' => $page ?? $productData['activePage'],
                // 'current_count' => $productData['current_count'],
                'count' => $result[0]['count'],
                'query' => $aggregation
            ],
        ];
        return $responseData;
    }
    public function getErrorProcessTag($params)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("refine_product")->getCollection();
        $userId = $this->di->getUser()->id;
        if ((isset($params['isParent']) && $params['isParent'] && !isset($params['container_id'])) || (isset($params['isParent']) && !$params['isParent'] && !isset($params['source_product_id']))) {
            return [
                "success" => false,
                "code" => "container_id or source_product_id missing",
                "message" => "Data is missing"
            ];
        }
        if (isset($params['isParent']) && $params['isParent']) {
            $aggregation[] = [
                '$match' => [
                    'user_id' => $userId,
                    'source_shop_id' => $this->di->getRequester()->getSourceId(),
                    'target_shop_id' => $this->di->getRequester()->getTargetId(),
                    'container_id' => $params['container_id']
                ]
            ];
        } else if (isset($params['isParent']) && !$params['isParent']) {
            $aggregation[] = [
                '$match' => [
                    'user_id' => $userId,
                    'source_shop_id' => $this->di->getRequester()->getSourceId(),
                    'target_shop_id' => $this->di->getRequester()->getTargetId(),
                    'items.source_product_id' => $params['source_product_id']
                ]
            ];
        }
        $result = $collection->aggregate($aggregation)->toArray();
        $result = $this->dataMold($result, $params);
        $responseData = [
            'success' => true,
            'data' => $result,
            'query' => $aggregation
        ];
        return $responseData;
    }
    public function dataMold($data, $params)
    {
        $parentError = [];
        $variantsError = [];
        $data = json_decode(json_encode($data), true);
        if (isset($params['isParent']) && $params['isParent'] == 'true') {
            foreach ($data as $parent) {
                $errorCount = [];
                $warningCount = [];
                foreach ($parent['items'] as $child) {
                    if ($parent['source_product_id'] == $child['source_product_id']) {
                        // Parent error handling
                        $parentError['title'] = $child['title'] ?? "N/A";
                        $parentError['image'] = $child['main_image'] ?? "N/A";
                        if (isset($child['error'])) {
                            $parentError['error'] = $child['error'] ?? [];
                        }
                        if (isset($child['process_tags'])) {
                            $parentError['process_tags'] = $child['process_tags'] ?? [];
                        }
                        if (isset($child['warning'])) {
                            $parentError['warning'] = $child['warning'] ?? [];
                        }
                    } else {
                        // Child error handling
                        if (isset($child['error'])) {
                            foreach ($child['error'] as $error) {

                                $key = $error['type'] . '-' . $error['message'];
                                if (isset($errorCount[$key])) {
                                    $errorCount[$key]['count'] += 1;
                                } else {
                                    $errorCount[$key] = $error;
                                    $errorCount[$key]['count'] = 1;
                                }
                            }
                        }
                        if (isset($child['process_tags'])) {
                            $variantsError['process_tags'] = $child['process_tags'] ?? [];
                        }
                        if (isset($child['warning'])) {
                            if (isset($child['warning'])) {
                                foreach ($child['warning'] as $error) {
                                    $key = $error['type'] . '-' . $error['code'];
                                    if (isset($warningCount[$key])) {
                                        $warningCount[$key]['count'] += 1;
                                    } else {
                                        $warningCount[$key] = $error;
                                        $warningCount[$key]['count'] = 1;
                                    }
                                }
                            }
                            // $variantsError['warning'][] = $child['warning'] ?? [];
                        }
                    }
                }
                $variantsError['error'] = array_values($errorCount);
                $variantsError['warning'] = array_values($warningCount);
            }
        } else if (isset($params['isParent']) && !$params['isParent']) {
            foreach ($data as $parent) {
                $errorCount = [];
                foreach ($parent['items'] as $child) {
                    if ($params['source_product_id'] == $child['source_product_id']) {
                        // Parent error handling
                        $variantsError['title'] = $child['variant_title'] ?? "N/A";
                        $variantsError['image'] = $child['main_image'] ?? "N/A";
                        if (isset($child['error'])) {
                            $variantsError['error'] = $child['error'] ?? [];
                        }
                        if (isset($child['process_tags'])) {
                            $variantsError['process_tags'] = $child['process_tags'] ?? [];
                        }
                        if (isset($child['warning'])) {
                            $variantsError['warning'] = $child['warning'] ?? [];
                        }
                    }
                }
            }
        }
        return [
            'parent' => $parentError,
            'child' => $variantsError,
            // 'product' => $data
        ];
    }
    public function getSourceProductIdWiseImages($data)
    {
        $totalProductProcessed = $this->di->getConfig()->get("ai_image_processing_count") ?? 10;
        if (!isset($data['source_product_ids'])) {
            return ["success" => false, "message" => "please provide source_product_ids for this action"];
        } elseif (count($data['source_product_ids']) > $totalProductProcessed) {
            return ["success" => false, "message" => "please select maximum " . $totalProductProcessed . " products for processing"];
        }
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("refine_product")->getCollection();
        $userId = $this->di->getUser()->id;
        $aggregation[] = [
            '$match' => [
                'user_id' => $userId,
                'source_shop_id' => $this->di->getRequester()->getSourceId(),
                'target_shop_id' => $this->di->getRequester()->getTargetId(),
                'items.source_product_id' => [
                    '$in' => $data['source_product_ids']
                ]
            ]
        ];
        $aggregation[] = [
            '$unwind' => '$items' // Deconstruct the `items` array
        ];
        $aggregation[] = [
            '$match' => [
                'items.main_image' => [
                    '$exists' => true,
                    '$ne' => "",

                ],
            ]
        ];
        $countAggregation = $aggregation;
        // $aggregation[] =  [
        //     '$group' => [
        //         '_id' => '$container_id', // Group by `main_image`
        //         'source_product_ids' => [
        //             '$push' => '$items.source_product_id' // Collect all `source_product_id` values
        //         ],
        //         'title' => ['$first' => '$title'],
        //         "container_id" => ['$first' => '$container_id'],
        //     ]
        // ];
        $aggregation[] =  [
            '$project' => [
                '_id' => 0, // Exclude `_id`
                'title' => 1,
                'variant_title' => '$items.variant_title',
                'main_image' => '$items.main_image',
                'source_product_ids' => '$items.source_product_id',
                'container_id' => 1
            ]
        ];
        $countAggregation[] = [
            '$count' => 'count',
        ];
        $result = $collection->aggregate($aggregation)->toArray();
        $groupedData = [];

        foreach ($result as $product) {
            $containerId = $product['container_id'];
            $title = $product['title'] ?? null;
            $variantTitle = $product['variant_title'] ?? null;
            $mainImage = $product['main_image'];
            $sourceProductId = $product['source_product_ids'];

            if (!isset($groupedData[$containerId])) {
                $groupedData[$containerId] = [
                    'title' => $title,
                    'variant_title' => $variantTitle,
                    'images' => [],
                    'container_id' => $containerId
                ];
            }

            // Ensure main_image is an array (since projection might return single or multiple images)
            $mainImages = is_array($mainImage) ? $mainImage : [$mainImage];

            foreach ($mainImages as $image) {
                $imageIndex = array_search($image, array_column($groupedData[$containerId]['images'], 'main_image'));

                if ($imageIndex === false) {
                    // If the image is not found, add a new entry
                    $groupedData[$containerId]['images'][] = [
                        'main_image' => $image,
                        'source_product_id' => [$sourceProductId]
                    ];
                } else {
                    // If the image exists, push the source_product_id to existing array
                    $groupedData[$containerId]['images'][$imageIndex]['source_product_id'][] = $sourceProductId;
                }
            }
        }

        // Convert associative array to indexed array
        $groupedData = array_values($groupedData);
        $count = $collection->aggregate($countAggregation)->toArray();
        return [
            'success' => true,
            'data' => $groupedData,
            'count' => $count[0]['count'],
            'query' => $aggregation
        ];
    }

    public function checkForDuplicateSKUExists()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->setSource("refine_product")->getCollection();
        $query = [
            'user_id' => $this->di->getUser()->id,
            'source_shop_id' => $this->di->getRequester()->getSourceId(),
            'target_shop_id' => $this->di->getRequester()->getTargetId(),
            "items.duplicate_sku" => true

        ];
        try {
            $duplicateSku_exists = $collection->findOne($query);
            if ($duplicateSku_exists) {
                return [
                    'success' => true,
                    'duplicate_sku' => true,
                    'message' => 'duplicate sku exists'
                ];
            } else {
                return [
                    'success' => true,
                    'duplicate_sku' => false,
                    'message' => 'duplicate sku does not exists'
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}