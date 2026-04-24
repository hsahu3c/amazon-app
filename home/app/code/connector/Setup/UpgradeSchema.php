<?php

namespace App\Connector\Setup;

use \Phalcon\Di\DiInterface;

class UpgradeSchema extends \App\Core\Setup\Schema
{

    const DEFAULT_EXPIRE_AFTER_SECONDS = 7 * 24 * 3600; // 7 days

    public function up(DiInterface $di, $moduleName, $currentVersion): void
    {
        $this->applyOnSingle($di, $moduleName, $currentVersion, 'db', function ($connection, $dbVersion) use ($di) {
            if ($dbVersion < '3.0.1') {
                $collection = $connection->selectCollection('product_container');
                $collection->createIndex(["_id" => 1]);
                $collection->createIndex(["user_id" => 1, 'shop_id' => 1, 'container_id' => 1]);
                $collection->createIndex(["user_id" => 1, 'shop_id' => 1, 'source_sku' => 1]);
                $collection->createIndex(["user_id" => 1, 'shop_id' => 1, 'source_product_id' => 1]);
                $collection->createIndex(["user_id" => 1, 'source_marketplace' => 1, 'source_product_id' => 1]);

                $collection = $connection->selectCollection('temp_webhook_container');
                $collection->createIndex(["user_id" => 1, 'shop_id' => 1, 'container_id' => 1]);

                $collection = $connection->selectCollection('refine_product');
                $collection->createIndex([
                    "user_id" => 1,
                    'source_shop_id' => 1,
                    'target_shop_id' => 1,
                    'source_product_id' => 1,
                    'container_id' => 1
                ]);
                return true;
            }

            if ($dbVersion < "3.0.2") {
                $this->handleNotificationTtl($di, $connection->selectCollection('notifications'));
                return true;
            }
        });
    }

    private function handleNotificationTtl(DiInterface $di, $collection): void
    {
        $indexName = 'notification_created_at_ttl';
        $expireAfterSeconds = (int) $di->getConfig()->path('notifications.expire_after_seconds') ?? 0;
        if ($expireAfterSeconds > 0) {
            $this->createTtlIndex(
                $collection,
                'created_at',
                $expireAfterSeconds,
                $indexName
            );
        } else {
            $this->removeIndexIfExists($collection, $indexName);
        }
    }

    /**
     * Creates a TTL index on a specified field in a MongoDB collection.
     *
     * This function checks if an index with the same name already exists before creating a new one.
     * It also allows for customizing the index name using the optional `$indexName` parameter.
     *
     * @param \MongoDB\Collection $collection The MongoDB collection where the index should be created.
     * @param string $fieldName The name of the field to be indexed.
     * @param integer $expireAfterSeconds The number of seconds after which documents should expire.
     * @param string|null $indexName (Optional) The name of the index. If not provided, a default name based on the field name and "_ttl" will be used.
     * @return boolean True if the index was created successfully, false otherwise.
     *
     * @throws \MongoDB\Exception\RuntimeException If there is an error creating the index.
     */

    function createTtlIndex($collection, $fieldName, $expireAfterSeconds, $indexName = null)
    {
        $existingIndexes = $collection->listIndexes();
        foreach ($existingIndexes as $index) {
            if ($index['name'] === $indexName ?? "{$fieldName}_ttl") {
                return true;
            }
        }

        $indexName ??= "{$fieldName}_ttl";

        $collection->createIndex([
            $fieldName => 1
        ], [
            "name" => $indexName,
            "expireAfterSeconds" => $expireAfterSeconds
        ]);

        return true;
    }

    /**
     * Removes the specified index from the MongoDB collection if it exists.
     *
     * @param \MongoDB\Collection $collection The MongoDB collection from which to remove the index.
     * @param string $indexName The name of the index to remove.
     *
     *
     * @throws \MongoDB\Exception\RuntimeException If an error occurs during the index removal.
     */
    private function removeIndexIfExists($collection, string $indexName): void
    {
        $existingIndexes = $collection->listIndexes();
        foreach ($existingIndexes as $index) {
            if ($index['name'] === $indexName) {
                $collection->dropIndex($indexName);
                break;
            }
        }
    }
}
