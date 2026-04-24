<?php

namespace App\Connector\Components;

/**
 * helper class for solution api
 */
class Solution
{
    public function __construct(\App\Connector\Models\Solution $sm)
    {
        $this->solutionModel = $sm;
    }

    /**
     * finds and inserts multiple codes at a time.
     *
     * @param array $data
     */
    public function getSolutions($data): array
    {
        $result = [];
        foreach ($data as $doc) {
            // if data has code
            if (!is_null($doc["code"] ?? null) || !is_null($doc["title"] ?? null)) {
                $sm = new \App\Connector\Models\Solution();
                if ($doc["marketplace"] ?? null)
                    $query = $sm->findFirst([
                        '$and' => [
                            ['type' => "solution"],
                            ["marketplace" => $doc["marketplace"]],
                            ["code" => $doc["code"]]
                        ]
                    ]);
                else
                    $query = $sm->findFirst([
                        '$and' => [
                            ['type' => "solution"],
                            ["code" => $doc["code"]],
                            ["marketplace" => [
                                '$exists' => false
                            ]]
                        ]
                    ]);

                if ($query) {
                    $d = $query->getData();
                    // if has answer, add in result
                    if (trim($d["answer" ?? null])) {
                        unset($d["_id"], $d["id"]);
                        $d["solution_exists"] = true;
                        array_push($result, $d);
                    } else {
                        $tmp = [];
                        $tmp["solution_exists"] = false;
                        $tmp["code"] = $d["code"];
                        array_push($result, $tmp);
                    }
                }
                // if not found, insert it
                else {
                    $this->setSolution($doc["code"], $doc["marketplace"] ?? "", $doc["entity"] ?? "", $doc["title"] ?? "");
                    $tmp = [];
                    $tmp["solution_exists"] = false;
                    $tmp["code"] = $doc["code"];
                    if ($doc["marketplace"] ?? null)
                        $tmp["marketplace"] = $doc["marketplace"];

                    array_push($result, $tmp);
                }
            }
        }

        return $result;
    }

    /**
     * returns whole collection except their ids
     *
     * @return array
     */
    public function getAllSolutions($data)
    {
        $opts = [
            'projection' => [
                'type' => 0,
            ],
            "sort" => ['_id' => 1]
        ];
        if ((int)$data["limit"] ?? null)
            $opts["limit"] = (int)$data["limit"];

        if (isset($data["marketplace"])) {
            $direction = strlen(trim($data["next"] ?? null)) ? '$gt' : (strlen(trim($data["previous"] ?? null)) ? '$lt' : null);
            $limit = (int)(strlen(trim($data["limit"] ?? null)) ? ($data["limit"] < 1 ? 5 : $data["limit"]) : 5);
            if ($direction) {
                try {
                    new \MongoDB\BSON\ObjectId($data["next"] ?? $data["previous"] ?? null);
                } catch (\Exception) {
                    return false;
                }

                $arr = ($this->solutionModel->find(
                    [
                        'type' => "solution",
                        'marketplace' => $data["marketplace"],
                        '_id' => [
                            $direction => new \MongoDB\BSON\ObjectId($data["next"] ?? $data["previous"] ?? null)
                        ]
                    ],
                    [
                        'projection' => [
                            'type' => 0,
                        ],
                        "sort" => ['_id' => $direction === '$gt' ? 1 : -1],
                        "limit" => $limit
                    ]
                ));
                $tmp = [];
                $lastId = null;
                foreach ($arr as $doc) {
                    $x  = $doc->getData();
                    if (strlen(trim($x["answer"] ?? null))) $x["solution_exists"] = true;
                    else $x["solution_exists"] = false;

                    $tmp[] = $x;
                    $lastId = (string) $doc->_id;
                }

                $queryDocsRemaining = ($this->solutionModel->find(
                    [
                        'type' => "solution",
                        'marketplace' => $data["marketplace"],
                        '_id' => [
                            $direction => new \MongoDB\BSON\ObjectId($lastId)
                        ]
                    ],
                    [
                        'projection' => ['type' => 0],
                        "sort" => ['_id' => 1]
                    ]
                ))->toArray();
                $docsRemaining = (count($queryDocsRemaining)) < 1 ? 0 : (count($queryDocsRemaining));
                $response = [];
                $response["next"] = $docsRemaining < 1 ? null : $lastId;
                $response[$data["marketplace"]] = $tmp;
                return $response;
            }

            $arr = ($this->solutionModel->find(
                [
                    'type' => "solution",
                    'marketplace' => $data["marketplace"]
                ],
                $opts
            ))->toArray();
        } else
            $arr = ($this->solutionModel->find(
                ['type' => "solution"],
                [
                    'projection' => [
                        'type' => 0,
                    ], "sort" => ['_id' => 1]
                ]
            ))->toArray();

        for ($i = 0; $i < count($arr); $i++) {
            if (strlen($arr[$i]["answer"] ?? null))
                $arr[$i]["solution_exists"] = true;
            else
                $arr[$i]["solution_exists"] = false;

            $arr[$i]["id"] = (string) $arr[$i]["_id"];
            unset($arr[$i]["_id"]);
        }

        //order by marketplaces
        $result = [];
        foreach ($arr as  $doc) {
            $tmp = $doc;
            unset($tmp["marketplace"]);
            $result[$doc["marketplace"]][] = $tmp;
        }


        return $result;
    }

