<?php


namespace App\Amazon\Components\Product;

use App\Amazon\Components\Cron\Route\Requestcontrol;
use App\Connector\Models\QueuedTasks;
use App\Core\Components\Base;

class Helper extends Base
{

    public const PRODUCT_STATUS_ERROR = 'Error';

    public const PRODUCT_STATUS_NOT_LISTED_OFFER = 'Not Listed: Offer';

    public const PRODUCT_STATUS_NOT_LISTED = 'Not Listed';

    public const PRODUCT_STATUS_LISTED = 'Listed';

    public const PRODUCT_STATUS_SOME_VARIANTS_LISTED = 'Some Variants Listed';

    public const PRODUCT_STATUS_LISTING_IN_PROGRESS = 'Listing in progress';

    public const PRODUCT_STATUS_ACTIVE = 'Active';

    public const PRODUCT_STATUS_INACTIVE = 'Inactive';

    public const PRODUCT_STATUS_INCOMPLETE = 'Incomplete';

    public const PRODUCT_STATUS_SUPRESSED = 'Supressed';

    public const PRODUCT_STATUS_DISABLED = 'Disabled';

    public const PRODUCT_STATUS_UNKNOWN = 'Unknown';

    public const PRODUCT_STATUS_UPLOADED = 'Submitted';

    public const PRODUCT_STATUS_PARENT = 'parent_Incomplete';

    public function uploadProduct($data, $operationType)
    {
        if (isset($data['source_product_ids'])) {
            $sourceProductIds = $data['source_product_ids'];
            $tag = \App\Amazon\Components\Common\Helper::PROCESS_TAG_UPLOAD_PRODUCT;
            if ($operationType == Product::OPERATION_TYPE_PARTIAL_UPDATE) {
                $tag = \App\Amazon\Components\Common\Helper::PROCESS_TAG_SYNC_PRODUCT;
            }
        }

        $res = $this->di->getObjectManager()->get(Product::class)
            ->init()->uploadNew($data, $operationType);
        return $res;
    }

    /*public function deleteProduct($data, $operationType)
    {
        if (isset($data['source_product_ids'])) {
            $sourceProductIds = $data['source_product_ids'];
        }
        $res = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product')
            ->init()->delete($data, $operationType);
        return $res;
    }*/

    public function uploadPrice($data)
    {
        if (isset($data['source_product_ids'])) {
            $sourceProductIds = $data['source_product_ids'];
        }

        $res = $this->di->getObjectManager()->get(Price::class)
            ->init()->updateNew($data);
        return $res;
    }

    public function uploadInventory($data)
    {

        if (isset($data['source_product_ids'])) {
            $sourceProductIds = $data['source_product_ids'];
        }

        $res = $this->di->getObjectManager()->get(Inventory::class)
            ->init()->updateNew($data);
        return $res;
    }

    public function uploadImage($data)
    {
        $sourceProductIds = [];

        if (isset($data['source_product_ids'])) {
            $sourceProductIds = $data['source_product_ids'];
        }

        $res = $this->di->getObjectManager()->get(Image::class)
            ->init()->updateNew($data);
        return $res;
    }

    public function prepareQuery($query)
    {
        if ($query != '') {
            $filterQuery = [];
            $orConditions = explode('||', (string) $query);

            $orConditionQueries = [];
            foreach ($orConditions as $value) {
                $andConditionQuery = trim($value);
                $andConditionQuery = trim($andConditionQuery, '()');
                $andConditions = explode('&&', $andConditionQuery);

                $andConditionSet = [];
                foreach ($andConditions as $andValue) {
                    //
                    $temp = explode("==", $andValue);
                    if (trim($temp[0]) == "variant_attribute") {
                        $var = explode(",", $temp[1]);


                        $tempset = [];
                        foreach ($var as $value) {
                            array_push($tempset, trim($value));
                            // $andConditionSet[] = $this->getAndConditions($tempset);

                        }

                        $andConditionSet[] = ["variant_attributes" => $tempset];
                    } //
                    else {

                        $andConditionSet[] = $this->getAndConditions($andValue);
                    }
                }

                $orConditionQueries[] = [
                    '$and' => $andConditionSet
                ];
            }

            $orConditionQueries = [
                '$or' => $orConditionQueries
            ];
            return $orConditionQueries;
        }

        return false;
    }

