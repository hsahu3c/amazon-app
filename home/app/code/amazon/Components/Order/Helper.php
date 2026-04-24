<?php


namespace App\Amazon\Components\Order;

use Exception;
use MongoDB\BSON\ObjectID;
use App\Connector\Models\QueuedTasks as ConnectorQueuedTasks;
use App\Core\Components\Base;
use Aws\S3\S3Client;

class Helper extends Base
{
    const QUEUE_NAME      = 'failed_order_report';
    const CHUNK_SIZE      = 100;
    const S3_URL_VALIDITY = '+72 hour';

    public function fetchOrders($data) {
        $res = $this->di->getObjectManager()->get(Order::class)
            ->init($data)
            ->fetchOrders($data);
        return $res;
    }

    public function shipOrders($data) {
        $res = $this->di->getObjectManager()->get(Order::class)
            ->init($data)
            ->shipOrders($data);
        return $res;
    }

    public function fetchOrdersByShopId($data)
    {   
        // print_r($data);die("second");
        $res = $this->di->getObjectManager()->get(Order::class)
            ->init($data)
            ->fetchOrdersByShopId($data);
        return $res;
    }

    public function getOrderById($data){
        $res = $this->di->getObjectManager()->get(Order::class)
            ->init()
            ->getOrderById($data);
        return $res;
    }

