<?php

namespace App\Connector\Components;

use App\Core\Models\BaseMongo;

class PaginationHelper extends BaseMongo
{
    const IS_EQUAL_TO = 1;

    const IS_NOT_EQUAL_TO = 2;

    const IS_CONTAINS = 3;

    const IS_NOT_CONTAINS = 4;

    const START_FROM = 5;

    const END_FROM = 6;

    const RANGE = 7;

    const CHECK_KEY_EXISTS = 8;

    const GREATER_THAN = 9;

    const LESS_THAN = 10;

    /**
     * getNextAndPrevCursorAggregate($data,$sortType,$sortBy)
     * 
     * function returning aggregate for sorting and cursor.
     * Aggregate must be added to your aggregate for proper pagination.
     * 
     * @param Array $data
     * @param String $sortType
     * @param String $sortBy
     * @return Array 
     */
    public function getNextAndPrevCursorAggregate($data, $sortType = "_id", $sortBy = 1)
    {
        if (isset($data['next'])) {
            $nextDecoded = json_decode(base64_decode($data['next']), true);
            $aggregation['$match'][$sortBy] = ($sortType === -1) ?
                [
                    '$lt' => ($sortBy == "_id") ? new \MongoDB\BSON\ObjectID($nextDecoded['cursor']['$oid']) : $nextDecoded['cursor']
                ] : [
                    '$gt' => ($sortBy == "_id") ? new \MongoDB\BSON\ObjectID($nextDecoded['cursor']['$oid']) : $nextDecoded['cursor']
                ];
        }

        if (isset($data['prev'])) {
            $nextDecoded = json_decode(base64_decode($data['prev']), true);
            if (count($nextDecoded['cursor']) != 0) {
                $lastIndex = $nextDecoded['cursor'][count($nextDecoded['cursor']) - 1];
                $aggregation['$match'][$sortBy] = ($sortType === -1) ? [
                    '$lte' => ($sortBy == "_id") ? new \MongoDB\BSON\ObjectID($lastIndex['$oid']) : $lastIndex
                ] : [
                    '$gte' => ($sortBy == "_id") ? new \MongoDB\BSON\ObjectID($lastIndex['$oid']) : $lastIndex
                ];
            }
        }

        $sortAggregate = ['$sort' => [$sortBy => $sortType]];
        $prev = $nextDecoded['pointer'] ?? null;
        $totalPageRead = $nextDecoded['totalPageRead'];
        return ['cursorAggregate' => $aggregation, 'sortAggregate' => $sortAggregate, 'prev' => $prev, 'total_page_read' => $totalPageRead];
    }

