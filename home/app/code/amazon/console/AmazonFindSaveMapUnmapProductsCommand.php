<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Core\Models\User;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use App\Amazon\Models\SourceModel;
use Exception;

#[\AllowDynamicProperties]
class AmazonFindSaveMapUnmapProductsCommand extends Command
{
    protected static $defaultName = "amazon:find-save-map-unmap-products";
    protected static $defaultDescription = "Find, save, map and unmap products from Amazon listings";

    public const PROCESS_CODE = 'find_save_map_unmap_products_from_amazon_listings';
    public const PAID_CLIENT_DEFAULT_PLAN = 0;
    public const USER_BATCH_SIZE = 500;
    public const LISTING_BATCH_SIZE = 500;
    public const CONTAINER_CHUNK_SIZE = 50;
    public const SOURCE_PRODUCT_CHUNK_SIZE = 2000;

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
        $this->setHelp('Find and save missing products from Amazon listings');
        $this->addOption("method", "m", InputOption::VALUE_REQUIRED, "Method name");
        $this->addOption("all_users", "a", InputOption::VALUE_OPTIONAL, "Process for all users");
        $this->addOption("specific_users", "p", InputOption::VALUE_OPTIONAL, "Process for specific users");
        $this->addOption("source", "s", InputOption::VALUE_OPTIONAL, "Source");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Initiating Amazon Find Save Map Unmap Products Process...</>');

