<?php

namespace App\Connector\Controllers;

class FrontendController extends \App\Core\Controllers\BaseController

{
    public function getStepCompletedAction()
    {
        $underMaintenance = false;
        if ($this->di->getConfig()->get('under_maintenance')) {
            $underMaintenance = $this->di->getConfig()->under_maintenance;
        }

        if (isset($underMaintenance) && $underMaintenance == true) {
            return $this->prepareResponse([
                'success' => false,
                'code' => 'under_maintenance',
                'message' => 'Under Maintenance, Please Comeback in approx 30 min.',
            ]);
        }

        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }
        if (isset($rawBody['version']) && $rawBody['version'] == '2') {
            $response = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->getStepCompleted($rawBody);
            return $this->prepareResponse($response);
        }
        $response = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->getActiveStep($rawBody);
        return $this->prepareResponse($response);
    }

    /*
 * Function to save steps completed
 */
    public function saveStepCompletedAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get(\App\Connector\Components\Helper::class)->saveStepCompleted($rawBody);
        return $this->prepareResponse($response);
    }

    public function getStepsDetailsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get('\App\Connector\Components\StepsHelper');
        return $this->prepareResponse($helper->getStepsDetails($rawBody));
    }

    public function saveStepsDetailsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get('\App\Connector\Components\StepsHelper');
        return $this->prepareResponse($helper->saveStepsDetails($rawBody));
    }

    // function to upload image
    public function uploadImageInS3BucketAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $basePath = BP . DS . 'var/file/images/';
            if (!file_exists($basePath)) {
                $oldmask = umask(0);
                mkdir($basePath, 0777, true);
                umask($oldmask);
            }

            $allImages = [];
            if ($this->request->hasFiles()) {
                foreach ($this->request->getUploadedFiles() as $file) {
                    $file->moveTo($basePath . $file->getName());
                    $img = (string) $file->getName();
                    $imageResponse = $this->di->getObjectManager()->get('\App\Connector\Components\StepsHelper')->uploadImageInS3Bucket($img);
                    array_push($allImages, $imageResponse);
                }
            }

            $response = ['success' => true, 'message' => "Images uploaded successfully", 'images' => $allImages];
        } catch (\Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }
}
