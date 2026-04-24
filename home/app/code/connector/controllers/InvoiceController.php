<?php

namespace App\Connector\Controllers;

class InvoiceController extends \App\Core\Controllers\BaseController
{

    public function getInvoicesAction()
    {
        $invoiceModel = new \App\Connector\Models\Invoice();
        $responseData = $invoiceModel->getInvoices($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function createInvoiceAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $invoiceModel = $this->di->getObjectManager()->create('\App\Connector\Models\Invoice');
        return $this->prepareResponse($invoiceModel->prepareInvoiceData($rawBody['invoiceData'], $rawBody['shippingAmountPaid']));
    }

    public function getInvoiceByOrderIdAction()
    {
        $invoiceModel = new \App\Connector\Models\Invoice();
        $responseData = $invoiceModel->getInvoiceByOrderId($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function viewInvoiceAction()
    {
        $invoiceModel = new \App\Connector\Models\Invoice();
        $responseData = $invoiceModel->viewInvoice($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function showInvoiceAction(): void
    {
        $invoiceDetails = $this->di->getRequest()->get('token');
        $invoiceFile = $this->di->getObjectManager()->get('App\Core\Components\Helper')->decodeToken($invoiceDetails, false);
        if ($invoiceFile['success'] === true) {
            $fileName = $invoiceFile['data']['fileName'];
            $filePath = BP . DS . 'var' . DS . 'invoice' . DS . 'temp' . DS . $fileName . '.php';
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