    /**
     * updates an incomplete solution 
     *
     * @param array $data
     * @return bool
     */
    public function updateSolution($data)
    {
        if (!isset($data["code"]))
            return false;

        $sm = new \App\Connector\Models\Solution();
        if (strlen(trim($data["marketplace"] ?? null)))
            $solution = $sm::findFirst([
                '$and' => [
                    ['type' => "solution"],
                    ['code' => $data["code"]],
                    ['marketplace' => $data["marketplace"]],
                ]
            ]);
        else
            $solution = $sm::findFirst([
                '$and' => [
                    ['type' => "solution"],
                    ['code' => $data["code"]],
                    ['marketplace' => ['$exists' => false]]
                ]
            ]);

        if ($solution) {
            unset($solution->id);
            $solution->code = $data["code"] ?? "";
            if (strlen(trim($data["marketplace"] ?? null)))
                $solution->marketplace = $data["marketplace"] ?? "";

            if (strlen(trim($data["entity"] ?? null)))
                $solution->entity = $data["entity"] ?? "";

            if (strlen(trim($data["title"] ?? null)))
                $solution->title = $data["title"] ?? "";

            $solution->answer = $data["answer"];
            return $solution->save();
        }

        return false;
    }

    /**
     * updates/create solution in db
     *
     * @param string $code
     * @param string $entity
     * @param string $title
     * @param string $answer
     * @param boolean $create
     */
    public function setSolution($code, $marketplace = "", $entity = "", $title = "", $answer = "", $create = false): bool
    {
        $sm = new \App\Connector\Models\Solution();
        if (strlen(trim($marketplace)) && $sm->findFirst([
            '$and' => [
                ['type' => "solution"],
                ['code' => $code],
                ['marketplace' => $marketplace],
            ]
        ])) {
            return false;
        }

        if (!strlen(trim($marketplace)) && $sm->findFirst([
            '$and' => [
                ['type' => "solution"],
                ['code' => $code],
                ['marketplace' => ['$exists' => false]]
            ]
        ])) {
            return false;
        }

        $sm->type = "solution";
        $sm->code = $code;
        if (strlen(trim($marketplace)))
            $sm->marketplace = $marketplace;

        $sm->entity = $entity;
        $sm->title = $title;
        $sm->answer = $answer;
        if ($sm->save())
            return true;

        return false;
    }

