<?php

namespace App\Plan\Controllers;


use Exception;
use App\Core\Controllers\BaseController;
use App\Plan\Models\Insights;

/**
 * Class InsightsController
 * @package App\Plan\Controllers
 */
class InsightsController extends BaseController
{
    public function getConvertibleClientsInfoAction()
    {
        $rawBody = $this->getRequestData();
        $insightsComp = $this->di->getObjectManager()->get(Insights::class);
        return $this->prepareResponse($insightsComp->getConvertibleClientsInfo($rawBody));
    }

    public function downloadCSVAction()
    {
        try {
            $csvFilePath = BP . DS . 'var' . DS . 'log' . DS . 'planAdminPanel' . DS .date('Y-m-d') .DS. 'statics.csv';
            $extension = 'csv';
            if (file_exists($csvFilePath)) {
                header('Content-Type: ' . 'csv' . '; charset=utf-8');
                header('Content-Disposition: attachment; filename=csv_file' . time() . '.' . $extension);
                @readfile($csvFilePath);
                die();
            }
        } catch (Exception $e) {
            $message = 'Some failuer occured!';
        }

        return $this->prepareResponse([
            'success' => false,
            'message' => 'Some failuer occured!'
        ]);
    }

    public function getAllAction()
    {
        $rawBody = $this->getRequestData();
        $insightModel = $this->di->getObjectManager()->get(Insights::class);
        return $insightModel->getAll($rawBody);
    }

    public function getBasicInsightsAction()
    {
        $rawBody = $this->getRequestData();
        $insightModel = $this->di->getObjectManager()->get(Insights::class);
        return $this->prepareResponse($insightModel->getBasicInsights($rawBody));
    }

    public function getUserTransactionInfoAction()
    {
        $rawBody = $this->getRequestData();
        $insightModel = $this->di->getObjectManager()->get(Insights::class);
        return $this->prepareResponse($insightModel->getUserTransactionInfo($rawBody));
    }

    public function getTopUsersAction()
    {
        $rawBody = $this->getRequestData();
        $insightModel = $this->di->getObjectManager()->get(Insights::class);
        return $this->prepareResponse($insightModel->getTopUsers($rawBody));
    }

    public function getCustomDataUsersAction()
    {
        $rawBody = $this->getRequestData();
        $insightModel = $this->di->getObjectManager()->get(Insights::class);
        return $this->prepareResponse($insightModel->getCustomDataUsers($rawBody));
    }
}
