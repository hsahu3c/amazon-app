<?php

namespace App\Amazon\Controllers;

use App\Amazon\Components\ProductHelper;
use App\Core\Controllers\BaseController;

/**
 * Class AmazonBuyShippingController
 * @package App\Amazon\Controllers
 */
class DashboardController extends BaseController
{
    public function getRatingAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $getRatesResponse = $this->di->getObjectManager()->get(ProductHelper::class)->getRating($rawBody);
        return $this->prepareResponse($getRatesResponse);
    }
}