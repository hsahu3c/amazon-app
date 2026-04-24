<?php

namespace App\Connector\Controllers;

class SourceController extends \App\Core\Controllers\BaseController
{
    public function getFilterAttributesAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        $success = false;
        $response = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }   

        if ($code = $rawBody['source']['marketplace']) {
            if ($model = $this->di->getConfig()->connectors->get($code)->get('source_model')) {
                $data = $this->di->getObjectManager()->get($model)->getFilterAttributes($rawBody);
                if (count($data['data']) > 0) {
                    $success = true;
                    $response = $data['data'];
                    // return $this->prepareResponse(['success' => true, 'data' => $data['data']]);
                }
                else{

                    $success = false;
                    $response = $data['data'];
                }
                // return $this->prepareResponse(['success' => false, 'data' => $data['data']]);
            }
        }
        else {
            return $this->prepareResponse(['success' => false, 'code' => 'missing_required_params', 'message' => 'Missing Code.']);
        }
         if($code = $rawBody['target']['marketplace'])
         {
            if ($model = $this->di->getConfig()->connectors->get($code)->get('source_model')) {
                $data = $this->di->getObjectManager()->get($model)->getFilterAttributes($rawBody);
                if (count($data['data']) > 0) {
                    $success = true;
                    if(!empty($response)){
                        $response = [...$data['data'],...$response];
                                           }
                    else{
                        $response = $data['data'];

                    }
                }
            }

        }
        
        if(!empty($response)){
            
            return $this->prepareResponse(['success' => $success, 'data' => $response]);
        }
        else{
            return $this->prepareResponse(['success' => false, 'code' => 'missing_required_params', 'message' => 'Missing Code.']);
 
        }
    }
}
