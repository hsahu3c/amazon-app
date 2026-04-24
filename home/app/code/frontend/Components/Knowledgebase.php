<?php

namespace App\Frontend\Components;

use App\Core\Components\Base;
use MongoDB\BSON\ObjectId;
use Exception;
class Knowledgebase extends Base
{

    public static $defaultPagination = 20;

    public const IS_EQUAL_TO = 1;

    public const IS_NOT_EQUAL_TO = 2;

    public const IS_CONTAINS = 3;

    public const IS_NOT_CONTAINS = 4;

    public const START_FROM = 5;

    public const END_FROM = 6;

    public const RANGE = 7;

    public const IS_GREATER_THAN = 8;

    public const IS_LESS_THAN = 9;

    public const IS_EXISTS = 10;

    public const CONTAIN_IN_ARRAY = 11;

    public static function checkInteger($key, $value)
    {
        if ($key == 'total.price') {
            $value = trim((string) $value);
            return (float)$value;
        }

        if (is_bool($value)) {
            return $value;
        }

        return trim((string) $value);
    }

    public static function search($filterParams = [])
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim((string) $key);
                if ($key != "_id") {
                    if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                        $conditions[$key] = self::checkInteger($key, $value[self::IS_EQUAL_TO]);
                    } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                        $conditions[$key] =  ['$ne' => self::checkInteger($key, trim(addslashes((string) $value[self::IS_NOT_EQUAL_TO])))];
                    } elseif (array_key_exists(self::IS_CONTAINS, $value)) {
                        $conditions[$key] = [
                            '$regex' =>  self::checkInteger($key, trim(addslashes((string) $value[self::IS_CONTAINS]))),
                            '$options' => 'i'
                        ];
                    } elseif (array_key_exists(self::IS_NOT_CONTAINS, $value)) {
                        $conditions[$key] = [
                            '$regex' => "^((?!" . self::checkInteger($key, trim(addslashes((string) $value[self::IS_NOT_CONTAINS]))) . ").)*$",
                            '$options' => 'i'
                        ];
                    } elseif (array_key_exists(self::START_FROM, $value)) {
                        $conditions[$key] = [
                            '$regex' => "^" . self::checkInteger($key, trim(addslashes((string) $value[self::START_FROM]))),
                            '$options' => 'i'
                        ];
                    } elseif (array_key_exists(self::END_FROM, $value)) {
                        $conditions[$key] = [
                            '$regex' => self::checkInteger($key, trim(addslashes((string) $value[self::END_FROM]))) . "$",
                            '$options' => 'i'
                        ];
                    } elseif (array_key_exists(self::RANGE, $value)) {
                        if (trim((string) $value[self::RANGE]['from']) && !trim((string) $value[self::RANGE]['to'])) {
                            $conditions[$key] =  ['$gte' => self::checkInteger($key, trim((string) $value[self::RANGE]['from']))];
                        } elseif (
                            trim((string) $value[self::RANGE]['to']) &&
                            !trim((string) $value[self::RANGE]['from'])
                        ) {
                            $conditions[$key] =  ['$lte' => self::checkInteger($key, trim((string) $value[self::RANGE]['to']))];
                        } else {
                            $conditions[$key] =  [
                                '$gte' => self::checkInteger($key, trim((string) $value[self::RANGE]['from'])),
                                '$lte' => self::checkInteger($key, trim((string) $value[self::RANGE]['to']))
                            ];
                        }
                    } elseif (array_key_exists(self::IS_GREATER_THAN, $value)) {
                        if (is_numeric(trim((string) $value[self::IS_GREATER_THAN]))) {
                            $conditions[$key] = ['$gte' => self::checkInteger($key, trim((string) $value[self::IS_GREATER_THAN]))];
                        }
                    } elseif (array_key_exists(self::IS_LESS_THAN, $value)) {
                        if (is_numeric(trim((string) $value[self::IS_LESS_THAN]))) {
                            $conditions[$key] = ['$lte' => self::checkInteger($key, trim((string) $value[self::IS_LESS_THAN]))];
                        }
                    } elseif (array_key_exists(self::IS_EXISTS, $value)) {
                        if (is_bool($value[self::IS_EXISTS] = filter_var(trim((string) $value[self::IS_EXISTS]), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE))) {
                            $conditions[$key] = ['$exists' => $value[self::IS_EXISTS]];
                        }
                    } elseif (array_key_exists(self::CONTAIN_IN_ARRAY, $value)) {
                        $value[self::CONTAIN_IN_ARRAY] = explode(",", (string) $value[self::CONTAIN_IN_ARRAY]);
                        $conditions[$key] = [
                            '$in' => $value[self::CONTAIN_IN_ARRAY]
                        ];
                    }
                }
            }
        }

        if (isset($filterParams['search'])) {
            $conditions['$text'] = ['$search' => self::checkInteger($key, trim(addslashes((string) $filterParams['search'])))];
        }

        return $conditions;
    }

    public function getDetails($rawBody)
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $collection = $mongo->getCollection('knowledge_base');
        $conditionalQuery = [];
        if (isset($rawBody['filter'])) {
            $conditionalQuery = self::search($rawBody);
        }

        $limit = $rawBody['count'] ?? self::$defaultPagination;
        $page = $rawBody['activePage'] ?? 1;
        $offset = ($page - 1) * $limit;

        $aggregation = [];

        if (isset($rawBody['filter']['_id'][1])) {
            $conditionalQuery['_id'] = new ObjectId($rawBody['filter']['_id'][1]);
        }

        if (count($conditionalQuery) > 0) {
            $aggregation[] = ['$match' => $conditionalQuery];
        }

        $countAggregation = $aggregation;
        $countAggregation[] = [
            '$count' => 'count'
        ];

        $totalRows = $collection->aggregate($countAggregation, $options);
        $aggregation[] = [
            '$sort' => [
                '_id' => -1
            ]
        ];
        $aggregation[] = ['$skip' => (int)$offset];
        $aggregation[] = ['$limit' => (int)$limit];

        $rows = $collection->aggregate($aggregation, $options)->toArray();

        $responseData = [];
        $responseData['success'] = true;
        $responseData['data']['rows'] = $rows;
        $totalRows = $totalRows->toArray();

        $totalRows = $totalRows[0]['count'] ?? 0;
        $responseData['data']['count'] = $totalRows;
        if (isset($count)) {
            $responseData['data']['mainCount'] = $count;
        }

        return $responseData;
    }

    public function saveDetails($rawBody)
    {
        if (!isset($rawBody['type'])) {
            return ['success' => false, 'message' => 'Document type is a required field.'];
        }

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('knowledge_base');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $conditions = [];
        foreach ($rawBody as $key => $value) {
            $conditions[$key] = $value;
        }

        $conditions['updatedAt'] = date('c');
        if (isset($rawBody['title'])) {
            $aggregation = [
                [
                    '$match' => (isset($conditions['_id']) ? ['_id' => ['$ne' => new ObjectId($rawBody['_id'])]] : [])
                        + ['title' => $rawBody['title']]
                ],
                ['$count' => 'count']
            ];
            $result = $collection->aggregate($aggregation, $options)->toArray();
            if (count($result) > 0) {
                return ['success' => false, 'message' => "Title already taken"];
            }
        }

        $keyword  = $rawBody['type'] == "section" ? "Section" : "Knowledge Base";
        if (isset($conditions['_id'])) { //update doc
            unset($conditions['_id']);
            $myUpdate = [
                '$set' => $conditions,
            ];
            if (isset($rawBody['unset'])) {
                unset($conditions['unset']);
                $myUpdate['$set'] = $conditions;
                $myUpdate['$unset'] = $rawBody['unset'];
            }

            $collection->updateOne(
                [
                    '_id' => new ObjectId($rawBody['_id'])
                ],
                $myUpdate
            );

            return ['success' => true, 'message' => $keyword . ' document updated successfully.'];
        }
        //add new doc
        $conditions['createdAt'] = date('c');
        $response = $collection->insertOne($conditions);
        return ['success' => true, 'message' => $keyword . ' document created successfully.', '_id' => ['$oid' => (string)$response->getinsertedId()]];
    }

    public function getTags()
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('knowledge_base');
        return ['success' => true, 'message' => 'Fetched Tags succesfully', 'tags' => $collection->distinct("tags")];
    }

    public function getSections()
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('knowledge_base');
        return ['success' => true, 'message' => 'Fetched Tags succesfully', 'sections' => $collection->distinct("title", ['type' => 'section'])];
    }

    public function deleteKnowledgeBase($rawBody)
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('knowledge_base');
        // $response = $collection->deleteMany(['_id' =>  new \MongoDB\BSON\ObjectId($rawBody['_id'])]);
        $response = $collection->deleteMany([
            '$or'=> [
              [ "_id" => new ObjectId($rawBody['_id']) ], // Delete the parent section
              [ "parent_section_ids" => ['$in' => [$rawBody['_id']] ] ] // Delete associated subsections/knowledge bases
            ]
        ]);
        if ($response->getDeletedCount() > 0)
            return ['success' => true, 'message' => 'Deleted Helper Doc succesfully.'];
        return ['success' => false, 'message' => 'Failed to delete Helper Doc.'];
    }

    public function getSuggestions($rawBody)
    {
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('knowledge_base');
        if (!isset($rawBody['query'])) {
            return ['success' => false, 'message' => 'Please provide query to get suggestions.'];
        }

        $aggragation = [];
        $aggragation[] = [
            '$search' => [
                "index" => "autoComplete",
                // "compound"=> [
                //     // "filter"=> [[
                //     //     "queryString"=> [
                //     //         "defaultPath"=> "type",
                //     //         "query"=> "knowledge_base"
                //     //         ]
                //     //         ]],
                //             "should"=> [[
                //                 "text"=> [
                //                   "query"=> $rawBody['query'],
                //                   "path"=> "title"
                //                 ]
                //             ]]
                //         ],
                "autocomplete" => [
                    "query" => $rawBody['query'],
                    "path" => "title",
                    "tokenOrder" => "any",
                    "fuzzy" => ["maxEdits" => 1, "prefixLength" => 1, "maxExpansions" => 256]
                ]
            ]
        ];

        try {
            $pData = $collection->aggregate($aggragation)->toArray();
            return ['success' => true, 'data' => $pData, 'param' => $rawBody, 'query' => $aggragation];
        } catch (Exception $e) {
            echo $e->getMessage();
            die;
        }
    }

    public function getKnowledgeBases($rawBody)
    {
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollection('knowledge_base');
        $limit = $rawBody['count'] ?? self::$defaultPagination;
        $page = $rawBody['activePage'] ?? 1;
        $offset = ($page - 1) * $limit;
        $response = [];
        $aggregation = [];
        if (isset($rawBody['doc_key'])) {
            if ($rawBody['doc_key'] == "parent") {
                $aggregation[] = ['$match' => ['parentSection_id' => ['$exists' => 0], 'parentSections' => ['$exists' => 0]]];
            } elseif ($rawBody['doc_key'] == "children" && $rawBody['id']) {
                $aggregation[] = ['$match' => [
                    'parentSection_id' => $rawBody['id'],
                ]];
            } elseif ($rawBody['doc_key'] == 'knowledge_base' && $rawBody['id']) {
                $aggregation[] = ['$match' => [
                    '_id' => new ObjectId($rawBody['id'])
                ]];
            }

            $aggregation[] = ['$skip' => (int)$offset];
            $aggregation[] = ['$limit' => (int)$limit];
            $response = $collection->aggregate($aggregation, $options)->toArray();
            return ['success' => true, 'data' => $response, 'param' => $rawBody, 'query' => $aggregation];
        }
    }
}