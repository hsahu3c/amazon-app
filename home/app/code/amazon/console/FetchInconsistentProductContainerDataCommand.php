<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Core\Models\User;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Exception;

#[\AllowDynamicProperties]
class FetchInconsistentProductContainerDataCommand extends Command
{
    protected static $defaultName = "amazon:fetch-inconsistent-product-container";
    protected static $defaultDescription = "Find source_product_ids in product_container (target_marketplace=amazon) that do not exist with source_marketplace=shopify";

    public const PROCESS_CODE = 'fetch_inconsistent_product_container_data';
    public const USER_BATCH_SIZE = 500;
    public const LISTING_BATCH_SIZE = 500;

    protected $mongo;
    protected $di;
    protected $input;
    protected $output;
    protected $logFile;

    public function __construct($di)
    {
        parent::__construct();
        $this->di = $di;
        $this->mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
    }

    protected function configure()
    {
        $this->setHelp('Fetch product_container target=amazon in batches, check source=shopify existence, store missing into orphaned_amazon_products_container. Uses cron_tasks for resume. Use -a or --all_users, or -p or --specific_users (flags; no value).');
        $this->addOption("all_users", "a", InputOption::VALUE_NONE, "Process for all users");
        $this->addOption("specific_users", "p", InputOption::VALUE_NONE, "Process for specific users (edit fetchSpecificUsers() with user IDs)");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->logFile = "amazon/scripts/fetchInconsistentProductContainer/" . date('d-m-Y') . '.log';

        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Fetch Inconsistent Product Container Data...</>');

        $this->allUsers = (bool) $input->getOption("all_users");
        $this->specificUsers = (bool) $input->getOption("specific_users");

        if (!$this->allUsers && !$this->specificUsers) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR: Either -a (all users) or -p (specific users) option is required</>');
            return 0;
        }

        $this->setAppCodeForAmazon();

        $resumeData = $this->getResumeData();

        if ($resumeData) {
            $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Resuming from User: ' . $resumeData['last_processed_user_id'] . '</>');
            $this->processUser($resumeData['last_processed_user_id'], $resumeData['last_processed_product_container_id']);
        }

        $lastProcessedUserId = $resumeData['last_processed_user_id'] ?? null;
        $totalProcessedUsers = 0;

