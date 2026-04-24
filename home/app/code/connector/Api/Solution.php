<?php

namespace App\Connector\Api;


/**
 * solution @api
 * FAQs @api
 */
class Solution extends \Phalcon\Di\Injectable
{
    /**
     * searches the asked error code in db
     */
    public function get($data): array
    {
        $solutionComponent = $this->di->getObjectManager()->get("\App\Connector\Components\Solution");
        foreach ($data as &$solution) {
            if (trim($solution["title"] ?? null)) {
                // if got title , convert it to code
                $solution["code"] = $solutionComponent->titleToCode($solution["title"]);
            }
        }

        if (!count($data))
            return ["success" => false, "msg" => "data is missing"];

        $resp = $solutionComponent->getSolutions($data);
        if (!$resp)
            return ["success" => false, "msg" => "invalid data"];

        return ["success" => true, "data" => $resp];
    }

    /**
     * get All solutions from db
     */
    public function getAll($data): array
    {
        $resp = $this->di->getObjectManager()->get("\App\Connector\Components\Solution")->getAllSolutions($data);
        if ($resp === false) {
            return ["success" => false, "msg" => "invalid id"];
        }

        return ["success" => true, "data" => $resp];
    }

    /**
     * creates a new solution
     */
    public function create($data): array
    {
        $solutionComponent = $this->di->getObjectManager()->get("\App\Connector\Components\Solution");
        $marketplace = $data["marketplace"] ?? null;
        $entity = $data["entity"] ?? null;
        $title = $data["title"] ?? null;
        // if got title but not code, convert the title to code
        if (!trim($data["code"] ?? null)) {
            if (trim($data["title"] ?? null)) {
                // if got title , convert it to code
                $code = $solutionComponent->titleToCode($data["title"]);
                $data["code"] = $solutionComponent->titleToCode($data["title"]);
            }
        }

        $code ??= $data["code"] ?? null;
        $answer = $data["answer"] ?? null;
        if (empty($code) || empty($entity) || empty($answer))
            return ["success" => false, "msg" => "missing required fields."];

        if ($solutionComponent->solutionExists($data))
            return ["success" => false, "err" => isset($data["marketplace"]) ? "code {$code} already exists in marketplace {$data["marketplace"]}." : "code {$code} already exists."];

        $resp = $solutionComponent->setSolution($code, $marketplace, $entity, $title, $answer);
        return ["success" => $resp, "msg" => $resp ? "code {$code} created successfully" : "code {$code} already exists"];
    }

    /**
     * updates error code's description and entity
     *
     */
    public function update($data): array
    {
        $solutionComponent = $this->di->getObjectManager()->get("\App\Connector\Components\Solution");
        if (!trim($data["code"] ?? null)) {
            if (trim($data["title"] ?? null)) {
                // if got title , convert it to code
                $data["code"] = $solutionComponent->titleToCode($data["title"]);
            }
        }

        $code = $data["code"] ?? null;
        $answer = $data["answer"] ?? null;
        if (empty($code) || empty($answer))
            return ["success" => false, "msg" => "missing required fields."];

        $resp = $solutionComponent->updateSolution($data);
        $code = $solutionComponent->trimTooLong($code);
        if ($resp)
            return ["success" => $resp, "msg" => "code {$code} updated successfuly."];

        if (isset($data["marketplace"]))
            return ["success" => $resp, "msg" => "code {$code} does not exists in marketplace {$data["marketplace"]}."];

        return ["success" => $resp, "msg" => "code {$code} does not exists."];
    }

    /**
     * deletes a single doc by code
     */
    public function delete($data): array
    {
        $solutionComponent = $this->di->getObjectManager()->get("\App\Connector\Components\Solution");
        if (!trim($data["code"] ?? null)) {
            if (trim($data["title"] ?? null)) {
                // if got title , convert it to code
                $data["code"] = $solutionComponent->titleToCode($data["title"]);
            }
        }

        $code = $data['code'] ?? null;
        if (!strlen($code))
            return [
                "sucess" => false,
                "msg" => "code is required"
            ];

        $resp = $solutionComponent->delete($data);
        $code = $solutionComponent->trimTooLong($code);
        if ($resp)
            return [
                "success" => true,
                "msg" => "{$code} deleted successfuly."
            ];

        return [
            "success" => false,
            "msg" => isset($data["marketplace"]) ? "{$code} in marketplace {$data["marketplace"]} does not exists." : "{$code} does not exists."
        ];
    }