        $method = $input->getOption("method") ?? false;
        if (!$method) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Method name (-m) is mandatory</>');
            return 0;
        }

        if ($method) {
            $this->$method();
        }

        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Process Completed!!</>');
        return 0;
    }

    /**
     * Main function to find and save missing products from Amazon listings
     */
    public function findAndSaveMissingProductsFromAmazonListings()
    {
        $this->allUsers = $this->input->getOption("all_users") ?? false;
        $this->specificUsers = $this->input->getOption("specific_users") ?? false;

        // Validate arguments
        if (!$this->allUsers && !$this->specificUsers) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR: Either -a (all users) or -p (specific users) option is required</>');
            return 0;
        }

        $this->source = $this->input->getOption("source") ?? false;
        if (!$this->source) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR: Source(-s) is required to start finding missing products</>');
            return 0;
        }
        $this->logFile = "amazon/scripts/findAndSaveMissingProductsFromAmazonListings/" . date('d-m-Y') . '.log';

        // Check if we need to resume from last processed user
        $resumeData = $this->getResumeData();

        if ($resumeData) {
            $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Resuming from User: ' . $resumeData['last_processed_user_id'] . '</>');
            $this->processUserMissingProducts($resumeData['last_processed_user_id'], $resumeData['last_processed_amazon_listing_id']);
        }

        $lastProcessedUserId = $resumeData['last_processed_user_id'] ?? null;
        $totalProcessedUsers = 0;

        // Handle specific users differently from all users
        if ($this->specificUsers) {
            // For specific users, process all of them without pagination but handle resume
            $users = $this->getUsersForProcessing($lastProcessedUserId);
            if (empty($users)) {
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No specific users to process or all users already processed!</>');
                return;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Processing ' . count($users) . ' specific users</>');
            foreach ($users as $user) {
                $this->processUserMissingProducts($user);
                // Update last processed user ID for resume capability
                $this->updateLastProcessedUserId($user);
                $totalProcessedUsers++;
            }
            // Clear only the listing ID after all specific users are done
            // Keep last_processed_user_id for tracking purposes
            $this->clearLastProcessedListingId();
        } else {
            // Continue processing batches of 500 users until all are processed
            do {
                // Get next batch of users (500 at a time)
                $users = $this->getUsersForProcessing($lastProcessedUserId);
                if (empty($users)) {
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> No more users to process. All users completed!</>');
                    break;
                }

                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Processing batch of ' . count($users) . ' users</>');
                // Process each user in the current batch
                foreach ($users as $user) {
                    $this->processUserMissingProducts($user);
                    $lastProcessedUserId = $user; // Update for next iteration
                    $totalProcessedUsers++;
                }

                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Completed batch of ' . count($users) . ' users. Total processed: ' . $totalProcessedUsers . '</>');
                $this->di->getLog()->logContent('Completed batch of ' . count($users) . ' users. Total processed: ' . $totalProcessedUsers, 'info', $this->logFile);
            } while (count($users) == self::USER_BATCH_SIZE); // Continue if we got a full batch
        }

        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> All users processing completed! Total users processed: ' . $totalProcessedUsers . '</>');
    }

    /**
     * Get resume data from cron_tasks
     */
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
                    'last_processed_amazon_listing_id' => $taskData['last_processed_amazon_listing_id'] ?? null
                ];
            }

            return null;
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in getResumeData: ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error getting resume data: ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Get users for processing based on input parameters (500 at a time)
     */
    private function getUsersForProcessing($lastProcessedUserId = null)
    {
        if ($this->specificUsers) {
            // For specific users, pass lastProcessedUserId to handle resume
            return $this->fetchSpecificUsers($lastProcessedUserId);
        }
        
        // Default to all users
        return $this->getAllUsers($lastProcessedUserId);
    }

    /**
     * Get all users from user_details collection (500 at a time)
     */
    private function getAllUsers($lastProcessedUserId = null)
    {
        try {
            $userDetails = $this->mongo->getCollectionForTable('user_details');

            $query = [
                'shops' => [
                    '$elemMatch' => [
                        'apps.app_status' => 'active',
                        'marketplace' => 'amazon'
                    ]
                ]
            ];

            // If resuming, start from the last processed user
            if ($lastProcessedUserId) {
                $query['_id'] = ['$gt' => new ObjectId($lastProcessedUserId)];
            }

            $users = $userDetails->find(
                $query,
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array'],
                    'limit' => self::USER_BATCH_SIZE,
                    'projection' => ['user_id' => 1, 'username' => 1],
                    'sort' => ['_id' => 1]
                ]
            )->toArray();

            return array_column($users, 'user_id');
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in getAllUsers: ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error getting all users: ' . $e->getMessage() . '</>');
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

    /**
     * Process missing products for a single user
     */
    private function processUserMissingProducts($userId, $lastProcessedListingId = null)
    {
        try {
            $this->di->getLog()->logContent('Starting Process for user: ' . $userId, 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Processing User: ' . $userId . '</>');

            // Set DI for user
            $response = $this->setDiForUser($userId);
            if (!isset($response['success']) || !$response['success']) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Unable to set DI for user: ' . $userId . '</>');
                $this->di->getLog()->logContent('Unable to set DI for user: ' . $userId, 'info', $this->logFile);
                return;
            }

            // Process Amazon listings in batches
            $this->processAmazonListingsBatch($userId, $lastProcessedListingId);

            // Update last processed user ID and clear listing ID after user completion
            $this->updateLastProcessedUserId($userId);
            $this->clearLastProcessedListingId();
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in processUserMissingProducts for user ' . $userId . ': ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error processing user ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Set DI for user (similar to existing pattern)
     */
    private function setDiForUser($userId)
    {
        try {
            if ($this->di->getUser()->id == $userId) {
                return [
                    'success' => true,
                    'message' => 'User DI is already set'
                ];
            }

            $getUser = User::findFirst([['_id' => $userId]]);

            if (empty($getUser)) {
                return [
                    'success' => false,
                    'message' => 'User not found.'
                ];
            }

            $getUser->id = (string) $getUser->_id;
            $this->di->setUser($getUser);

            if ($this->di->getUser()->getConfig()['username'] == 'admin') {
                return [
                    'success' => false,
                    'message' => 'User not found in DB. Fetched DI of admin.'
                ];
            }

            return [
                'success' => true,
                'message' => 'User set in DI successfully'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Process Amazon listings in batches of 500
     */
    private function processAmazonListingsBatch($userId, $lastProcessedListingId = null)
    {
        try {
            $amazonListings = $this->mongo->getCollectionForTable('amazon_listing');
            $productContainer = $this->mongo->getCollectionForTable('product_container');

            $query = ['user_id' => $userId, 'matched' => true, 'matchedProduct' => ['$exists' => true]];
            // Add ObjectId filter if resuming from last processed listing
            if ($lastProcessedListingId) {
                $query['_id'] = ['$gte' => new ObjectId($lastProcessedListingId)];
            }

            $options = [
                'typeMap' => ['root' => 'array', 'document' => 'array'],
                'limit' => self::LISTING_BATCH_SIZE,
                'projection' => [
                    'user_id' => 1,
                    'shop_id' => 1,
                    'source_container_id' => 1,
                    'source_product_id' => 1,
                    'seller-sku' => 1
                ],
                'sort' => ['_id' => 1]
            ];

            $processedCount = 0;

            do {
                $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> last processed listing id: ' . json_encode($query['_id']) . '</>');
                $listings = $amazonListings->find($query, $options)->toArray();
                if (empty($listings)) {
                    break;
                }

                $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Processing batch of ' . count($listings) . ' listings</>');
                // Extract source_product_ids
                $sourceProductIds = array_column($listings, 'source_product_id');
                $sourceProductIds = array_filter($sourceProductIds); // Remove null/empty values

                if (!empty($sourceProductIds)) {
                    // Find existing products in product_container
                    $existingProducts = $productContainer->find(
                        [
                            'user_id' => $userId,
                            'source_product_id' => ['$in' => $sourceProductIds],
                            'source_marketplace' => $this->source
                        ],
                        [
                            'typeMap' => ['root' => 'array', 'document' => 'array'],
                            'projection' => ['source_product_id' => 1]
                        ]
                    )->toArray();
                    $existingSourceProductIds = [];
                    $existingSourceProductIds = array_column($existingProducts, 'source_product_id');
                    // Find missing products
                    $missingSourceProductIds = array_diff($sourceProductIds, $existingSourceProductIds);
                    if (!empty($missingSourceProductIds)) {
                        $this->insertSourceProductData($userId, $listings, $missingSourceProductIds, false);
                    } else {
                        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> No missing source products found for in this chunk </>');
                    }
                    if (!empty($existingSourceProductIds)) {
                        $targetListingsIds = array_column($listings, '_id');
                        $targetListingsIds = array_map('strval', $targetListingsIds);
                        $targetProductData = $this->checkTargetProductData($userId, $listings, $existingSourceProductIds, $targetListingsIds);
                        if (!empty($targetProductData)) {
                            $targetExistingsIds = array_column($targetProductData, 'source_product_id');
                            $targetMissingSourceProductIds = array_diff($existingSourceProductIds, $targetExistingsIds);
                            if (!empty($targetMissingSourceProductIds)) {
                                $this->insertTargetProductData($userId, $listings, $targetMissingSourceProductIds, false);
                            }
                        } else {
                            $this->insertTargetProductData($userId, $listings, $existingSourceProductIds, false);
                        }
                    }
                }

                // Update last processed listing ID after each batch
                $lastListing = end($listings);
                if ($lastListing) {
                    $this->updateLastProcessedListingIdAndUserId($userId, $lastListing['_id']);
                }

                $processedCount += count($listings);

                // Update query for next batch
                if (!empty($listings)) {
                    $lastId = end($listings)['_id'];
                    $query['_id'] = ['$gt' => new ObjectId($lastId)];
                }
            } while (count($listings) == self::LISTING_BATCH_SIZE);

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Completed processing ' . $processedCount . ' listings for user: ' . $userId . '</>');
            $this->di->getLog()->logContent('Completed processing ' . $processedCount . ' listings for user: ' . $userId, 'info', $this->logFile);
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in processAmazonListingsBatch for user ' . $userId . ': ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error processing Amazon listings for user ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Insert missing products into orphaned_amazon_products_container_new
     * Optimized using bulkWrite for better performance
     */
    private function insertSourceProductData($userId, $listings, $missingSourceProductIds, $exists)
    {
        try {
            $orphanedAmazonProductsContainer = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');

            $operations = [];
            $now = new UTCDateTime();

            foreach ($listings as $listing) {
                if (in_array($listing['source_product_id'], $missingSourceProductIds)) {
                    $filter = [
                        'user_id' => $userId,
                        'shop_id' => $listing['shop_id'] ?? null,
                        'container_id' => $listing['source_container_id'] ?? null,
                        'source_product_id' => $listing['source_product_id']
                    ];

                    $updateData = [
                        '$setOnInsert' => [
                            'user_id' => $userId,
                            'container_id' => $listing['source_container_id'] ?? null,
                            'amazon_listing_id' => (string)$listing['_id'] ?? null,
                            'shop_id' => $listing['shop_id'] ?? null,
                            'marketplace' => 'amazon',
                            'source_product_id' => $listing['source_product_id'],
                            'seller-sku' => $listing['seller-sku'] ?? null,
                            'source_exists' => $exists,
                            'created_at' => $now
                        ],
                        '$set' => [
                            'updated_at' => $now
                        ]
                    ];

                    $operations[] = [
                        'updateOne' => [
                            $filter,
                            $updateData,
                            ['upsert' => true]
                        ]
                    ];
                }
            }

            if (empty($operations)) {
                return;
            }

            $result = $orphanedAmazonProductsContainer->bulkWrite($operations);
            $insertedCount = $result->getUpsertedCount();
            $duplicateCount = count($operations) - $insertedCount;

            if ($insertedCount > 0 || $duplicateCount > 0) {
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Inserted ' . $insertedCount . ' new missing source doc (skipped ' . $duplicateCount . ' duplicates)</>');
                $this->di->getLog()->logContent('Inserted missing source docs for user ' . $userId . ': ' . $insertedCount . ' new, ' . $duplicateCount . ' duplicates skipped', 'info', $this->logFile);
            }
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in insertMissingProducts for user ' . $userId . ': ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error inserting missing products for user ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    private function insertTargetProductData($userId, $listings, $targetExistingsIds, $exists)
    {
        try {
            $orphanedAmazonProductsContainer = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');

            $insertedCount = 0;
            $duplicateCount = 0;

            foreach ($listings as $listing) {
                if (in_array($listing['source_product_id'], $targetExistingsIds)) {
                    $filter = [
                        'user_id' => $userId,
                        'shop_id' => $listing['shop_id'] ?? null,
                        'container_id' => $listing['source_container_id'] ?? null,
                        'source_product_id' => $listing['source_product_id']
                    ];

                    $updateData = [
                        '$setOnInsert' => [
                            'user_id' => $userId,
                            'container_id' => $listing['source_container_id'] ?? null,
                            'amazon_listing_id' => (string)$listing['_id'] ?? null,
                            'shop_id' => $listing['shop_id'] ?? null,
                            'marketplace' => 'amazon',
                            'source_product_id' => $listing['source_product_id'],
                            'seller-sku' => $listing['seller-sku'] ?? null,
                            'source_exists' => true,
                            'target_exists' => $exists,
                            'created_at' => new UTCDateTime()
                        ],
                        '$set' => [
                            'updated_at' => new UTCDateTime()
                        ]
                    ];

                    $result = $orphanedAmazonProductsContainer->updateOne($filter, $updateData, ['upsert' => true]);

                    if ($result->getUpsertedCount() > 0) {
                        $insertedCount++;
                    } else {
                        $duplicateCount++;
                    }
                }
            }

            if ($insertedCount > 0 || $duplicateCount > 0) {
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Inserted ' . $insertedCount . ' new missing target doc (skipped ' . $duplicateCount . ' duplicates)</>');
                $this->di->getLog()->logContent('Inserted missing target docs for user ' . $userId . ': ' . $insertedCount . ' new, ' . $duplicateCount . ' duplicates skipped', 'info', $this->logFile);
            }
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in insertMissingProducts for user ' . $userId . ': ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error inserting missing products for user ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    private function checkTargetProductData($userId, $listings, $existingSourceProductIds, $targetListingsIds)
    {
        try {
            $targetProductData = $this->mongo->getCollectionForTable('product_container');
            return $targetProductData->find(
                [
                    'user_id' => $userId,
                    'source_product_id' => ['$in' => $existingSourceProductIds],
                    'target_listing_id' => ['$in' => $targetListingsIds],
                    'target_marketplace' => 'amazon',
                ],
                [
                    'typeMap' => ['root' => 'array', 'document' => 'array']
                ]
            )->toArray();
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in checkTargetProductData for user ' . $userId . ': ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error checking target product data for user ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Update last processed Amazon listing ID
     */
    private function updateLastProcessedListingIdAndUserId($userId, $listingId)
    {
        try {
            $cronTasks = $this->mongo->getCollectionForTable('cron_tasks');
            $cronTasks->updateOne(
                ['task' => self::PROCESS_CODE],
                [
                    '$set' => [
                        'last_processed_user_id' => $userId,
                        'last_processed_amazon_listing_id' => $listingId,
                        'updated_at' => new UTCDateTime()
                    ]
                ],
                ['upsert' => true]
            );
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in updateLastProcessedListingIdAndUserId: ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error updating last processed listing: ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Update last processed user ID
     */
    private function updateLastProcessedUserId($userId)
    {
        try {
            $cronTasks = $this->mongo->getCollectionForTable('cron_tasks');
            $cronTasks->updateOne(
                ['task' => self::PROCESS_CODE],
                [
                    '$set' => [
                        'last_processed_user_id' => $userId,
                        'updated_at' => new UTCDateTime()
                    ]
                ],
                ['upsert' => true]
            );
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in updateLastProcessedUserId: ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error updating last processed user: ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Clear last processed Amazon listing ID after user completion
     */
    private function clearLastProcessedListingId()
    {
        try {
            $cronTasks = $this->mongo->getCollectionForTable('cron_tasks');
            $cronTasks->updateOne(
                ['task' => self::PROCESS_CODE],
                [
                    '$unset' => ['last_processed_amazon_listing_id' => 1],
                    '$set' => ['updated_at' => new UTCDateTime()]
                ]
            );
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in clearLastProcessedListingId: ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error clearing last processed listing: ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Clear both last processed user ID and listing ID
     * Note: Currently not in use. Kept for potential future use when switching between 
     * processing modes (all users vs specific users) or manual reset is needed.
     */
    private function clearLastProcessedUserAndListingId()
    {
        try {
            $cronTasks = $this->mongo->getCollectionForTable('cron_tasks');
            $cronTasks->updateOne(
                ['task' => self::PROCESS_CODE],
                [
                    '$unset' => [
                        'last_processed_user_id' => 1,
                        'last_processed_amazon_listing_id' => 1
                    ],
                    '$set' => ['updated_at' => new UTCDateTime()]
                ]
            );
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Cleared resume data after completing all specific users</>');
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in clearLastProcessedUserAndListingId: ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error clearing last processed data: ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Process orphaned Amazon products by grouping by user_id and processing container_ids in chunks
     */
    public function importOrphanedAmazonProductsByContainerIds()
    {
        $this->source = $this->input->getOption("source") ?? false;
        if (!$this->source) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR: Source is required to import products(-s)</>');
            return 0;
        }
        $this->logFile = "amazon/scripts/importOrphanedAmazonProductsByContainerIds/" . date('d-m-Y') . '.log';
        $this->di->getLog()->logContent('Starting Orphaned Amazon Products Processing...', 'info', $this->logFile);
        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Starting Orphaned Amazon Products Processing...</>');

        $orphanedCollection = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');

        // Group by user_id and get unique container_ids (excluding picked ones)
        $pipeline = [
            [
                '$match' => [
                    'is_imported' => ['$exists' => false],
                    'source_exists' => false
                ]
            ],
            [
                '$group' => [
                    '_id' => '$user_id',
                    'container_ids' => [
                        '$addToSet' => '$container_id'
                    ],
                    'count' => ['$sum' => 1]
                ]
            ],
            [
                '$sort' => ['_id' => 1]
            ]
        ];

        $userGroups = $orphanedCollection->aggregate($pipeline, [
            'typeMap' => ['root' => 'array', 'document' => 'array']
        ])->toArray();
        if (empty($userGroups)) {
            $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No orphaned products found to process</>');
            return;
        }

        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Found ' . count($userGroups) . ' users with orphaned products</>');

        foreach ($userGroups as $userGroup) {
            $userId = $userGroup['_id'];
            $containerIds = $userGroup['container_ids'];
            $totalContainers = count($containerIds);

            $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Processing User: ' . $userId . ' with ' . $totalContainers . ' containers</>');
            $this->di->getLog()->logContent('Processing User: ' . $userId . ' with ' . $totalContainers . ' containers', 'info', $this->logFile);
            // Set DI for user
            $response = $this->setDiForUser($userId);
            if (!isset($response['success']) || !$response['success']) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Unable to set DI for user: ' . $userId . '</>');
                $this->di->getLog()->logContent('Unable to set DI for user: ' . $userId, 'info', $this->logFile);
                continue;
            }

            // Process container_ids in chunks of 100
            $this->processContainerChunks($userId, $containerIds);
        }

        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Orphaned Amazon Products Processing Completed!</>');
    }

    /**
     * Process container IDs in chunks of 100
     */
    private function processContainerChunks($userId, $containerIds)
    {
        $chunkSize = self::CONTAINER_CHUNK_SIZE;
        $totalChunks = ceil(count($containerIds) / $chunkSize);

        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Processing ' . count($containerIds) . ' containers in ' . $totalChunks . ' chunks</>');

        for ($i = 0; $i < count($containerIds); $i += $chunkSize) {
            $chunkNumber = ($i / $chunkSize) + 1;
            $chunkContainerIds = array_slice($containerIds, $i, $chunkSize);

            $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Processing Chunk ' . $chunkNumber . '/' . $totalChunks . ' (' . count($chunkContainerIds) . ' containers)</>');
            // Process the chunk
            $this->initiateImportProcessing($userId, $chunkContainerIds, $chunkNumber);
        }
    }

    /**
     * Process a single chunk of containers
     */
    private function initiateImportProcessing($userId, $containerIds)
    {
        try {
            $path = '\Components\Product\Import';
            $method = 'importOrUpdateProductsByContainerIds';
            $commonHelper = $this->di->getObjectManager()->get('\App\Amazon\Components\Common\Helper');
            $class = $commonHelper->checkClassAndMethodExists($this->source, $path, $method);
            if (empty($class)) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> ERROR: Class or method not found</>');
                return 0;
            }
            $importHelper = $this->di->getObjectManager()->get($class);

            $importResponse = $importHelper->$method(['container_ids' => $containerIds, 'user_id' => $userId]);
            if (isset($importResponse['success']) && $importResponse['success']) {
                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Imported successfully');
                $this->markContainersAsImported($userId, $containerIds, true);
            } else {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error from importOrUpdateProductsByContainerIds for user: ' . $userId . '</>');
                $this->di->getLog()->logContent('Error from importOrUpdateProductsByContainerIds for user: ' . $userId . ': ' . json_encode(['importResponse' => $importResponse]), 'info', $this->logFile);
                $this->markContainersAsImported($userId, $containerIds, false, $importResponse);
            }
        } catch (Exception $e) {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error in initiateImportProcessing: ' . $e->getMessage() . '</>');
            $this->di->getLog()->logContent('Error in initiateImportProcessing: ' . $e->getMessage(), 'info', $this->logFile);
        }
    }

    /**
     * Mark containers as imported
     */
    private function markContainersAsImported($userId, $containerIds, $isImported, $errorMessage = '')
    {
        try {
            $orphanedCollection = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');
            $updateFilter = [
                'is_imported' => $isImported,
                'updated_at' => new UTCDateTime()
            ];
            if (!empty($errorMessage)) {
                $updateFilter['error_message'] = json_encode($errorMessage);
            }
            $orphanedCollection->updateMany(['user_id' => $userId, 'container_id' => ['$in' => $containerIds]], ['$set' => $updateFilter]);
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in markContainersAsImported for user ' . $userId . ': ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error marking containers as imported: ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Process imported orphaned Amazon products for unmapping and mapping
     */
    public function processImportedAndMissingTargetOrphanedProducts()
    {
        try {
            $this->logFile = "amazon/scripts/processImportedAndMissingTargetOrphanedProducts/" . date('d-m-Y') . '.log';
            $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Starting Imported and Missing Target Orphaned Products Processing...</>');

            $orphanedCollection = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');

            // Group by user_id and create unique product_data array with source_product_id and seller-sku pairs
            $pipeline = [
                [
                    '$match' => [
                        '$or' => [
                            ['is_imported' => ['$exists' => true]],
                            ['source_exists' => true, 'target_exists' => false]
                        ],
                        'completed' => ['$exists' => false]
                    ]
                ],
                [
                    '$group' => [
                        '_id' => '$user_id',
                        'product_data' => [
                            '$addToSet' => [
                                'source_product_id' => '$source_product_id',
                                'seller-sku' => '$seller-sku',
                                'shop_id' => '$shop_id'
                            ]
                        ],
                        'count' => ['$sum' => 1]
                    ]
                ],
                [
                    '$sort' => ['_id' => 1]
                ]
            ];

            $userGroups = $orphanedCollection->aggregate($pipeline, [
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ])->toArray();
            if (empty($userGroups)) {
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No imported and missing target orphaned products found to process</>');
                return;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Found ' . count($userGroups) . ' users with imported orphaned products</>');
            foreach ($userGroups as $userGroup) {
                $userId = $userGroup['_id'];
                $productData = $userGroup['product_data'] ?? [];

                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Processing User: ' . $userId . ' with ' . count($productData) . ' unique products</>');

                // Set DI for user
                $response = $this->setDiForUser($userId);
                if (!isset($response['success']) || !$response['success']) {
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Unable to set DI for user: ' . $userId . '</>');
                    $this->di->getLog()->logContent('Unable to set DI for user: ' . $userId, 'info', $this->logFile);
                    continue;
                }
                // Process unmap and map operations for this user
                if (!empty($productData)) {
                    $this->mapImportedProductsAndUnmapNotImportedProducts($userId, $productData);
                } else {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Empty product data so processUserUnmapAndMap not called for user: ' . $userId . '</>');
                }
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in processImportedAndMissingTargetOrphanedProducts: ' . $e->getMessage(), 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error processing imported and missing target orphaned products: ' . $e->getMessage() . '</>');
        }
    }

    private function mapImportedProductsAndUnmapNotImportedProducts($userId, $orphanedProductData)
    {
        $this->logFile = "amazon/scripts/mapImportedProductsAndUnmapNotImportedProducts/" . date('d-m-Y') . '.log';
        $this->di->getLog()->logContent('Starting Process for user: ' . $userId, 'info', $this->logFile);
        $sourceMarketplace = $this->di->getUser()->shops[0]['marketplace'] ?? false;
        $sourceShopId = $this->di->getUser()->shops[0]['_id'] ?? false;
        $targetMarketplace = 'amazon';
        $mappedProducts = [];
        $productNotFoundSourceProductIds = [];
        $orphanedSourceProductIds = array_column($orphanedProductData, 'source_product_id');
        if ($sourceShopId && $sourceMarketplace) {
            $amazonListingCollection = $this->mongo->getCollectionForTable('amazon_listing');
            $query = ['user_id' => $userId, 'shop_id' => $sourceShopId, 'source_product_id' => ['$in' => $orphanedSourceProductIds]];
            $option = [
                'projection' => ['_id' => 0],
                'typeMap'   => ['root' => 'array', 'document' => 'array']
            ];
            $importedProductData = $this->mongo->getCollectionForTable('product_container')->find($query, $option)->toArray();
            if (!empty($importedProductData)) {
                foreach ($importedProductData as $product) {
                    $importedSourceProductDataMap[$product['source_product_id']] = $product;
                    $importedSourceProductIds[] = $product['source_product_id'];
                }
                $orphanedProductSkuToIdMap = [];
                // Create a map of orphaned product sku to source product id
                foreach ($orphanedProductData as $product) {
                    if (in_array($product['source_product_id'], $importedSourceProductIds)) {
                        // Products which are imported
                        $orphanedProductSkuToIdMap[$product['seller-sku']] = $product['source_product_id'];
                        $orphanedProductShopIdMap[$product['seller-sku']] = $product['shop_id'];
                    } else {
                        // Products which are not imported
                        $productNotFoundSourceProductIds[] = $product['source_product_id'];
                    }
                }

                if (!empty($orphanedProductSkuToIdMap)) {
                    $amazonSkus = array_map('strval', array_keys($orphanedProductSkuToIdMap));
                    $option = [
                        'typeMap'   => ['root' => 'array', 'document' => 'array']
                    ];
                    $amazonListingData = $amazonListingCollection->find(['user_id' => $userId, 'seller-sku' => ['$in' => $amazonSkus]], $option)->toArray();
                    if (empty($amazonListingData)) {
                        $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> No Amazon listing data found for user: ' . $userId . '</>');
                        $this->di->getLog()->logContent('No Amazon listing data found for user: ' . $userId, 'info', $this->logFile);
                        return;
                    }
                    // Amazon listing data map
                    foreach ($amazonListingData as $amazonData) {
                        $amazonListingDataMap[$amazonData['seller-sku']] = $amazonData;
                    }
                    foreach ($orphanedProductSkuToIdMap as $amazonSkuKey => $sourceProductId) {
                        $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Processing product: ' . $sourceProductId . ' with Amazon SKU: ' . $amazonSkuKey . '</>');
                        $this->di->getLog()->logContent('Processing product: ' . $sourceProductId . ' with Amazon SKU: ' . $amazonSkuKey, 'info', $this->logFile);
                        $targetShopId = $orphanedProductShopIdMap[$amazonSkuKey];
                        if (isset($amazonListingDataMap[$amazonSkuKey]['fulfillment-channel']) && ($amazonListingDataMap[$amazonSkuKey]['fulfillment-channel'] != 'DEFAULT' && $amazonListingDataMap[$amazonSkuKey]['fulfillment-channel'] != 'default')) {
                            $channel = 'FBA';
                        } else {
                            $channel = 'FBM';
                        }

                        $asin = '';
                        if (isset($amazonListingDataMap[$amazonSkuKey]['asin1']) && $amazonListingDataMap[$amazonSkuKey]['asin1'] != '') {
                            $asin = $amazonListingDataMap[$amazonSkuKey]['asin1'];
                        } elseif (isset($amazonListingDataMap[$amazonSkuKey]['asin2']) && $amazonListingDataMap[$amazonSkuKey]['asin2'] != '') {
                            $asin = $amazonListingDataMap[$amazonSkuKey]['asin2'];
                        } elseif (isset($amazonListingDataMap[$amazonSkuKey]['asin3']) && $amazonListingDataMap[$amazonSkuKey]['asin3'] != '') {
                            $asin = $amazonListingDataMap[$amazonSkuKey]['asin3'];
                        }

                        $status = $amazonListingDataMap[$amazonSkuKey]['status'];
                        if (isset($amazonListingDataMap[$amazonSkuKey]['type']) && $amazonListingDataMap[$amazonSkuKey]['type'] == 'variation') {
                            $status = 'parent_' . $amazonListingDataMap[$amazonSkuKey]['status'];
                        }

                        // update asin on product container
                        $asinData = [
                            [
                                'user_id' => $userId,
                                'target_marketplace' => 'amazon',
                                'shop_id' => (string)$targetShopId,
                                'container_id' => $importedSourceProductDataMap[$sourceProductId]['container_id'],
                                'source_product_id' => $sourceProductId,
                                'asin' => $asin,
                                'sku' => $amazonSkuKey,
                                "shopify_sku" => $importedSourceProductDataMap[$sourceProductId]['sku'],
                                'matchedwith' => 'manual',
                                'status' => $status,
                                'target_listing_id' => (string)$amazonListingDataMap[$amazonSkuKey]['_id'],
                                'source_shop_id' => $sourceShopId,
                                'fulfillment_type' => $channel
                            ]
                        ];

                        $validatorParams = [
                            'target' => [
                                'marketplace' => $targetMarketplace,
                                'shopId' => $targetShopId,
                            ],
                            'source' => [
                                'marketplace' => $sourceMarketplace,
                                'shopId' => $sourceShopId,
                            ],
                            'activePage' => 1,
                            'useRefinProduct' => 1,
                        ];

                        $validator = $this->di->getObjectManager()->get(SourceModel::class);
                        $valid = $validator->validateSaveProduct($asinData, $userId, $validatorParams);
                        if (isset($valid['success']) && !$valid['success']) {
                            $this->di->getLog()->logContent('Error in validateSaveProduct for user: ' . $userId . ': ' . json_encode($validatorParams), 'info', $this->logFile);
                            $this->setErrorInOrphanedProduct($userId, $sourceProductId, 'validateSaveProduct', $valid);
                            continue;
                        }

                        $editHelper = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit');
                        $saveProductResponse = $editHelper->saveProduct($asinData, $userId, $validatorParams);

                        if (isset($saveProductResponse['success']) && !$saveProductResponse['success']) {
                            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error  occurered in saveProduct</>');
                            $this->di->getLog()->logContent('Error  occurered in saveProduct: ' . json_encode($saveProductResponse), 'info', $this->logFile);
                            $this->setErrorInOrphanedProduct($userId, $sourceProductId, 'saveProduct', $saveProductResponse);
                            continue;
                        }

                        $matchedData = [
                            'source_product_id' => $sourceProductId,
                            'source_container_id' => $importedSourceProductDataMap[$sourceProductId]['container_id'],
                            'matched' => true,
                            'manual_mapped' => true,
                            'matchedwith' => 'manual',
                            'matchedProduct' => [
                                'source_product_id' => $sourceProductId,
                                'container_id' => $importedSourceProductDataMap[$sourceProductId]['container_id'],
                                'main_image' => $importedSourceProductDataMap[$sourceProductId]['main_image'],
                                'title' => $importedSourceProductDataMap[$sourceProductId]['title'],
                                'barcode' => $importedSourceProductDataMap[$sourceProductId]['barcode'],
                                'sku' => $importedSourceProductDataMap[$sourceProductId]['sku'],
                                'shop_id' => $importedSourceProductDataMap[$sourceProductId]['shop_id']
                            ]
                        ];
                        $amazonListingCollection->updateOne(['_id' => $amazonListingDataMap[$amazonSkuKey]['_id']], ['$unset' => ['closeMatchedProduct' => 1, 'unmap_record' => 1, 'manual_unlinked' => 1, 'manual_unlink_time' => 1], '$set' => $matchedData]);
                        $this->markMappedAndCompleted($userId, $sourceProductId);
                        $mappedProducts[] = $sourceProductId;
                    }
                }
            } else {
                $productNotFoundSourceProductIds = array_column($orphanedProductData, 'source_product_id');
            }
            $this->di->getLog()->logContent('Total mapped products: ' . count($mappedProducts) . ' for user: ' . $userId, 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Total mapped products: ' . count($mappedProducts) . '</>');
            if (count($productNotFoundSourceProductIds) > 0) {
                //Directly unsetting values from amazon listing collection as product not found in product_container collection
                $unmappedProductCount = count($productNotFoundSourceProductIds);
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Total unmapped products: ' . $unmappedProductCount . '</>');
                $this->di->getLog()->logContent('Total unmapped products: ' . $unmappedProductCount . ' for user: ' . $userId . 'source_product_ids: ' . json_encode($productNotFoundSourceProductIds), 'info', $this->logFile);
                $unsetValues = [
                    'source_product_id' => 1,
                    'source_variant_id' => 1,
                    'source_container_id' => 1,
                    'matched' => 1,
                    'manual_mapped' => 1,
                    'matchedProduct' => 1,
                    'matchedwith' => 1,
                    'closeMatchedProduct' => 1,
                    'matched_updated_at' => 1,
                    'matched_at' => 1
                ];
                $amazonListingCollection->updateMany(['user_id' => $userId, 'source_product_id' => ['$in' => $productNotFoundSourceProductIds]], ['$unset' => $unsetValues]);
                $this->markUnmappedAndCompleted($userId, $productNotFoundSourceProductIds);
            } else {
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Total unmapped products: 0</>');
                $this->di->getLog()->logContent('No unmapped products found for user: ' . $userId, 'info', $this->logFile);
            }
        } else {
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Source shop id or marketplace not found for user: ' . $userId . '</>');
            $this->di->getLog()->logContent('Source shop id or marketplace not found for user: ' . $userId, 'info', $this->logFile);
        }
        $this->markCompleted($userId, $orphanedSourceProductIds);
        $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> mapImportedProductsAndUnmapNotImportedProducts completed for user: ' . $userId . '</>');
        $this->di->getLog()->logContent('mapImportedProductsAndUnmapNotImportedProducts completed for user: ' . $userId, 'info', $this->logFile);
    }

    private function markUnmappedAndCompleted($userId, $productNotFoundSourceProductIds)
    {
        try {
            $orphanedCollection = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');
            $orphanedCollection->updateMany(['user_id' => $userId, 'source_product_id' => ['$in' => $productNotFoundSourceProductIds]], ['$set' => ['product_not_found' => true, 'unmapped' => true, 'is_imported' => false, 'completed' => true, 'updated_at' => new UTCDateTime()]]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in markUnmappedAndCompleted for user ' . $userId . ': ' . $e->getMessage(), 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error marking unmapped products for user: ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    private function setErrorInOrphanedProduct($userId, $sourceProductId, $errorType, $errorData)
    {
        try {
            $orphanedCollection = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');
            $orphanedCollection->updateOne(['user_id' => $userId, 'source_product_id' => $sourceProductId], ['$set' => ['error' => $errorType, 'error_data' => json_encode($errorData), 'updated_at' => new UTCDateTime()]]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in setErrorInOrphanedProduct for user ' . $userId . ': ' . $e->getMessage(), 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error setting error in orphaned product for user: ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    private function markMappedAndCompleted($userId, $productId)
    {
        try {
            $orphanedCollection = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');
            $orphanedCollection->updateMany(['user_id' => $userId, 'source_product_id' => $productId], ['$set' => ['mapped' => true, 'completed' => true, 'updated_at' => new UTCDateTime()]]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in markMappedAndCompleted for user ' . $userId . ': ' . $e->getMessage(), 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error marking mapped products for user: ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    private function markCompleted($userId, $completedSourceProductIds)
    {
        try {
            $orphanedCollection = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');
            $orphanedCollection->updateMany(['user_id' => $userId, 'source_product_id' => ['$in' => $completedSourceProductIds]], ['$set' => ['completed' => true, 'updated_at' => new UTCDateTime()]]);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in markCompleted for user ' . $userId . ': ' . $e->getMessage(), 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error marking completed for user: ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Delete product container entries for completed orphaned products
     * Groups by user_id and deletes in chunks of 2000 source_product_ids
     */
    public function deleteEditedDocsOfOrphanedProducts()
    {
        try {
            $this->logFile = "amazon/scripts/deleteEditedDocsOfOrphanedProducts/" . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('Starting Delete Edited Docs of Orphaned Products Processing...', 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Starting Delete Edited Docs of Orphaned Products Processing...</>');

            $orphanedCollection = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');

            // Group by user_id and get unique source_product_ids for completed products
            $pipeline = [
                [
                    '$match' => [
                        'is_imported' => false
                    ]
                ],
                [
                    '$group' => [
                        '_id' => '$user_id',
                        'source_product_ids' => [
                            '$addToSet' => '$source_product_id'
                        ],
                        'count' => ['$sum' => 1]
                    ]
                ],
                [
                    '$sort' => ['_id' => 1]
                ]
            ];

            $userGroups = $orphanedCollection->aggregate($pipeline, [
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ])->toArray();

            if (empty($userGroups)) {
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No completed orphaned products found to process</>');
                return;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Found ' . count($userGroups) . ' users with completed orphaned products</>');

            foreach ($userGroups as $userGroup) {
                $userId = $userGroup['_id'];
                $sourceProductIds = $userGroup['source_product_ids'];
                $totalProducts = count($sourceProductIds);

                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Processing User: ' . $userId . ' with ' . $totalProducts . ' source product IDs</>');
                $this->di->getLog()->logContent('Processing User: ' . $userId . ' with ' . $totalProducts . ' source product IDs', 'info', $this->logFile);

                // Process source_product_ids in chunks and delete from productContainer
                $this->deleteSourceProductIdsChunkWise($userId, $sourceProductIds);
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Delete Product Container for Completed Orphaned Products Processing Completed!</>');
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in deleteEditedDocsOfOrphanedProducts: ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error in deleteEditedDocsOfOrphanedProducts: ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Delete product container entries in chunks of 2000 source_product_ids
     */
    private function deleteSourceProductIdsChunkWise($userId, $sourceProductIds)
    {
        try {
            $chunkSize = self::SOURCE_PRODUCT_CHUNK_SIZE;
            $totalChunks = ceil(count($sourceProductIds) / $chunkSize);

            $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Processing ' . count($sourceProductIds) . ' source product IDs in ' . $totalChunks . ' chunks</>');
            $this->di->getLog()->logContent('Processing ' . count($sourceProductIds) . ' source product IDs in ' . $totalChunks . ' chunks for user: ' . $userId, 'info', $this->logFile);

            $chunks = array_chunk($sourceProductIds, $chunkSize);
            $totalDeleted = 0;

            foreach ($chunks as $chunkNumber => $chunkSourceProductIds) {
                $chunkNum = $chunkNumber + 1;
                $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Processing Chunk ' . $chunkNum . '/' . $totalChunks . ' (' . count($chunkSourceProductIds) . ' source product IDs)</>');
                $this->di->getLog()->logContent('Deleting edited docs of source_productIds: ' . json_encode($chunkSourceProductIds) . ' for user: ' . $userId, 'info', $this->logFile);
                $deletedCount = $this->deleteEditedDocsIfSourceNotExists($userId, $chunkSourceProductIds);
                $totalDeleted += $deletedCount;

                $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Deleted ' . $deletedCount . ' product container entries in chunk ' . $chunkNum . '</>');
                $this->di->getLog()->logContent('Deleted ' . $deletedCount . ' product container entries in chunk ' . $chunkNum . ' for user: ' . $userId, 'info', $this->logFile);

                // Mark edited_deleted as true in orphaned_amazon_products_container_new after chunk deletion
                $this->markEditedDeletedForChunk($userId, $chunkSourceProductIds);
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Total deleted product container entries for user ' . $userId . ': ' . $totalDeleted . '</>');
            $this->di->getLog()->logContent('Total deleted product container entries for user ' . $userId . ': ' . $totalDeleted, 'info', $this->logFile);
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in deleteSourceProductIdsChunkWise for user ' . $userId . ': ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error in deleteSourceProductIdsChunkWise for user: ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Delete product container entries for a specific user and source_product_ids
     */
    private function deleteEditedDocsIfSourceNotExists($userId, $sourceProductIds)
    {
        try {
            $productContainer = $this->mongo->getCollectionForTable('product_container');

            $query = [
                'user_id' => $userId,
                'target_marketplace' => 'amazon',
                'source_product_id' => ['$in' => $sourceProductIds]
            ];

            $result = $productContainer->deleteMany($query);
            $deletedCount = $result->getDeletedCount();

            return $deletedCount;
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in deleteEditedDocsIfSourceNotExists for user ' . $userId . ': ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error deleting product container entries for user: ' . $userId . ': ' . $e->getMessage() . '</>');
            return 0;
        }
    }

    /**
     * Mark edited_deleted as true in orphaned_amazon_products_container_new for a chunk of source_product_ids
     */
    private function markEditedDeletedForChunk($userId, $sourceProductIds)
    {
        try {
            $orphanedCollection = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');
            
            $result = $orphanedCollection->updateMany(
                [
                    'user_id' => $userId,
                    'source_product_id' => ['$in' => $sourceProductIds]
                ],
                [
                    '$set' => [
                        'edited_deleted' => true,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $updatedCount = $result->getModifiedCount();
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Marked edited_deleted=true for ' . $updatedCount . ' orphaned product records</>');
            $this->di->getLog()->logContent('Marked edited_deleted=true for ' . $updatedCount . ' orphaned product records for user: ' . $userId . ' with source_product_ids: ' . json_encode($sourceProductIds), 'info', $this->logFile);
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in markEditedDeletedForChunk for user ' . $userId . ': ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error marking edited_deleted for user: ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Sync inventory for mapped orphaned products
     * Fetches data from orphaned_amazon_products_container_new where mapped:true
     * Groups by shop_id with complete productData (max 500 docs per shop) and processes once per shop
     */
    public function syncInventoryForMappedOrphanedProducts()
    {
        try {
            $this->logFile = "amazon/scripts/syncInventoryForMappedOrphanedProducts/" . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('Starting Inventory Sync for Mapped Orphaned Products Processing...', 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Starting Inventory Sync for Mapped Orphaned Products Processing...</>');

            $orphanedCollection = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');

            // Group by shop_id and get complete productData (max 500 docs per shop) where mapped:true
            $pipeline = [
                [
                    '$match' => [
                        'mapped' => true,
                        'inventory_sync_intiated' => ['$exists' => false]
                    ]
                ],
                [
                    '$group' => [
                        '_id' => '$shop_id',
                        'product_data' => [
                            '$push' => '$$ROOT'
                        ],
                        'user_id' => ['$first' => '$user_id'],
                        'count' => ['$sum' => 1]
                    ]
                ],
                [
                    '$project' => [
                        '_id' => 1,
                        'user_id' => 1,
                        'product_data' => [
                            '$slice' => ['$product_data', 500]
                        ],
                        'count' => 1
                    ]
                ],
                [
                    '$sort' => ['_id' => 1]
                ]
            ];

            $shopGroups = $orphanedCollection->aggregate($pipeline, [
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ])->toArray();
            if (empty($shopGroups)) {
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No mapped orphaned products found to process</>');
                return;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Found ' . count($shopGroups) . ' shops with mapped orphaned products</>');

            $currentUserId = null;
            $processedSourceProductIds = [];

            foreach ($shopGroups as $shopGroup) {
                $shopId = $shopGroup['_id'];
                $userId = $shopGroup['user_id'];
                $productData = $shopGroup['product_data'] ?? [];
                $totalProducts = count($productData);

                // If user changed, mark previous user's source_product_ids as inventory_sync_intiated
                if ($currentUserId !== null && $currentUserId !== $userId) {
                    $this->markInventorySyncInitiated($currentUserId, $processedSourceProductIds);
                    $processedSourceProductIds = []; // Reset for new user
                }

                $currentUserId = $userId;

                // Use shopId from group _id, or fallback to first product's shop_id if available
                if (empty($shopId) && !empty($productData) && isset($productData[0]['shop_id'])) {
                    $shopId = $productData[0]['shop_id'];
                }

                // Skip if shopId is still null or empty
                if (empty($shopId)) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Skipping group with null shop_id for User: ' . $userId . '</>');
                    $this->di->getLog()->logContent('Skipping group with null shop_id for User: ' . $userId, 'info', $this->logFile);
                    continue;
                }

                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Processing Shop: ' . $shopId . ' for User: ' . $userId . ' with ' . $totalProducts . ' products</>');
                $this->di->getLog()->logContent('Processing Shop: ' . $shopId . ' for User: ' . $userId . ' with ' . $totalProducts . ' products', 'info', $this->logFile);

                // Set DI for user
                $response = $this->setDiForUser($userId);
                if (!isset($response['success']) || !$response['success']) {
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Unable to set DI for user: ' . $userId . '</>');
                    $this->di->getLog()->logContent('Unable to set DI for user: ' . $userId, 'info', $this->logFile);
                    continue;
                }

                // Process product data once per shop (max 500 products)
                $shopSourceProductIds = $this->processInventorySyncForShop($userId, $shopId, $productData);

                // Track source_product_ids processed for this user
                if (!empty($shopSourceProductIds)) {
                    $processedSourceProductIds = array_merge($processedSourceProductIds, $shopSourceProductIds);
                }
            }

            // Mark last user's source_product_ids as inventory_sync_intiated
            if ($currentUserId !== null && !empty($processedSourceProductIds)) {
                $this->markInventorySyncInitiated($currentUserId, $processedSourceProductIds);
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Inventory Sync for Mapped Orphaned Products Processing Completed!</>');
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in syncInventoryForMappedOrphanedProducts: ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error in syncInventoryForMappedOrphanedProducts: ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Process inventory sync for a shop (max 500 products per shop)
     * @return array Returns array of source_product_ids that were processed
     */
    private function processInventorySyncForShop($userId, $shopId, $productData)
    {
        try {
            // Get source marketplace and shop_id from user's shops
            $sourceMarketplace = $this->di->getUser()->shops[0]['marketplace'] ?? false;
            $sourceShopId = $this->di->getUser()->shops[0]['_id'] ?? false;
            if (!$sourceMarketplace || !$sourceShopId) {
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Source marketplace or shop_id not found for user: ' . $userId . '</>');
                $this->di->getLog()->logContent('Source marketplace or shop_id not found for user: ' . $userId, 'info', $this->logFile);
                return [];
            }

            // Extract source_product_ids from productData (max 500 already limited in aggregation)
            $sourceProductIds = array_column($productData, 'source_product_id');
            $totalProducts = count($sourceProductIds);

            if (empty($sourceProductIds)) {
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No source_product_ids found for shop: ' . $shopId . '</>');
                $this->di->getLog()->logContent('No source_product_ids found for shop: ' . $shopId . ' and user: ' . $userId, 'info', $this->logFile);
                return [];
            }

            $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Processing ' . $totalProducts . ' products for shop: ' . $shopId . '</>');
            $this->di->getLog()->logContent('Processing ' . $totalProducts . ' products for shop: ' . $shopId . ' and user: ' . $userId . ' with source_product_ids: ' . json_encode($sourceProductIds), 'info', $this->logFile);

            // Prepare data for inventory sync
            $prepareData = [
                'operationType' => 'inventory_sync',
                'target' => [
                    'marketplace' => \App\Amazon\Components\Common\Helper::TARGET,
                    'shopId' => (string)$shopId
                ],
                'source' => [
                    'marketplace' => $sourceMarketplace,
                    'shopId' => (string)$sourceShopId
                ],
                'source_product_ids' => $sourceProductIds,
                'useRefinProduct' => true,
                'process_type' => 'automatic',
                'usePrdOpt' => true,
                'projectData' => $this->getKeysForProjection(),
                'feedApi' => true,
                'filter' => [
                    'item.status' => [
                        '0' => ['Inactive', 'Incomplete', 'Active', 'Submitted']
                    ]
                ]
            ];

            // Get Product Syncing instance and call startSync
            $product = $this->di->getObjectManager()->get(\App\Connector\Models\Product\Syncing::class);
            $response = $product->startSync($prepareData);

            // Log the response
            $this->di->getLog()->logContent('startSync response for shop: ' . $shopId . ' user: ' . $userId . ': ' . json_encode($response), 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Completed inventory sync for shop: ' . $shopId . ' with ' . $totalProducts . ' products - Response logged</>');

            // Return source_product_ids that were processed
            return $sourceProductIds;
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in processInventorySyncForShop for shop ' . $shopId . ' user ' . $userId . ': ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error in processInventorySyncForShop for shop: ' . $shopId . ': ' . $e->getMessage() . '</>');
            return [];
        }
    }

    /**
     * Mark source_product_ids as inventory_sync_intiated in orphaned_amazon_products_container_new
     */
    private function markInventorySyncInitiated($userId, $sourceProductIds)
    {
        try {
            if (empty($sourceProductIds)) {
                return;
            }

            $orphanedCollection = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');

            // Remove duplicates
            $sourceProductIds = array_values(array_unique($sourceProductIds));

            $result = $orphanedCollection->updateMany(
                [
                    'user_id' => $userId,
                    'source_product_id' => ['$in' => $sourceProductIds]
                ],
                [
                    '$set' => [
                        'inventory_sync_intiated' => true,
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $updatedCount = $result->getModifiedCount();
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Marked inventory_sync_intiated=true for ' . $updatedCount . ' products for user: ' . $userId . '</>');
            $this->di->getLog()->logContent('Marked inventory_sync_intiated=true for ' . $updatedCount . ' products for user: ' . $userId . ' with source_product_ids: ' . json_encode($sourceProductIds), 'info', $this->logFile);
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in markInventorySyncInitiated for user ' . $userId . ': ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error marking inventory_sync_intiated for user: ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Get the projection keys for product data.
     *
     * @return array
     */
    private function getKeysForProjection()
    {
        return [
            'additional_images' => 0,
            'barcode' => 0,
            'brand' => 0,
            'additional_images' => 0,
            'compare_at_price' => 0,
            'created_at' => 0,
            'description' => 0,
            'grams' => 0,
            'handle' => 0,
            'is_imported' => 0,
            'low_sku' => 0,
            'main_image' => 0,
            'price' => 0,
            'product_type' => 0,
            'seo' => 0,
            'source_created_at' => 0,
            'tags' => 0,
            'source_updated_at' => 0,
            'template_suffix' => 0,
            'variant_attributes' => 0,
            'variant_image' => 0,
            'weight' => 0,
            'weight_unit' => 0,
            'marketplace' => 0,
            'position' => 0,
            'published_at' => 0,
            'taxable' => 0,
            'variant_title' => 0
        ];
    }
            /**
     * Close unmapped orphaned listings on Amazon
     * Fetches products from orphaned_amazon_products_container_new where unmapped:true and _id > specified ObjectId
     * Processes in chunks of 20, searches via search-listing API, then closes via listing-update API
     */
    public function closeUnmappedOrphanedListings()
    {
        try {
            $this->logFile = "amazon/scripts/closeUnmappedOrphanedListings/" . date('d-m-Y') . '.log';
            $this->di->getLog()->logContent('Starting Close Unmapped Orphaned Listings Processing...', 'info', $this->logFile);
            $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Starting Close Unmapped Orphaned Listings Processing...</>');

            $orphanedCollection = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');

            // Group by user_id and shop_id with product data (seller-sku)
            $pipeline = [
                [
                    '$match' => [
                        'unmapped' => true,
                        'is_imported' => false
                    ]
                ],
                [
                    '$group' => [
                        '_id' => [
                            'user_id' => '$user_id',
                            'shop_id' => '$shop_id'
                        ],
                        'product_data' => [
                            '$push' => [
                                'seller-sku' => '$seller-sku',
                                'source_product_id' => '$source_product_id'
                            ]
                        ],
                        'count' => ['$sum' => 1]
                    ]
                ],
                [
                    '$sort' => ['_id.user_id' => 1]
                ]
            ];

            $userShopGroups = $orphanedCollection->aggregate($pipeline, [
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ])->toArray();
            if (empty($userShopGroups)) {
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No unmapped orphaned products found to process</>');
                return;
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Found ' . count($userShopGroups) . ' user-shop groups with unmapped orphaned products</>');

            $currentUserId = null;

            foreach ($userShopGroups as $group) {
                $userId = $group['_id']['user_id'];
                $shopId = $group['_id']['shop_id'];
                $productData = $group['product_data'] ?? [];
                $totalProducts = count($productData);
                

                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> Processing User: ' . $userId . ' Shop: ' . $shopId . ' with ' . $totalProducts . ' products</>');
                $this->di->getLog()->logContent('Processing User: ' . $userId . ' Shop: ' . $shopId . ' with ' . $totalProducts . ' products', 'info', $this->logFile);

                // Set DI for user if changed
                if ($currentUserId !== $userId) {
                    $response = $this->setDiForUser($userId);
                    if (!isset($response['success']) || !$response['success']) {
                        $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Unable to set DI for user: ' . $userId . '</>');
                        $this->di->getLog()->logContent('Unable to set DI for user: ' . $userId, 'info', $this->logFile);
                        continue;
                    }
                    $currentUserId = $userId;
                }

                // Get remote_shop_id for the target shop
                $remoteShopId = $this->getRemoteShopId($shopId, $userId);
                if (empty($remoteShopId)) {
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Remote shop ID not found for shop: ' . $shopId . '</>');
                    $this->di->getLog()->logContent('Remote shop ID not found for shop: ' . $shopId . ' user: ' . $userId, 'info', $this->logFile);
                    continue;
                }

                // Extract SKUs and process in chunks of 20
                $skuDataMap = [];
                foreach ($productData as $product) {
                    if (!empty($product['seller-sku'])) {
                        $skuDataMap[$product['seller-sku']] = $product['source_product_id'];
                    }
                }

                if (empty($skuDataMap)) {
                    $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No valid SKUs found for shop: ' . $shopId . '</>');
                    continue;
                }

                $skuChunks = array_chunk(array_keys($skuDataMap), self::CLOSE_LISTING_CHUNK_SIZE);
                
                $totalChunks = count($skuChunks);
               
                $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Processing ' . count($skuDataMap) . ' SKUs in ' . $totalChunks . ' chunks</>');
                foreach ($skuChunks as $chunkIndex => $skuBatch) {
                    $chunkNum = $chunkIndex + 1;
                    $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Processing Chunk ' . $chunkNum . '/' . $totalChunks . ' (' . count($skuBatch) . ' SKUs)</>');
                    $closedSkus = $this->processCloseListingsForChunk($userId, $skuBatch, $remoteShopId, $shopId);
                     if (!empty($closedSkus)) {
                        $this->markListingClosed($userId, $closedSkus);
                    }
                   

                }
            }

            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Close Unmapped Orphaned Listings Processing Completed!</>');
        } catch (Exception $e) {
            $logFile = $this->logFile ?? 'exception.log';
            $this->di->getLog()->logContent('Error in closeUnmappedOrphanedListings: ' . $e->getMessage(), 'info', $logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error in closeUnmappedOrphanedListings: ' . $e->getMessage() . '</>');
        }
    }

    /**
     * Get remote shop ID for a given shop
     */
    private function getRemoteShopId($shopId, $userId)
    {
        try {
            $userShops = $this->di->getUser()->shops ?? [];
            foreach ($userShops as $shop) {
                if ((string)$shop['_id'] === (string)$shopId && isset($shop['remote_shop_id'])) {
                    return $shop['remote_shop_id'];
                }
            }

            // Fallback to fetching from user_details
            $userDetails = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $shop = $userDetails->getShop($shopId, $userId);
            if (!empty($shop) && isset($shop['remote_shop_id'])) {
                return $shop['remote_shop_id'];
            }

            return null;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in getRemoteShopId: ' . $e->getMessage(), 'info', $this->logFile);
            return null;
        }
    }

    /**
     * Process a chunk of SKUs: search via search-listing API, then close each via listing-update API
     * @return array Returns array of SKUs that were successfully closed
     */
    private function processCloseListingsForChunk($userId, $skuBatch, $remoteShopId, $targetShopId)
    {
        $closedSkus = [];
        $failedSkus = [];

        try {
            $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);

            // Step 1: Call search-listing API with SKU array
            $rawBody = [
                'sku' => $skuBatch,
                'shop_id' => $remoteShopId,
                'includedData' => 'issues,attributes,summaries'
            ];
            $response = $commonHelper->sendRequestToAmazon('search-listing', $rawBody, 'GET');
           

            if (!isset($response['success']) || !$response['success']) {
                $errorMsg = $response['error'] ?? 'Unknown error';
                $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error calling search-listing: ' . $errorMsg . '</>');
                $this->di->getLog()->logContent('Error in search-listing for user ' . $userId . ': ' . $errorMsg, 'error', $this->logFile);
                return $closedSkus;
            }

            // Check if response has items
            if (!isset($response['response']['items']) || !is_array($response['response']['items']) || empty($response['response']['items'])) {
                $this->output->writeln('<options=bold;bg=yellow> ➤ </><options=bold;fg=yellow> No items found in search-listing response</>');
                $this->di->getLog()->logContent('No items found in search-listing response for user ' . $userId, 'info', $this->logFile);
                return $closedSkus;
            }

            $this->output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Found ' . count($response['response']['items']) . ' items in search-listing response</>');

            // Step 2: Process each item and close listing
            foreach ($response['response']['items'] as $item) {
                $sku = $item['sku'] ?? null;
                if (empty($sku)) {
                    continue;
                }

                $closeResult = $this->closeListingForSku($sku, $item, $remoteShopId, $targetShopId, $userId);

                if ($closeResult['success']) {
                    $closedSkus[] = $sku;
                    $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Successfully closed listing for SKU: ' . $sku . '</>');
                } else {
                    $failedSkus[] = $sku;
                    $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Failed to close listing for SKU: ' . $sku . ' - ' . ($closeResult['message'] ?? 'Unknown error') . '</>');
                }
            }

            $this->di->getLog()->logContent('Chunk processed - Closed: ' . count($closedSkus) . ', Failed: ' . count($failedSkus) . ' for user ' . $userId, 'info', $this->logFile);

            return $closedSkus;
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in processCloseListingsForChunk for user ' . $userId . ': ' . $e->getMessage(), 'error', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error in processCloseListingsForChunk: ' . $e->getMessage() . '</>');
            return $closedSkus;
        }
    }

    /**
     * Close listing for a single SKU using item attributes from search response
     */
    private function closeListingForSku($sku, $itemData, $remoteShopId, $targetShopId, $userId)
    {
        try {
            $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);

            // Use attributes directly from search-listing response
            $attributes = $itemData['attributes'] ?? [];
            $purchasableOffer = $attributes['purchasable_offer'] ?? [];
            if (empty($purchasableOffer)) {
                return ['success' => false, 'message' => 'No purchasable_offer found in item attributes'];
            }

            // Build toUpdatePrice array with end_at set to current time to close listing
            $toUpdatePrice = [];
            foreach ($purchasableOffer as $key => $audience) {
                if (isset($audience['audience']) && $audience['audience'] == "ALL") {
                    $toUpdatePrice[$key] = [
                        'audience' => $audience['audience'],
                        'currency' => $audience['currency'] ?? '',
                        'end_at' => ['value' => date('c')],
                        'marketplace_id' => $audience['marketplace_id'] ?? '',
                        'our_price' => $audience['our_price'] ?? []
                    ];
                }
            }
            if (empty($toUpdatePrice)) {
                return ['success' => false, 'message' => 'No ALL audience found in purchasable_offer'];
            }

            // Send update-listing request to close this SKU
            $payload = [
                'shop_id' => $remoteShopId,
                'product_type' => 'PRODUCT',
                'sku' => rawurlencode($sku),
                'patches' => [
                    [
                        'op' => 'replace',
                        'operation_type' => 'PARTIAL_UPDATE',
                        'path' => '/attributes/purchasable_offer',
                        'value' => $toUpdatePrice
                    ]
                ]
            ];

            $res = $commonHelper->sendRequestToAmazon('listing-update', $payload, 'POST');

            if (isset($res['success']) && $res['success'] && isset($res['response']['status']) && $res['response']['status'] == 'ACCEPTED') {
                $this->di->getLog()->logContent('Successfully closed listing for SKU: ' . $sku . ' user: ' . $userId, 'info', $this->logFile);
                
                // Update amazon_listing collection to mark as closed
                // $this->updateAmazonListingStatus($userId, $targetShopId, $sku);
                
                return ['success' => true, 'response' => $res['response']];
            } elseif (isset($res['success']) && $res['success'] && isset($res['response']['issues'])) {
                $this->di->getLog()->logContent('Issues while closing listing for SKU: ' . $sku . ' - ' . json_encode($res['response']['issues']), 'error', $this->logFile);
                return ['success' => false, 'message' => 'Issues: ' . json_encode($res['response']['issues'])];
            } else {
                $this->di->getLog()->logContent('Failed to close listing for SKU: ' . $sku . ' - ' . json_encode($res), 'error', $this->logFile);
                return ['success' => false, 'message' => 'Listing update failed'];
            }
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in closeListingForSku for SKU ' . $sku . ': ' . $e->getMessage(), 'error', $this->logFile);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function markListingClosed($userId, $skus)
    {
        try {
            if (empty($skus)) {
                return;
            }

            $orphanedCollection = $this->mongo->getCollectionForTable('orphaned_amazon_products_container_new');

            $result = $orphanedCollection->updateMany(
                [
                    'user_id' => $userId,
                    'seller-sku' => ['$in' => $skus]
                ],
                [
                    '$set' => [
                        'listing_closed' => true,
                        'listing_closed_at' => new UTCDateTime(),
                        'updated_at' => new UTCDateTime()
                    ]
                ]
            );

            $updatedCount = $result->getModifiedCount();
            $this->output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Marked listing_closed=true for ' . $updatedCount . ' orphaned product records</>');
            $this->di->getLog()->logContent('Marked listing_closed=true for ' . $updatedCount . ' orphaned product records for user: ' . $userId . ' with SKUs: ' . json_encode($skus), 'info', $this->logFile);
        } catch (Exception $e) {
            $this->di->getLog()->logContent('Error in markListingClosed for user ' . $userId . ': ' . $e->getMessage(), 'error', $this->logFile);
            $this->output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error marking listings closed for user: ' . $userId . ': ' . $e->getMessage() . '</>');
        }
    }

}