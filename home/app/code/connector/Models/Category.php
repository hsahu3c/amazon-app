<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;

class Category extends BaseMongo
{
    protected $table = 'category';

    protected $isGlobal = true;

    public function getRootCategory($request)
    {
        if (isset($request['marketplace'])) {
            $marketplaceDependency = $this->checkDependentMarketplaces($request['marketplace']);
            if ($marketplaceDependency['success']) {
                $rootCat = $this->findByField([
                    'category_origin' => $marketplaceDependency['origin'],
                    'parent_id' => 0
                ]);
            } else {
                $rootCat = $this->findByField([
                    "marketplace" => $request['marketplace'],
                    "parent_id" => 0
                ]);
            }

            $returnData = [];

            foreach ($rootCat as $key => $value) {
                $filter = ["parent_id" => $value['_id']];
                $this->checkUserDependCategory($filter, $request);
                $exists = $this->loadByField($filter);
                $id = $value['_id'];
                unset($value['_id']);
                $returnData[$key] = $value;
                $returnData[$key]['is_child'] = 0;
                if ($exists) {
                    $returnData[$key]['is_child'] = 1;
                }

                $returnData[$key]['next_level'] = (string)$id;
            }

            return ['success' => true, 'message' => '', 'data' => $returnData];
        }
        return ['success' => false, 'code' => 'undefined_marketplace', 'message' => 'Marketplace is not defined'];
    }

    public function getChildCategory($request)
    {

        if (isset($request['next_level'])) {
            $mongoId = $request['next_level'];
            $filter = ['parent_id' => $mongoId];
            $this->checkUserDependCategory($filter, $request['marketplace']);
            $childCat = $this->findByField($filter);
            $returnData = [];
            foreach ($childCat as $key => $value) {
                $childCat_filter = ["_id" => $value['_id']];
                $this->checkUserDependCategory($childCat_filter, $request['marketplace']);
                $exists = $this->loadByField($childCat_filter);
                $id = $value['_id'];
                unset($value['_id']);
                $returnData[$key] = $value;
                $returnData[$key]['is_child'] = 0;
                if ($exists) {
                    $returnData[$key]['is_child'] = 1;
                }

                $returnData[$key]['next_level'] = (string)$id;
            }

            return ['success' => true, 'message' => '', 'data' => $returnData];
        }
        return ['success' => false, 'code' => 'next_level', 'message' => 'Next level key missing'];
    }

    public function searchCategory($request)
    {

        if (isset($request['filters'])) {
            $finalArr = [];

            $data = $request['filters'];
            if (isset($data['mapping'])) {
                foreach ($data['mapping'] as $marketplace => $oid) {
                    $finalArr['marketplace'] = $marketplace;
                    $finalArr['_id'] = new \MongoDB\BSON\ObjectId($oid);
                }
            } else {
                $finalArr = $data;
            }

            $params = [];
            $params = $this->prepareFilterParams($finalArr);
            $marketplaceDependency = $this->checkDependentMarketplaces($request['filters']['marketplace']);
            if ($marketplaceDependency['success']) {
                $params['category_origin'] = $marketplaceDependency['origin'];
                if(isset($params['marketplace'])) {
                    unset($params['marketplace']);
                }
            }

            $custom = [];
            if (isset($request['page'], $request['limit'])) {
                $limit = (int)$request['limit'];
                $page = (int)$request['page'] - 1;
                $skip = ($limit * $page) + 1;
            } else {
                $skip = 0;
                $limit = 300;
            }

            $custom['skip'] = $skip;
            $custom['limit'] = $limit;
            $searchCategory = $this->findByField($params, $custom);


            return ['success' => true, 'data' => $searchCategory];
        }
        return ['success' => false, 'code' => 'invalid filter', 'message' => 'any filters as key allowed'];
    }


    public function searchCategoryFilter()
    {
        return [
            'name' => function (array $filterParams): array {
                return [
                    'custom_category_path' => [
                        '$regex' => $filterParams['name'],
                        '$options' => 'i'
                    ]
                ];
            },
            'marketplace' => 1,
            'id' => function (array $filterParams): array {
                return [
                    '_id' => new \MongoDB\BSON\ObjectId($filterParams['id'])
                ];
            }
        ];
    }

    public function prepareFilterParams($filterParams)
    {
        $searchFilters = $this->searchCategoryFilter();

        foreach ($filterParams as $key => $value) {
            if (isset($searchFilters[$key])) {
                if ($searchFilters[$key] instanceof \Closure) {
                    if (call_user_func($searchFilters[$key], $filterParams)) {
                        $getData = call_user_func($searchFilters[$key], $filterParams);
                        $filterParams = array_merge($filterParams, $getData);
                        unset($filterParams[$key]);
                    }
                }
            }
        }

        return $filterParams;
    }