    /**
     * faq endpoint
     */
    public function search($data): array
    {
        if (!count($data))
            return ["success" => false, "msg" => "either keyword or marketplace is required."];

        if (!isset($data["keyword"])) {
            $marketplace = $data["marketplace"] ?? null;
            $group = $data["group"] ?? null;
            $limit = (int)$data["limit"] ?? 5;
            $lastId = $data["lastId"] ?? null;
            $next = ($data["next"] ?? "true") == "true" ? true : false;
            return ["success" => true, "data" => $this->di->getObjectManager()->get("\App\Connector\Components\Solution")->getFaqs(
                $marketplace,
                $limit,
                $group,
                $lastId,
                $next
            )];
        }

        $keyword = $data["keyword"];
        $marketplace = $data["marketplace"] ?? null;
        $group = $data["group"] ?? null;
        $limit = $data["limit"] ?? 5;
        $lastId = $data["lastId"] ?? null;
        $next = ($data["next"] ?? null) == "true" ? true : false;
        $full = ($data["exact"] ?? null) ? true : false;
        $resp = $this->di->getObjectManager()->get("\App\Connector\Components\Solution")->search($keyword, $marketplace, $group, $limit, $lastId, $next, $full);
        return ["success" => true, "data" => $resp];
    }

    /**
     * creates faq
     *
     * @param [type] $data
     */
    public function createfaq($data): array
    {
        $errors = [];
        $required = [
            "title",
            "answer",
            "marketplace",
            "group",
            "code"
        ];
        // check if code is already in use
        if (!$this->di->getObjectManager()->get("\App\Connector\Components\Solution")->checkUnique($data['code'])) {
            return [
                'success' => false,
                'msg' => "{$data['code']} is already in use."
            ];
        }

        foreach ($required as $req) {
            if (!key_exists($req, array_keys($data)) && !strlen(trim($data[$req] ?? null)))
                array_push($errors, "{$req} is required");
        }

        if ($errors !== [])
            return ["success" => false, "msg" => $errors];

        $resp = $this->di->getObjectManager()->get("\App\Connector\Components\Solution")->createFaq($data);
        if ($resp === -1)
            return ["success" => false, "msg" => "group_name is required."];

        if ($resp)
            return ["success" => true, "msg" => "FAQ successfully created"];

        return ["success" => false, "msg" => "FAQ already exists"];
    }

    /**
     * deletes a single faq by it's id
     *
     * @param array $data
     */
    public function deleteFaq($data): array
    {
        $id = $data["_id"] ?? $data["id"] ?? null;
        if (!$id)
            return ["success" => false, "msg" => "id is required"];

        $res = $this->di->getObjectManager()->get("\App\Connector\Components\Solution")->deleteFaq($id);
        if ($res)
            return ["success" => true, "msg" => "faq {$id} deleted successfully"];

        return ["success" => false, "msg" => "faq {$id} does't exists"];
    }

    /**
     * updates a single faq by it's id
     *
     * @param array $data
     */
    public function updateFaq($data): array
    {
        $id = $data["_id"] ?? $data["id"] ?? null;
        if (!$id)
            return ["success" => false, "msg" => "id is required"];

        $res = $this->di->getObjectManager()->get("\App\Connector\Components\Solution")->updateFaq($data);
        if ($res)
            return ["success" => true, "msg" => "faq {$id} updated successfully"];

        return ["success" => false, "msg" => "faq {$id} does't exists"];
    }

    public function marketplaces(): array
    {
        $res = $this->di->getObjectManager()->get("\App\Connector\Components\Solution")->getAllMarketplaces();
        return [
            "success" => true,
            "data" => $res
        ];
    }

    public function getFaqByCode($data): array
    {
        $code = $data["code"] ?? null;
        if ($code === null)
            return [
                "success" => false,
                "msg" => "code is missing"
            ];

        $res = $this->di->getObjectManager()->get("\App\Connector\Components\Solution")->getFaqByCode($code);
        if ($res === false)
            return [
                "success" => false,
                "msg" => "faq does't exists"
            ];

        return [
            "success" => true,
            "data" => $res
        ];
    }

    public function code($data): array
    {
        if (!strlen(trim($data["code"] ?? null)))
            return [
                'success' => false,
                'msg' => 'field code is required'
            ];

        $res = $this->di->getObjectManager()->get("\App\Connector\Components\Solution")->checkUnique($data['code']);
        return ['success' => $res, "msg" => $res ? "ok" : "code {$data['code']} is already in use."];
    }
}
