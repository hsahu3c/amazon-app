<?php

namespace App\Connector\Controllers;

class AiController extends \App\Core\Controllers\BaseController
{
    public function getSeoSuggestionsAction()
    {
        $this->request->getHeader('Content-Type');
        $rawBody = [];
        $rawBody = $this->request->getJsonRawBody(true);

        if (!empty($rawBody)) {
            $AI = new \App\Connector\Components\AI\getSuggestions;
            $res = $AI->getSeoSuggestions($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }

    public function getCategorySuggestionsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!empty($rawBody)) {
            $AI = new \App\Connector\Components\AI\getSuggestions;
            $res = $AI->getCategorySuggestions($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }

    public function getCreditInfoAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!empty($rawBody)) {
            $AI = new \App\Connector\Components\AI\credits;
            $res = $AI->getCreditInfo($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }


    public function getProductWiseClicksInfoAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!empty($rawBody)) {
            $AI = new \App\Connector\Components\AI\credits;
            $res = $AI->getProductWiseClicksInfo($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }

    public function enableAiServiceBetaAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!empty($rawBody)) {
            $AI = new \App\Connector\Components\AI\credits;
            $res = $AI->enableAiServiceBeta($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }

    public function adjustProductWiseClicksInfoAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!empty($rawBody)) {
            $AI = new \App\Connector\Components\AI\credits;
            $res = $AI->adjustProductWiseClicksInfo($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }

    public function adjustCreditsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!empty($rawBody)) {
            $AI = new \App\Connector\Components\AI\credits;
            $res = $AI->adjustCredits($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }

    public function getCategoriesByIdsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!empty($rawBody)) {
            $AI = new \App\Connector\Components\AI\Categories;
            $res = $AI->getCategories($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }

    public function getKeywordsAction()
    {

        $this->request->getHeader('Content-Type');
        $rawBody = [];
        $rawBody = $this->request->getJsonRawBody(true);

        if (!empty($rawBody)) {
            $AI = new \App\Connector\Components\AI\getSuggestions;
            $res = $AI->getKeywords($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }

    public function getIntentKeywordsAction()
    {

        $this->request->getHeader('Content-Type');
        $rawBody = [];
        $rawBody = $this->request->getJsonRawBody(true);

        if (!empty($rawBody)) {
            $AI = new \App\Connector\Components\AI\Keywords;
            $res = $AI->getKeywordsSuggestions($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }

    public function getBulletPointSuggestionsAction()
    {
        $this->request->getHeader('Content-Type');
        $rawBody = [];
        $rawBody = $this->request->getJsonRawBody(true);

        if (!empty($rawBody)) {
            $AI = new \App\Connector\Components\AI\getSuggestions;
            $res = $AI->getBulletPointSuggestions($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }

    public function getKeywordsRelevantInfoAction()
    {
        $this->request->getHeader('Content-Type');
        $rawBody = [];
        $rawBody = $this->request->getJsonRawBody(true);

        if (!empty($rawBody)) {
            $AI = new \App\Connector\Components\AI\Keywords;
            $res = $AI->getKeywordsRelevantInfo($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }

    public function getKeywordsFromASINAction(){
        $this->request->getHeader('Content-Type');
        $rawBody = [];
        $rawBody = $this->request->getJsonRawBody(true);

        if (!empty($rawBody)) {
            $AI =  new \App\Connector\Components\AI\Extractor;;
            $res = $AI->aboveLayer($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }

    public function functionCallAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!empty($rawBody)) {
            $AI =  new \App\Connector\Components\AI\FunctionCall;
            $res = $AI->functionCall($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }
}