    public function getAndConditions($andCondition)
    {
        $preparedCondition = [];
        $conditions = ['==', '!=', '!%LIKE%', '%LIKE%', '>=', '<=', '>', '<'];
        $andCondition = trim((string) $andCondition);

        foreach ($conditions as $value) {
            if (str_contains($andCondition, $value)) {
                $keyValue = explode($value, $andCondition);
                $isNumeric = false;
                $valueOfProduct = trim(addslashes($keyValue[1]));
                if (
                    trim($keyValue[0]) == 'price' ||
                    trim($keyValue[0]) == 'quantity'
                ) {
                    $isNumeric = true;
                    $valueOfProduct = (float)$valueOfProduct;
                }

                if (trim($keyValue[0]) == 'collections') {
                    $productIds = $this->di->getObjectManager()->get('\App\Shopify\Models\SourceModel')->getProductIdsByCollection((string)$valueOfProduct);
                    if (
                        $productIds &&
                        count($productIds)
                    ) {
                        if (
                            $value == '==' ||
                            $value == '%LIKE%'
                        ) {
                            $preparedCondition['source_product_id'] = [
                                '$in' => $productIds
                            ];
                        } elseif (
                            $value == '!=' ||
                            $value == '!%LIKE%'
                        ) {
                            $preparedCondition['source_product_id'] = [
                                '$nin' => $productIds
                            ];
                        }
                    } else {
                        $preparedCondition['source_product_id'] = [
                            '$in' => $productIds
                        ];
                    }

                    continue;
                }

                if (trim($keyValue[0]) == 'status') {
                    $marketplace = 'amazon';
                    if ($marketplace) {
                        if ($valueOfProduct === 'Disabled' || $valueOfProduct === 'Not_listed') {
                            $disabledFilter = [];
                            $disabledFilter[] = ["marketplace.{$marketplace}" => ['$exists' => 0]];

                            $disabledFilter[] = ["marketplace.{$marketplace}.status" =>
                            ['$nin' => ['Active', 'Inactive', 'Incomplete', 'Supressed', 'Available for Offer']]];

                            $preparedCondition['$or'] = $disabledFilter;
                        } else {
                            $preparedCondition["marketplace.{$marketplace}.status"] = $valueOfProduct;
                        }
                    }

                    continue;
                }

                switch ($value) {
                    case '==':
                        if ($isNumeric) {
                            $preparedCondition[trim($keyValue[0])] = $valueOfProduct;
                        } else {
                            $preparedCondition[trim($keyValue[0])] = [
                                '$regex' => '^' . $valueOfProduct . '$',
                                '$options' => 'i'
                            ];
                        }

                        break;
                    case '!=':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$not' => [
                                '$regex' => '^' . $valueOfProduct . '$',
                                '$options' => 'i'
                            ]
                        ];
                        break;
                    case '%LIKE%':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$regex' => ".*" . $valueOfProduct . ".*",
                            '$options' => 'i'
                        ];
                        break;
                    case '!%LIKE%':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$regex' => "^((?!" . $valueOfProduct . ").)*$",
                            '$options' => 'i'
                        ];
                        break;
                    case '>':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$gt' => (float)$valueOfProduct
                        ];
                        break;
                    case '<':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$lt' => (float)$valueOfProduct
                        ];
                        break;
                    case '>=':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$gte' => (float)$valueOfProduct
                        ];
                        break;
                    case '<=':
                        $preparedCondition[trim($keyValue[0])] = [
                            '$lte' => (float)$valueOfProduct
                        ];
                        break;
                }

                break;
            }
        }

        return $preparedCondition;
    }

    public function getProductsByQuery($productQueryDetails, $userId = false, $unwindVariants = false, $projectionFields = [])
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $sendProduct = isset($productQueryDetails['send_product']) ? true : false;
        $distinctContainers = $productQueryDetails['distinct_containers'] ?? true;
        $filterQuery = $productQueryDetails['query'] ?? '';
        $filterQuery = $this->prepareQuery($filterQuery);
        $filterQuery['user_id'] = (string)$this->di->getUser()->id;
        $profileId = $productQueryDetails['profile_id'] ?? false;

        if ($filterQuery) {
            $finalQuery[] = [
                '$match' => $filterQuery
            ];

            $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')
                ->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);

            if ($sendProduct) {
                $output = ['success' => true];

                if ($distinctContainers) {
                    $select_fields = ['user_id', 'container_id', 'source_product_id', 
                    'group_id', 'title', 'marketplace', 'main_image', /*'description',*/
                        'type', 'brand', 'product_type', 'visibility',
                        'app_codes', 'additional_images', 'variant_attributes', 'profile'];

                    $projectionFields = is_array($projectionFields) ? $projectionFields : [];

                    $_group = ['_id' => '$container_id'];

                    if ($profileId !== false) {
                        $_group['profile'] = ['$first' => '$profile'];
                    }

                    foreach ($select_fields as $field) {
                        if (empty($projectionFields)) {
                            if (!isset($_group[$field])) {
                                $_group[$field] = ['$first' => '$' . $field];
                            }
                        } elseif (in_array($field, $projectionFields)) {
                            if (!isset($_group[$field])) {
                                $_group[$field] = ['$first' => '$' . $field];
                            }
                        }
                    }

                    $finalQuery[] = [
                        '$group' => $_group
                    ];
                }

                $facet = [];

                if ($profileId !== false) {
                    $profiledCountFacet = [];

                    $profiledCountFacet[] = ['$match' => ['profile.app_tag' => 'amazon']];

                    if ($profileId !== 'newTemp') {
                        $profiledCountFacet[] = ['$match' => ['profile.profile_id' => ['$ne' => $profileId]]];
                    }

                    $profiledCountFacet[] = ['$count' => 'count'];

                    $facet['profiled_count'] = $profiledCountFacet;
                }

                $facet['total_count'] = [
                    ['$count' => 'count']
                ];

                $currentPage = $productQueryDetails['activePage'] ?? 1;

                $limit = $productQueryDetails['count'] ?? 20;
                $skip = ($currentPage - 1) * $limit;

                // $facet['product_data'] = [
                //     ['$skip' => $skip],['$limit' => $limit]
                // ];
                if (isset($productQueryDetails['all_product']) && $productQueryDetails['all_product']) {
                    $facet['product_data'] = [];
                } else {
                    $facet['product_data'] = [
                        ['$skip' => $skip], ['$limit' => $limit]
                    ];
                }

                $finalQuery[] = [
                    '$facet' => $facet
                ];
                // echo json_encode($finalQuery);die;
                $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];

                $response = $collection->aggregate($finalQuery, $options);
                $response = $response->toArray();

                if ($profileId !== false) {
                    $output['already_profiled'] = $response[0]['profiled_count'][0]['count'] ?? 0;
                }

                $output['data'] = $response[0]['total_count'][0]['count'] ?? 0;
                $output['rows'] = $response[0]['product_data'] ?? [];
                $output['activePage'] = $currentPage;
                $output['count'] = $limit;

                return $output;
            }
            $output = ['success' => true];
            if ($distinctContainers) {
                $finalQuery[] = [
                    '$group' => [
                        '_id' => '$container_id',
                        'profile' => ['$first' => '$profile']
                    ]
                ];
            }
            $facet = [];
            if ($profileId !== false) {
                $profiledCountFacet = [];

                $profiledCountFacet[] = ['$match' => ['profile.app_tag' => 'amazon']];

                if ($profileId !== 'newTemp') {
                    $profiledCountFacet[] = ['$match' => ['profile.profile_id' => ['$ne' => $profileId]]];
                }

                $profiledCountFacet[] = ['$count' => 'count'];

                $facet['profiled_count'] = $profiledCountFacet;
            }
            $facet['total_count'] = [
                ['$count' => 'count']
            ];
            $finalQuery[] = [
                '$facet' => $facet
            ];
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $response = $collection->aggregate($finalQuery, $options);
            $response = $response->toArray();
            if ($profileId !== false) {
                $output['already_profiled'] = $response[0]['profiled_count'][0]['count'] ?? 0;
            }
            $output['data'] = $response[0]['total_count'][0]['count'] ?? 0;
            return $output;
        }

        return ['success' => false, 'code' => 'no_products_found', 'message' => 'No products found'];
    }

    public function getProfileProductsCount($user_id, $data)
    {
        if (isset($data['profile_name']) || isset($data['profile_id'])) {
            $distinctContainers = true;
            if (isset($data['distinct_containers'])) {
                $distinctContainers = $data['distinct_containers'];
            }

            $filter = [
                'user_id' => $user_id,
                // 'app_codes'         => ['$in' => ['amazon_sales_channel']],
                'app_codes' => 'amazon_sales_channel',
                'profile.app_tag' => 'amazon'
            ];

            if (isset($data['profile_name'])) {
                $filter['profile.profile_name'] = $data['profile_name'];
            } else {
                $filter['profile.profile_id'] = $data['profile_id'];
            }

            if ($distinctContainers) {
                $filter['visibility'] = 'Catalog and Search';
            }

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);

            $count = $collection->count($filter);

            return ['success' => true, 'count' => $count];
        }
        return ['success' => false, 'message' => 'profile_id or profile_name is required'];
    }

    /**
     * initiate amazon look-up
     *
     * @param array $data
     * @return bool|array
     */
    // public function searchFromAmazon($data)
    // {
    //     try {
    //         $sourceMarketplace = $targetMarketplace = $sourceShopId = $targetShopId = '';
    //         if (isset($data['source_marketplace'])) {
    //             $sourceMarketplace = $data['source_marketplace']['marketplace'];
    //             $sourceShopId = $data['source_marketplace']['source_shop_id'];
    //         }
    //         if (isset($data['target_marketplace'])) {
    //             $targetMarketplace = $data['target_marketplace']['marketplace'];
    //             $targetShopId = $data['target_marketplace']['target_shop_id'];
    //         }
    //         if ($sourceMarketplace == '' || $targetMarketplace == '' || $sourceShopId == '' || $targetShopId == '') {
    //             return ['success' => false, 'message' => 'Required parameter source_marketplace, target_marketplace and accounts are missing'];
    //         }
    //         $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
    //         $userId = $this->di->getUser()->id;
    //         $connectedAccounts = $this->_user_details->getShop($targetShopId, $userId);
    //         if ($connectedAccounts['warehouses'][0]['status'] != 'active') {
    //             return ['success' => false, 'message' => 'Please enable your Amazon account from Settings section to initiate the processes.'];
    //         }

    //         $sourceProductIds = [];
    //         if (isset($data['container_ids']) && !empty($data['container_ids'])) {
    //             $query = [
    //                 'user_id' => (string)$userId,
    //                 'shop_id' => $sourceShopId,
    //                 'type' => 'simple',
    //                 'container_id' => [
    //                     '$in' => $data['container_ids']
    //                 ]
    //             ];
    //             $options = [
    //                 'typeMap' => ['root' => 'array', 'document' => 'array'],
    //                 'projection' => ['source_product_id' => 1],
    //             ];
    //             $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
    //             $sourceProductIdsArray = $helper->getproductbyQuery($query, $options);

    //             if (!empty($sourceProductIdsArray)) {
    //                 $sourceProductIds = array_column($sourceProductIdsArray, 'source_product_id');
    //             }
    //         }
    //         if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
    //             if (!empty($sourceProductIds)) {
    //                 $sourceProductIds = array_merge($sourceProductIds, $data['source_product_ids']);
    //             } else {
    //                 $sourceProductIds = $data['source_product_ids'];
    //             }
    //         }
    //         if (!empty($sourceProductIds)) {
    //             /**
    //              * selected products look-up
    //              */
    //             $data = [
    //                 'user_id' => (string)$userId,
    //                 'data' => [
    //                     'source_shop_id' => $sourceShopId,
    //                     'target_shop_id' => $targetShopId,
    //                     'source_marketplace' => $sourceMarketplace,
    //                     'target_marketplace' => $targetMarketplace,
    //                     'source_product_ids' => $sourceProductIds
    //                 ]
    //             ];
    //             $productComponent = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\Product');
    //             $productComponent->search($data);
    //             return ['success' => true, 'message' => 'Amazon Lookup completed successfully.'];
    //         }
    //         $appCode = $this->di->getAppCode()->get();
    //         $appTag = $this->di->getAppCode()->getAppTag();
    //         $queueData = [
    //             'user_id' => (string)$userId,
    //             'message' => 'Amazon Lookup is in progress',
    //             'process_code' => $this->di->getObjectManager()->get('\App\Amazon\Components\Cron\Helper')->getSearchOnAmazonQueueName(),
    //             'marketplace' =>  $targetMarketplace,
    //             'app_tag' => $appTag
    //         ];
    //         $queuedTask = new \App\Connector\Models\QueuedTasks;
    //         $queuedTaskId = $queuedTask->setQueuedTask($targetShopId, $queueData);
    //         if ($queuedTaskId) {
    //             $logFile = 'amazon/' . $userId . '/' . $sourceShopId . '/' . $targetShopId . '/lookup//' . date('Y-m-d') . '.log';
    //             $handlerData = [
    //                 'type' => 'full_class',
    //                 'class_name' => '\App\Amazon\Components\Product\Product',
    //                 'method' => 'search',
    //                 'appCode' => $appCode,
    //                 'appTag' => $appTag,
    //                 'queue_name' => $this->di->getObjectManager()->get('\App\Amazon\Components\Cron\Helper')->getSearchOnAmazonQueueName(),
    //                 'user_id' => $userId,
    //                 'queued_task_id' => $queuedTaskId,
    //                 'data' => [
    //                     'source_shop_id' => $sourceShopId,
    //                     'target_shop_id' => $targetShopId,
    //                     'source_marketplace' => $sourceMarketplace,
    //                     'target_marketplace' => $targetMarketplace,
    //                 ]
    //             ];
    //             /**
    //              * bulk products look-up
    //              */
    //             $query = [
    //                 'user_id' => (string) $this->di->getUser()->id,
    //                 'type' => 'simple',
    //                 'shop_id' => $sourceShopId,
    //                 '$or' => [
    //                     ["marketplace.target_marketplace" => ['$ne' => 'amazon']],
    //                     [
    //                         "marketplace.status" => ['$nin' => [
    //                             \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_ACTIVE,
    //                             \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INACTIVE,
    //                             \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_INCOMPLETE,
    //                             \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_UPLOADED,
    //                             \App\Amazon\Components\Product\Helper::PRODUCT_STATUS_NOT_LISTED_OFFER
    //                         ]],
    //                         "marketplace.target_marketplace" => 'amazon'
    //                     ],
    //                     ["marketplace.target_marketplace" => 'amazon', "marketplace.shop_id" => ['$ne' => (string) $targetShopId]],
    //                 ],
    //             ];
    //             $helper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
    //             $productsCount = $helper->getproductCountbyQuery($query);
    //             if ($productsCount > 0) {
    //                 $handlerData['individual_weight'] =  $productsCount;
    //                 $this->di->getMessageManager()->pushMessage($handlerData);
    //                 return ["success" => true, "message" => "Look up initiated. Please wait; it may take a while. The products' status in App will get updated to Not Listed: Offer If they get matched with Amazon's Product catalog"];
    //             } else {
    //                 $queuedTask->updateFeedProgress($queuedTaskId, 100, 'lookup completed');
    //                 return ["success" => false, "message" => "No product is eligible for Amazon lookup"];
    //             }
    //         } else {
    //             return ['success' => false, 'message' => "Amazon look up is already running"];
    //         }
    //     } catch (\Exception $e) {
    //         $this->di->getLog()->logContent('Exception in look-up : ' . print_r($e->getMessage(), true) . print_r($e->getTraceAsString(), true), 'info', 'exception.log');
    //         return ['success' => false, 'message' => $e->getMessage()];
    //     }
    // }

    public function deleteAmazonListing($user)
    {

        $user_id = $user['user_id'];
        $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
        $connectedAccounts = $commonHelper->getAllAmazonShops($user_id);
        foreach ($connectedAccounts as $account) {
            if ($account['warehouses'][0]['status'] == 'active') {
                $amazonShopsData[] = $account;
            }
        }

        if (!empty($amazonShopsData)) {

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $amazonListingCollection = $mongo->getCollection(\App\Amazon\Components\Common\Helper::AMAZON_LISTING);

            $amazonListingCollection->deleteMany(
                ['user_id' => $user_id, 'source_product_id' => ['$exists' => false], 'updated_at' => ['$lt' => date('Y-m-d')]]
            );

            $query = [
                'user_id' => $user_id,
                'updated_at' => ['$lt' => date('Y-m-d')]
            ];
            $options = [
                'typeMap' => ['root' => 'array', 'document' => 'array'],
                'projection' => ['_id' => 1, 'user_id' => 1, 'seller_sku' => 1, 'source_product_id' => 1, 'source_container_id' => 1],
            ];

            $listings = $amazonListingCollection->find($query, $options)->toArray();

            if (!empty($listings)) {
                $queueTaskData = [
                    'user_id' => $user_id,
                    'message' => 'Delete extra amazon listing products',
                    'type' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getAmazonListingDeleteQueueName(),
                    'progress' => 0.00,
                    'app_tag' => 'custom',
                    'created_at' => gmdate("F j, Y, G:i T"),
                ];

                $queuedTask = new QueuedTasks;
                $queuedTaskId = $queuedTask->setQueuedTask($user_id, $queueTaskData);

                if (!isset($queuedTaskId['status'])) {

                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => Requestcontrol::class,
                        'method' => 'deleteAmazonListing',
                        'queue_name' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getAmazonListingDeleteQueueName(),
                        'user_id' => $user_id,
                        'data' => [
                            'amazon_shops' => $amazonShopsData,
                            'page' => 0,
                            'limit' => 100,
                            'total_pages' => (int)(ceil(count($listings) / 100))
                        ]
                    ];

                    $this->di->getMessageManager()->pushMessage($handlerData);
                }
            }

            return ['success' => true, 'message' => 'Deleting Process Starts'];
        }
        return ['success' => false, 'message' => 'Please enable your Amazon account from Settings section to initiate the processes.'];

    }

    public function getAllProductStatuses()
    {
        $status = [
            [
                'label' => self::PRODUCT_STATUS_ERROR,
                'value' => self::PRODUCT_STATUS_ERROR,
                'color_code' => '#F7A8A9'
            ],
            [
                'label' => self::PRODUCT_STATUS_LISTED,
                'value' => self::PRODUCT_STATUS_ACTIVE,
                'color_code' => '#8EE392'
            ],
            [
                'label' => self::PRODUCT_STATUS_NOT_LISTED_OFFER,
                'value' => self::PRODUCT_STATUS_NOT_LISTED_OFFER,
                'color_code' => '#8EE392'
            ],
            [
                'label' => self::PRODUCT_STATUS_NOT_LISTED,
                'value' => self::PRODUCT_STATUS_NOT_LISTED,
                'color_code' => '#FAEAAF'
            ],
            [
                'label' => self::PRODUCT_STATUS_SOME_VARIANTS_LISTED,
                'value' => self::PRODUCT_STATUS_SOME_VARIANTS_LISTED,
                'color_code' => '#B5F3BA'
            ],
            [
                'label' => self::PRODUCT_STATUS_LISTING_IN_PROGRESS,
                'value' => self::PRODUCT_STATUS_LISTING_IN_PROGRESS,
                'color_code' => '#DADADA'
            ],
            [
                'label' => self::PRODUCT_STATUS_INACTIVE,
                'value' => self::PRODUCT_STATUS_INACTIVE,
                'color_code' => '#DADADA'
            ],
            [
                'label' => self::PRODUCT_STATUS_INCOMPLETE,
                'value' => self::PRODUCT_STATUS_INCOMPLETE,
                'color_code' => '#8EE392'
            ],
            [
                'label' => self::PRODUCT_STATUS_SUPRESSED,
                'value' => self::PRODUCT_STATUS_SUPRESSED,
                'color_code' => '#8EE392'
            ],
            [
                'label' => self::PRODUCT_STATUS_DISABLED,
                'value' => self::PRODUCT_STATUS_DISABLED,
                'color_code' => '#8EE392'
            ],
            [
                'label' => self::PRODUCT_STATUS_UNKNOWN,
                'value' => self::PRODUCT_STATUS_UNKNOWN,
                'color_code' => '#8EE392'
            ],
        ];
        return $status;

    }

    /**
     * @param $data
     * @return array
     */
    public function updatePrice($data)
    {
        $amazonShopsData = [];
        $source_marketplace = '';
        $target_marketplace = '';
        $accounts = [];

        if (isset($data['source_marketplace'])) {
            $source_marketplace = $data['source_marketplace']['marketplace'];
        }

        if (isset($data['target_marketplace'])) {
            $target_marketplace = $data['target_marketplace']['marketplace'];
            $accounts = $data['target_marketplace']['shop_id'];
        }

        if ($target_marketplace == '' || $source_marketplace == '' || empty($accounts)) {
            return ['success' => false, 'message' => 'Required parameter source_marketplace, target_marketplace and accounts are missing'];
        }

        $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
        $connectedAccounts = $commonHelper->getAllCurrentAmazonShops();

        foreach ($connectedAccounts as $account) {
            if (!in_array($account['_id'], $accounts)) {
                continue;
            }

            if ($account['warehouses'][0]['status'] == 'active') {
                $amazonShopsData[] = $account;
            }
        }

        if (!empty($amazonShopsData)) {

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainerCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);

            $sourceProductIds = [];
            if (isset($data['container_ids']) && !empty($data['container_ids'])) {
                $query = [
                    'user_id' => (string)$this->di->getUser()->id,
                    'container_id' => [
                        '$in' => $data['container_ids']
                    ],
                    'source_marketplace' => $source_marketplace
                ];
                $sourceProductIdsArray = $productContainerCollection->find(
                    $query,
                    [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'projection' => ['source_product_id' => 1],
                    ]
                )->toArray();
                if (!empty($sourceProductIdsArray)) {
                    $sourceProductIds = array_column($sourceProductIdsArray, 'source_product_id');
                }
            }

            if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
                if (!empty($sourceProductIds)) {
                    $sourceProductIds = array_merge($sourceProductIds, $data['source_product_ids']);
                } else {
                    $sourceProductIds = $data['source_product_ids'];
                }
            }

            if ($sourceProductIds) {
                $queueData = [
                    'user_id' => $this->di->getUser()->id,
                    'message' => 'Update price in-progress',
                    'type' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getPriceSyncQueueName(),
                    'progress' => 0.00,
                    'created_at' => date('c'),
                ];

                $queuedTask = new QueuedTasks;
                $queuedTaskId = $queuedTask->setQueuedTask($this->di->getUser()->id, $queueData);

                if ($queuedTaskId) {

                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => Requestcontrol::class,
                        'method' => 'processUpdatePrice',
                        'queue_name' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getPriceSyncQueueName(),
                        'user_id' => $this->di->getUser()->id,
                        'queued_task_id' => $queuedTaskId,
                        'data' => [
                            'source_product_ids' => $sourceProductIds,
                            'amazon_shops' => $amazonShopsData,
                            'source_marketplace' => $source_marketplace,
                            'target_marketplace' => $target_marketplace,
                            'source_shop_id' => $data['source_marketplace']['shop_id'],
                            'target_shop_id' => $data['target_marketplace']['shop_id'],
                        ]
                    ];

                    $this->di->getMessageManager()->pushMessage($handlerData);

                    return ['success' => true, 'message' => "Updating Price on Amazon"];
                }
                return ['success' => false, 'message' => "Price update on Amazon is already running"];

            }
            return ["success" => false, "message" => "No products found to upload price"];

        }
        return ['success' => false, 'message' => 'Please enable your Amazon account from Settings section to initiate the processes.'];

    }

    /**
     * @param $data
     * @return array
     */
    public function updateInventory($data)
    {
        $amazonShopsData = [];
        $source_marketplace = '';
        $target_marketplace = '';
        $accounts = [];

        if (isset($data['source_marketplace'])) {
            $source_marketplace = $data['source_marketplace']['marketplace'];
        }

        if (isset($data['target_marketplace'])) {
            $target_marketplace = $data['target_marketplace']['marketplace'];
            $accounts = $data['target_marketplace']['shop_id'];
        }

        if ($target_marketplace == '' || $source_marketplace == '' || empty($accounts)) {
            return ['success' => false, 'message' => 'Required parameter source_marketplace, target_marketplace and accounts are missing'];
        }

        $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
        $connectedAccounts = $commonHelper->getAllCurrentAmazonShops();

        foreach ($connectedAccounts as $account) {
            if (!in_array($account['_id'], $accounts)) {
                continue;
            }

            if ($account['warehouses'][0]['status'] == 'active') {
                $amazonShopsData[] = $account;
            }
        }

        if (!empty($amazonShopsData)) {

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainerCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);

            $sourceProductIds = [];
            if (isset($data['container_ids']) && !empty($data['container_ids'])) {
                $query = [
                    'user_id' => (string)$this->di->getUser()->id,
                    'container_id' => [
                        '$in' => $data['container_ids']
                    ],
                    'source_marketplace' => $source_marketplace
                ];
                $sourceProductIdsArray = $productContainerCollection->find(
                    $query,
                    [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'projection' => ['source_product_id' => 1],
                    ]
                )->toArray();
                if (!empty($sourceProductIdsArray)) {
                    $sourceProductIds = array_column($sourceProductIdsArray, 'source_product_id');
                }
            }

            if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
                if (!empty($sourceProductIds)) {
                    $sourceProductIds = array_merge($sourceProductIds, $data['source_product_ids']);
                } else {
                    $sourceProductIds = $data['source_product_ids'];
                }
            }

            if ($sourceProductIds) {

                $queueData = [
                    'user_id' => $this->di->getUser()->id,
                    'message' => 'Update Inventory is in-progress',
                    'type' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getInventorySyncQueueName(),
                    'progress' => 0.00,
                    'created_at' => date('c'),
                ];

                $queuedTask = new QueuedTasks;
                $queuedTaskId = $queuedTask->setQueuedTask($this->di->getUser()->id, $queueData);
                if ($queuedTaskId) {

                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => Requestcontrol::class,
                        'method' => 'processUpdateInventory',
                        'queue_name' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getInventorySyncQueueName(),
                        'user_id' => $this->di->getUser()->id,
                        'queued_task_id' => $queuedTaskId,
                        'data' => [
                            'source_product_ids' => $sourceProductIds,
                            'amazon_shops' => $amazonShopsData,
                            'source_marketplace' => $source_marketplace,
                            'target_marketplace' => $target_marketplace,
                            'source_shop_id' => $data['source_marketplace']['shop_id'],
                        ]
                    ];

                    $this->di->getMessageManager()->pushMessage($handlerData);

                    return ['success' => true, 'message' => "Updating inventory on Amazon"];
                }
                return ['success' => false, 'message' => "Update inventory is in progress. Please wait for a while."];

            }
            return ["success" => false, "message" => "No products found to upload inventory"];

        }
        return ['success' => false, 'message' => 'Please enable your Amazon account from Settings section to initiate the processes.'];

    }

    /**
     * @param $data
     * @return array
     */
    public function updateImage($data)
    {
        $amazonShopsData = [];
        $source_marketplace = '';
        $target_marketplace = '';
        $accounts = [];

        if (isset($data['source_marketplace'])) {
            $source_marketplace = $data['source_marketplace']['marketplace'];
        }

        if (isset($data['target_marketplace'])) {
            $target_marketplace = $data['target_marketplace']['marketplace'];
            $accounts = $data['target_marketplace']['shop_id'];
        }

        if ($target_marketplace == '' || $source_marketplace == '' || empty($accounts)) {
            return ['success' => false, 'message' => 'Required parameter source_marketplace, target_marketplace and accounts are missing'];
        }

        $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
        $connectedAccounts = $commonHelper->getAllCurrentAmazonShops();

        foreach ($connectedAccounts as $account) {
            if (!in_array($account['_id'], $accounts)) {
                continue;
            }

            if ($account['warehouses'][0]['status'] == 'active') {
                $amazonShopsData[] = $account;
            }
        }

        if (!empty($amazonShopsData)) {

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainerCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);

            $sourceProductIds = [];
            if (isset($data['container_ids']) && !empty($data['container_ids'])) {
                $query = [
                    'user_id' => (string)$this->di->getUser()->id,
                    'container_id' => [
                        '$in' => $data['container_ids']
                    ],
                    'source_marketplace' => $source_marketplace
                ];
                $sourceProductIdsArray = $productContainerCollection->find(
                    $query,
                    [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'projection' => ['source_product_id' => 1],
                    ]
                )->toArray();
                if (!empty($sourceProductIdsArray)) {
                    $sourceProductIds = array_column($sourceProductIdsArray, 'source_product_id');
                }
            }

            if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
                if (!empty($sourceProductIds)) {
                    $sourceProductIds = array_merge($sourceProductIds, $data['source_product_ids']);
                } else {
                    $sourceProductIds = $data['source_product_ids'];
                }
            }

            if ($sourceProductIds) {

                $queueData = [
                    'user_id' => $this->di->getUser()->id,
                    'message' => 'Update Image is in-progress',
                    'type' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getImageSyncQueueName(),
                    'progress' => 0.00,
                    'created_at' => date('c'),
                ];

                $queuedTask = new QueuedTasks;
                $queuedTaskId = $queuedTask->setQueuedTask($this->di->getUser()->id, $queueData);
                if ($queuedTaskId) {

                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => Requestcontrol::class,
                        'method' => 'processUpdateImage',
                        'queue_name' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getImageSyncQueueName(),
                        'user_id' => $this->di->getUser()->id,
                        'queued_task_id' => $queuedTaskId,
                        'data' => [
                            'source_product_ids' => $sourceProductIds,
                            'amazon_shops' => $amazonShopsData,
                            'source_marketplace' => $source_marketplace,
                            'target_marketplace' => $target_marketplace,
                            'source_shop_id' => $data['source_marketplace']['shop_id'],
                        ]
                    ];

                    $this->di->getMessageManager()->pushMessage($handlerData);

                    return ['success' => true, 'message' => "Updating image on Amazon"];
                }
                return ['success' => false, 'message' => "Update image is in progress. Please wait for a while."];

            }
            return ["success" => false, "message" => "No products found to upload inventory"];

        }
        return ['success' => false, 'message' => 'Please enable your Amazon account from Settings section to initiate the processes.'];

    }

    /**
     * @param $data
     * @return array
     */
    public function removeProduct($data)
    {
        $amazonShopsData = [];
        $source_marketplace = '';
        $target_marketplace = '';
        $accounts = [];

        if (isset($data['source_marketplace'])) {
            $source_marketplace = $data['source_marketplace']['marketplace'];
        }

        if (isset($data['target_marketplace'])) {
            $target_marketplace = $data['target_marketplace']['marketplace'];
            $accounts = $data['target_marketplace']['shop_id'];
        }

        if ($target_marketplace == '' || $source_marketplace == '' || empty($accounts)) {
            return ['success' => false, 'message' => 'Required parameter source_marketplace, target_marketplace and accounts are missing'];
        }

        $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
        $connectedAccounts = $commonHelper->getAllCurrentAmazonShops();

        foreach ($connectedAccounts as $account) {
            if (!in_array($account['_id'], $accounts)) {
                continue;
            }

            if ($account['warehouses'][0]['status'] == 'active') {
                $amazonShopsData[] = $account;
            }
        }

        if (!empty($amazonShopsData)) {

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainerCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);

            $sourceProductIds = [];
            if (isset($data['container_ids']) && !empty($data['container_ids'])) {
                $query = [
                    'user_id' => (string)$this->di->getUser()->id,
                    'container_id' => [
                        '$in' => $data['container_ids']
                    ],
                    'source_marketplace' => $source_marketplace
                ];
                $sourceProductIdsArray = $productContainerCollection->find(
                    $query,
                    [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'projection' => ['source_product_id' => 1],
                    ]
                )->toArray();
                if (!empty($sourceProductIdsArray)) {
                    $sourceProductIds = array_column($sourceProductIdsArray, 'source_product_id');
                }
            }

            if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
                if (!empty($sourceProductIds)) {
                    $sourceProductIds = array_merge($sourceProductIds, $data['source_product_ids']);
                } else {
                    $sourceProductIds = $data['source_product_ids'];
                }
            }

            if ($sourceProductIds) {

                $queueData = [
                    'user_id' => $this->di->getUser()->id,
                    'message' => 'Update Image is in-progress',
                    'type' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getImageSyncQueueName(),
                    'progress' => 0.00,
                    'created_at' => date('c'),
                ];

                $queuedTask = new QueuedTasks;
                $queuedTaskId = $queuedTask->setQueuedTask($this->di->getUser()->id, $queueData);
                if ($queuedTaskId) {

                    $handlerData = [
                        'type' => 'full_class',
                        'class_name' => Requestcontrol::class,
                        'method' => 'processUpdateImage',
                        'queue_name' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getImageSyncQueueName(),
                        'user_id' => $this->di->getUser()->id,
                        'queued_task_id' => $queuedTaskId,
                        'data' => [
                            'source_product_ids' => $sourceProductIds,
                            'amazon_shops' => $amazonShopsData,
                            'source_marketplace' => $source_marketplace,
                            'target_marketplace' => $target_marketplace,
                            'source_shop_id' => $data['source_marketplace']['shop_id'],
                        ]
                    ];

                    $this->di->getMessageManager()->pushMessage($handlerData);

                    return ['success' => true, 'message' => "Updating image on Amazon"];
                }
                return ['success' => false, 'message' => "Update image is in progress. Please wait for a while."];

            }
            return ["success" => false, "message" => "No products found to upload inventory"];

        }
        return ['success' => false, 'message' => 'Please enable your Amazon account from Settings section to initiate the processes.'];

    }

    public function deleteProduct($data)
    {

        $amazonShopsData = [];
        $source_marketplace = '';
        $target_marketplace = '';
        $accounts = [];

        if (isset($data['source_marketplace'])) {
            $source_marketplace = $data['source_marketplace']['marketplace'];
        }

        if (isset($data['target_marketplace'])) {
            $target_marketplace = $data['target_marketplace']['marketplace'];
            $accounts = $data['target_marketplace']['shop_id'];
        }

        if ($target_marketplace == '' || $source_marketplace == '' || empty($accounts)) {
            return ['success' => false, 'message' => 'Required parameter source_marketplace, target_marketplace and accounts are missing'];
        }

        $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
        $connectedAccounts = $commonHelper->getAllCurrentAmazonShops();

        foreach ($connectedAccounts as $account) {
            if (!in_array($account['_id'], $accounts)) {
                continue;
            }

            if ($account['warehouses'][0]['status'] == 'active') {
                $amazonShopsData[] = $account;
            }
        }


        if (!empty($amazonShopsData)) {

            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $productContainerCollection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PRODUCT_CONTAINER);

            $sourceProductIds = [];
            if (isset($data['container_ids']) && !empty($data['container_ids'])) {
                $data['container_ids'] = is_array($data['container_ids']) ? $data['container_ids'] : [$data['container_ids']];
                $query = [
                    'user_id' => (string)$this->di->getUser()->id,
                    'container_id' => [
                        '$in' => $data['container_ids']
                    ],
                    'source_marketplace' => $source_marketplace
                ];
                $sourceProductIdsArray = $productContainerCollection->find(
                    $query,
                    [
                        'typeMap' => ['root' => 'array', 'document' => 'array'],
                        'projection' => ['source_product_id' => 1],
                    ]
                )->toArray();
                if (!empty($sourceProductIdsArray)) {
                    $sourceProductIds = array_column($sourceProductIdsArray, 'source_product_id');
                }
            }

            if (isset($data['source_product_ids']) && !empty($data['source_product_ids'])) {
                if (!empty($sourceProductIds)) {
                    $sourceProductIds = array_merge($sourceProductIds, $data['source_product_ids']);
                } else {
                    $sourceProductIds = $data['source_product_ids'];
                }
            }

            if ($sourceProductIds) {

                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => Requestcontrol::class,
                    'method' => 'processProductDelete',
                    'queue_name' => $this->di->getObjectManager()->get(\App\Amazon\Components\Cron\Helper::class)->getProductDeleteQueueName(),
                    'user_id' => $this->di->getUser()->id,
                    'data' => [
                        'source_product_ids' => $sourceProductIds,
                        'amazon_shops' => $amazonShopsData,
                        'source_marketplace' => $source_marketplace,
                        'target_marketplace' => $target_marketplace,
                        'source_shop_id' => $data['source_marketplace']['shop_id'],
                    ]
                ];

                $this->di->getMessageManager()->pushMessage($handlerData);

                return ['success' => true, 'message' => "Deleting product from Amazon"];


            }
            return ["success" => false, "message" => "No products found to delete "];

        }
        return ['success' => false, 'message' => 'Please enable your Amazon account from Settings section to initiate the processes.'];

    }

    public function getConnectedSource($payload)
    {
        $microTime = microtime(true);
        if (isset($payload['remote_shop_id'])) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable("user_details");

            $aggregate = [
                [
                    '$match' => [
                        'shops.remote_shop_id' => $payload['remote_shop_id']
                    ],
                ],
                [
                    '$unwind' => ["path" => '$shops']
                ]
            ];
            $result = $collection->aggregate($aggregate)->toArray();
            $shopName = $this->filterShopName($result, $payload['remote_shop_id']);
            return [
                'success' => true,
                'query_time' => number_format(microtime(true) - $microTime),
                'count' => count($shopName),
                'data' => $shopName,
                'message' => count($shopName) > 0 ? 'Connected Accounts Fetch Successfully' : 'No Accounts are Connected'
            ];
        }
        return [
            'success' => false,
            'data' => 'No Data Found',
            'message' => "Remote Shop Id is missing"
        ];
    }

    public function filterShopName($shopArray, $remoteShopId)
    {
        $targetCollection = [];
        $sourcesCollection = [];
        if (count($shopArray) > 0) {
            foreach ($shopArray as $value) {
                if (array_key_exists('sources', $value['shops']) && $value['shops']['remote_shop_id'] == $remoteShopId) {
                    $sourceShopId = $this->filterSourceShopId($value['shops']['sources']);
                    $targetCollection = array_merge($targetCollection, $sourceShopId);
                } else if (array_key_exists('targets', $value['shops'])) {
                    array_push($sourcesCollection, $value['shops']);
                }
            }

            return $this->findMatchingShop($sourcesCollection, $targetCollection);
        }
        return [];
    }

    public function filterSourceShopId($sourceShops)
    {
        if (count($sourceShops) > 0) {
            $shops = [];
            foreach ($sourceShops as $value) {
                array_push($shops, $value['shop_id']);
            }

            return $shops;
        }
        return [];
    }

    public function findMatchingShop($sourcesCollection, $targetCollection)
    {
        $arr = [];
        if (count($sourcesCollection) > 0) {
            foreach ($sourcesCollection as $key) {
                if (in_array($key['_id'], $targetCollection, true)) {
                    array_push($arr, $key['domain']);
                }
            }

            return $arr;
        }
        return $arr;
    }

    public function canAutoLinkProduct($data, $getMatchedProductDetails = false)
    {
        if (!isset($data['user_id']) || !isset($data['target_shop_id']) || !isset($data['sku'])) {
            return ['success' => true, 'matched_product' => null];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('amazon_listing');

        $query = [
            'user_id' => $data['user_id'],
            'shop_id' => (string)$data['target_shop_id'],
            'seller-sku' => (string)$data['sku']
        ];

        $projection = ['automate_sync' => 1];
        if ($getMatchedProductDetails) {
            $projection['matchedProduct'] = 1;
        }

        $listing = $collection->findOne($query, ['projection' => $projection, 'typeMap' => ['root' => 'array', 'document' => 'array']]);

        if (empty($listing)) {
            // If listing not found, default to allowing auto-link
            return ['success' => true, 'matched_product' => null];
        }

        // If automate_sync is explicitly set to false, auto link is disabled
        if (isset($listing['automate_sync']) && $listing['automate_sync'] === false) {
            return ['success' => false, 'matched_product' => $listing['matchedProduct'] ?? null];
        }

        return ['success' => true, 'matched_product' => $listing['matchedProduct'] ?? null];
    }
}
