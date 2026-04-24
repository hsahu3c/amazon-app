<?php

namespace App\Amazon\Models;

use App\Core\Models\BaseMongo;

class OrderErrorSolution extends BaseMongo
{
    protected $table = 'order_error_solution';

    public function initialize()
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }

    public function get(array $params): array
    {
        $query = [];
        if (isset($params['filter'])) {
            $containerModel = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');
            $query = $containerModel->search($params);
        }

        $limit = (int)($params['count'] ?? 10);
        $page = $params['activePage'] ?? 1;
        $offset = ($page - 1) * $limit;

        $aggregation = [];
        if (!empty($query)) {
            $aggregation[] = ['$match' => $query];
        }

        // Total count
        $totalCountAggregation = $aggregation;
        $totalCountAggregation[] = [
            '$count' => 'total_count',
        ];

        // $skip and $limit
        $aggregation[] = ['$skip' => $offset];
        $aggregation[] = ['$limit' => $limit];

        try {
            // Aggregation to get the results
            $results = $this->getCollection()->aggregate($aggregation)->toArray();

            // Aggregation to get the total count
            $countResult = $this->getCollection()->aggregate($totalCountAggregation)->toArray();
            $totalCount = $countResult[0]['total_count'] ?? 0;

            return [
                'total_count' => $totalCount,
                'data' => $results,
            ];
        } catch (\Exception $e) {
            throw new \Exception("Error executing MongoDB query: " . $e->getMessage());
        }
    }

    public function saveErrorSolution(array $params)
    {
        try {
            // Preparing the data to be saved
            $data = [
                'error_msg' => $params['error_msg'],
                'category' => $params['category'],
                'solution_steps' => $params['solution_steps'],
            ];

            // Update case if id is set in params
            if (isset($params['id']) && $params['id']) {
                $result = $this->getCollection()->updateOne(
                    ['_id' => new \MongoDB\BSON\ObjectId($params['id'])],
                    ['$set' => $data]
                );

                return [
                    'success' => true,
                    'message' => 'Order error solution updated.',
                    'id' => (string) $params['id'],
                ];
            } else {
                // Checking if an entry with the same error_msg and category exists
                $existingEntry = $this->getCollection()->findOne([
                    'error_msg' => $params['error_msg'],
                    'category' => $params['category'],
                ]);

                if ($existingEntry) {
                    return [
                        'success' => false,
                        'message' => 'Error message already exists in the specified category.',
                    ];
                }

                $result = $this->getCollection()->insertOne($data);
                return [
                    'success' => true,
                    'message' => 'Order error solution created.',
                    'id' => (string) $result->getInsertedId(),
                ];
            }
        } catch (\Exception $e) {
            throw new \Exception("Error saving order error solution: " . $e->getMessage());
        }
    }
}
