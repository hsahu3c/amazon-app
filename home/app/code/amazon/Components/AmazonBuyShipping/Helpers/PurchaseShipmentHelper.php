<?php

namespace App\Amazon\Components\AmazonBuyShipping\Helpers;

use App\Core\Components\Base;

class PurchaseShipmentHelper extends Base
{
    public const REQUIRED_SIZE_FOR_REQUESTED_DOCS = [
        'length',
        'width',
        'unit'
    ];

    public function getRequestToken($requestData)
    {
        $requestToken =  $requestData['requestToken'] ?? null;
        if (!$requestToken) {
            return ['success' => false, 'message' => 'Request token not found'];
        }

        return ['success' => true, 'data' => $requestToken];
    }

    public function getRateId($requestData)
    {
        $rateId = $requestData['rateId'] ?? null;
        if (!$rateId) {
            return ['success' => false, 'message' => 'Rate id not found'];
        }

        return ['success' => true, 'data' => $rateId];
    }

    public function getRequestedDocumentSpecification($requestData)
    {
        $requestData = $requestData['document_specification'] ?? $requestData['documentSpecification'] ?? [];
        $format = $requestData['format'] ?? 'PDF';
        $size = $requestData['size'] ?? null;
        $sizeRes = $this->getSizeForRequestedDocument($size);
        if (!$sizeRes['success']) {
            return $sizeRes;
        }

        $size = $sizeRes['data'];
        $dpi = $this->getDpi($requestData);
        $pageLayout = $this->getPageLayout($requestData);
        $needFileJoining = $this->getNeedFileJoining($requestData);
        $requestedDocumentTypes = $this->getRequestedDocumentTypes($requestData);
        return ['success' => true, 'data' => compact(
            'format',
            'size',
            'dpi',
            'pageLayout',
            'needFileJoining',
            'requestedDocumentTypes'
        )];
    }

    public function getSizeForRequestedDocument($size)
    {
        if ($size == null) {
            $size = $this->getDefaultSize();
        }

        $response = $this->validateSizeFields($size);
        if (!$response['success']) {
            return $response;
        }

        foreach ($size as $key => $value) {
            if ($key == 'length' || $key == 'width') {
                $size[$key] = (float)$value;
            }
        }

        return ['success' => true, 'data' => $size];
    }

    public function getDefaultSize()
    {
        return ['length' => 6, 'width' => 4, 'unit' => 'INCH'];
    }

    public function validateSizeFields($params)
    {
        foreach (self::REQUIRED_SIZE_FOR_REQUESTED_DOCS as $fields) {
            if (!isset($params[$fields]) || !$params[$fields]) {
                return ['success' => false, 'message' => "Required field {$fields} missing in size"];
            }
        }

        return ['success' => true];
    }

    public function getDpi($params)
    {
        return (int)($params['dpi'] ?? null);
    }

    public function getPageLayout($params)
    {
        return $params['pageLayout'] ?? 'DEFAULT';
    }

    public function getNeedFileJoining($params)
    {
        return (bool)($params['needFileJoining'] ?? false);
    }

    public function getRequestedDocumentTypes($params)
    {
        return isset($params['requestedDocumentTypes'])
            && is_array($params['requestedDocumentTypes'])
            ? $params['requestedDocumentTypes']
            : ['LABEL'];
    }

    public function getRequestedValueAddedServices($params)
    {
        if (!isset($params['valueAddedServices'])) {
            return ['success' => true];
        }

        $preparedData = [];
        foreach ($params['valueAddedServices'] as $valAddedGroup) {
            if (isset($valAddedGroup['valueAddedServices'])) {
                $preparedData[] = ['id' => $valAddedGroup['valueAddedServices']['id'] ?? null];
            }
        }

        $response = ['success' => true];
        if (!empty($preparedData)) {
            $response['data'] = $preparedData;
        }

        return $response;
    }
}