    /**
     * deletes a single doc by code
     *
     * @param array $code
     * @return bool
     */
    public function delete($data)
    {
        if (!strlen(trim($data["code"] ?? null)))
            return false;

        if ($data["marketplace"] ?? null)
            $doc = $this->solutionModel->findFirst([
                '$and' => [
                    ['type' => "solution"],
                    ["code" => $data["code"]],
                    ["marketplace" => $data["marketplace"]]
                ]
            ]);
        else
            $doc = $this->solutionModel->findFirst([
                '$and' => [
                    ['type' => "solution"],
                    ['code' => $data["code"]],
                    ['marketplace' => ['$exists' => false]]
                ]
            ]);

        if ($doc)
            return $doc->delete();

        return false;
    }

    /**
     * tells weather the solution exist or not.
     */
    public function solutionExists(array $data): bool
    {
        if (isset($data["marketplace"]) && !$this->solutionModel->findFirst([
            '$and' => [
                ['type' => "solution"],
                ['code' => $data["code"]],
                ['marketplace' => $data["marketplace"]]
            ]
        ]))
            return false;

        if ($this->solutionModel->findFirst([
            '$and' => [
                ['type' => "solution"],
                ['code' => $data["code"]],
                ['marketplace' => ['$exists' => false]]
            ]
        ]))
            return true;

        return false;
    }

    /**
     * finds for faq by keywords
     *
     * @param string $keyword
     * @param string $marketplace
     * @param string $group
     * @param integer $limit
     * @param string $lastId
     * @param boolean $next
     */
    public function search($keyword, $marketplace = null, $group = null, $limit = 5, $lastId = null, $next = false, $full = false): array
    {
        $op = ((bool) $next) ? '$gt' : '$lt';
        // if marketplace & group both are set
        if (!is_null($marketplace) && !is_null($group)) {
            $query = [
                "marketplace" => $marketplace,
                "group" => $group,
                "type" => "faq"
            ];
            $query = [...$query, ...$this->handleRegexSearch($keyword, $full)];
            if (!is_null($lastId))
                $query['_id'] = [
                    $op => new \MongoDB\BSON\ObjectId($lastId)
                ];
        }
        // if only marketplace set
        elseif (!is_null($marketplace)) {
            $query = [
                "marketplace" => $marketplace,
                "type" => "faq"
            ];
            $query = [...$query, ...$this->handleRegexSearch($keyword, $full)];
        }
        // if only group set
        elseif (!is_null($group)) {
            $query = [
                "group" => $group,
                "type" => "faq"
            ];
            $query = [...$query, ...$this->handleRegexSearch($keyword, $full)];
        } else {
            $query = [
                "type" => "faq"
            ];
            $query = [...$query, ...$this->handleRegexSearch($keyword, $full)];
        }

        $data = [];
        $totalCount = count($this->solutionModel->find($query, ['projection' => [
            'type' => 0,
        ], "sort" => ['_id' => 1]])) - $limit;
        $totalCount = $totalCount <= 0 ? 0 : $totalCount;

        $groups = [];
        foreach ($this->solutionModel->find($query, ["sort" => ['_id' => 1]]) as $doc) {
            array_push($groups, $doc->group);
        }

        $groups = array_unique($groups);
        foreach ($groups as $g) {
            $query["group"] = $g;
            foreach ($this->solutionModel->find($query, ["limit" => $limit, "sort" => ['_id' => 1]]) as $doc) {
                $x = $doc->getData();
                $tmp = $query;
                $tmp["group"] = $x["group"];
                $groupWiseTotal = count($this->solutionModel->find($tmp, ['projection' => [
                    'type' => 0,
                ], "sort" => ['_id' => 1]])) - $limit;
                if (!is_null($marketplace)) {
                    $groupName = $this->solutionModel->findFirst([
                        "type" => "group",
                        "marketplace" => $marketplace,
                        "group" => $doc->group,
                    ]);
                } else {
                    $groupName = $this->solutionModel->findFirst([
                        "type" => "group",
                        "group" => $doc->group
                    ]);
                }

                $data[$doc->marketplace][$doc->group]["group_name"] = $groupName->name;
                $data[$doc->marketplace][$doc->group]["next_page"] = $next ? ($totalCount ?  $x["_id"] : null) : ($groupWiseTotal <= 0 ? null : $x["_id"]);
                $data[$doc->marketplace][$doc->group]["data"][] = $x;
            }
        }

        return $data;
    }

