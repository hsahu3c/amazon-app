<?php
namespace App\Frontend\Components\AmazonMulti\Dev;

use App\Core\Components\Base;
use MongoDB\BSON\UTCDateTime;
use DateTime;
use DateTimeZone;

class FailedOrderGrid extends Base
{
    public function getFailedOrdersData($data) {
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('order_container');
            
            // Build the aggregation pipeline
            $pipeline = [];

            // Convert to DateTime in UTC
            $dt = new \DateTime($data['date_from'] ?? date('Y-m-d', strtotime('-1 day')), new DateTimeZone('UTC'));
            
            // Build the base filter structure
            $baseFilters = [
                "must" => [
                    [
                        "equals" => [
                            "path" => "marketplace",
                            "value" => "amazon"
                        ]
                    ],
                    [
                        "equals" => [
                            "path" => "object_type", 
                            "value" => "source_order"
                        ]
                    ],
                    [
                        "range" => [
                            'path' => 'updated_at_iso',
                            'gte' => new UTCDateTime($dt->getTimestamp() * 1000)
                            // "gte" => $data['date_from'] ?? date('Y-m-d', strtotime('-1 day'))
                        ]
                    ]

                ],
                'mustNot' => [
                    [
                        "equals" => [
                            "path" => "status", 
                            "value" => "Created"
                        ]
                    ],
                ],
                "should" => [
                    [
                        "compound" => [
                            "must" => [
                                [
                                    "equals" => [
                                        "path" => "targets.status",
                                        "value" => "failed"
                                    ]
                                ]
                            ],
                            "mustNot" => [
                                [
                                    "text" => [
                                        "path" => "marketplace_status",
                                        "query" => "Canceled"
                                    ]
                                ],
                                [
                                    "exists" => [
                                        "path" => "targets.order_id"
                                    ]
                                ]
                            ]
                        ]
                    ],
                    [
                        "exists" => [
                            "path" => "shipment_error"
                        ]
                    ],
                    [
                        "equals" => [
                            "path" => "targets.cancellation_status",
                            "value" => "Failed"
                        ]
                    ],
                    [
                        "compound" => [
                            "must" => [
                                [
                                    "embeddedDocument" => [
                                        "path" => "attributes",
                                        "operator" => [
                                            "compound" => [
                                                "must" => [
                                                    [
                                                        "equals" => [
                                                            "path" => "attributes.key",
                                                            "value" => "Amazon LatestShipDate"
                                                        ]
                                                    ],
                                                    [
                                                        "range" => [
                                                            "path" => "attributes.value",
                                                            "gte" => $data['date_from'] ?? date('Y-m-d', strtotime('-1 day'))
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ],
                                [
                                    "exists" => [
                                        "path" => "shipment_error"
                                    ]
                                ],
                                [
                                    "text" => [
                                        "path" => "shipping_status",
                                        "query" => "Unshipped"
                                    ]
                                ],
                                [
                                    "text" => [
                                        "path" => "marketplace_status",
                                        "query" => "Unshipped"
                                    ]
                                ]
                            ]
                        ]
                    ],
                    [
                        "text" => [
                            "path" => "refund_status",
                            "query" => "Failed"
                        ]
                    ]
                ],
                "minimumShouldMatch" => 1
            ];
            
            // Add user_id filter if provided
            if (isset($data['user_id']) && !empty($data['user_id']) && is_array($data['user_id'])) {
                $baseFilters['must'][] = [
                    "in" => [
                        "path" => "user_id",
                        "value" => $data['user_id']
                    ]
                ];
            }
            
            // Add date range filter if provided
            // if (isset($data['date_from']) && !empty($data['date_from'])) {
            //     $dateRange = [
            //         "range" => [
            //             "path" => "created_at",
            //             "gte" => $data['date_from']
            //         ]
            //     ];
                
            //     if (isset($data['date_to']) && !empty($data['date_to'])) {
            //         $dateRange["range"]["lte"] = $data['date_to'];
            //     }
                
            //     $baseFilters['must'][] = $dateRange;
            // }
            
            // Merge with any additional filters from data
            if (isset($data['filters'])) {
                $baseFilters = $this->mergeFilters($baseFilters, $data['filters']);
            }
            
            // Add match stage with the complex filter structure
            // $matchStage = $this->buildMatchStage($baseFilters);
            // echo json_encode($baseFilters);die;
            $pipeline[] = [
                '$search' => [
                    'index' => 'order_stat',
                    'compound' => $baseFilters
                ]
            ];
            // if (!empty($matchStage)) {
            //     $pipeline[] = ['$match' => $matchStage];
            // }

            // group by user_id and count the number of failed orders with status in array
            $pipeline[] = [
                '$group' => [
                    '_id' => '$user_id', 
                    'count' => ['$sum' => 1], 
                    'status' => ['$push' => '$status'],
                    'targets' => ['$push' => '$targets'],
                    'refund_status' => ['$push' => '$refund_status'],
                    'marketplace_status' => ['$push' => '$marketplace_status'],
                    'shipping_status' => ['$push' => '$shipping_status'],
                    'shipment_error' => ['$push' => '$shipment_error']
                    ]
                ];

            // Add lookup to get user details
            $pipeline[] = [
                '$lookup' => [
                    'from' => 'user_details',
                    'localField' => '_id',
                    'foreignField' => 'user_id',
                    'as' => 'user_data'
                ]
            ];
            
            // Add user fields from the lookup
            $pipeline[] = [
                '$addFields' => [
                    'user_id' => ['$arrayElemAt' => ['$user_data.user_id', 0]],
                    'username' => ['$arrayElemAt' => ['$user_data.username', 0]],
                    'name' => ['$arrayElemAt' => ['$user_data.name', 0]]
                ]
            ];

            // remove user_data from the pipeline
            $pipeline[] = [
                '$project' => [
                    'user_data' => 0
                ]
            ];
            
            // Transform the status array into an object with counts for each status type
            $pipeline[] = [
                '$addFields' => [
                    'status' => [
                        '$arrayToObject' => [
                            [
                                '$map' => [
                                    'input' => [
                                        '$setUnion' => ['$status', []]
                                    ],
                                    'as' => 'statusType',
                                    'in' => [
                                        'k' => '$$statusType',
                                        'v' => [
                                            '$size' => [
                                                '$filter' => [
                                                    'input' => '$status',
                                                    'cond' => ['$eq' => ['$$this', '$$statusType']]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];
            
            // Add pagination
            $activePage = isset($data['activePage']) ? (int)$data['activePage'] : 1;
            $count = isset($data['count']) ? (int)$data['count'] : 10;
            $skip = ($activePage - 1) * $count;
            
            if ($skip > 0) {
                $pipeline[] = ['$skip' => $skip];
            }
            // $pipeline[] = ['$limit' => $count];
            $pipeline[] = ['$sort' => ['count' => -1]];
            // Execute aggregation
            $result = $collection->aggregate($pipeline)->toArray();
            
            return [
                'success' => true, 
                'message' => 'Failed orders retrieved successfully', 
                'data' => $result,
                'aggregation' => $pipeline,
                'pagination' => [
                    'activePage' => $activePage,
                    'count' => $count,
                    'total' => count($result)
                ]
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false, 
                'message' => 'Error retrieving failed orders: ' . $e->getMessage(), 
                'data' => []
            ];
        }
    }
    
    /**
     * Build MongoDB match stage from the filter structure
     */
    private function buildMatchStage($filters) {
        $matchConditions = [];
        
        // Handle 'must' conditions (AND logic)
        if (isset($filters['must']) && is_array($filters['must'])) {
            foreach ($filters['must'] as $condition) {
                $matchConditions = array_merge($matchConditions, $this->processCondition($condition));
            }
        }
        
        // Handle 'should' conditions (OR logic with min_should_match)
        if (isset($filters['should']) && is_array($filters['should'])) {
            $shouldConditions = [];
            foreach ($filters['should'] as $condition) {
                $shouldConditions[] = $this->processCondition($condition);
            }
            
            if (!empty($shouldConditions)) {
                $minShouldMatch = isset($filters['min_should_match']) ? $filters['min_should_match'] : 1;
                $matchConditions['$or'] = $shouldConditions;
                
                // Note: MongoDB doesn't have direct min_should_match equivalent
                // This would need to be handled at application level or with additional logic
            }
        }
        
        return $matchConditions;
    }
    
    /**
     * Process individual filter conditions
     */
    private function processCondition($condition) {
        if (isset($condition['equals'])) {
            return [$condition['equals']['path'] => $condition['equals']['value']];
        }
        
        if (isset($condition['exists'])) {
            return [$condition['exists']['path'] => ['$exists' => true]];
        }
        
        if (isset($condition['text'])) {
            return [$condition['text']['path'] => ['$regex' => $condition['text']['query'], '$options' => 'i']];
        }
        
        if (isset($condition['range'])) {
            $range = [];
            if (isset($condition['range']['gte'])) {
                $range['$gte'] = $condition['range']['gte'];
            }
            if (isset($condition['range']['lte'])) {
                $range['$lte'] = $condition['range']['lte'];
            }
            if (isset($condition['range']['gt'])) {
                $range['$gt'] = $condition['range']['gt'];
            }
            if (isset($condition['range']['lt'])) {
                $range['$lt'] = $condition['range']['lt'];
            }
            return [$condition['range']['path'] => $range];
        }
        
        if (isset($condition['compound'])) {
            $compound = [];
            
            if (isset($condition['compound']['must'])) {
                $mustConditions = [];
                foreach ($condition['compound']['must'] as $mustCondition) {
                    $mustConditions = array_merge($mustConditions, $this->processCondition($mustCondition));
                }
                $compound = array_merge($compound, $mustConditions);
            }
            
            if (isset($condition['compound']['mustNot'])) {
                $mustNotConditions = [];
                foreach ($condition['compound']['mustNot'] as $mustNotCondition) {
                    $processed = $this->processCondition($mustNotCondition);
                    foreach ($processed as $key => $value) {
                        $mustNotConditions[$key] = ['$not' => $value];
                    }
                }
                $compound = array_merge($compound, $mustNotConditions);
            }
            
            return $compound;
        }
        
        if (isset($condition['embeddedDocument'])) {
            // Handle embedded document queries
            $path = $condition['embeddedDocument']['path'];
            $operator = $condition['embeddedDocument']['operator'];
            
            if (isset($operator['compound']['must'])) {
                $embeddedConditions = [];
                foreach ($operator['compound']['must'] as $embeddedCondition) {
                    $embeddedConditions = array_merge($embeddedConditions, $this->processCondition($embeddedCondition));
                }
                
                return [
                    $path => [
                        '$elemMatch' => $embeddedConditions
                    ]
                ];
            }
        }
        
        return [];
    }
    
    /**
     * Merge additional filters with base filters
     */
    private function mergeFilters($baseFilters, $additionalFilters) {
        // Merge must conditions
        if (isset($additionalFilters['must']) && is_array($additionalFilters['must'])) {
            $baseFilters['must'] = array_merge($baseFilters['must'], $additionalFilters['must']);
        }
        
        // Merge should conditions
        if (isset($additionalFilters['should']) && is_array($additionalFilters['should'])) {
            $baseFilters['should'] = array_merge($baseFilters['should'], $additionalFilters['should']);
        }
        
        // Update min_should_match if provided
        if (isset($additionalFilters['min_should_match'])) {
            $baseFilters['min_should_match'] = $additionalFilters['min_should_match'];
        }
        
        return $baseFilters;
    }
}