<?php
namespace App\Frontend\Controllers;

use App\Core\Controllers\BaseController;
use App\Frontend\Components\MigrationHelper;
class MigratorController extends BaseController
{
    public function getMigrationAnalyticsAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(MigrationHelper::class)->getAnalyticsReport($rawBody);
        return $this->prepareResponse($response);
    }

    public function getMigrationCreditsAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(MigrationHelper::class)->getCredits($rawBody);
        return $this->prepareResponse($response);
    }

    public function setNecessaryDataAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(MigrationHelper::class)->setNecessaryData($rawBody);
        return $this->prepareResponse($response);
    }
}