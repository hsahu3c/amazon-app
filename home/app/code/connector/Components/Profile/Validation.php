<?php

namespace App\Connector\Components\Profile;

use App\Core\Models\BaseMongo;
use Type;

use function PHPSTORM_META\type;

class Validation extends BaseMongo
{
    public $userId = '';

    public $tableName = 'profile';

    //keys to hold validation and requested keys
    public $requestKeys = [];

    public function init(&$data = []): void
    {
        if (isset($data['user_id'])) {
            $this->userId = $data['user_id'];
        } else {
            $this->userId = $this->di->getUser()->id;
            $data['user_id'] = $this->userId;
        }
    }


    public function getAggregateForName()
    {
        $aggregate = [];
        $aggregate[] = [
            '$match' => [
                'user_id' => $this->userId,
                'type' => 'profile'
            ]
        ];
        $aggregate[] = [
            '$match' => [
                'shop_ids' => [
                    '$elemMatch' => [
                        'target' => $this->di->getRequester()->getTargetId(),
                        'source' => $this->di->getRequester()->getSourceId()
                    ]
                ]
            ]
        ];
        $aggregate[] = [
            '$project' => ['name' => 1]
        ];
        return $aggregate;
    }


    public function validateProfieName($data)
    {
        if (!isset($data['name'])) {
            return ['success' => false, 'message' => 'Send Name', 'code' => 'data_missing'];
        }

        $this->init($data);

        $profile = $this->getCollectionForTable($this->tableName)->aggregate($this->getAggregateForName())->toArray();

        $toValidateName = $data['name'];
        $correctName = true;
        foreach ($profile as $value) {
            if ($value['name'] == $toValidateName && (string)$value['_id'] !== $data['profile_id']) {
                $correctName = false;
            }
        }

        if ($correctName) {
            return ['success' => true, 'message' => 'unique name'];
        }

        return ['success' => false, 'message' => 'Duplicate name', 'code' => 'duplicate_name'];
    }




    /**
     * validateProfile
     * function to validate profile data
     * 
     * @param [array] $data
     * @return array
     */
    public function validateProfile($data)
    {
        $this->init($data);

        if (!isset($data['validate_on'])) {
            return ['success' => false, 'message' => 'Data Missing'];
        }

        if (isset($data['profile_id']) && !$this->idValid($data['profile_id'])) {
            return ['success' => false, 'message' => 'Invalid Profile ID', 'code' => 'invalid_profie_id'];
        }

        $filterData =
            $data['validate_on'];

        if (count($filterData) == 0) {
            return ['success' => false, 'message' => 'Data Missing'];
        }

        $profileId = isset($data['profile_id']) ? $data['profile_id'] : false;

        if (isset($data['query']) && $data['query'] == true) {
            if (isset($filterData['query']) && strlen($filterData['query']) > 0 && $this->testQuery($filterData['query'])) {
                return $this->prepareDuplicateResponseWithQuery($filterData, $profileId);
            }
        }

        $profile = $this->getCollectionForTable($this->tableName)->aggregate($this->getAggregateForValidation($filterData, $profileId))->toArray();
        return $this->prepareDuplicateResponse($profile);
    }

    public function getAggregateForValidation($data, $profileId = false)
    {
        $aggregate = [];

        $aggregate[] = [
            '$match' => [
                'user_id' => $this->userId,
                'type' => 'profile',
                '_id' => ['$ne' => $profileId ? $this->getIdConverted($profileId) : ""]
            ]
        ];

        $aggregate[] = [
            '$match' => [
                'shop_ids' => [
                    '$elemMatch' => [
                        'target' => $this->di->getRequester()->getTargetId(),
                        'source' => $this->di->getRequester()->getSourceId()
                    ]
                ]
            ]
        ];

        // Match conditions
        $tempArr = [];
        foreach ($data as $k => $v) {
            $tempArr[] = [$k => ['$regex' => '^' . preg_quote($v) . '$', '$options' => 'i']];
        }
        $aggregate[] = ['$match' => ['$or' => $tempArr]];

        $projectFields = ['_id' => 0];
        foreach ($data as $k => $v) {
            $fieldPath = str_contains($k, '.') ? explode('.', $k)[0] : $k;
            $projectFields[$fieldPath] = [
                '$cond' => [
                    'if' => [
                        '$regexMatch' => [
                            'input' => '$' . $k,
                            'regex' => '^' . preg_quote($v) . '$', // Exact match using regex with anchors
                            'options' => 'i' // Case-insensitive
                        ]
                    ],
                    'then' => 1,
                    'else' => 0
                ]
            ];
        }
        $aggregate[] = ['$project' => $projectFields];

        return $aggregate;
    }


    public function getIdConverted($id)
    {
        return new \MongoDB\BSON\ObjectId($id);
    }

    public function idValid($id)
    {
        return $id instanceof \MongoDB\BSON\ObjectID
            || preg_match('/^[a-f\d]{24}$/i', $id);
    }

