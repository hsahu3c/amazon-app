<?php
namespace App\Shopifyhome\Models;

use Exception;
use App\Core\Models\Base;

class FrontendCategories extends Base
{
    protected $table = 'frontend_categories';

    public static $defaultPageCount = 30;

    public $sqlConfig;

    public function initialize(): void
    {
//        $this->sqlConfig = $this->di->getObjectManager()->get('\App\Connector\Components\Data');
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }

    public function getAll($param) {
        $pageNum = $param['page_num'];
        $start = ($pageNum - 1) * self::$defaultPageCount;
        $end = self::$defaultPageCount;
        $query = 'SELECT gfc.*, wfc.path as walmart_category_path FROM `frontend_categories` as gfc LEFT JOIN `walmart_frontend_categories` as wfc ON gfc.walmart_category = wfc.id LIMIT ' . $start . ', ' . $end;
        $countQuery = 'SELECT COUNT(*) FROM `frontend_categories`';
        $user_db = $this->getMultipleDbManager()->getDb();
        try {
            $connection = $this->di->get($user_db);
            $attributes = $connection->fetchAll($query);
            $totalCount = $connection->fetchAll($countQuery);
            $toReturnData = [
                'attributes' => $attributes,
                'totalPages' => ceil($totalCount[0]['COUNT(*)']/self::$defaultPageCount)
            ];
            return ['success' => true, 'code' => 'engine_attributes', 'message' => 'Engine attributes', 'data' => $toReturnData];
        } catch(Exception) {
            return ['success' => false, 'code' => 'something_went_wrong', 'message' => 'Something went wrong'];
        }
    }

    public function updateMapping($googleCategories) {
        $user_db = $this->getMultipleDbManager()->getDb();
        $connection = $this->di->get($user_db);
        try {
            foreach ($googleCategories as $value) {
                $walmartModel = \App\Walmart\Models\FrontendCategories::findFirst("path='" . addslashes((string) $value['mappedCategory']) . "'");
                if ($walmartModel) {
                    $updateQuery = "UPDATE `frontend_categories` SET `walmart_category`= '" . $walmartModel->id . "' WHERE id = " . $value['id'];
                    $connection->query($updateQuery);
                }
            }

            return ['success' => true, 'message' => 'Mapping updated'];
        } catch(Exception) {
            return ['success' => false, 'code' => 'something_went_wrong', 'message' => 'Something went wrong'];
        }
    }


    public function getCategorySuggestions($engineCategories) {
        try {
            $user_db = $this->getMultipleDbManager()->getDb();
            $connection = $this->di->get($user_db);
            foreach ($engineCategories as $key => $engineCategoryDetails) {
                $engineCategoryPath = $engineCategoryDetails['path'];
                $query = "SELECT id, MATCH(`path`) AGAINST('" . addslashes((string) $engineCategoryPath) . "') relevancy,path FROM frontend_categories WHERE MATCH(`path`) AGAINST('" . addslashes((string) $engineCategoryPath) . "') ORDER BY `relevancy` DESC LIMIT 0, 20";
                $engineCategories[$key]['googleCategory'] = $connection->fetchAll($query);
            }

            return ['success' => true, 'code' => 'google_mapping_suggestions', 'message' => 'Google mapping suggestions', 'data' => $engineCategories];
        } catch(Exception) {
            return ['success' => false, 'code' => 'something_went_wrong', 'message' => 'Something went wrong'];
        }
    }

    public function getMatchingCategories($searchDetails) {
        $searchText = $searchDetails['search'];
        if ($searchText != '') {
            $user_db = $this->getMultipleDbManager()->getDb();
            $connection = $this->di->get($user_db);
            $query = "SELECT path FROM `frontend_categories` WHERE `path` LIKE '%" . addslashes((string) $searchText) . "%' LIMIT 0, 10";
            $searchedCategories = $connection->fetchAll($query);
            $toReturnCategories = [];
            foreach ($searchedCategories as $value) {
                $toReturnCategories[] = $value['path'];
            }

            return $toReturnCategories;
        }
        return [];
    }

}