    /**
     * get all faqs grouped
     *
     * @param array $data
     */
    public function getFaqs($marketplace, $limit = 5, $groupCode = null, $lastId = null, $next = false): array
    {
        $op = ((bool) $next) ? '$gt' : '$lt';
        $data = [];
        if (!is_null($groupCode)) {
            $groups = $this->solutionModel->find([
                "type" => "group",
                "group" => $groupCode,
                "marketplace" => $marketplace
            ]);
        } else {
            $groups = $this->solutionModel->find([
                "type" => "group",
                "marketplace" => $marketplace
            ]);
        }

        //getting groups
        foreach ($groups as $group) {
            $query = [
                "type" => "faq",
                "group" => $group->group,
                "marketplace" => $marketplace
            ];
            if (!is_null($lastId)) {
                $query['_id'] = [
                    $op => new \MongoDB\BSON\ObjectId($lastId)
                ];
            }

            //getting assosiated faqs
            $faqs = $this->solutionModel->find($query, [
                'limit' => $limit,
                'projection' => [
                    'group' => 0,
                    'type' => 0,
                ],
                "sort" => ['_id' => 1]
            ]);
            $totalFaqsCount = count($this->solutionModel->find($query, [
                'projection' => [
                    'group' => 0,
                    'type' => 0,
                ],
                "sort" => ['_id' => 1]
            ]));
            $totalFaqsCount = $limit ? ($totalFaqsCount - $limit) : 0;
            $totalFaqsCount = $totalFaqsCount <= 0 ? 0 : $totalFaqsCount;
            $tmp = [];
            foreach ($faqs as $faq) {
                array_push($tmp, $faq->getData());
            }

            if (!is_null($lastId)) {
                $data[$groupCode] = [
                    "next_page" => $next ? ($totalFaqsCount ? end($tmp)["_id"] : null) : end($tmp)["_id"],
                    "group_name" => $group->name,
                    "data" => $tmp
                ];
            } else {
                $data[$group->group] = [
                    "next_page" => $next ? ($totalFaqsCount ? end($tmp)["_id"] : null) : end($tmp)["_id"],
                    "group_name" => $group->name,
                    "data" => $tmp
                ];
            }
        }

        return [$marketplace => $data];
    }

    /**
     * creates FAQs in db
     */
    public function createFaq(array $data): int|bool
    {
        // if it's already exists, skip it
        if ($this->solutionModel->findFirst([
            "type" => "faq",
            "title" => $data["title"],
            "marketplace" => $data["marketplace"],
            "group" => $data["group"],

        ])) {
            return false;
        }

        // if group does't already exists
        if (!$this->solutionModel->findFirst([
            "type" => "group",
            "marketplace" => $data["marketplace"],
            "group" => $data["group"],
        ])) {
            if (!strlen(trim($data["group_name"])))
                return -1;

            $group = new \App\Connector\Models\Solution();
            $group->type = "group";
            $group->marketplace = $data["marketplace"];
            $group->group = $data["group"];
            $group->name = $data["group_name"];
            $group->save();
        }

        $faq = new \App\Connector\Models\Solution();
        $faq->type = "faq";
        $faq->title = $data["title"];
        $faq->answer = $data["answer"];
        $faq->marketplace = $data["marketplace"];
        $faq->group = $data["group"];
        $faq->code = $data["code"];
        var_dump($faq->save());
        return true;
    }