    /**
     * getNextPrevProfileData()
     * 
     * function to get profile data with pointers
     * 
     * this function will return aggregated data as well as next and prev pointers for next page
     */
    public function getNextPrevData($aggregateData = [], $data = [], $collection = "")
    {
        if (count($aggregateData) == 0 || strlen($collection) == 0) {
            return false;
        }

        $condition = $aggregateData['aggregate'];
        $totalPageRead = $aggregateData['totalPageRead'];
        $prev = $aggregateData['prev'];
        $limit = $aggregateData['limit'];
        $sortBy = $data['sort_by'] ?? "_id";
        $sortBy = strlen($sortBy) > 0 ? $sortBy : "_id";

        $profileArray = $this->getCollectionForTable($collection)->aggregate($condition);

        try {

            $cursor = $profileArray;
            $it = new \IteratorIterator($cursor);
            $it->rewind();

            $rows = [];
            while ($limit > 0 && $doc = $it->current()) {
                $rows[] = $doc;
                $limit--;
                $it->next();
            }

            if ($it->valid()) {
                if (!isset($data['prev'])) {
                    $prev[] = $rows[0][$sortBy];
                }

                //  else if (count($prev) > 1) {
                //     array_pop($prev);
                // }
                $next = base64_encode(json_encode([
                    'cursor' => $doc[$sortBy],
                    'pointer' => $prev,
                    'totalPageRead' => $totalPageRead + 1,
                ]));
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        $prevCursor = null;

        if (count($prev) >= 1 || isset($data['prev'])) {
            if (count($prev) === 1 && $this->checkObjectIds($rows[0][$sortBy], $prev[0])) {
                $prevCursor = null;
                $totalPageRead = 0;
            } else {

                $this->checkObjectIds($prev[count($prev) - 1], $rows[0][$sortBy])  && array_pop($prev);
                $prevCursor = base64_encode(json_encode([
                    'cursor' => $prev,
                    'totalPageRead' => $totalPageRead - 1,
                ]));
            }
        }

        $responseData = [
            'activePage' => $totalPageRead + 1,
            'current_count' => count($rows),
            'next' => $next ?? null,
            'prev' => $prevCursor,
            'rows' => $rows
        ];

        return $responseData;
    }


    /**
     * checkObjectIds($obj1, $obj2)
     * 
     * function will check wheter the ids are equal or not
     */
    public function checkObjectIds($obj1, $obj2)
    {
        $oid1 = json_decode(json_encode($obj1), true);
        $oid2 = json_decode(json_encode($obj2), true);
        $val = false;
        if (isset($oid1['$oid']) && isset($oid2['$oid'])) {
            $val =  $oid1['$oid'] === $oid2['$oid'];
        }

        return $val || ($obj1 === $obj2);
    }

    public static function searchMongo($filterParams = [])
    {

        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim($key);

                if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                    $conditions[$key] = self::checkInteger($key, $value[self::IS_EQUAL_TO]);
                } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[$key] =  ['$ne' => self::checkInteger($key, trim($value[self::IS_NOT_EQUAL_TO]))];
                } elseif (array_key_exists(self::IS_CONTAINS, $value)) {
                    $conditions[$key] = [
                        '$regex' =>  self::checkInteger($key, trim(addslashes($value[self::IS_CONTAINS]))),
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::IS_NOT_CONTAINS, $value)) {
                    $conditions[$key] = [
                        '$regex' => "^((?!" . self::checkInteger($key, trim(addslashes($value[self::IS_NOT_CONTAINS]))) . ").)*$",
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::GREATER_THAN, $value)) {
                    $conditions[$key] =  ['$gte' => self::checkInteger($key, trim($value[self::GREATER_THAN]))];
                } elseif (array_key_exists(self::LESS_THAN, $value)) {
                    $conditions[$key] =  ['$lte' => self::checkInteger($key, trim($value[self::LESS_THAN]))];
                } elseif (array_key_exists(self::START_FROM, $value)) {
                    $conditions[$key] = [
                        '$regex' => "^" . self::checkInteger($key, trim(addslashes($value[self::START_FROM]))),
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::END_FROM, $value)) {
                    $conditions[$key] = [
                        '$regex' => self::checkInteger($key, trim(addslashes($value[self::END_FROM]))) . "$",
                        '$options' => 'i'
                    ];
                } elseif (array_key_exists(self::RANGE, $value)) {
                    if ($key == 'shops.1.created_at' || $key == 'created_at') {
                        if ($key == 'shops.1.created_at') {
                            $conditions[$key] =  [
                                '$gte' => $value[self::RANGE]['from'],
                                '$lte' => $value[self::RANGE]['to']
                            ];
                        } else {
                            $conditions[$key] =  [
                                '$gte' => new \MongoDB\BSON\UTCDateTime(strtotime($value[self::RANGE]['from']) * 1000),
                                '$lte' => new \MongoDB\BSON\UTCDateTime((strtotime($value[self::RANGE]['to']) + 86399) * 1000)
                            ];
                        }
                    } else {
                        if (trim($value[self::RANGE]['from']) && !trim($value[self::RANGE]['to'])) {
                            $conditions[$key] =  ['$gte' => self::checkInteger($key, trim($value[self::RANGE]['from']))];
                        } elseif (
                            trim($value[self::RANGE]['to']) &&
                            !trim($value[self::RANGE]['from'])
                        ) {
                            $conditions[$key] =  ['$lte' => self::checkInteger($key, trim($value[self::RANGE]['to']))];
                        } else {
                            $conditions[$key] =  [
                                '$gte' => self::checkInteger($key, trim($value[self::RANGE]['from'])),
                                '$lte' => self::checkInteger($key, trim($value[self::RANGE]['to']))
                            ];
                        }
                    }
                } elseif (array_key_exists(self::CHECK_KEY_EXISTS, $value)) {
                    $conditions[$key] = ['$exists' => $value[self::CHECK_KEY_EXISTS] == 'true' ? true : false];
                }
            }
        }

        return $conditions;
    }

    public static function checkInteger($key, $value)
    {
        return trim($value);
    }
}
