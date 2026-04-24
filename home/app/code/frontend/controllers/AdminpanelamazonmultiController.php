<?php

namespace App\Frontend\Controllers;

use App\Core\Controllers\BaseController;
use App\Frontend\Components\AdminpanelamazonmultiHelper;
use App\Core\Models\User;
use MongoDB\BSON\ObjectId;
use Exception;
use App\Frontend\Components\AmazonMulti\Helper;

use Aws\Sqs\SqsClient;
use Aws\Exception\AwsException;

class AdminpanelamazonmultiController extends BaseController
{

    /**
     * necessary functions
     */
    public function getAPIRequestData()
    {
        $rawBody = [];
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        return $rawBody;
    }

    public function getHelperClass($classname)
    {
        return $this->di->getObjectManager()->get('\App\Frontend\Components\AmazonMulti\\' . $classname);
    }

    /**
     * necessary functions ends
     */

    public function getUsersDataAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getUsersData($rawBody));
    }


    public function getShopProductCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getUserProductsCount($rawBody['user_id'], $rawBody['shop_id']));
    }

    public function getTargetOrdersCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getSingleUserOrdersCount($rawBody['user_id'], $rawBody['shop_id']));
    }

    public function getAdminConfigAction()
    {
        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getAdminConfig());
    }

    public function getAppConfigAction()
    {
        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getAppConfig());
    }

    public function addAdminConfigAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->addAdminConfig($rawBody));
    }

    public function addAppConfigAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->addAppConfig($rawBody));
    }

    public function getAllUserFailedOrdersCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getAllUserFailedOrdersCount($rawBody));
    }

    public function getAppOrdersCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getAppOrdersCount($rawBody));
    }

    public function getUserTypeCountAction()
    {
        // die("Sdf");
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getUserTypeCount($rawBody));
    }



    public function getFailedOrderAction()
    {
        // die("Sdf");
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getFailedOrder($rawBody));
    }


    public function getStepCompletedCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getStepCompletedCount($rawBody));
    }

    public function setAdminConfigAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->setAdminConfig($rawBody));
    }

    public function InventorySyncShopifyAmazonAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $userId = $rawBody['user'];
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable('product_container');

        $query = [];
        $query[] = ['$match' => ['user_id' => $userId, 'marketplace.amazon.status' => ['$exists' => true], 'quantity' => ['$exists' => true]]];
        $query[] = ['$match' => ['marketplace.amazon.status' => ['$in' => ['Active', 'Inactive', 'Incomplete', 'Uploaded']]]];

        $productData = $productCollection->aggregate($query, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        $productData = $productData->toArray();

        $chunkData = array_chunk($productData, 25);

        foreach ($chunkData as $product) {
            $sourceProductIds = [];

            foreach ($product as $value) {
                // echo PHP_EOL;
                $sourceProductIds['source_product_ids'][] = $value['source_product_id'];
            }

            $inventoryComponent = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\InventoryWebhook');

            $return = $inventoryComponent->init(['user_id' => $rawBody['user']])->updateNew($sourceProductIds);
            // echo "innerloop complete";
            // echo PHP_EOL;
            sleep(1);
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        $helper->createActivityLog(["action" => "InventorySyncShopifyAmazon", "payload" => ["message" => "inProgress"]]);

        return ['success' => true, "message" => "inprogress"];
        die("progress");
    }

    public function getLoginAsUserUrlAction()
    {
        // print_r($this->di->getConfig()->live_login_url);
        // die();
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getLoginAsUserUrl($rawBody));
    }

    public function orderStatusAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $user_id = $this->di->getUser()->id;

        $id = $rawBody['user_id'] ?? $user_id;

        $test = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        $res = $test->orderStatusFile($id, $rawBody);
        return $this->prepareResponse($res);
    }

    public function editAdminConfigAction()
    {
        // die('fg');
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->editAdminConfig($rawBody));
    }

    public function importAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!isset($rawBody['user_id'])) {
            return ['success' => false, 'message' => 'required params missing'];
        }

        $user = User::findFirst([['_id' => new ObjectId($rawBody['user_id'])]]);
        if (!$user) {
            return ['success' => false, 'message' => 'user not found'];
        }

        $user->id = (string)$user->_id;
        $this->di->setUser($user);
        $product = $this->di->getObjectManager()->create('\App\Connector\Models\ProductContainer');

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        $helper->createActivityLog(["action" => "import", "payload" => ["message" => "product import initiated"]]);

        return $this->prepareResponse($product->importProducts($rawBody));
    }

    public function exportCSVAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);

        $returnArr = $helper->exportCSV($rawBody);
        return $this->prepareResponse(['data' => $returnArr]);
    }

    public function downloadCSVAction(): void
    {
        // die("dsg");
        $shipmentFileToken = $this->di->getRequest()->get('file_token');
        try {
            $token = $this->di->getObjectManager()->get('App\Core\Components\Helper')->decodeToken($shipmentFileToken, false);
            $filePath = $token['data']['file_path'];
            $tempFilePath = explode('.', (string) $filePath);
            $extension = 'csv';
            if (file_exists($filePath)) {
                header('Content-Type: ' . 'csv' . '; charset=utf-8');
                header('Content-Disposition: attachment; filename=csv_file' . time() . '.' . $extension);
                @readfile($filePath);
                // unlink($filePath);
                die;
            }
            die('Invalid Url. No report found.');
        } catch (Exception) {
            die('Invalid Url. No report found.');
        }
    }

    public function importCSVAction()
    {
        // die("ds");
        $rawBody = $this->request->getJsonRawBody(true);
        $getParams = $this->di->getRequest()->get();
        $userId = $this->di->getUser()->id;
        $appTag = $this->di->getAppCode()->getAppTag();
        // die($appTag);
        $path = BP . DS . 'var/file/';
        $files = $this->request->getUploadedFiles();
        $name = $userId . ".csv";

        // print_r($files);
        // die;

        foreach ($files as $file) {
            if ($file->getType() !== "text/csv") {
                return $this->prepareResponse(['error' => "file type not csv"]);
            }

            $file->moveTo(
                $path . $name
            );
        }

        $count = 0;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $file = fopen($path . $name, 'r');
        while (($handle = fgetcsv($file)) !== false) {
            if ($count == 0) {
                $collection->updateOne([
                    'user_id' => $userId
                ], [
                    '$set' => [
                        'CSVcolumn' => json_encode($handle)
                    ]
                ]);
            }

            $count++;
        }

        fclose($file);
        // die("done");
        $rawBody['count'] = $count;
        $rawBody['path'] = $path;
        $rawBody['name'] = $name;

        $profileModel = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        // TODO : change the name
        $returnArr = $profileModel->importCSVAndUpdate($rawBody);
        return $this->prepareResponse(['data' => $returnArr]);

        die();
    }

    public function userSyncAction()
    {
        // die('sdf');
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);

        return match ($rawBody['sync_type']) {
            'order_sync' => $this->prepareResponse($helper->orderSync($rawBody)),
            'shipment_sync' => $this->prepareResponse($helper->shipmentSync($rawBody)),
            'register_order_sync' => $this->prepareResponse($helper->registerForOrderSync($rawBody)),
            default => $this->prepareResponse(['success' => false, 'message' => 'wrong sync type']),
        };
        die;
    }


    public function getOrderAnalysisAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getOrderAnalysis($rawBody));
    }



    public function getTotalOrdersAction()
    {
        // die("Sdf");
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getTotalOrders($rawBody));
    }

    // Plans
    // product
    public function getProductAction()
    {
        // die("Sdf");
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getProduct($rawBody));
    }


    public function validateDevAction()
    {
        // die("here");
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (isset($rawBody['password']) && $rawBody['password'] === "+n6voUUfLIUgG0WefccsqCfTukeGNHkxBUh7tJ4/cH8PH3T1fZ8t1pBmkTy/9FJT") {
            return $this->prepareResponse(['success' => 'true']);
        }

        return $this->prepareResponse(['success' => false, 'message' => "You are not authorised!!!"]);
    }







    public function getMigrationCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getMigrationCount($rawBody));
    }


    public function syncUserCollectionsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->syncUserCollections($rawBody));
    }

    public function getMigrationGridAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getMigrationGrid($rawBody));
    }

    public function getAllMigrationRelatedCountsAction()
    {
        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getAllMigrationRelatedCounts($rawBody));
    }

    public function getRefineUsersDataAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getRefineUsersData($rawBody));
    }

    public function getRefineFilteredCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getRefineFilteredCount($rawBody));
    }

    public function updateFaqAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->updateFaq($rawBody));
    }

    // reporting section 

    public function getUserCsvDownloadUrlAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getUserCsvDownloadUrl($rawBody));
    }








    /**
     * Settlement Amount Starts
     */

    public function getSettlementAmountHelper()
    {
        return $this->getHelperClass('SettlementAmountsHelper');
    }

    public function getSettleMentAmountAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getSettlementAmountHelper();
        return $this->prepareResponse($helper->getSettleMentAmounts($rawBody));
    }

    public function getSettlementGridAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getSettlementAmountHelper();
        return $this->prepareResponse($helper->getSettlementGrid($rawBody));
    }

    public function getSettlementGridCountAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getSettlementAmountHelper();
        return $this->prepareResponse($helper->getSettlementGridCount($rawBody));
    }

    public function getOrderUsageAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getSettlementAmountHelper();
        return $this->prepareResponse($helper->getOrderUsage($rawBody));
    }

    /**
     * Settlement Amount Ends
     */



    /**
     * exhausted limit start
     */

    public function getExhaustedGridHelper()
    {
        return $this->getHelperClass('ExhaustedGridHelper');
    }

    public function getExhaustedLimitAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getExhaustedGridHelper();
        return $this->prepareResponse($helper->getExhaustedLimit($rawBody));
    }

    public function getExhaustedLimitCountAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getExhaustedGridHelper();
        return $this->prepareResponse($helper->getExhaustedLimitCount($rawBody));
    }

    /**
     * exhausted limit end
     */



    /**
     * knowledge base starts
     */
    public function getKnowledgeBaseHelper()
    {
        return $this->getHelperClass('KnowledgeBaseHelper');
    }

    public function getKnowledgeBaseAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getKnowledgeBaseHelper();

        return $this->prepareResponse($helper->getKnowledgeBase($rawBody));
    }

    public function createKnowledgeBaseAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getKnowledgeBaseHelper();
        return $this->prepareResponse($helper->createKnowledgeBase($rawBody));
    }

    public function updateKnowledgeBaseAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getKnowledgeBaseHelper();
        return $this->prepareResponse($helper->updateKnowledgeBase($rawBody));
    }

    public function deleteKnowledgeBaseAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getKnowledgeBaseHelper();
        return $this->prepareResponse($helper->deleteKnowledgeBase($rawBody));
    }

    public function sectionWiseKnowledgeBaseAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getKnowledgeBaseHelper();
        return $this->prepareResponse($helper->sectionWiseKnowledgeBase($rawBody));
    }

    public function getKnowledgeBaseSectionsAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getKnowledgeBaseHelper();
        return $this->prepareResponse($helper->getKnowledgeBaseSections($rawBody));
    }

    public function searchKnowledgeBaseAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getKnowledgeBaseHelper();
        return $this->prepareResponse($helper->searchKnowledgeBase($rawBody));
    }

    public function getKnowledgeBaseCountAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getKnowledgeBaseHelper();
        return $this->prepareResponse($helper->getKnowledgeBaseCount($rawBody));
    }

    /**
     * knowledge base ends
     */



    /**
     * BDA Access Controls starts
     */

    public function getBDAHelper()
    {
        return $this->getHelperClass('BdaAccessHelper');
    }

    public function getBDAsAction()
    {
        $helper = $this->getBDAHelper();
        return $this->prepareResponse($helper->getBDAs());
    }

    public function createBDAAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getBDAHelper();
        return $this->prepareResponse($helper->createBDA($rawBody));
    }

    public function updateStatusBDAAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getBDAHelper();
        return $this->prepareResponse($helper->updateStatusBDA($rawBody));
    }

    public function createActivityLogAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->di->getObjectManager()->get(Helper::class);
        return $this->prepareResponse($helper->createActivityLog($rawBody));
    }

    public function getActivityLogsAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getBDAHelper();
        return $this->prepareResponse($helper->getActivityLogs($rawBody));
    }

    public function getUserActivityLogsAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getBDAHelper();
        return $this->prepareResponse($helper->getUserActivityLogs($rawBody));
    }

    public function getActionTypesAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getBDAHelper();
        return $this->prepareResponse($helper->getActionTypes($rawBody));
    }

    public function getBdaAclAction()
    {
        $rawBody = $this->getAPIRequestData();
        $helper = $this->getBDAHelper();
        return $this->prepareResponse($helper->getBdaAcl($rawBody));
    }

    /**
     * BDA Access Control ends
     */


    /**
     * Dashboard Actions starts
     */

    private function getDashboardHelper()
    {
        return $this->getHelperClass('DashboardHelper');
    }

    public function getInstallationStatsAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getInstallationStats', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getDailyTopSalesAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getDailyTopSales', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getMonthlyTopSalesAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getMonthlyTopSales', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getUserConnectedAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getUserConnected', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getUserPlanAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getUserPlan', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getAmazonLocationAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getAmazonLocation', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getPlanDetailsAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getPlanDetails', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getSalesAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getSales', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getSalesCountAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getSalesCount', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getUninstallGridAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getUninstallGrid', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getUninstallGridCountAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getUninstallGridCount', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getFreePlanGridAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getFreePlanGrid', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getFreePlanGridCountAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getFreePlanGridCount', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getPaidPlanGridAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getPaidPlanGrid', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getPaidPlanGridCountAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getDashboardHelper()->getMethod('getPaidPlanGridCount', $rawBody);
        return $this->prepareResponse($response);
    }

    /**
     * Dashboard Actions ends
     */


    /**
     * Order Analysis Helper starts
     */
    private function getOrderAnalysisHelper()
    {
        return $this->getHelperClass('OrderAnalysisHelper');
    }

    public function getYearlyOrMonthlyOrdersAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getOrderAnalysisHelper()->getMethod('getYearlyOrMonthlyOrders', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getPlanWiseOrdersDataAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getOrderAnalysisHelper()->getMethod('getPlanWiseOrdersData', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getUserOrdersCountAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getOrderAnalysisHelper()->getMethod('getUserOrdersCount', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getUsernamesAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getOrderAnalysisHelper()->getMethod('getUsernames', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getUserConnectedAmazonAccountsAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getOrderAnalysisHelper()->getMethod('getUserConnectedAmazonAccounts', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getAmazonAccountWiseOrdersAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getOrderAnalysisHelper()->getMethod('getAmazonAccountWiseOrders', $rawBody);
        return $this->prepareResponse($response);
    }


    /**
     * Order Analysis Helper Ends
     */



    /**
     * Supercharge Actions starts
     */

    private function getSuperChargeHelper()
    {
        return $this->getHelperClass('SuperChargeHelper');
    }

    public function getSuperChargeUsersAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getSuperChargeHelper()->getMethod('getSuperChargeUsers', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getSuperChargeUsersCountAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getSuperChargeHelper()->getMethod('getSuperChargeUsersCount', $rawBody);
        return $this->prepareResponse($response);
    }

    /**
     * Supercharge Actions ends
     */





    public function getUserWiseChartDataAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getUserWiseChartData($rawBody));
    }

    public function validateWebhooksAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class)
            ->validateWebhooks($rawBody);
        return $this->prepareResponse($response);
    }

    /**
     * Category helper Action start
     */

    /**
     * This function is used to get requirement condition of selected target attributes.
     *
     * @return array
     */
    public function getTargetSelectedAttributesAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getTargetSelectedAttributes($rawBody));
    }

    /**
     * This function is used to get requirement condition of selected target attributes.
     *
     * @return array
     */
    public function changeAttributeRequirementAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->changeAttributeRequirement($rawBody));
    }

    /**
     * Category helper Action end
     */

    public function addUserResetKeyAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->addUserResetKey($rawBody));
    }

    public function getAllInterviewUsersAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->getAllInterviewUsers($rawBody));
    }

    public function saveInterviewUserAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->saveInterviewUser($rawBody));
    }

    public function deleteInterviewUserAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->deleteInterviewUser($rawBody));
    }

    public function showOnlyGridAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class);
        return $this->prepareResponse($helper->showOnlyGrid($rawBody));
    }

    public function confirmShipmentHistoryAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(AdminpanelamazonmultiHelper::class)
            ->confirmShipmentHistory($rawBody);
        return $this->prepareResponse($response);
    }

    public function getPlanInsightsAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getPaymentHelper()->getMethod('getPlanInsights', $rawBody);
        return $this->prepareResponse($response);
    }

    public function getTopPlanUsersAction()
    {
        $rawBody = $this->getAPIRequestData();
        $response = $this->getPaymentHelper()->getMethod('getTopPlanUsers', $rawBody);
        return $this->prepareResponse($response);
    }

    private function getPaymentHelper()
    {
        return $this->getHelperClass('PaymentHelper');
    }

    public function fetchLateSyncOrderByFilterAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get('\App\Amazon\Components\Order\LateOrderAnalysis')
            ->fetchLateSyncOrderByFilter($rawBody);
        return $this->prepareResponse($response);
    }

        public function getStuckQueueTaskDataAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable('queued_tasks');
        $dateBefore6Hours = (new \DateTime('-6 hours', new \DateTimeZone('UTC')))->format('c');
        $query = [];
        $query[] = ['$match' => ['created_at' => [ '$lte' => $dateBefore6Hours ] ]];
        $query[] = ['$sort' => ['created_at' => 1 ]];

        if ( isset($rawBody['user_id']) && !empty($rawBody['user_id']) ) {
            if ( is_array($rawBody['user_id']) ) {
                $query[] = ['$match' => ['user_id' => ['$in' => $rawBody['user_id']] ]];
            } else {
                $query[] = ['$match' => ['user_id' => $rawBody['user_id'] ]];
            }
        }

        $query[] = [
            '$lookup' => [
                'from' => 'user_details',
                'localField' => 'user_id',
                'foreignField' => 'user_id',
                'as' => 'user_details',
            ],
        ];

        $query[] = [
            '$addFields' => [
                'user_id' => ['$arrayElemAt' => ['$user_details.user_id', 0]],
                'username' => ['$arrayElemAt' => ['$user_details.username', 0]],
                'name' => ['$arrayElemAt' => ['$user_details.name', 0]],
            ],
        ];

        $query[] = [
            '$project' => [
                'user_details' => 0,
            ],
        ];

        $productData = $productCollection->aggregate($query, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        $productData = $productData->toArray();
        
        return $this->prepareResponse([
            'success' => true,
            'data' => $productData
        ]);
    }

    public function deleteStuckQueueTaskDataAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $productCollection = $mongo->getCollectionForTable('queued_tasks');

        $productData = $productCollection->deleteMany(['_id' => new \MongoDB\BSON\ObjectID($rawBody['_id']) ]);
        
        return $this->prepareResponse([
            'success' => true,
        ]);
    }

    
    public function leadAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $requester = $this->di->getRequester();
        $response = $this->sendLeadMail($rawBody);
        return $this->prepareResponse($response);
    }

    public function sendLeadMail($data){

        // print_r($data);die;
        if(!isset($data['email'])){
        return ['success'=>false,'message'=>'Email is required'];

        }
        // $emailData['email'] = 'anssingh@threecolts.com';
        $emailData['email'] = 'support@cedcommerce.com';
        $emailData['subject'] = 'Lead For Amazon';
        $emailData['bccs'] = ["kgupta@threecolts.com", "adogra@threecolts.com"];//$this->di->getConfig()->allowed_ip_mail;
        $emailData['replyTo']['replyToEmail']=$data['email'];
        $emailData['replyTo']['replyToName']=$data['firstName']??$data['first_name'];

        $emailData['path'] =  'amazon' . DS . 'view' . DS . 'email' . DS . 'leadmail.volt.php';
        $emailBody = '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">';
        $emailBody .= '<tr><th>Key</th><th>Value</th></tr>'; // Table header
        
        foreach ($data as $key => $value) {
            $emailBody .= "<tr>
                            <td style='font-weight: bold;'>$key</td>
                            <td>$value</td>
                          </tr>";
        }
        
        $emailBody .= '</table>';
        $emailData['body']=$emailBody;

     
        $sendMailResponse = $this->di->getObjectManager()->create('\App\Core\Components\SendMail')->send($emailData);
        return ['success'=>true,'message'=>'We will connect with you '];

    }

    public function getSQSQueueDataAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        // if (str_contains((string) $contentType, 'application/json')) {
        //     return $this->request->getJsonRawBody(true);
        // } else {
        //     return $this->request->get();
        // }
        try {
            $sqsClient = new SqsClient(include BP . '/app/etc/aws.php');

            // suffix filter (change to what you want, e.g. '-prod')
            $prefixFilter = $this->di->getConfig()->get('app_code') ?? ""; // e.g. '-prod'
            // var_dump($suffixFilter);die;

            $result = $sqsClient->listQueues();

            $queueData = [];
            $skiped = [];

            if (isset($result['QueueUrls'])) {
                foreach ($result['QueueUrls'] as $queueUrl) {
                    $queueName = basename($queueUrl); // get queue name only

                    // Apply suffix filter
                    if ($prefixFilter && strpos($queueName, $prefixFilter) !== 0) {
                        $skiped[] = $queueName;
                        continue;
                    }

                    $attributes = $sqsClient->getQueueAttributes([
                        'QueueUrl' => $queueUrl,
                        'AttributeNames' => [
                            'ApproximateNumberOfMessages',
                            'ApproximateNumberOfMessagesNotVisible',
                            'ApproximateNumberOfMessagesDelayed'
                        ],
                    ]);

                    $attrs = $attributes->get('Attributes');

                    $queueData[] = [
                        'name'  => $queueName,
                        'total' => (int)$attrs['ApproximateNumberOfMessages'] + (int)$attrs['ApproximateNumberOfMessagesNotVisible'] + (int)$attrs['ApproximateNumberOfMessagesDelayed'],
                        'value' => [
                            'available' => (int)$attrs['ApproximateNumberOfMessages'],
                            'in_flight' => (int)$attrs['ApproximateNumberOfMessagesNotVisible'],
                            'delayed'   => (int)$attrs['ApproximateNumberOfMessagesDelayed']
                        ]
                    ];
                }
            }

            return $this->prepareResponse([
                'success' => true,
                'data' => $queueData,
                'prefix' => $prefixFilter,
                'message' => 'SQS queue data fetched successfully',
                // Uncomment the next line if you want to return skipped queues
                // 'skiped' => $skiped
            ]);

        } catch (AwsException $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => 'Error fetching SQS queue data: ' . $e->getMessage()
            ]);
        }
    }

    public function getPathsDiskUsageAction()
    {
        $results = $this->di->getCache()->get('disk_usage_adminpanel');
        if ($results && $results['ttl'] > time()) {
            return $this->prepareResponse([
                'success' => true,
                'data' => $results,
                'from_cache' => true,
                'message' => 'Disk usage fetched successfully'
            ]);
        }
        $results = [];
        $paths = ['/', '/var/www/html/home/var/log', '/var/www/html/home/var/file', '/var/www/html/home/var'];
        foreach ($paths as $path) {
            if ($path === '/') {
                // Use df for root filesystem
                $output = shell_exec('df -h / 2>/dev/null');
                $lines = explode("\n", trim($output));

                if (count($lines) >= 2) {
                    $parts = preg_split('/\s+/', $lines[1]);
                    $results['ROOT'] = [
                        'total' => $parts[1] ?? 'N/A',
                        'used'  => $parts[2] ?? 'N/A',
                        'avail' => $parts[3] ?? 'N/A',
                        'use%'  => $parts[4] ?? 'N/A',
                    ];
                } else {
                    $results[$path] = "N/A";
                }
            } else {
                // Use du for normal paths
                $command = sprintf('du -sh %s 2>/dev/null', escapeshellarg($path));
                $output = shell_exec($command);

                if ($output) {
                    [$size, $name] = preg_split('/\s+/', trim($output), 2);
                    $results[$path] = ['size' => $size];
                } else {
                    $results[$path] = "N/A";
                }
            }
        }
        // add ttl 1 hour
        $results['ttl'] = time() + 3600;
        $this->di->getCache()->set('disk_usage_adminpanel', $results);
        return $this->prepareResponse([
            'success' => true,
            'data' => $results,
            'message' => 'Disk usage fetched successfully'
        ]);
    }

    public function getAmazonS3LogAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Frontend\Components\AmazonMulti\AmazonS3Log')
            ->s3ListByPrefix($rawBody['prefix'] ?? "", $rawBody['limit'] ?? 100, $rawBody['token'] ?? null, true );
        return $this->prepareResponse($response);
    }

    public function getFailedOrdersDataAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Frontend\Components\AmazonMulti\Dev\FailedOrderGrid')
            ->getFailedOrdersData($rawBody['body']);
        return $this->prepareResponse($response);
    }
}