    public function getCategory($request)
    {
        if (!isset($request['filters'])) {

            $getCategory = $this->findByField($request['filters']);
            return ['success' => true, 'data' => $getCategory];
        }
        return ['success' => false, 'code' => 'invalid filter', 'message' => 'filter'];
    }



    public function createCategory($data)
    {

        foreach ($data as $value) {

            if (isset($value['marketplace'], $value['name'])) {

                $dataForSave = $value;
                $marketplace = $dataForSave['marketplace'];
                $this->extractCategory($dataForSave, $marketplace);
            } else {
                return ['success' => false, 'code' => 'required_param_missing', 'message' => 'marketplace/name missing', 'data' => []];
            }
        }

        return ['success' => true, 'message' => 'data inserted successfully', 'data' => []];
    }


    public function extractCategory($dataForSave, $marketplace): void
    {
        $dataForSave['marketplace'] = $marketplace;

        if (isset($dataForSave['children'])) {
            $childrens = $dataForSave['children'];
            unset($dataForSave['children']);
        }

        $dataForSave['parent_id'] = 0;
        $dataForSave['custom_category_path'] = $dataForSave['name'];

        $afterSavedData = $this->savedCategory($dataForSave);
        $parent_id = $afterSavedData['parent_id'];
        if (isset($childrens) && count($childrens)) {
            $childrenArr = [];
            $parent_id = $afterSavedData['parent_id'];
            $custom_category_path = $afterSavedData['custom_category_path'];

            foreach ($childrens as $children) {
                if (!isset($children['marketplace'])) {
                    $children['marketplace'] = $marketplace;
                }

                if (isset($children['children'])) {
                    $childrenArr[$children['name']] = $children['children'];
                    unset($children['children']);
                }

                $children['parent_id'] = $parent_id;
                $customPath = $custom_category_path . '>' . $children['name'];
                $children['custom_category_path'] = trim($customPath, '>');

                $afterSavedData = $this->savedCategory($children);
                if (isset($childrenArr[$children['name']])) {
                    $childrenArr[$children['name']]['parent_id'] = $afterSavedData['parent_id'];
                    $childrenArr[$children['name']]['custom_category_path'] = $afterSavedData['custom_category_path'];
                }
            }

            if (!empty($childrenArr)) {
                $this->traverseChildren($childrenArr, $marketplace);
            }
        }
    }


    public function traverseChildren($childrenArr, $marketplace)
    {
        try {
            $childrenArrNew = [];
            $childrenChange = 0;
            foreach ($childrenArr as $childrens) {
                if (isset($childrens['parent_id'])) {
                    $parent_id = $childrens['parent_id'];
                    unset($childrens['parent_id']);
                }

                if (isset($childrens['custom_category_path'])) {
                    $custom_category_path = $childrens['custom_category_path'];
                    unset($childrens['custom_category_path']);
                }

                foreach ($childrens as $children) {

                    $children['parent_id'] = $parent_id ?? 0;
                    $children['custom_category_path'] = isset($custom_category_path) ? trim($custom_category_path . '>' . $children['name']) : $children['name'];

                    if (!isset($children['marketplace'])) {
                        $children['marketplace'] = $marketplace;
                    }

                    if (isset($children['children'])) {
                        $childrenArrNew[$children['name']] = $children['children'];
                        unset($children['children']);
                    }

                    $afterSavedData = $this->savedCategory($children);
                    if (isset($childrenArrNew[$children['name']])) {
                        $childrenArrNew[$children['name']]['parent_id'] = $afterSavedData['parent_id'];
                        $childrenArrNew[$children['name']]['custom_category_path'] = $afterSavedData['custom_category_path'];
                    }
                }

                $childrenChange = 1;
            }

            if (!empty($childrenArrNew)) {
                return $this->traverseChildren($childrenArrNew, $marketplace);
            }
        } catch (\Exception $e) {
            return ['success' => false, 'code' => 'exceptions', 'message' => "something went wrong", "real_message" => $e->getMessage()];
        }
    }

    public function checkUserDependCategory(&$filter,&$data): void{
        if (!empty($this->di->getConfig()->user_depend_category)){
            if (!empty($this->di->getConfig()->user_depend_category->get($data['marketplace']))){
                $data['user_id'] ??= $this->di->getUser()->id;
                $filter['user_id'] = $this->di->getUser()->id;
            }
        }
    }

