<?php

/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Components\Product;

use App\Shopifyhome\Components\Core\Common;
use App\Connector\Models\QueuedTasks;
use Exception;

class Label extends Common
{

    private $mongo;
    private $labelContainer;
    private $productContainer;
    private $refineContainer;
    private $queueTasksContainer;
    const ALLOWED_FILE_TYPE = 'text/csv';

    /**
     * Initializes MongoDB collections used in this class.
     */
    public function initiate()
    {
        $this->mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $this->labelContainer = $this->mongo->getCollectionForTable('label_container');
        $this->productContainer = $this->mongo->getCollectionForTable('product_container');
        $this->refineContainer = $this->mongo->getCollectionForTable('refine_product');
        $this->queueTasksContainer = $this->mongo->getCollection("queued_tasks");
    }

    /**
     * Retrieves labels assigned to a specific user, shop, and marketplace.
     *
     * @param array $data
     * @return array
     */
    public function getLabels($data = null)
    {
        $this->initiate();
        try {
            $sourceShopId = $this->di->getRequester()->getSourceId() ?? $data['source_shop_id'];
            $sourceMarketplace =  $this->di->getRequester()->getSourceName() ?? $data['source_marketplace'];
            $options = [
                "projection" => ['_id' => 0, 'name' => 1, 'id' => 1, 'created_at' => 1],
                "typeMap" => ['root' => 'array', 'document' => 'array'],
            ];
            $labelData = $this->labelContainer->find([
                'user_id' => $this->di->getUser()->id,
                'source_shop_id' => $sourceShopId,
                'source' => $sourceMarketplace
            ], $options)->toArray();
            if (!empty($labelData)) {
                if (isset($data['count'])) {
                    foreach ($labelData as $label) {
                        $productCount = $this->productContainer->count([
                            'user_id' => $this->di->getUser()->id,
                            'shop_id' => $sourceShopId,
                            'source_marketplace' => $sourceMarketplace,
                            'visibility' => 'Catalog and Search',
                            'assigned_labels' => ['$elemMatch' => ['id' => $label['id']]]
                        ]);
                        $label['count'] = $productCount;
                        $preparedData[] = $label;
                    }
                } else {
                    $preparedData = $labelData;
                }
                return ['success' => true, 'data' => $preparedData];
            }

            return ['success' => false, 'message' => 'No data found'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception getLabels(), Error: ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }
    /**
     * Creates a new label and assigns it a unique ID.
     *
     * @param array $data
     * @return array
     */
    public function createLabel($data)
    {
        $this->initiate();
        try {
            if (!empty($data['name'])) {
                $sourceShopId = $this->di->getRequester()->getSourceId() ?? $data['source_shop_id'];
                $sourceMarketplace =  $this->di->getRequester()->getSourceName() ?? $data['source_marketplace'];
                $insertedDoc = $this->labelContainer->insertOne([
                    'user_id' => $this->di->getUser()->id,
                    'source_shop_id' => $sourceShopId,
                    'source' => $sourceMarketplace,
                    'name' => $data['name'],
                    'created_at' => date('c')
                ]);

                $labelId = (string) $insertedDoc->getInsertedId();
                $this->labelContainer->updateOne(
                    ['_id' => $insertedDoc->getInsertedId()],
                    ['$set' => ['id' => $labelId]]
                );
                return ['success' => true, 'message' => 'Label created successfully'];
            } else {
                $message = 'Required params missing';
            }
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception createLabel(), Error: ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * Deletes a label and removes it from all assigned products.
     *
     * @param array $data
     * @return array
     */
    public function deleteLabel($data)
    {
        $this->initiate();
        try {
            if (!empty($data['id'])) {
                $userId = $this->di->getUser()->id;
                $sourceShopId = $this->di->getRequester()->getSourceId() ?? $data['source_shop_id'];
                $sourceMarketplace =  $this->di->getRequester()->getSourceName() ?? $data['source_marketplace'];
                $this->labelContainer->deleteOne([
                    '_id' => new \MongoDB\BSON\ObjectId($data['id'])
                ]);
                $this->productContainer->updateMany(
                    [
                        'user_id' => $userId,
                        'shop_id' => $sourceShopId,
                        'source_marketplace' => $sourceMarketplace,
                        'assigned_labels' => ['$exists' => true]
                    ],
                    [
                        '$pull' => ['assigned_labels' => ['id' => $data['id']]]
                    ]
                );
                $this->refineContainer->updateMany(
                    [
                        'user_id' => $userId,
                        'source_shop_id' => $sourceShopId,
                        'assigned_labels' => ['$exists' => true]
                    ],
                    [
                        '$pull' => ['assigned_labels' => ['id' => $data['id']]]
                    ]
                );
                return ['success' => true, 'message' => 'Label deleted and unassigned from all products successfully'];
            } else {
                $message = 'Required params missing';
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception deleteLabel(), Error: ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    /**
     * Saves product labels for a given container.
     * If assigned_labels is empty, it removes labels from the product container and refine container.
     * Otherwise, it updates the assigned labels.
     *
     * @param array $data Contains assigned_labels, container_id, source_shop_id, and source_marketplace.
     * @return array Success or failure response.
     */
    public function saveProductLabels($data)
    {
        $this->initiate();
        try {
            if (isset($data['assigned_labels']) && !empty($data['container_id'])) {
                $sourceShopId = $this->di->getRequester()->getSourceId() ?? $data['source_shop_id'];
                $sourceMarketplace =  $this->di->getRequester()->getSourceName() ?? $data['source_marketplace'];
                $userId = $this->di->getUser()->id;
                if (empty($data['assigned_labels'])) {
                    $this->productContainer->updateOne(
                        [
                            'user_id' => $userId,
                            'shop_id' => $sourceShopId,
                            'source_marketplace' => $sourceMarketplace,
                            'container_id' => $data['container_id'],
                            'visibility' => 'Catalog and Search'
                        ],
                        [
                            '$unset' => ['assigned_labels' => 1]
                        ]
                    );
                    $this->refineContainer->updateMany(
                        [
                            'user_id' => $userId,
                            'source_shop_id' => $sourceShopId,
                            'container_id' => $data['container_id']
                        ],
                        [
                            '$unset' => ['assigned_labels' => 1]
                        ]
                    );
                    return ['success' => true, 'message' => 'All labels unassigned successfully'];
                } else {
                    $this->productContainer->updateOne(
                        [
                            'user_id' => $userId,
                            'shop_id' => $sourceShopId,
                            'source_marketplace' => $sourceMarketplace,
                            'container_id' => $data['container_id'],
                            'visibility' => 'Catalog and Search'
                        ],
                        ['$set' => ['assigned_labels' => $data['assigned_labels']]]
                    );
                    $this->refineContainer->updateMany(
                        [
                            'user_id' => $userId,
                            'source_shop_id' => $sourceShopId,
                            'container_id' => $data['container_id']
                        ],
                        ['$set' => ['assigned_labels' => $data['assigned_labels']]]
                    );
                    return ['success' => true, 'message' => 'Label assigned successfully'];
                }
            } else {
                $message = 'Required params missing';
            }
            return ['success' => false, 'message' => $message ?? 'Something went wrong'];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception saveProductLabels(), Error: ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * Handles bulk label assignment through CSV.
     * Uploads the CSV file, checks for an ongoing process, and queues the task in AWS SQS.
     *
     * @param array $data Contains unique_identifier and assigned_labels.
     * @return array Success or failure response.
     */
    public function bulkLabelAssignmentCSV($data)
    {
        $this->initiate();
        if (!empty($data['unique_identifier']) && !empty($data['assigned_labels'])) {
            $userId = $this->di->getUser()->id;
            $sourceShopId = $this->di->getRequester()->getSourceId() ?? $data['source_shop_id'];
            $sourceMarketplace =  $this->di->getRequester()->getSourceName() ?? $data['source_marketplace'];
            $path = BP . DS . "var/file/$userId/";
            $files = $data['files'] ?? '';
            $fileNameFormat = "bulk_label_assignment.csv";
            if (!empty($files)) {
                $queueData = [
                    'user_id' => $this->di->getUser()->id,
                    'message' => "Label Assignment Process",
                    'process_code' => 'csv_product_label',
                    'marketplace' => $sourceMarketplace
                ];
                $queuedTask = new QueuedTasks;
                $queuedTaskId = $queuedTask->setQueuedTask($sourceShopId, $queueData);
                if (!$queuedTaskId) {
                    return ['success' => false, 'message' => 'Bulk Label Assignment is already under process.Check for updates in Overview section'];
                }
                foreach ($files as $file) {
                    if ($file->getType() != self::ALLOWED_FILE_TYPE) {
                        return ['success' => false, 'message' => 'Please use CSV file type only'];
                    }
                    $file->moveTo(
                        $path . $fileNameFormat
                    );
                }

                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => '\App\Shopifyhome\Components\Product\Label',
                    'method' => 'initateBulkLabelAssignmentProcess',
                    'user_id' => $userId,
                    'shop_id' => $sourceShopId,
                    'marketplace' => $sourceMarketplace,
                    'queue_name' => 'bulk_label_assignment',
                    'data' => [
                        'file_path' => $path . $fileNameFormat,
                        'unique_identifier' => $data['unique_identifier'],
                        'assigned_labels' => json_decode($data['assigned_labels'],true),
                        'process_code' => 'csv_product_label'
                    ]
                ];
                $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
                $sqsHelper->pushMessage($handlerData);
                return ['success' => true, 'message' => 'Bulk label assignment process inititated'];
            } else {
                $message = 'File not found';
            }
        } else {
            $message = 'Required params missing';
        }

        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    /**
     * Initiates the bulk label assignment process from AWS SQS.
     * Reads the CSV file, extracts identifiers, and assigns labels to products.
     *
     * @param array $sqsData Contains queue data and file path.
     * @return array Success response after process completion.
     */
    public function initateBulkLabelAssignmentProcess($sqsData)
    {
        $this->initiate();
        if (!empty($sqsData['data'])) {
            $messageData = $sqsData['data'];
            $page = $messageData['page'] ?? 1;
            $limit = 500;
            $offset = ($page - 1) * $limit;
            $maxAllowedRows = 10000;

            if (!isset($messageData['total_rows'])) {
                $messageData['total_rows'] = $this->getTotalRowsFromCsv($messageData['file_path']);
            }
            if (!isset($messageData['labelAssignedCount'])) {
                $messageData['labelAssignedCount'] = 0;
            }
            if ($messageData['total_rows'] > $maxAllowedRows) {
                $this->removeEntryFromQueuedTask($messageData['process_code']);
                $this->updateActivityLog($sqsData, false, 'Max rows allowed in single CSV file is 10000');
                unlink($messageData['file_path']);
                return ['success' => false, 'message' => 'Max rows allowed in single CSV file is 10000'];
            }

            $identifiers = $this->fetchCsvData($messageData['file_path'], $messageData['unique_identifier'], $offset, $limit);
            if (empty($identifiers)) {
                $this->removeEntryFromQueuedTask($messageData['process_code']);
                $this->updateActivityLog($sqsData, false, 'Selected unique identifier not found in CSV');
                unlink($messageData['file_path']);
                return ['success' => false, 'message' => 'Selected unique identifier not found in CSV'];
            }
            $response = $this->assignLabels($identifiers, $sqsData);
            $progress = round((($page * $limit) / $messageData['total_rows']) * 100, 2);
            $this->updateQueuedTaskProgress($messageData['process_code'], $progress);
            $messageData['labelAssignedCount'] = $messageData['labelAssignedCount'] + $response['data'];
            if (($page * $limit) < $messageData['total_rows']) {
                $messageData['page'] = $page + 1;
                $sqsData['data'] = $messageData;
                $sqsHelper = $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs');
                $sqsHelper->pushMessage($sqsData);
                $message = "Bulk label assignment page- {$page} completed";
            } else {
                $this->removeEntryFromQueuedTask($messageData['process_code']);
                $this->updateActivityLog($sqsData, true, 'Your selected labels has been assigned to '.$messageData['labelAssignedCount']. ' product(s)');
                unlink($messageData['file_path']);
                $message = "Bulk label assignment process completed";
            }
        }
        return ['success' => true, 'message' => $message ?? 'Process Completed'];
    }

    /**
     * Reads a CSV file and extracts identifiers based on the unique identifier column.
     *
     * @param string $csvPath Path to the CSV file.
     * @param string $uniqueIdentifier Column name to extract values.
     * @return array List of extracted identifiers.
     */
    private function fetchCsvData($csvPath, $uniqueIdentifier, $offset, $limit)
    {
        $identifiers = [];
        if (($handle = fopen($csvPath, "r")) !== false) {
            $header = fgetcsv($handle);
            if (!$header) {
                fclose($handle);
                return [];
            }
            $header = array_map('strtolower', $header);
            $index = array_search($uniqueIdentifier, $header);
            if ($index === false) {
                fclose($handle);
                return [];
            }

            $currentRow = 0;
            while (($row = fgetcsv($handle)) !== false) {
                if ($currentRow >= $offset && count($identifiers) < $limit) {
                    $identifiers[] = $row[$index];
                }
                $currentRow++;
                if (count($identifiers) >= $limit) {
                    break;
                }
            }
            fclose($handle);
        }
        return $identifiers;
    }

    /**
     * Fetching total number of rows present in a CSV
     *
     * @param string $csvPath Path of CSV File
     * @param string
     */
    private function getTotalRowsFromCsv($csvPath)
    {
        $totalRows = 0;
        if (($handle = fopen($csvPath, "r")) !== false) {
            fgetcsv($handle); // Skip header
            while (fgetcsv($handle) !== false) {
                $totalRows++;
            }
            fclose($handle);
        }
        return $totalRows;
    }

    /**
     * Assigns labels to products based on extracted identifiers.
     * Updates progress and logs activity.
     *
     * @param array $identifiers List of product identifiers.
     * @param array $data Contains label details and process metadata.
     */
    private function assignLabels($identifiers, $data)
    {
        try {
            $identifiers = array_values((array_unique($identifiers)));
            $messageData = $data['data'];
            $labelAssignedCount = 0;
            $containerIds = $this->productContainer->distinct(
                "container_id",
                [
                    'user_id' => $this->di->getUser()->id,
                    'shop_id' => $data['shop_id'],
                    'source_marketplace' => $data['marketplace'],
                    $messageData['unique_identifier'] => ['$in' => $identifiers]
                ]
            );
            if (!empty($containerIds)) {
                $this->productContainer->updateMany(
                    [
                        'user_id' => $this->di->getUser()->id,
                        'shop_id' => $data['shop_id'],
                        'source_marketplace' => $data['marketplace'],
                        'visibility' => 'Catalog and Search',
                        'container_id' => ['$in' => $containerIds]
                    ],
                    ['$addToSet' => ['assigned_labels' => ['$each' => $messageData['assigned_labels']]]]
                );
                $this->refineContainer->updateMany(
                    [
                        'user_id' => $this->di->getUser()->id,
                        'source_shop_id' => $data['shop_id'],
                        'container_id' => ['$in' => $containerIds]
                    ],
                    ['$addToSet' => ['assigned_labels' => ['$each' => $messageData['assigned_labels']]]]
                );
                $labelAssignedCount = $labelAssignedCount + count($containerIds);
            }
            return ['success' => true, 'data' => $labelAssignedCount];
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception assignLabels(), Error: ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * Logs the activity of bulk label assignment.
     * Creates notifications based on success or failure.
     *
     * @param array $data Contains marketplace and process metadata.
     * @param bool $status Success or failure status.
     * @param string $message Message to log.
     */
    private function updateActivityLog($data, $status, $message)
    {
        $appTag = $this->di->getAppCode()->getAppTag();
        $notificationData = [
            'severity' => $status ? 'success' : 'critical',
            'message' => $message ?? 'Bulk Label Assignment Process',
            "user_id" => $this->di->getUser()->id,
            "marketplace" => $data['marketplace'],
            "appTag" => $appTag,
            'process_code' => $data['data']['process_code'] ?? 'csv_product_label'
        ];
        $this->di->getObjectManager()->get('\App\Connector\Models\Notifications')
            ->addNotification($data['shop_id'], $notificationData);
    }

    /**
     * Updates the progress of the bulk label assignment process in the queue.
     *
     * @param string $processCode Unique process identifier.
     * @param float $progress Progress percentage.
     */
    private function updateQueuedTaskProgress($processCode, $progress)
    {
        try {
            $this->queueTasksContainer->updateOne(
                [
                    'user_id' => $this->di->getUser()->id,
                    'process_code' => $processCode
                ],
                [
                    '$set' => ['progress' => $progress]

                ]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception removeEntryFromQueuedTask(), Error: ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }

    /**
     * Removes an entry from the queued task after the process is completed.
     *
     * @param string $processCode Unique process identifier.
     */
    private function removeEntryFromQueuedTask($processCode)
    {
        try {
            $this->queueTasksContainer->deleteOne([
                'user_id' => $this->di->getUser()->id,
                'process_code' => $processCode
            ]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Exception removeEntryFromQueuedTask(), Error: ' . print_r($e->getMessage()), 'info',  'exception.log');
        }
    }
}