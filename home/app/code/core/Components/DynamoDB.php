<?php

namespace App\Core\Components;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\DynamoDb\Exception\DynamoDbException;

/**
 * CRUD operations for DynamoDB
 */
class DynamoDB extends Base
{
    public $db, $table, $pk, $sk,$marshaler;
    /**
     * Constructor
     *
     * @param string $table         Table Name
     * @param string $partitionKey  Partition key name
     * @param string $sortKey       Sort key name
     * 
     * NOTE: Constructor parameters are optional but due to a bug in ObjectManager
     *      they must be provided when using ObjectManager
     */
    public function __construct($table = null, $partitionKey = null, $sortKey = null)
    {
        $this->table = $table;
        $this->pk = $partitionKey;
        $this->sk = $sortKey;
        $this->marshaler = new Marshaler();
    }
    /**
     * Secondary constructor, called after setting di
     *
     * @return void
     */
    public function _construct()
    {
        $this->getClient();
    }
    /**
     * Create a dynamoDB client
     *
     * @return DynamoDbClient
     */
    public function getClient()
    {
        if (!$this->db) {
            if (!($config = $this->di->getConfig()->path("aws.dynamo", null))->region); {
                $config = $config = $this->di->getConfig()->path("aws.default", null);
            }
            $this->db = new DynamoDbClient($config->toArray());
        }
        return $this->db;
    }
    /**
     * Wrapper for DynamoDb createTable operation
     *
     * @param array $data   Query for creating the table
     * @return array
     */
    public function createTable($data, $wait = false)
    {
        // Set table name, partition key and sort key from the data provided
        $this->table = $data['TableName'];
        foreach ($data["KeySchema"] as $keys) {
            if ($keys["KeyType"] == "HASH") {
                $this->pk = $keys["AttributeName"];
            } elseif ($keys["KeyType"] == "RANGE") {
                $this->sk = $keys["AttributeName"];
            }
        }
        $result = [];
        try {
            $result = $this->db->createTable($data);
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() == "ResourceInUseException") {
                return ['success' => false, "message" => $e->getAwsErrorMessage()];
            }
        }
        // Wait for table to be created. It takes some time
        // Writing to a table right after running a createTable query will result in errors
        if ($wait && $result["TableStatus"] == "CREATING") {
            do {
                $result = $this->db->describeTable([
                    'TableName' => $this->table
                ]);
                $status = $result['Table']['TableStatus'];
                usleep(100000);
            } while ($status != "ACTIVE");
        }
        return ["success" => $result["TableStatus"] == "CREATING", "message" => "Creating..."];
    }
    /**
     * Insert one item into the table
     *
     * @param array $item
     * @param boolean $formatted
     * @return array
     */
    public function insert($item, $format = false)
    {
        if ($format) {
            $item = $this->marshaler->marshalItem($item);
        }
        print_r($item);
        try {
            $this->db->putItem(["TableName" => $this->table, "Item" => $item]);
        } catch (DynamoDbException $e) {
            return ['success' => false, "message" => $e->getAwsErrorMessage()];
        }
        return ['success' => true, "message" => "ok"];
    }
    /**
     * Retrieve an item from the table
     *
     * @param string $pk            Partition Key
     * @param string $sk            Sort Key
     * @param string $projection    Comma separated string of attributes to get 
     * @return array
     */
    public function find($pk, $sk = null, $projection = null)
    {
        $key = [
            $this->pk => ['S' => "$pk"]
        ];
        if ($sk) {
            $key["$this->sk"] = [
                'S' => "$sk"
            ];
        }
        $query = [
            "TableName" => $this->table,
            "Key" => $key
        ];
        if ($projection) {
            $query["ProjectionExpression"] = $projection;
        }
        try {
            return $this->marshaler->unmarshalItem($this->db->getItem($query)["Item"]);
        } catch (DynamoDbException $e) {
            return ["success" => false, "message" => $e->getAwsErrorMessage()];
        }
    }
    /**
     * Delete item from table
     *
     * @param array $item
     * @return void
     */
    public function delete($data)
    {
        if (!array_key_exists("TableName", $data)) {
            $data['TableName'] = $this->table;
        }
        try {
            return $this->db->deleteItem($data);
        } catch (DynamoDbException $e) {
            return ["success" => false, "message" => $e->getAwsErrorMessage()];
        }
    }
    /**
     * Wrapper for dynamoDB query
     *
     * @param array $data
     * @return array
     */
    public function query($data)
    {
        if (!array_key_exists("TableName", $data)) {
            $data['TableName'] = $this->table;
        }
        try {
            return $this->db->query($data);
        } catch (DynamoDbException $e) {
            return ["success" => false, "message" => $e->getAwsErrorMessage()];
        }
    }
    /**
     * Wrapper for dynamoDB scan
     *
     * @param array $data
     * @return array
     */
    public function scan($data)
    {
        if (!array_key_exists("TableName", $data)) {
            $data['TableName'] = $this->table;
        }
        try {
            return $this->db->scan($data);
        } catch (DynamoDbException $e) {
            return ["success" => false, "message" => $e->getAwsErrorMessage()];
        }
    }
    /**
     * Wrapper for dynamoDB update
     *
     * @param array $data
     * @return void
     */
    public function update($data)
    {
        if (!array_key_exists("TableName", $data)) {
            $data['TableName'] = $this->table;
        }
        try {
            return $this->db->updateItem($data);
        } catch (DynamoDbException $e) {
            return ["success" => false, "message" => $e->getAwsErrorMessage()];
        }
    }
    /**
     * Find multiple documents form DB based on PK (and SK)
     *
     * @param array $items
     * @param boolean|string $projection
     * @param boolean $format
     * @return void
     */
    public function findMany($items, $projection = false, $format = false)
    {
        if ($format) {
            $tempItems=[];
            foreach($items as $item){
                $tempItems[] = $this->marshaler->marshalItem($item);
            }
            $items=$tempItems;
        }
        $query = [
            "RequestItems" => [
                $this->table => [
                    "Keys" => $items
                ],
            ]
        ];
        if($projection){
            $query["RequestItems"][$this->table]["ProjectionExpression"]=$projection;
        }
        try {
            $response=$this->db->batchGetItem($query)["Responses"][$this->table];
            $items=[];
            foreach($response as $item){
                $items[]=$this->marshaler->unmarshalItem($item);
            }
            return $items;
        } catch (DynamoDbException $e) {
            return ["success" => false, "message" => $e->getAwsErrorMessage()];
        }
    }
    /**
     * Wrapper for batch write item
     *
     * @param array $data
     * @return void
     */
    public function batchWrite($data)
    {
        try {
            return $this->db->batchWriteItem($data);
        } catch (DynamoDbException $e) {
            return ["success" => false, "message" => $e->getAwsErrorMessage()];
        }
    }
}
