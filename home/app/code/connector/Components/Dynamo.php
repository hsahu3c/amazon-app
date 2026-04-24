<?php
namespace App\Connector\Components;
use Aws\DynamoDb\DynamoDbClient;

class Dynamo extends \App\Core\Components\Base
{
    public $table;

    public $dynamoDbClient;

    public $uniqueKeys = [];

    public $tableUniqueColumn;


    public function getTable()
    {
        return $this->table;
    } 

    public function setTable($table)
    {
        $this->table = $table;
        return $this->table;
    }

    public function setUniqueKeys($uniqueKeys)
    {
        $this->uniqueKeys = $uniqueKeys;
        return $this->uniqueKeys;
    }

    public function getUniqueKeys()
    {
        return $this->uniqueKeys;
    }


    public function setDetails($config)
    {
        if(empty($config)){
            $client = DynamoDbClient::factory(include BP.'/app/etc/dynamo.php');
            $this->dynamoDbClient = $client;
            return $this->dynamoDbClient;
        }

        $client = DynamoDbClient::factory($config);
        $this->dynamoDbClient = $client;
        return $this->dynamoDbClient;
    }

    public function getDetails()
    {
        if(is_null($this->dynamoDbClient)){
            $client = DynamoDbClient::factory(include BP.'/app/etc/dynamo.php');
            $this->dynamoDbClient = $client;
            return $this->dynamoDbClient;
        }

        return $this->dynamoDbClient;
    }

    public function getTableUniqueColumn() 
    {
        return $this->tableUniqueColumn;
    }

    public function setTableUniqueColumn($columnName): void 
    {
        $this->tableUniqueColumn = $columnName;
    }

    public function save($data)
    {
        $table = $this->getTable();
        $client = $this->getDetails();
        if (!empty($this->uniqueKeys) && !is_null($this->tableUniqueColumn)) {
            $batchItem = [];
            foreach ($data as $sentValue) {
                $item = [];
                $uniquekeyValue = '';
                foreach ($sentValue as $key => $value) {
                    if (in_array($key, $this->uniqueKeys)) {
                        if (empty($uniquekeyValue)) {
                            $uniquekeyValue = $value;
                        } else {
                            $uniquekeyValue = $uniquekeyValue . '_' . $value;
                        }
                    }
                    $item[$key] = ['S' => (string)$value];
                }
                if (!empty($uniquekeyValue)) {
                    $item[$this->tableUniqueColumn] = ['S' => (string)$uniquekeyValue];
                    $batchItem[] = ['PutRequest' => ['Item' => $item]];

                }
            }
            $chunks = array_chunk($batchItem, 25);
            try {
                foreach ($chunks as $chunk) {
                    $client->batchWriteItem(['RequestItems' => [$table => $chunk]]);
                }
                return ['success' => true, 'message' => "Data inserted successfully!!"];
            } catch (\Exception $e) {
                $this->di->getLog()->logContent('Exception from Connector\Components\Dynamo.php save(): '
                    . json_encode($e), 'info', 'exception.log');
            }
        } else {
            $message = 'unique key or unique table column is not defined';
        }
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }


    public function saveSingle($data)
    {
        $table = $this->getTable();
        $client = $this->getDetails();
        if (!empty($this->uniqueKeys) && !is_null($this->tableUniqueColumn)) {
            $uniquekeyValue = '';
            foreach ($data as $key => $value) {
                if (in_array($key, $this->uniqueKeys)) {
                    if (empty($uniquekeyValue)) {
                        $uniquekeyValue = $value;
                    } else {
                        $uniquekeyValue = $uniquekeyValue . '_' . $value;
                    }
                }
                $item[$key] = ['S' => (string)$value];
            }
            if (!empty($uniquekeyValue)) {
                try {
                    $item[$this->tableUniqueColumn] = ['S' => (string)$uniquekeyValue];
                    $dataToInsert = [
                        'TableName' => $table,
                        'Item' => $item
                    ];
                    $client->putItem($dataToInsert);
                    return ['success' => true, 'message' => "Data inserted successfully!!"];
                } catch (\Exception $e) {
                    $this->di->getLog()->logContent('Exception from Connector\Components\Dynamo.php saveSingle(): '
                        . json_encode($e), 'info', 'exception.log');
                }
            }
        } else {
            $message = 'unique key or unique table column is not defined';
        }
        return ['success' => false, 'message' => $message ?? 'Something went wrong'];
    }

    public function scanData($table)
    {
        $client = $this->getDetails();
        $scan_response = $client->scan(array(
            'TableName' => $table
        ));
        return $scan_response;
    }

}
