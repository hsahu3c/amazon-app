<?php

namespace App\Connector\Controllers;

class RefundController extends \App\Core\Controllers\BaseController
{

    public function getRefundsAction()
    {
        $refundModel = new \App\Connector\Models\Refund();
        $responseData = $refundModel->getRefunds($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function createRefundAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $refundModel = $this->di->getObjectManager()->create('\App\Connector\Models\Refund');
        return $this->prepareResponse($refundModel->prepareRefundData($rawBody));
    }

    public function getRefundByOrderIdAction()
    {
        $refundModel = new \App\Connector\Models\Refund();
        $responseData = $refundModel->getRefundByOrderId($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function viewRefundAction()
    {
        $refundModel = new \App\Connector\Models\Refund();
        $responseData = $refundModel->viewRefund($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function showRefundAction(): void
    {
        $refundDetails = $this->di->getRequest()->get('token');
        $refundFile = $this->di->getObjectManager()->get('App\Core\Components\Helper')->decodeToken($refundDetails, false);
        if ($refundFile['success'] === true) {
            $fileName = $refundFile['data']['fileName'];
            $filePath = BP . DS . 'var' . DS . 'refund' . DS . 'temp' . DS . $fileName . '.php';
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