        if ($this->specificUsers) {
            $users = $this->fetchSpecificUsers($lastProcessedUserId);
            if (empty($users)) {
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No specific users to process or all users already processed</>');
                return 0;
            }
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Processing ' . count($users) . ' specific users</>');
            foreach ($users as $user) {
                $this->processUser($user);
                $this->updateLastProcessedUserId($user);
                $totalProcessedUsers++;
            }
            $this->clearLastProcessedProductContainerId();
        } else {
            do {
                $users = $this->getAllUsers($lastProcessedUserId);
                if (empty($users)) {
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> No more users to process. All users completed!</>');
                    break;
                }
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Processing batch of ' . count($users) . ' users</>');
                foreach ($users as $user) {
                    $this->processUser($user);
                    $lastProcessedUserId = $user;
                    $totalProcessedUsers++;
                }
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Completed batch. Total processed: ' . $totalProcessedUsers . '</>');
                $this->di->getLog()->logContent('Completed batch of ' . count($users) . ' users. Total processed: ' . $totalProcessedUsers, 'info', $this->logFile);
            } while (count($users) == self::USER_BATCH_SIZE);
        }

        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> All users processing completed! Total: ' . $totalProcessedUsers . '</>');
        return 0;
    }

    private function processUser($userId, $lastProcessedProductContainerId = null)
    {
        try {
            $this->di->getLog()->logContent('Starting process for user: ' . $userId, 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Processing User: ' . $userId . '</>');

            $response = $this->setDiForUser($userId);
            if (!isset($response['success']) || !$response['success']) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Unable to set DI for user: ' . $userId . '</>');
                $this->di->getLog()->logContent('Unable to set DI for user: ' . $userId, 'info', $this->logFile);
                return;
            }

            $this->processProductContainerBatch($userId, $lastProcessedProductContainerId);

            $this->updateLastProcessedUserId($userId);
            $this->clearLastProcessedProductContainerId();
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in processUser for user ' . $userId . ': ' . $e->getMessage(), 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error processing user ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    private function processProductContainerBatch($userId, $lastProcessedProductContainerId = null)
    {
        try {
            $productContainer = $this->mongo->getCollectionForTable('product_container');
            $orphanedContainer = $this->mongo->getCollectionForTable('orphaned_amazon_products_container');

            $query = [
                'user_id' => $userId,
                'target_marketplace' => 'amazon',
            ];
            if ($lastProcessedProductContainerId) {
                $query['_id'] = ['$gt' => new ObjectId($lastProcessedProductContainerId)];
            }

            $options = [
                'typeMap' => ['root' => 'array', 'document' => 'array'],
                'limit' => self::LISTING_BATCH_SIZE,
                'projection' => [
                    'source_product_id' => 1,
                    'container_id' => 1,
                    'shop_id' => 1,
                    'source_shop_id' => 1,
                    'sku' => 1,
                ],
                'sort' => ['_id' => 1],
            ];

            $processedCount = 0;

            do {
                $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> last processed product_container id: ' . json_encode($query['_id'] ?? 'start') . '</>');
                $targetProducts = $productContainer->find($query, $options)->toArray();
                if (empty($targetProducts)) {
                    break;
                }

                $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Processing batch of ' . count($targetProducts) . ' product_container docs</>');

                $sourceProductIds = array_column($targetProducts, 'source_product_id');
                $sourceProductIds = array_filter($sourceProductIds);
                $sourceProductIds = array_values(array_unique($sourceProductIds));

                if (!empty($sourceProductIds)) {
                    $existingSourceProducts = $productContainer->find(
                        [
                            'user_id' => $userId,
                            'source_product_id' => ['$in' => $sourceProductIds],
                            'source_marketplace' => 'shopify',
                        ],
                        [
                            'typeMap' => ['root' => 'array', 'document' => 'array'],
                            'projection' => ['source_product_id' => 1],
                        ]
                    )->toArray();
                    $existingSourceProductIds = array_column($existingSourceProducts, 'source_product_id');
                    $missingSourceProductIds = array_diff($sourceProductIds, $existingSourceProductIds);

                    if (!empty($missingSourceProductIds)) {
                        $this->insertMissingProductContainerData($userId, $targetProducts, $missingSourceProductIds, $orphanedContainer);
                    } else {
                        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> No missing source products found in this chunk</>');
                    }
                }

                $lastDoc = end($targetProducts);
                if ($lastDoc) {
                    $this->updateLastProcessedProductContainerIdAndUserId($userId, (string)$lastDoc['_id']);
                }

                $processedCount += count($targetProducts);

                if (!empty($targetProducts)) {
                    $lastId = end($targetProducts)['_id'];
                    $query['_id'] = ['$gt' => new ObjectId($lastId)];
                }
            } while (count($targetProducts) == self::LISTING_BATCH_SIZE);

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Completed processing ' . $processedCount . ' product_container docs for user: ' . $userId . '</>');
            $this->di->getLog()->logContent('Completed processing ' . $processedCount . ' product_container docs for user: ' . $userId, 'info', $this->logFile);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in processProductContainerBatch for user ' . $userId . ': ' . $e->getMessage(), 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error processing product_container for user ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    private function insertMissingProductContainerData($userId, $targetProducts, $missingSourceProductIds, $orphanedContainer)
    {
        try {
            $operations = [];
            $now = new UTCDateTime();
            foreach ($targetProducts as $product) {
                $sourceProductId = $product['source_product_id'] ?? null;
                if (empty($sourceProductId) || !in_array($sourceProductId, $missingSourceProductIds)) {
                    continue;
                }
                $filter = [
                    'user_id' => $userId,
                    'shop_id' => $product['shop_id'] ?? null,
                    'container_id' => $product['container_id'] ?? null,
                    'source_product_id' => $sourceProductId,
                ];
                $updateData = [
                    '$setOnInsert' => [
                        'user_id' => $userId,
                        'container_id' => $product['container_id'] ?? null,
                        'shop_id' => $product['shop_id'] ?? null,
                        'source_shop_id' => $product['source_shop_id'] ?? null,
                        'marketplace' => 'amazon',
                        'source_product_id' => $sourceProductId,
                        'sku' => $product['sku'] ?? null,
                        'source_exists' => false,
                        'duplicate'=>true,
                        'created_at' => $now,
                    ],
                    '$set' => ['updated_at' => $now],
                ];
                $operations[] = ['updateOne' => [$filter, $updateData, ['upsert' => true]]];
            }
            if (!empty($operations)) {
                $result = $orphanedContainer->bulkWrite($operations);
                $insertedCount = $result->getUpsertedCount();
                $duplicateCount = count($operations) - $insertedCount;
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Inserted ' . $insertedCount . ' new missing source docs (skipped ' . $duplicateCount . ' duplicates)</>');
                $this->di->getLog()->logContent('Inserted missing source docs for user ' . $userId . ': ' . $insertedCount . ' new, ' . $duplicateCount . ' duplicates skipped', 'info', $this->logFile);
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in insertMissingProductContainerData for user ' . $userId . ': ' . $e->getMessage(), 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error inserting missing products for user ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    private function getResumeData()
    {
        try {
            $cronTasks = $this->mongo->getCollectionForTable('cron_tasks');
            $taskData = $cronTasks->findOne(
                ['task' => self::PROCESS_CODE],
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
            );
            if ($taskData && isset($taskData['last_processed_user_id'])) {
                return [
                    'last_processed_user_id' => $taskData['last_processed_user_id'],
                    'last_processed_product_container_id' => $taskData['last_processed_product_container_id'] ?? null,
                ];
            }
            return null;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in getResumeData: ' . $e->getMessage(), 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error getting resume data: ' . $e->getMessage() . '</>');
            return null;
        }
    }

    private function getAllUsers($lastProcessedUserId = null)
    {
        try {
            $userDetails = $this->mongo->getCollectionForTable('user_details');
            $query = [
                'shops' => [
                    '$elemMatch' => [
                        'apps.app_status' => 'active',
                        'marketplace' => 'amazon',
                    ],
                ],
            ];
            if ($lastProcessedUserId) {
                $query['_id'] = ['$gt' => new ObjectId($lastProcessedUserId)];
            }
            $users = $userDetails->find(
                $query,
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => self::USER_BATCH_SIZE,
                    'projection' => ['user_id' => 1],
                    'sort' => ['_id' => 1],
                ]
            )->toArray();
            return array_column($users, 'user_id');
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in getAllUsers: ' . $e->getMessage(), 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error getting all users: ' . $e->getMessage() . '</>');
            return [];
        }
    }

    /**
     * Get specific users from hardcoded array
     */
    private function fetchSpecificUsers($lastProcessedUserId = null)
    {
        try {
            $users = [];
            // Add specific user IDs here
            // Example: $users = ['user_id_1', 'user_id_2', 'user_id_3'];

            if (empty($users)) {
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No specific users defined in fetchSpecificUsers()</>');
                return [];
            }

            // If resuming, skip already processed users
            if ($lastProcessedUserId) {
                $lastProcessedIndex = array_search($lastProcessedUserId, $users);
                if ($lastProcessedIndex !== false) {
                    // Get only users after the last processed one
                    $users = array_slice($users, $lastProcessedIndex + 1);
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Resuming from user after: ' . $lastProcessedUserId . '. Remaining users: ' . count($users) . '</>');
                }
            } else {
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Processing ' . count($users) . ' specific users</>');
            }

            return $users;
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in fetchSpecificUsers: ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error getting specific users: ' . $e->getMessage() . '</>');
            return [];
        }
    }

    private function setDiForUser($userId)
    {
        try {
            if ($this->di->getUser()->id == $userId) {
                return ['success' => true, 'message' => 'User DI is already set'];
            }
            $getUser = User::findFirst([['_id' => $userId]]);
            if (empty($getUser)) {
                return ['success' => false, 'message' => 'User not found.'];
            }
            $getUser->id = (string) $getUser->_id;
            $this->di->setUser($getUser);
            if ($this->di->getUser()->getConfig()['username'] == 'admin') {
                return ['success' => false, 'message' => 'User not found in DB. Fetched DI of admin.'];
            }
            return ['success' => true, 'message' => 'User set in DI successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function updateLastProcessedProductContainerIdAndUserId($userId, $productContainerId)
    {
        try {
            $cronTasks = $this->mongo->getCollectionForTable('cron_tasks');
            $cronTasks->updateOne(
                ['task' => self::PROCESS_CODE],
                [
                    '$set' => [
                        'last_processed_user_id' => $userId,
                        'last_processed_product_container_id' => $productContainerId,
                        'updated_at' => new UTCDateTime(),
                    ],
                ],
                ['upsert' => true]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in updateLastProcessedProductContainerIdAndUserId: ' . $e->getMessage(), 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error updating last processed: ' . $e->getMessage() . '</>');
        }
    }

    private function updateLastProcessedUserId($userId)
    {
        try {
            $cronTasks = $this->mongo->getCollectionForTable('cron_tasks');
            $cronTasks->updateOne(
                ['task' => self::PROCESS_CODE],
                [
                    '$set' => [
                        'last_processed_user_id' => $userId,
                        'updated_at' => new UTCDateTime(),
                    ],
                ],
                ['upsert' => true]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in updateLastProcessedUserId: ' . $e->getMessage(), 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error updating last processed user: ' . $e->getMessage() . '</>');
        }
    }

    private function clearLastProcessedProductContainerId()
    {
        try {
            $cronTasks = $this->mongo->getCollectionForTable('cron_tasks');
            $cronTasks->updateOne(
                ['task' => self::PROCESS_CODE],
                [
                    '$unset' => ['last_processed_product_container_id' => 1],
                    '$set' => ['updated_at' => new UTCDateTime()],
                ]
            );
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in clearLastProcessedProductContainerId: ' . $e->getMessage(), 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error clearing last processed product container id: ' . $e->getMessage() . '</>');
        }
    }

    private function setAppCodeForAmazon()
    {
        $this->di->getAppCode()->setAppTag('amazon_sales_channel');
        $appCode = $this->di->getConfig()->app_tags['amazon_sales_channel']['app_code'];
        $appCodeArray = $appCode->toArray();
        $this->di->getAppCode()->set($appCodeArray);
    }
}