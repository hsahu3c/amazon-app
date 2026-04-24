<?php

namespace App\Frontend\Components\AmazonMulti;

use App\Core\Components\Base;
use MongoDB\BSON\Regex;
use MongoDB\BSON\ObjectID;
class KnowledgebaseHelper extends Base
{
    public function stringValidator($value)
    {
        $type = gettype($value);
        if ($type === 'string') {
            if (ctype_space((string) $value) || strlen((string) $value) == 0 || ctype_space((string) $value) || strlen((string) $value) == 0) {
                return false;
            }
            return true;
        }
        return false;
    }

    public function getKnowledgeBase($payload)
    {
        $mtime = microtime(true);
        $activePage = $payload['data']['activePage'] ?? 1;
        $listCount = $payload['data']['listItemCount'] ?? 10;
        if (isset($payload['data']['query']) && $this->stringValidator($payload['data']['query'])) {
            $result = $this->searchKnowledgeBase($payload);
            return [
                'success' => true,
                'count' => count($result),
                'data' => $result,
                'query_time' => number_format(microtime(true) - $mtime)
            ];
        }
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("knowledge_base");
        // $dataCount = $collection->find()->toArray();
        if ($payload['data']['filter'] === 'all') {
            $aggregate = [
                ['$skip' => ($activePage - 1) * (int)$listCount],
                ['$limit' => (int)$listCount]
            ];
            $fetchData = $collection->aggregate($aggregate)->toArray();
        } else {
            $query = [
                [
                    '$match' => ['section_code' => $payload['data']['filter']]
                ],
                ['$skip' => ($activePage - 1) * (int)$listCount],
                ['$limit' => (int)$listCount]
            ];
            $fetchData = $collection->aggregate($query)->toArray();
        }

        return [
            'success' => true,
            'count' => count($fetchData),
            'data' => $fetchData,
            'queryTime' => number_format(microtime(true) - $mtime, 3)
        ];
    }

    public function getKnowledgeBaseCount($payload)
    {
        $mtime = microtime(true);

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("knowledge_base");
        if (isset($payload['data']['query']) && $this->stringValidator($payload['data']['query'])) {
            if ($payload['data']['filter'] === 'all') {
                $prepareAggregate[] = [
                    '$match' => [
                        'question' =>
                        [
                            '$regex' => new Regex($payload['data']['query'], 'i')
                        ]
                    ]
                ];
                // $fetchData = $collection->aggregate($prepareAggregate)->toArray();
            } else {
                $prepareAggregate[] = [
                    '$match' => [
                        'section_code' => $payload['data']['filter'], 'question' => ['$regex' => new Regex($payload['data']['query'], 'i')]
                    ]
                ];
            }

            $fetchData = $collection->aggregate($prepareAggregate)->toArray();
        } else {
            if ($payload['data']['filter'] === 'all') {
                $fetchData = $collection->find()->toArray();
            } else {
                $query = [
                    [
                        '$match' => ['section_code' => $payload['data']['filter']]
                    ]
                ];
                $fetchData = $collection->aggregate($query)->toArray();
            }
        }

        return [
            'success' => true,
            'count' => count($fetchData),
            'data' => $fetchData,
            'queryTime' => number_format(microtime(true) - $mtime, 3)
        ];
    }