    public function fetchGMVData($data)
    {
        $this->di->getLog()->logContent('fetchGMVData data: ' . json_encode($data['user_id']), 'info', 'GMVData.log');
        $userId = $data['user_id'];

        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $orderCollection = $mongo->getCollectionForTable('order_container');
            $paymentCollection = $mongo->getCollectionForTable('payment_details');
            $paymentDetails = $paymentCollection->findOne(
                [
                    'user_id' => $userId,
                    'type' => 'active_plan',
                    'status' => 'active',
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'projection' => [
                        'user_id' => 1,
                        'plan_details.custom_price' => 1,
                        'plan_details.billed_type' => 1,
                        'plan_details.title' => 1
                    ],
                ]
            );
            if (empty($paymentDetails)) {
                $this->di->getLog()->logContent('No payment details found for user_id' . json_encode($userId), 'info', 'GMVData.log');
                return true;
            }

            // Update $data['source_marketplace'] with the latest paymentDetails result
            $planDetails = (isset($paymentDetails['plan_details']) && is_array($paymentDetails['plan_details']))
                ? $paymentDetails['plan_details']
                : [];
            if (!isset($data['source_marketplace']) || !is_array($data['source_marketplace'])) {
                $data['source_marketplace'] = [];
            }
            $data['source_marketplace']['custom_price'] = (float)($planDetails['custom_price'] ?? 0);
            $data['source_marketplace']['billed_type'] = (string)($planDetails['billed_type'] ?? '');
            $data['source_marketplace']['title'] = (string)($planDetails['title'] ?? '');
            $shops = $this->di->getUser()->shops ?? [];
            if (empty($shops)) {
                $this->di->getLog()->logContent('No shops found for user_id: ' . $userId, 'info', 'GMVData.log');
                return true;
            }
            foreach ($shops as $shop) {
                if ($shop['marketplace'] == 'shopify') {
                    $data['phone'] = $shop['phone'] ?? '';
                    $data['email'] = $shop['email'] ?? '';
                } else {
                    $marketplaceShopId = $shop['_id'];
                    $marketplaceId = $shop['warehouses'][0]['marketplace_id'] ?? '';
                    $match = [
                        'object_type' => 'source_order',
                        'user_id' => (string)$userId,
                        'marketplace' => 'amazon',
                        'marketplace_shop_id' => (string)$marketplaceShopId,
                    ];

                    $pipeline = [
                        ['$match' => $match],
                        [
                            '$group' => [
                                '_id' => null,
                                'order_count' => ['$sum' => 1],
                                // total.price is stored as string in doc; convert to double
                                'total_price_sum' => [
                                    '$sum' => [
                                        '$convert' => [
                                            'input' => '$total.price',
                                            'to' => 'double',
                                            'onError' => 0,
                                            'onNull' => 0,
                                        ]
                                    ]
                                ],
                            ]
                        ],
                    ];

                    $aggOptions = ['typeMap' => ['root' => 'array', 'document' => 'array']];
                    $orderAgg = $orderCollection->aggregate($pipeline, $aggOptions)->toArray();
                    $orderSummary = $orderAgg[0] ?? ['order_count' => 0, 'total_price_sum' => 0];
                    $orderCount = (int)($orderSummary['order_count'] ?? 0);
                    $orderTotalSum = (float)($orderSummary['total_price_sum'] ?? 0);

                    // Archive order container aggregate (count + sum(marketplace_price))
                    $archiveCollection = $mongo->getCollectionForTable('archive_order_container');
                    $archivePipeline = [
                        [
                            '$match' => [
                                'user_id' => (string)$userId,
                                'marketplace' => 'amazon',
                                'marketplace_shop_id' => (string)$marketplaceShopId,
                            ]
                        ],
                        [
                            '$group' => [
                                '_id' => null,
                                'archive_count' => ['$sum' => 1],
                                'archive_marketplace_price_sum' => [
                                    '$sum' => [
                                        '$convert' => [
                                            'input' => '$marketplace_price',
                                            'to' => 'double',
                                            'onError' => 0,
                                            'onNull' => 0,
                                        ]
                                    ]
                                ],
                            ]
                        ],
                    ];
                    $archiveAgg = $archiveCollection->aggregate($archivePipeline, $aggOptions)->toArray();
                    $archiveSummary = $archiveAgg[0] ?? ['archive_count' => 0, 'archive_marketplace_price_sum' => 0];
                    $archiveCount = (int)($archiveSummary['archive_count'] ?? 0);
                    $archiveMarketplaceSum = (float)($archiveSummary['archive_marketplace_price_sum'] ?? 0);

                    $totalOrderCount = (int)($orderCount + $archiveCount);
                    $amount = (float)($orderTotalSum + $archiveMarketplaceSum);
                    $amountUsd = $this->convertCurrency((string)$marketplaceId, $amount);
                    $gmvDataCollection = $mongo->getCollectionForTable('gmvData');
                    $sourceMarketplace = (isset($data['source_marketplace']) && is_array($data['source_marketplace']))
                        ? $data['source_marketplace']
                        : [];
                    $gmvDoc = [
                        'user_id' => (string)$userId,
                        'username' => (string)($data['username'] ?? ''),
                        'email' => (string)($data['email'] ?? ''),
                        'phone' => (string)($data['phone'] ?? ''),
                        'marketplace_id' => (string)$marketplaceId,
                        'marketplace_shop_id' => (string)$marketplaceShopId,
                        'totalOrderCount' => (int)$totalOrderCount,
                        'amount' => (float)$amountUsd,
                        'source_marketplace' => [
                            'custom_price' => (float)($sourceMarketplace['custom_price'] ?? 0),
                            'billed_type' => (string)($sourceMarketplace['billed_type'] ?? ''),
                            'title' => (string)($sourceMarketplace['title'] ?? ''),
                        ],
                        'created_at' => date('c'),
                    ];
                    $gmvDataCollection->insertOne($gmvDoc);
                }
            }
            
            return true;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('fetchGMVData exception: ' . $e->getMessage() . ' data: ' . json_encode($data), 'error', 'GMVData.log');
        }
    }

    private function convertCurrency($marketplace_id, $amount)
    {
        //Rates as per 9 Jan 2026
        $conversionRate = [
            //North America
            'A2EUQ1WTGCTBG2' => 0.72, // Canada
            'ATVPDKIKX0DER' => 1, //US
            'A1AM78C64UM0Y8' => 0.056, //Mexico
            'A2Q3Y263D00KWC' => 0.19, //Brazil
            //Europe
            'A28R8C7NBKEWEA' => 1.16, //Ireland
            'A1RKKUPIHCS9HS' => 1.16, //Spain
            'A13V1IB3VIYZZH' => 1.16, //France
            'AMEN7PMS3EDWL' => 1.16, //Belgium
            'A1805IZSGTT6HS' => 1.16, //Netherlands
            'A1PA6795UKMFR9' => 1.16, //Germany
            'APJ6JRA9NG5V4' => 1.16, //Italy
            'A1F83G8C2ARO7P' => 1.34, //UK
            'A2NODRKZP88ZB9' => 0.11, //Sweden
            'AE08WJ6YKNBMC' => 0.060, //South Africa
            'A1C3SOZRARQ6R3' => 0.28, //Poland
            'ARBP9OOSHTCHU' => 0.021, //Egypt
            'A33AVAJ2PDY3EV' => 0.023, //Turkey
            'A17E79C6D8DWNP' => 0.27, //Soudi Arabia
            'A2VIGQ35RCS4UG' => 0.27, //UAE
            'A21TJRUUN4KGV' => 0.011, //India
            //Far East
            'A19VAU5U5O7RUS' => 0.78, //Singapore
            'A39IBJ37TRP1C6' => 0.67, //Australia
            'A1VC38T7YXB528' => 0.0063, //Japan
        ];

        if (isset($conversionRate[$marketplace_id])) {
            return $amount * $conversionRate[$marketplace_id];
        }
    }

    /**
     * SQS worker — fetches one chunk of failed orders and writes them to CSV.
     * Re-queues itself (with updated last_traversed_object_id) until all records
     * are processed, mirroring ExportUnlinkedProduct::exportAmazonListing().
     *
     * @param array $data  SQS message payload
     *
     * @return void
     */
    public function fetchOrderData($data)
    {
        try {
            $userId                = $data['user_id'];               
            $marketplaceShopId     = $data['marketplace_shop_id'];     
            $csvFilePath           = $data['csv_file_path'];         
            $feedId                = $data['feed_id'] ;
            $totalCount            = (int)($data['total_count']        ?? 0);
            $exportedCount         = (int)($data['exported_count']     ?? 0);
            $lastTraversedObjectId = $data['last_traversed_object_id'] ?? '';
            $prevMondayStr         = $data['prev_monday_str']          ?? date('Y-m-d', strtotime('-7 days'));
            $currentDateStr        = $data['current_date_str']         ?? date('Y-m-d');
            $logFile               = 'amazon/FailedOrderReport/' . $userId . '/' . date('d-m-Y') . '.log';

            // Defensive guard — feed_id MUST be a non-empty string before any MongoDB
            // ObjectId construction. setQueuedTask() returns ['success'=>false,...] on
            // failure; a non-empty array passes empty() but is still invalid here.
            if (empty($feedId) || !is_string($feedId)) {
                $this->di->getLog()->logContent(
                    'Helper::fetchOrderData() — invalid feed_id (' . gettype($feedId) . '): ' . json_encode($feedId),
                    'info',
                    $logFile
                );
                return;
            }

            // Idempotency guard — updateFeedProgress() deletes the queued_task document once
            // progress reaches 100 %. If the task is already gone, this is a duplicate SQS
            // re-delivery (SQS guarantees at-least-once, not exactly-once). Skip silently so
            // the email/notification never fires a second time.
            $taskStillExists = $this->di->getObjectManager()
                ->get(ConnectorQueuedTasks::class)
                ->checkQueuedTaskExists($feedId);

            if (!$taskStillExists) {
                $this->di->getLog()->logContent(
                    'Helper::fetchOrderData() — feed_id=' . $feedId . ' already completed; skipping duplicate SQS delivery.',
                    'info',
                    $logFile
                );
                return;
            }

            if (empty($csvFilePath)) {
                $this->di->getLog()->logContent('CSV file path is empty'.json_encode($data), 'info', $logFile);
                $this->di->getObjectManager()->get(ConnectorQueuedTasks::class)->updateFeedProgress($feedId, 100, "CSV file path is empty", false);
                return;
            }

            $mongo= $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $orderCollection = $mongo->getCollectionForTable('order_container');

            // Build match query
            $match = [
                'user_id'             => (string)$userId,
                'object_type'         => 'source_order',
                'marketplace_shop_id' => (string)$marketplaceShopId,
                'created_at'          => [
                    '$gte' => $prevMondayStr,
                    '$lt' => $currentDateStr,
                ],
                'targets.errors' => ['$exists' => true],
            ];

            // Cursor-based pagination: subsequent batches add _id > lastId
            if (!empty($lastTraversedObjectId)) {
                $match['_id'] = ['$gt' => new ObjectID((string)$lastTraversedObjectId)];
            }

            $queryOptions = [
                'typeMap'    => ['root' => 'array', 'document' => 'array'],
                'limit'      => self::CHUNK_SIZE,
                'sort'       => ['_id' => 1],
                'projection' => [
                    'marketplace_reference_id' => 1,
                    'targets'                  => 1,
                    'source_created_at'        => 1,
                    'marketplace_status'       => 1,
                    'status'                   => 1,
                ],
            ];

            $orders = $orderCollection->find($match, $queryOptions)->toArray();

            if (!empty($orders)) {
                // Write this batch to CSV
                $this->writeCsvFile($csvFilePath, $orders);

                // Advance cursor and count
                $lastTraversedObjectId = (string)end($orders)['_id'];
                $exportedCount        += count($orders);

                if ($exportedCount < $totalCount) {
                    // === MORE CHUNKS TO PROCESS ===

                    // 1. Update progress
                    $progress = round(($exportedCount / $totalCount) * 100, 2);

                    $this->di->getObjectManager()->get(ConnectorQueuedTasks::class)->updateFeedProgress($feedId,$progress,"Fetched $exportedCount of $totalCount orders sync data",false);

                    // 2. Push next chunk with updated cursor
                    $nextBatch = [
                        'type'                     => 'full_class',
                        'class_name'               => self::class,
                        'method'                   => 'fetchOrderData',
                        'queue_name'               => self::QUEUE_NAME,
                        'user_id'                  => $userId,
                        'marketplace_shop_id'      => $marketplaceShopId,
                        'csv_file_path'            => $csvFilePath,
                        'feed_id'                  => $feedId,
                        'total_count'              => $totalCount,
                        'exported_count'           => $exportedCount,
                        'last_traversed_object_id' => $lastTraversedObjectId,
                        'prev_monday_str'          => $prevMondayStr,
                        'current_date_str'         => $currentDateStr,
                        // propagate notification fields across chunks
                        'appTag'                   => $data['appTag']   ?? '',
                        'message'                  => $data['message']  ?? '',
                    ];
                    $this->di->getLog()->logContent('Pushing next batch'.json_encode($nextBatch), 'info', $logFile);
                    $this->di->getObjectManager()->get('App\Core\Components\Message\Handler\Sqs')->pushMessage($nextBatch);
                } else {
                    // === FINAL CHUNK — COMPLETE ===

                    // 1. Upload CSV to S3 and get pre-signed URL
                    $s3Url = $this->getDownloadUrlS3($data['csv_file_path']);
                    // Display end = currentDateStr - 1 day because the DB query uses $lt (strictly
                    // less than), so the last day actually included in the data is currentDateStr - 1.
                    // e.g. cron on 2026-03-06: $lt 2026-03-06 → data covers up to 2026-03-05.
                    $displayEndDate = date('Y-m-d', strtotime(($data['current_date_str'] ?? 'now') . ' -1 day'));
                    $dateRange      = ($data['prev_monday_str'] ?? '') . ' to ' . $displayEndDate;

                    if ($s3Url) {
                        $data['url']     = $s3Url;
                        $message         = "Your Weekly Order(s) Sync Report from {$dateRange} is ready.";
                        $data['message'] = $message;
                    } else {
                        $message              = "Your Weekly Order(s) Sync Report from {$dateRange} has been generated successfully. However, we encountered an issue while preparing the download link. Please contact support if this issue persists.";
                        $data['message']      = $message;
                        $data['severity']     = 'error';
                    }

                    // 2. Send email to the user with the CSV attached
                    //    (before notification so the user gets the report via email first)
                    //    Note: notification keeps $data['url'] = $s3Url for the UI download link.
                    $this->sendReportEmail($data, $data['csv_file_path'], $dateRange);

                    // 3. Mark QueuedTask 100%
                    $this->di->getObjectManager()->get(ConnectorQueuedTasks::class)
                        ->updateFeedProgress($feedId, 100, $message, false);

                    // 4. Fire UI notification
                    $this->addNotification($data);

                    // 5. Clean up local file
                    if (file_exists($data['csv_file_path'])) {
                        unlink($data['csv_file_path']);
                    }
                    $this->di->getLog()->logContent('Completed fetching orders sync data'.json_encode($data), 'info', $logFile);
                }
            } else {
                // No data found in this batch — mark complete
                $this->di->getObjectManager()->get(ConnectorQueuedTasks::class)
                    ->updateFeedProgress($feedId, 100, "Failed Order Report complete. All data prsendocessed.", false);
                $this->di->getLog()->logContent('No data found in this batch'.json_encode($data), 'info', $logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent(
                'Exception Helper::fetchOrderData() user_id=' . ($data['user_id'] ?? '') . ': ' . print_r($e->getMessage(), true),
                'info',
                'exception.log'
            );
        }
    }

    /**
     * Append order rows to the per-user CSV file.
     *
     * @param string $filePath
     * @param array  $rows     Array of order documents from order_container
     *
     * @return void
     */
    private function writeCsvFile($filePath, $rows)
    {
        try {
            if (empty($rows) || !file_exists($filePath)) {
                return;
            }

            $handle = fopen($filePath, 'a');
            if ($handle === false) {
                return;
            }

            foreach ($rows as $row) {
                // targets is an array of objects; collect all error strings across all targets
                $targetsErrors = '';
                if (!empty($row['targets']) && is_array($row['targets'])) {
                    $allErrors = [];
                    foreach ($row['targets'] as $target) {
                        if (!empty($target['errors']) && is_array($target['errors'])) {
                            foreach ($target['errors'] as $err) {
                                $allErrors[] = (string)$err;
                            }
                        }
                    }
                    $targetsErrors = implode(' | ', $allErrors);
                }

                fputcsv($handle, [
                    $row['marketplace_reference_id'] ?? '',  // Order ID
                    $row['source_created_at']        ?? '',  // Created on Amazon
                    $row['marketplace_status']       ?? '',  // Status
                    $row['status']                   ?? '',  // Sync Status
                    $targetsErrors,                          // Failed order reason
                ]);
            }

            fclose($handle);
        } catch (Exception $e) {
            $this->di->getLog()->logContent(
                'Exception Helper::writeCsvFile(): ' . print_r($e->getMessage(), true),
                'info',
                'exception.log'
            );
        }
    }

    /**
     * Upload the finished CSV to S3 and return a pre-signed download URL.
     * Mirrors ExportUnlinkedProduct::getDownloadUrlS3().
     *
     * @param string $filePath  Absolute path to the local CSV file
     *
     * @return string|null  Pre-signed URL on success, null on failure
     */
    private function getDownloadUrlS3($filePath)
    {
        try {
            $bucketName = $this->di->getConfig()->get('bulk_linking');
            $parts    = explode(DIRECTORY_SEPARATOR, rtrim($filePath, DIRECTORY_SEPARATOR));
            $fileName = implode('/', array_slice($parts, -3)); // e.g. {userId}/{shopId}/failed_orders_{date}.csv
            $config     = include BP . '/app/etc/aws.php';

            $s3Client = new S3Client($config);

            $s3Client->putObject([
                'Bucket'      => $bucketName,
                'Key'         => $fileName,
                'SourceFile'  => $filePath,
                'ContentType' => 'text/csv',
            ]);

            $cmd     = $s3Client->getCommand('GetObject', ['Bucket' => $bucketName, 'Key' => $fileName]);
            $request = $s3Client->createPresignedRequest($cmd, self::S3_URL_VALIDITY);

            return (string)$request->getUri();
        } catch (Exception $e) {
            $this->di->getLog()->logContent(
                'Exception Helper::getDownloadUrlS3(): ' . print_r($e->getMessage(), true),
                'info',
                'exception.log'
            );
        }
    }

    /**
     * Send a "Failed Order Report is ready" email to the user with the CSV attached.
     *
     * How email sending works in this codebase (ref: Plan.php:2275, Common/Helper.php:2762):
     *   - Plan.php calls Email::sendPlanActivationMail() → builds $mailData → Email::sendMail()
     *     which fetches user details, appends config (bccs, sender, support_page) and calls
     *     SendMail::send($mailData).
     *   - The direct pattern used across amazon code (AmazonScriptsCommand, Common/Helper) is
     *     to build $mailData with at minimum: email, name, subject, path (Volt template), and
     *     any custom template vars, then call SendMail::send() directly.
     *   - SendMail::send() compiles the Volt template and dispatches the email via the mailer.
     *   - The mailer supports file attachments via the 'files' key (array of file paths).
     *     SendMail::send() passes it straight through as $data['files'] ?? [].
     *
     * NOTE: The notification flow is untouched — $s3Url is still set on $data['url'] in
     *       fetchOrderData() and passed to addNotification() for the UI download link.
     *
     * @param array  $data         SQS payload (user_id, marketplace_shop_id, total_count, …)
     * @param string $csvFilePath  Absolute path to the local CSV file to attach
     * @param string $dateRange    Human-readable date range e.g. "2026-02-23 to 2026-03-02"
     *
     * @return void
     */
    private function sendReportEmail($data, $csvFilePath, $dateRange)
    {
        try {
            $appName = $this->di->getConfig()->app_name ?? 'CedCommerce Amazon';

            // Resolve user email + name from DI (set by FailedOrderReportCommand::setDiForUser)
            $user  = $this->di->getUser() ?? $data['user_id'];
            $email = $user->source_email ?? ($user->email ?? '');
            $name  = $user->name         ?? ($user->username ?? 'there');


            if (empty($email)) {
                return;
            }

            $mailData = [
                'email'        => $email,
                'name'         => $name,
                'app_name'     => $appName,
                'subject'      => 'Your Weekly Order(s) Sync Report is Ready - ' . $appName,
                'date_range'   => $dateRange,
                'total_count'  => $data['total_count'] ?? 0,
                'generated_on' => date('d-m-Y H:i:s'),
                // Attach the CSV directly — SendMail::send() passes 'files' to the mailer
                'files'        => file_exists($csvFilePath) ? [['path' => $csvFilePath, 'name' => basename($csvFilePath)]] : [],
                'path'         => 'amazon' . DS . 'view' . DS . 'email' . DS . 'FailedOrderReport.volt',
            ];

            $mailResponse = $this->di->getObjectManager()
                ->get('App\Core\Components\SendMail')
                ->send($mailData);

        } catch (Exception $e) {
            $this->di->getLog()->logContent(
                'Exception Helper::sendReportEmail(): ' . print_r($e->getMessage(), true),
                'info',
                'exception.log'
            );
        }
    }

    /**
     * Persist a UI notification for the user once the report is done.
     * Mirrors ExportUnlinkedProduct::addNotification().
     *
     * @param array $messageData  Must contain user_id, marketplace_shop_id, appTag, message
     *
     * @return void
     */
    private function addNotification($messageData)
    {
        try {
            $userId            = $this->di->getUser()->id ?? ($messageData['user_id'] ?? '');
            $marketplaceShopId = $messageData['marketplace_shop_id'] ?? '';

            $notificationData = [
                'user_id'      => $userId,
                'message'      => $messageData['message']  ?? 'Failed Order Report is complete.',
                'severity'     => $messageData['severity'] ?? 'success',
                'created_at'   => date('c'),
                'shop_id'      => $marketplaceShopId,
                'marketplace'  => 'amazon',
                'appTag'       => $messageData['appTag']   ?? '',
                'process_code' => self::QUEUE_NAME,
            ];

            if (!empty($messageData['url'])) {
                $notificationData['url'] = $messageData['url'];
            }

            $this->di->getObjectManager()
                ->get('App\Connector\Models\Notifications')
                ->addNotification($marketplaceShopId, $notificationData);
        } catch (Exception $e) {
            $this->di->getLog()->logContent(
                'Exception Helper::addNotification(): ' . print_r($e->getMessage(), true),
                'info',
                'exception.log'
            );
        }
    }
}