    /**
     * deleted a single faq
     *
     * @param array $data
     * @return mixed
     */
    public function deleteFaq($data)
    {
        $faq = new \App\Connector\Models\Solution();
        $ref = $faq->findFirst([
            "_id" => new \MongoDB\BSON\ObjectId($data),
            "type" => "faq"
        ]);
        if (!$ref)
            return false;

        // check if no faq associated with group
        $c = $faq->find([
            "group" => $ref->group,
            "type" => "faq"
        ]);
        // if count is 1 , delete group also
        if (count($c->toArray()) === 1) {
            $group = $faq->findFirst([
                "group" => $ref->group,
                "marketplace" => $ref->marketplace,
                "type" => "group"
            ]);
            if ($group)
                $group->delete();
        }

        return $ref->delete();
    }

    /**
     * updates a single faq by it's id
     *
     * @param array $data
     * @return mixed
     */
    public function updateFaq($data)
    {
        $fields = [
            "type",
            "title",
            "answer",
            "marketplace",
            "group",
        ];
        $faq = new \App\Connector\Models\Solution();
        $ref = $faq->findFirst([
            "_id" => new \MongoDB\BSON\ObjectId($data["_id"] ?? $data["id"] ?? null),
            "type" => "faq"
        ]);
        if (!$ref)
            return false;

        foreach ($fields as $field) {
            if ($data[$field] ?? null) {
                $ref->$field = $data[$field];
            }
        }

        unset($ref->id);
        if (!$faq->findFirst([
            "type" => "group",
            "group" => $data["group"],
            "marketplace" => $data["marketplace"],
            "name" => $data["group_name"],
        ])) {
            $faqGroup = new \App\Connector\Models\Solution();
            $faqGroup->type = "group";
            $faqGroup->group = $data["group"];
            $faqGroup->marketplace = $data["marketplace"];
            $faqGroup->name = $data["group_name"];
            $faqGroup->save();
        }

        return $ref->save();
    }

    public function getFaqByCode($code)
    {
        $faq = new \App\Connector\Models\Solution();
        try {
            $faq = $faq->findFirst([
                "code" => $code,
                "type" => "faq"
            ]);
            if ($faq) {
                $data = $faq->getData();
                unset($data["id"], $data["type"]);
                return $data;
            }
        } catch (\Exception) {
            return false;
        }

        return false;
    }

    /**
     * retrives all marketplaces from db
     */
    public function getAllMarketplaces(): array
    {
        $marketplaces = new \App\Connector\Models\Solution();
        $result = [];
        $res =  $marketplaces->find([
            "type" => "group"
        ], [
            "projection" => [
                "marketplace" => 1,
                "_id" => 0
            ]
        ]);
        foreach ($res as $doc) {
            if ($doc->marketplace !== null)
                $result[] = $doc->marketplace;
        }

        $result = array_unique($result);
        return array_values($result);
    }

    public function titleToCode($string): string
    {
        $title = "";
        foreach (explode(" ", $string) as $word) {
            $title .= strtolower(trim($word));
        }

        return $title;
    }

    public function handleRegexSearch($query, $full = false): array
    {
        $query = str_replace("$", '', $query);
        $query = str_replace("|", '', $query);
        $query = str_replace("^", '', $query);
        $query = preg_quote($query, '/');
        if (!$full) {
            $query = array_filter(explode(" ", $query), function ($x) {
                if (strlen(trim($x)))
                    return trim($x);
            });
            $query = join('|', $query);
        }

        return [
            '$or' => [

                [
                    'title' => new \MongoDB\BSON\Regex("{$query}", 'i')
                ],
                [
                    'answer' => new \MongoDB\BSON\Regex("{$query}", 'i')
                ],

            ]
        ];
    }

    public function checkUnique($code): bool
    {
        $db = new \App\Connector\Models\Solution();
        if ($db->findFirst(['type' => 'faq', 'code' => $code]))
            return false;

        return true;
    }

    public function trimTooLong($code)
    {
        return strlen($code) > 50 ? substr($code, 0, 50) . "...." : $code;
    }
}