    public function savedCategory($data)
    {
        if (isset($data['marketplace_parent_id'])) {
            $filter = [
                "marketplace" => $data['marketplace'],
                "name" => $data['name'],
                "marketplace_parent_id" => $data['marketplace_parent_id']
            ];
            $this->checkUserDependCategory($filter, $data);
            $exists = $this->loadByField($filter);
        } else {
            $filter = [
                "marketplace" => $data['marketplace'],
                "name" => $data['name']
            ];
            $this->checkUserDependCategory($filter, $data);
            $exists = $this->loadByField($filter);
        }

        if ($exists) {
            if (isset($data['mapping'], $exists['mapping'])) {
                $exists['mapping'] = (array)$exists['mapping'];
                $data['mapping'] = array_merge($exists['mapping'], $data['mapping']);
            } elseif (isset($exists['mapping'])) {
                $exists['mapping'] = (array)$exists['mapping'];
                $data['mapping'] = $exists['mapping'];
            }

            $data['_id'] = $exists['_id'];
            if (isset($exists['parent_id'])) {
                $data['parent_id'] = $exists['parent_id'];
            }
        }

        $obj = $this->di->getObjectManager()->create('\App\Connector\Models\Category');
        $obj->setData($data);
        $obj->save();

        $savedData = $obj->getData();
        $savedData['parent_id'] = (string) $savedData['_id'];


        return $savedData;
    }


    public function deleteCategory($data)
    {
        $deleteData = 0;
        $notDeleteData = 0;
        foreach ($data as $value) {
            if (isset($value['marketplace'], $value['name'])) {
                $collection = $this->getCollection();
                $collection->deleteOne([
                    "marketplace" => $value['marketplace'],
                    "name" => $value['name']
                ], ['w' => true]);
                $deleteData++;
            } elseif (isset($value['marketplace_parent_id'])) {
                $collection = $this->getCollectioncreateCategory();
                $collection->deleteOne([
                    "marketplace_parent_id" => $value['marketplace_parent_id']
                ], ['w' => true]);
                $deleteData++;
            } else {
                $notDeleteData++;
            }
        }

        return ['success' => true, 'message' => 'data deleted successfully', 'data' => ['deleteData' => $deleteData, 'notDeleteData' => $notDeleteData]];
    }


    public function addChildren($data)
    {
        $error = [];



        foreach ($data as $value) {
            if (isset($value['marketplace'], $value['marketplace_parent_id'])) {
                $parent_filter = [
                    "marketplace" => $value['marketplace'],
                    "marketplace_id" => $value['marketplace_parent_id']
                ];
                $this->checkUserDependCategory($parent_filter, $value);
                $exists = $this->loadByField($parent_filter);
                if ($exists) {
                    if (!isset($value['level'])) $value['level'] = (int)$exists['level']+1;

                    if (!isset($value['full_path'])) $value['full_path'] = (int)$exists['full_path'].">".$value['name'];

                    $value['parent_id'] = (string) $exists['_id'];
                    $value['custom_category_path'] = $exists['custom_category_path'] . '>' . $value['name'];
                    $obj = $this->di->getObjectManager()->create('\App\Connector\Models\Category');
                    $children_filter = [
                        "marketplace" => $value['marketplace'],
                        "marketplace_id" => $value['marketplace_id'],
                        "marketplace_parent_id"=>$value['marketplace_parent_id']
                    ];
                    $this->checkUserDependCategory($children_filter, $value);
                    $isChildrenExists = $this->loadByField($children_filter);
                    if ($isChildrenExists){
                        $value['_id'] = $isChildrenExists['_id'];
                    }

                    $obj->setData($value);
                    $obj->save();
                    if (!$obj->save()) {
                        $error[] = ['message' => 'invalid data', 'data' => $value];
                    }
                } else {
                    $error[] = ['message' => 'invalid marketplace_parent_id ', 'data' => $value];
                }
            } else {
                $error[] = ['message' => 'invalid message', 'data' => $value];
            }
        }

        return ['success' => true, 'message' => 'data insert successfully', 'data' => ['error' => $error]];
    }

    public function checkDependentMarketplaces($marketplace)
    {
        if (!empty($this->di->getConfig()->dependent_marketplace_categories)){
            $categoryConfig = $this->di->getConfig()->dependent_marketplace_categories;
            if (!empty($categoryConfig->get($marketplace)) && !empty($categoryConfig->get($marketplace)->get('origin'))) {
                return [
                    'success' => true,
                    'origin' => strtolower($this->di->getConfig()->dependent_marketplace_categories->get($marketplace)->get('origin'))
                ];
            }
        }

        return [
            'success' => false,
            'message' => 'This marketplace does not depend on any origin category'
        ];
    }
}
