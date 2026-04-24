<?php

namespace App\Connector\Controllers;

use App\Core\Controllers\BaseController;

class VendorController extends BaseController
{
    public function getallAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }else{
            $rawBody = $this->request->get();
        }

        $vendor = $this->di->getObjectManager()->create('\App\Connector\Models\Vendor');
        return $this->prepareResponse($vendor->getvendors($rawBody));
    }
}