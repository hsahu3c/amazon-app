<?php
namespace App\Frontend\Controllers;

use App\Core\Controllers\BaseController;
use App\Frontend\Components\AdminpanelHelper;
use App\Core\Models\User;
use MongoDB\BSON\ObjectId;
use Exception;
class AdminpanelController extends BaseController
{
    public function getAllUsersAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
        return $this->prepareResponse($helper->getAllUsers($rawBody));
    }

    public function getAdminConfigAction()
    {
        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
        return $this->prepareResponse($helper->getAdminConfig());
    }

    public function getAppConfigAction()
    {
        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
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

        // print_r($rawBody);
        // die;

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
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

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
        return $this->prepareResponse($helper->addAppConfig($rawBody));
    }

    public function getUserOrdersCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
        return $this->prepareResponse($helper->getUserOrdersCount($rawBody));
    }

    public function getAppOrdersCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
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

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
        return $this->prepareResponse($helper->getUserTypeCount($rawBody));
    }

    public function getUserConnectedAction()
    {
        // die("Sdf");
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
        return $this->prepareResponse($helper->getUserConnected($rawBody));
    }

    public function getUserPlanAction()
    {
        // die("Sdf");
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
        return $this->prepareResponse($helper->getUserPlan($rawBody));
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

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
        return $this->prepareResponse($helper->getFailedOrder($rawBody));
    }

    public function getAmazonLocationAction()
    {
        // die("Sdf");
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
        return $this->prepareResponse($helper->getAmazonLocation($rawBody));
    }

    public function getStepCompletedCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
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

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
        return $this->prepareResponse($helper->setAdminConfig($rawBody));
    }

    public function InventorySyncShopifyAmazonAction(): void
    {
        // die("n");
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

        // $query[] = ['$match'=>['user_id' =>$userId]];
        $productData = $productCollection->aggregate($query, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        $productData = $productData->toArray();
        //var_dump(expression)
        $chunkData = array_chunk($productData, 25);

        //  var_dump(count($productData));die("jjvjv");

        echo "start process";
        echo PHP_EOL;

        foreach ($chunkData as $key => $product) {
            echo "inloop process";
            echo PHP_EOL;
            echo $key;
            echo PHP_EOL;
            $sourceProductIds = [];

            foreach ($product as $value) {
                echo PHP_EOL;
                $sourceProductIds['source_product_ids'][] = $value['source_product_id'];
            }

            $inventoryComponent = $this->di->getObjectManager()->get('\App\Amazon\Components\Product\InventoryWebhook');

            $return = $inventoryComponent->init(['user_id' => $rawBody['user']])->updateNew($sourceProductIds);
            echo "innerloop complete";
            echo PHP_EOL;
            sleep(1);
        }

        die("chhv");
    }

    public function getLoginAsUserUrlAction()
    {
        // die("n");
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
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

        // if( 'arise' === $this->di->getRequester()->getTargetName()   ) {



        // $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        // $collection = $mongo->getCollectionForTable('order_container');
        // $dbs_order_status = $collection->aggregate([
        //     [
        //         '$match'       => [
        //             'user_id' => $user_id,
        //             'parent'  => true
        //         ]
        //     ],
        //     [ '$unwind'      => '$dbs_order_status' ],
        //     [ '$sortByCount' => '$dbs_order_status' ],
        // ])->toArray();

        // $dbs_order_status = json_decode(json_encode($dbs_order_status), true);

        // $dba_order_status = $collection->aggregate([
        //     [
        //         '$match'       => [
        //             'user_id' => $user_id,
        //             'parent'  => true
        //         ]
        //     ],
        //     [ '$unwind'      => '$dba_order_status' ],
        //     [ '$sortByCount' => '$dba_order_status' ]
        // ])->toArray();
        // $dba_order_status = json_decode(json_encode($dba_order_status), true);

        // $status = array_merge( $dba_order_status, $dbs_order_status ); 
        // $finalArray = [];
        // foreach( $status as  $value ) {
        //     $finalArray[$value['_id']] = [ 
        //         '_id' => $value['_id'] ?? '',
        //         'count' =>  ( $finalArray[$value['_id']]['count']  ?? 0 ) + $value['count'] ?? 0
        //     ];
        // }   
        // $status =  array_values( $finalArray );

        // $total = array_sum( array_column( $status, 'count' ) );
        // $data = [
        //     'total' => $total,
        //     'status' => $status
        // ];
        // return $this->prepareResponse( [
        //     'success' => true,
        //     'data' => $data
        // ] );
        // }

        $test = $this->di->getObjectManager()->get(AdminpanelHelper::class);
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

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);
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
        return $this->prepareResponse($product->importProducts($rawBody));
    }

    public function exportCSVAction()
    {
        $userId = $this->di->getUser()->id;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        //$mongo->setSource("queued_tasks");
        $collection = $mongo->getCollectionForTable('queued_tasks');

        $rowQueuedTasks = $collection->find(['user_id' => $userId, 'message' => 'CSV Export in progress'])->toArray();

        if (count($rowQueuedTasks)) {
            return json_encode(['success' => true, 'message' => 'Export Already in Progress']);
        }

        // die("inexport");
        $getParams = $this->di->getRequest()->get();
        // die(json_encode($getParams));
        $profileModel = $this->di->getObjectManager()->get(AdminpanelHelper::class);
        $rawBody = $this->request->getJsonRawBody(true);
        // die(json_encode($getParams));
        $returnArr = $profileModel->exportCSV($getParams);
        return $this->prepareResponse(['data' => $returnArr]);
        die;
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

        $profileModel = $this->di->getObjectManager()->get(AdminpanelHelper::class);
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

        $helper = $this->di->getObjectManager()->get(AdminpanelHelper::class);

        return match ($rawBody['sync_type']) {
            'order_sync' => $this->prepareResponse($helper->orderSync($rawBody)),
            'shipment_sync' => $this->prepareResponse($helper->shipmentSync($rawBody)),
            'register_order_sync' => $this->prepareResponse($helper->registerForOrderSync($rawBody)),
            default => $this->prepareResponse(['success' => false, 'message' => 'wrong sync type']),
        };
        die;
    }
}