    public function createKnowledgeBase($payload)
    {
        if (isset($payload['data'])) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable("knowledge_base");

            $prepareData = $this->createKnowledgeFormatData($payload['data']['details'], false);

            $collection->insertOne($prepareData);

            return [
                'success' => true,
                'message' => 'Question Added Successfully'
            ];
        }
        return [
            'success' => false,
            'message' => 'Data is Missing'
        ];
    }

    public function updateKnowledgeBase($payload)
    {
        $mtime = microtime(true);
        if (isset($payload['data']) && isset($payload['data']['id'])) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable("knowledge_base");
            $updatedData = $this->createKnowledgeFormatData($payload['data']['details'], true);
            $fetchData = $collection->updateOne(
                ['_id' => new ObjectID($payload['data']['id'])],
                ['$set' =>  $updatedData]
            );
            return [
                'success' => true,
                'message' => 'Value updated successfully',
                'time' => number_format(microtime(true) - $mtime, 3)
            ];
        }
        return [
            'success' => false,
            'message' => 'Data or Document ID is missing'
        ];
    }

    public function deleteKnowledgeBase($payload)
    {
        if (isset($payload['data']) && isset($payload['data']['id'])) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable("knowledge_base");
            $fetchData = $collection->deleteOne(['_id' => new ObjectID($payload['data']['id'])]);
            return [
                'success' => true,
                'message' => 'Deleted Successfully'
            ];
        }
        return [
            'success' => false,
            'message' => 'Error !! Invalid Id'
        ];
    }

    public function createKnowledgeFormatData($rawData, $isUpdate)
    {
        if (count($rawData) > 0 && $this->stringValidator($rawData['question'])) {
            $format = [];
            $format = ['question' => $rawData['question']];
            if (isset($rawData['section_code'])) $format["section_code"] = $rawData['section_code'];

            if (isset($rawData['answer'])) $format["answer"] = $rawData['answer'];

            if (isset($rawData['attachment'])) $format["attachment"] = $rawData['attachment'];

            date_default_timezone_set('Asia/Calcutta');
            if ($isUpdate == true) {
                $format["updated_on"] = date("d-m-Y H:i:s");
                $format['updated_by'] = $this->di->getUser()->username;
                $format['updated_user_id'] = $this->di->getUser()->id;
            } else {
                $format["created_at"] = date("d-m-Y H:i:s");
                $format['created_by'] = $this->di->getUser()->username;
                $format['created_user_id'] = $this->di->getUser()->id;
            }

            return $format;
        }
        return [
            'success' => false,
            'message' => 'Question Format is Invalid'
        ];
    }

    public function sectionWiseKnowledgeBase($payload)
    {
        if (isset($payload['data'])) {
            if ($payload['data']['filter']) {
                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $collection = $mongo->getCollectionForTable("knowledge_base");

                $query[] = ['$group' => ['_id' => '$section_code', 'data' => ['$push' => '$$ROOT']]];
                $fetchData = $collection->aggregate($query)->toArray();
                return [
                    'success' => true,
                    'count' => count($fetchData),
                    'data' => $fetchData
                ];
            }
        } else {
            return [
                'success' => false,
                'message' => 'Data is Missing'
            ];
        }
    }

    public function getKnowledgeBaseSections()
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable("knowledge_base");
        $result = $collection->distinct('section_code');
        return [
            'success' => true,
            'count' => count($result),
            'data' => $result
        ];
    }

    public function searchKnowledgeBase($payload)
    {
        $activePage = $payload['data']['activePage'] ?? 1;
        $listCount = $payload['data']['listItemCount'] ?? 10;
        if (isset($payload['data'])) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable("knowledge_base");
            if ($payload['data']['filter'] === 'all') {
                $prepareAggregate = [
                    [
                        '$match' => [
                            'question' =>
                            [
                                '$regex' => new Regex($payload['data']['query'], 'i')
                            ]
                        ]
                    ],
                    ['$skip' => ($activePage - 1) * (int)$listCount],
                    ['$limit' => (int)$listCount]
                ];
            } else {
                $prepareAggregate = [
                    [
                        '$match' => [
                            'section_code' => $payload['data']['filter'], 'question' => ['$regex' => new Regex($payload['data']['query'], 'i')]
                        ]
                    ],
                    ['$skip' => ($activePage - 1) * (int)$listCount],
                    ['$limit' => (int)$listCount]
                ];
            }

            $result = $collection->aggregate($prepareAggregate)->toArray();

            return $result;
        }
        return [];
    }
}