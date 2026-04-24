<?php

namespace App\Connector\Controllers;

class ShipmentController extends \App\Core\Controllers\BaseController
{

    public function getShipmentsAction()
    {
        $shipmentModel = new \App\Connector\Models\Shipment();
        $responseData = $shipmentModel->getShipments($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function createShipmentAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $shipmentModel = $this->di->getObjectManager()->create('\App\Connector\Models\Shipment');
        return $this->prepareResponse($shipmentModel->prepareShipmentData($rawBody));
    }

    public function getShipmentByOrderIdAction()
    {
        $shipmentModel = new \App\Connector\Models\Shipment();
        $responseData = $shipmentModel->getShipmentByOrderId($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function viewShipmentAction()
    {
        $shipmentModel = new \App\Connector\Models\Shipment();
        $responseData = $shipmentModel->viewShipment($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function showShipmentAction(): void
    {
        $shipmentDetails = $this->di->getRequest()->get('token');
        $shipmentFile = $this->di->getObjectManager()->get('App\Core\Components\Helper')->decodeToken($shipmentDetails, false);
        if ($shipmentFile['success'] === true) {
            $fileName = $shipmentFile['data']['fileName'];
            $filePath = BP . DS . 'var' . DS . 'shipment' . DS . 'temp' . DS . $fileName . '.php';
            $finalHtml = require $filePath;
            $this->view->disable();
            $mpdf = new \Mpdf\Mpdf();
            $mpdf->WriteHTML($finalHtml);
            $br = rand(0, 100000);
            $ispis = "Pobjeda Rudet-Izvjestaj-".$br;
            $mpdf->Output($ispis, "I");
            exit();
        }
        die('Url not valid anymore');
    }
}