    public function prepareDuplicateResponse($data)
    {
        if (count($data) > 0) {
            $duplicateKeys = [];
            foreach ($data as $val) {
                foreach ($val as $k => $v) {
                    if ($v) {
                        if (!in_array($k, $duplicateKeys)) {
                            $duplicateKeys[] = $k;
                        }
                    }
                }
            }

            if ($duplicateKeys !== []) {
                return [
                    'success' => false,
                    'message' => 'Duplicate Data',
                    'code' => 'duplicate_data',
                    'data' => ["duplicate keys" => $duplicateKeys]
                ];
            }
        }

        return ['success' => true, 'message' => 'No Duplicate Value found!!!!'];
    }

    function trimValues($str = "")
    {
        $str = ltrim($str, "(");
        $str = rtrim($str, ")");
        $str = trim($str, " ");
        return $str;
    }

    public function testQuery($query)
    {
        if (strlen($query) > 0) {
            if (str_contains($query, "||") || str_contains($query, "&&")) {
                return true;
            }
        }

        return false;
    }

    public function prepareDuplicateResponseWithQuery($data, $profileId)
    {
        $profiles = $this->getCollectionForTable($this->tableName)->find([
            'user_id' => $this->userId,
            'type' => 'profile',
            '_id' => ['$ne' => $profileId ? $this->getIdConverted($profileId) : ""],
            'shop_ids' => [
                '$elemMatch' => [
                    'target' => $this->di->getRequester()->getTargetId(),
                    'source' => $this->di->getRequester()->getSourceId()
                ]
            ]
        ])->toArray();

        $duplicateKeys = [];
        foreach ($profiles as $v) {
            foreach ($data as $key => $value) {
                if ($key === 'query' && isset($v['query']) && strlen($v['query']) > 0) {
                    if (($v[$key] === $value) || $this->compareQueries($value, $v['query'])) {
                        array_push($duplicateKeys, $key);
                        unset($data[$key]);
                    }
                } else if ($v[$key] === $value) {
                    array_push($duplicateKeys, $key);
                    unset($data[$key]);
                }

                if (count($data) == 0) {
                    break;
                }
            }
        }

        if ($duplicateKeys !== []) {
            return [
                'success' => false,
                'message' => 'Duplicate Data',
                'code' => 'duplicate_data',
                'data' => ["duplicate keys" => $duplicateKeys]
            ];
        }

        return ['success' => true, 'message' => 'No Duplicate Value found!!!!'];
    }

    public function compareQueries($query1, $query2)
    {
        $query1 = $this->trimValues($query1);
        $query2 = $this->trimValues($query2);
        $orSeparated = $this->getAndCompareOr($query1, $query2);

        if ($orSeparated['duplicate']) {
            $duplicate = $orSeparated['proceed'] ? $this->getAndCompareAnd($orSeparated['str1'], $orSeparated['str2']) : true;
            if ($duplicate) {
                return true;
            }
        }

        return false;
    }

    public function getAndCompareOr($str1, $str2)
    {
        $str1 = explode("||", $str1);
        $str2 = explode("||", $str2);

        if (count($str1) === count($str2)) {
            if ($this->compareArrays($str1, $str2)) {
                return ['duplicate' => true, 'proceed' => false, "str1" => $str1, "str2" => $str2];
            }

            return ['duplicate' => true, 'proceed' => true, "str1" => $str1, "str2" => $str2];
        }

        return ['duplicate' => false];
    }

    public function getAndCompareAnd($arr1, $arr2)
    {
        $arr1 = $this->getAndArray($arr1);
        $arr2 = $this->getAndArray($arr2);

        if (count($arr1) === count($arr2)) {
            if ($this->compareAndArray($arr1, $arr2)) {
                return true;
            }
        } else {
            return false;
        }
    }

    public function getAndArray($arr)
    {
        foreach ($arr as $k => $v) {
            $arr[$k] = explode("&&", $v);
        }

        return $arr;
    }

    public function compareAndArray($arr1, $arr2)
    {
        $arr = [];
        foreach ($arr1 as $k => $v) {
            foreach ($v as $key => $val) {
                $arr1[$k][$key] = trim($val, " ");
            }
        }

        foreach ($arr2 as $k => $v) {
            foreach ($v as $key => $val) {
                $arr2[$k][$key] = trim($val, " ");
            }
        }

        foreach ($arr1 as $value) {
            foreach ($arr2 as $v) {
                if (count($value) == count($v)) {
                    if (count(array_diff($value, $v)) == 0) {
                        array_push($arr, $v);
                    }
                }
            }
        }

        if (count($arr) == count($arr1)) {
            return true;
        }

        return false;
    }

    public function compareArrays($arr1, $arr2)
    {

        $result = array_diff($arr1, $arr2);
        if (count($result) == 0) {
            return true;
        }

        return false;
    }
}
