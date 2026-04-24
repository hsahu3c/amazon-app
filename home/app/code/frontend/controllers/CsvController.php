<?php
namespace App\Frontend\Controllers;

use App\Core\Controllers\BaseController;
use App\Frontend\Components\CsvHelper;
use Exception;
class CsvController extends BaseController
{
    public function exportCSVAction()
    {
        // $phpExcel = new PHPExcel;
        $getParams = $this->di->getRequest()->get();

        // die(json_encode(getallheaders()));

        // $tosendData= getallheaders();

        // die($phpExcel);
        // $url="https://a0dzuejr75.execute-api.ap-northeast-3.amazonaws.com/lamdalamdalamda/";
        // $response = $this->di->getObjectManager()->get('App\Core\Components\Guzzle')
        // ->call($url, [], ['params'=>$getParams,'headers'=>getallheaders()], "PUT");
        // print_r(json_encode($response));
        // die;
        $userId = $this->di->getUser()->id;

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("queued_tasks");

        $collection = $mongo->getCollection();

        $rowQueuedTasks = $collection->find(['user_id'=>$userId,'message'=>'CSV Export in progress'])->toArray();

        if (count($rowQueuedTasks)) {
            return json_encode(['success'=>true,'message'=>'Export Already in Progress']);
        }

        $profileModel = $this->di->getObjectManager()->get(CsvHelper::class);
        $rawBody = $this->request->getJsonRawBody(true);
        // die(json_encode($getParams));
        $getParams['count']=300;
        // die(json_encode($getParams));
        $returnArr = $profileModel->getProductDeatils($getParams);
        return $this->prepareResponse(['data'=> $returnArr]);
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
            $extension ='csv';
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
        $path=BP.DS.'var/file/';
        $files= $this->request->getUploadedFiles();
        $name=$userId.".csv";
        // print_r($files);
        // die;
        // die($name);
        foreach ($files as $file) {
            if ($file->getType()!=="text/csv") {
                return $this->prepareResponse(['error'=>"file type not csv"]);
            }

            $file->moveTo(
                $path .$name
            );
        }

        $count=0;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $file=fopen($path .$name, 'r');
        while (($handle=fgetcsv($file))!==false) {
            if ($count==0) {
                $collection->updateOne([
                'user_id'=>$userId], [
                '$set'=>[
                'CSVcolumn'=>json_encode($handle)
            ]]);
            }

            $count++;
        }

        fclose($file);
        // die("done");
        $rawBody['count']=$count;
        $rawBody['path']=$path;
        $rawBody['name']=$name;

        $profileModel = $this->di->getObjectManager()->get(CsvHelper::class);
        // TODO : change the name
        $returnArr = $profileModel->importCSVAndUpdate($rawBody);
        return $this->prepareResponse(['data'=>$returnArr]);

        die();
    }
}
