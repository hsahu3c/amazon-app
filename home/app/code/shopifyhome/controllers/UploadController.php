<?php


/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_amazon
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright © 2018 CedCommerce. All rights reserved.
 * @license     EULA http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Controllers;


use App\Core\Controllers\BaseController;
use Exception;
use SimpleXMLElement;
use App\Ebay\Models\Shop\Details;
use App\Ebay\Models\Shop\UploadedProducts;

class UploadController extends BaseController
{
    public function indexAction() {
        try {
            $response['success'] = false;
            $response['message'] = 'product uploading limit exceeded';
            $userId = $this->di->getUser()->id;
            $totals = UploadedProducts::count(
                [
                    'conditions' => 'user_id =?1 AND ebay_status =?2',
                    'bind' => [1 => $userId, 2 => 'item_uploaded']
                ]
            );
            if ($totals <= 50) {
                $handlerData = [
                    'type' => 'full_class',
                    'class_name' => '\App\Ebay\Controllers\UploadController',
                    'method' => 'ebay',
                    'queue_name' => "ebay_queue",
                    'own_weight' => 100,
                    'data' => [
                        'message' => $userId
                    ]
                ];
                $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
                $helper->createQueue($handlerData['queue_name'], $handlerData);
                $response['success'] = true;
                $response['message'] = 'queued';
            }
        } catch (Exception $exception) {
            $response['success'] = false;
            $response['message'] = $exception->getMessage();
        }

        return $this->response->setJsonContent($response);
    }

    public function ebay($data)
    {
        try {
            $userId = $data['data']['message'] ?? $data;
            $ebayExistingDetails = Details::findFirst("user_id = '{$userId}'");
            if ($ebayExistingDetails) {
                $ebayExistingDetails = $ebayExistingDetails->toArray();
                $response = $this->di->getObjectManager()->get('\App\Magento\Components\Helper')
                    ->sendRequest($userId, 'rest/V1/hub-api/getProduct');
                $response = @json_decode((string) $response, true);
                if (isset($response[0]['products'])) {
                    if (!empty($response[0]['products']) && count($response[0]['products']) <= 5) {
                        $finalDataXml = '';
                        foreach ($response[0]['products'] as $productData) {
                            $product = json_decode((string) $productData['product_data'], true);
                            $item['Title'] = $product['name'];
                            $item['SKU'] = $product['sku'];
                            $item['Description'] = $product['description'];
                            $item['StartPrice'] = $product['price'];
                            $item['Quantity'] = $product['inventory'];
                            $item['ListingType'] = $product['listingType'];
                            $item['ListingDuration'] = $product['listingDuration'];
                            $item['DispatchTimeMax'] = $product['maxDispathTime'];

                            if (isset($product['Brand'])) {
                                $item['ProductListingDetails']['BrandMPN']['Brand'] = $product['Brand'];
                                if (isset($product['MPN'])) {
                                    $item['ProductListingDetails']['BrandMPN']['MPN'] = $product['MPN'];
                                }
                            }

                            if (isset($product['upc'])) {
                                $item['ProductListingDetails']['UPC'] = $product['upc'];
                            }

                            if (isset($product['ean'])) {
                                $item['ProductListingDetails']['UPC'] = $product['ean'];
                            }

                            if (isset($product['isbn'])) {
                                $item['ProductListingDetails']['ISBN'] = $product['isbn'];
                            }

                            if (!empty($product['configAttrList'])) {
                                $nameValueList = '';
                                foreach ($product['configAttrList'] as $itemSpec) {
                                    $nameValueList .= '<NameValueList>
                                      <Name>' . $itemSpec['Name'] . '</Name>
                                      <Value>' . $itemSpec['Value']. '</Value>
                                    </NameValueList>';
                                }
                            }

                            if ($nameValueList != "" && $nameValueList != null) {
                                $nameValueList = '<ItemSpecifics>' . $nameValueList . '</ItemSpecifics>';
                                $item['ItemSpecifics'] = "ced";
                            }

                            $item['PrimaryCategory']['CategoryID'] = $product['primaryCategory'];
                            $item['CategoryMappingAllowed'] = true;
                            if ($product['conditionId']) {
                                $item['ConditionID'] = $product['conditionId'];
                            }

                            if (!empty($product['shippingMethods']) && $product['serviceType'] != '') {
                                $item['ShippingDetails'] = 'cedShippingDetails';
                                $shippingType = '<ShippingType>'.$product['serviceType'].'</ShippingType>';
                                $shipServices = '';
                                $count = 1;
                                foreach ($product['shippingMethods'] as $value) {
                                    $shipServices .= '<ShippingServiceOptions>
	                                <ShippingServicePriority>' . $count . '</ShippingServicePriority>
	                                <ShippingService>'.$value['shipping_method'].'</ShippingService>
	                                <ShippingServiceCost>'.$value['price'].'</ShippingServiceCost>
	                                <ShippingServiceAdditionalCost>'.$value['additional_price'].'</ShippingServiceAdditionalCost>
	                                </ShippingServiceOptions>';
                                    $count++;
                                }

                                $finalShipService = $shippingType.$shipServices;
                            }

                            $item['ReturnPolicy']['ReturnsAcceptedOption'] = $product['returnAccepted'];
                            if ($product['returnAccepted'] == 'ReturnsAccepted') {
                                if ($product['country'] == 'US') {
                                    $item['ReturnPolicy']['RefundOption'] = $product['refundType'];
                                }

                                $returnDays = $product['returnDays'] == 'Days_14' ? 'Days_30' : $product['returnDays'];
                                $item['ReturnPolicy']['ReturnsWithinOption'] = $returnDays;
                                $item['ReturnPolicy']['ShippingCostPaidByOption'] = $product['shipCostPaidby'];
                            }

                            if (in_array($ebayExistingDetails['site_id'], [71,77,101,186])) {
                                $item['ReturnPolicy']['Description'] = $product['returnDescription'];
                            }

                            $item['HitCounter'] = 'HiddenStyle';

                            $item['Site'] = $product['site'];
                            $item['Country'] = $product['country'];
                            $item['Currency'] = $product['currency'];
                            $item['PostalCode'] = $product['postalCode'];
                            $item['OutOfStockControl'] = true;
                            if ($product['itemLocation']) {
                                $item['Location'] = $product['itemLocation'];
                            }

                            $item['PaymentMethods'] = 'ced';
                            $item['PictureDetails']['PictureURL'] = 'cedPicture';
                            $paymethod = '';
                            $allImgs = '';
                            foreach ($product['pictureUrls'] as $pictureUrl) {
                                $allImgs .= '<PictureURL>' . $pictureUrl . '</PictureURL>';
                            }

                            foreach ($product['paymentMethods'] as $paymentmethod) {
                                $paymethod .= '<PaymentMethods>' . $paymentmethod . '</PaymentMethods>';
                            }

                            if ($product['payPalemail'] && in_array('PayPal', $product['paymentMethods'])) {
                                $item['PayPalEmailAddress'] = $product['payPalemail'];
                            }

                            $xmlArray['AddItemRequestContainer']['MessageID'] = $productData['product_id'];
                            $xmlArray['AddItemRequestContainer']['Item'] = $item;
                            $rootElement = "AddItemRequestContainer";
                            $xml = new SimpleXMLElement("<{$rootElement}/>");
                            $this->array2XML($xml, $xmlArray['AddItemRequestContainer']);
                            $finalXml = $xml->asXML();
                            if (str_contains($finalXml, '<ItemSpecifics>ced</ItemSpecifics>')) {
                                $finalXml = str_replace('<ItemSpecifics>ced</ItemSpecifics>', $nameValueList, $finalXml);
                            }

                            if (str_contains($finalXml, '<PictureURL>cedPicture</PictureURL>')) {
                                $finalXml = str_replace('<PictureURL>cedPicture</PictureURL>', $allImgs, $finalXml);
                            }

                            if (str_contains($finalXml, '<ShippingDetails>cedShippingDetails</ShippingDetails>')) {
                                $finalXml = str_replace('cedShippingDetails', $finalShipService, $finalXml);
                            }

                            if (str_contains($finalXml, '<PaymentMethods>ced</PaymentMethods>')) {
                                $finalXml = str_replace('<PaymentMethods>ced</PaymentMethods>', $paymethod, $finalXml);
                            }

                            $finalDataXml .= $finalXml;
                        }

                        $responseToMagento = [];

                        $xmlBody = str_replace('<?xml version="1.0"?>', '', $finalDataXml);
                        $xmlHeader = '<?xml version="1.0" encoding="utf-8"?>
                            <AddItemsRequest xmlns="urn:ebay:apis:eBLBaseComponents">
                                <RequesterCredentials>
                                <eBayAuthToken>' .$ebayExistingDetails['ebay_access_token']. '</eBayAuthToken>
                                  </RequesterCredentials>
                                  <Version>989</Version>
                                  <ErrorLanguage>en_US</ErrorLanguage>
                                  <WarningLevel>High</WarningLevel>';
                        $xmlFooter = '</AddItemsRequest>';
                        $variable = "AddItems";
                        $return = $xmlHeader . $xmlBody . $xmlFooter;

                        $uploadOnEbay = $this->di->getObjectManager()
                            ->get('App\Ebay\Controllers\RequestController')->sendHttpRequest($return, $variable, 'server', $ebayExistingDetails['site_id'], $ebayExistingDetails['env']);
                        if ($uploadOnEbay->Ack == "Warning" || $uploadOnEbay->Ack == "Success") {
                            if (isset($uploadOnEbay->AddItemResponseContainer)) {
                                if (count($uploadOnEbay->AddItemResponseContainer) > 1) {
                                    foreach ($uploadOnEbay->AddItemResponseContainer as $value) {
                                        $corID = $value->CorrelationID;
                                        $ebayID = $value->ItemID;
                                        $prodDEtails = new UploadedProducts();
                                        $prodDEtails->saveProductDetails($corID, $ebayID, 'item_uploaded');
                                        $this->di->getObjectManager()->get('App\Magento\Components\Helper')
                                            ->sendRequest($userId, 'rest/V1/hub-api/syncProduct', [
                                                'magento_product_id' => $corID,
                                                'ebay_item_id' => $ebayID,
                                                'ebay_status' =>  'item_uploaded']);
                                    }
                                } else {
                                    $ebayID = $uploadOnEbay->AddItemResponseContainer->ItemID;
                                    $corID = $uploadOnEbay->AddItemResponseContainer->CorrelationID;
                                    $prodDEtails = new UploadedProducts();
                                    $prodDEtails->saveProductDetails($corID, $ebayID, 'item_uploaded');
                                    $this->di->getObjectManager()->get('App\Magento\Components\Helper')
                                        ->sendRequest($userId, 'rest/V1/hub-api/syncProduct', [
                                            'magento_product_id' => $corID,
                                            'ebay_item_id' => $ebayID,
                                            'ebay_status' => 'item_uploaded']);
                                }
                            }
                        } else if ($uploadOnEbay->Ack == "PartialFailure") {
                            if (isset($uploadOnEbay->AddItemResponseContainer)) {
                                foreach ($uploadOnEbay->AddItemResponseContainer as $value) {
                                    $prodDEtails = new UploadedProducts();
                                    $corID = $value->CorrelationID;

                                    if (isset($value->ItemID)) {
                                        $prodDEtails->saveProductDetails($corID, $value->ItemID, 'item_uploaded');

                                        $this->di->getObjectManager()->get('App\Magento\Components\Helper')
                                            ->sendRequest($userId, 'rest/V1/hub-api/syncProduct', [
                                                'magento_product_id' => $corID,
                                                'ebay_item_id' => $value->ItemID,
                                                'ebay_status' => 'item_uploaded']);

                                        //TODO save status and respons in table
                                    } else {
                                        $prodDEtails->saveProductDetails($corID, 'not_found', 'item_failure', $uploadOnEbay->AddItemResponseContainer->Errors);
                                        $this->di->getObjectManager()->get('App\Magento\Components\Helper')
                                            ->sendRequest($userId, 'rest/V1/hub-api/syncProduct', [
                                                'magento_product_id' => $corID,
                                                'ebay_item_id' => 'not_found',
                                                'ebay_status' => $uploadOnEbay->AddItemResponseContainer->Errors]);
                                    }
                                }
                            }
                        } else if ($uploadOnEbay->Ack == "Failure") {
                            if (isset($uploadOnEbay->AddItemResponseContainer)) {
                                if (count($uploadOnEbay->AddItemResponseContainer) > 1) {
                                    foreach ($uploadOnEbay->AddItemResponseContainer as $value) {
                                        $corID = $value->CorrelationID;
                                        $prodDEtails = new UploadedProducts();
                                        $saveStatus = $prodDEtails->saveProductDetails($corID, 'not_found', 'item_failure', $uploadOnEbay->AddItemResponseContainer->Errors);
                                        if ($saveStatus) {
                                            $this->di->getObjectManager()->get('App\Magento\Components\Helper')
                                                ->sendRequest($userId, 'rest/V1/hub-api/syncProduct', [
                                                    'magento_product_id' => $corID,
                                                    'ebay_item_id' => 'not_found',
                                                    'ebay_status' => $uploadOnEbay->AddItemResponseContainer->Errors]);
                                        }

                                        //TODO save Error status and response in table
                                    }
                                } else {
                                    $corID = $uploadOnEbay->AddItemResponseContainer->CorrelationID;
                                    if (isset($uploadOnEbay->AddItemResponseContainer->Errors)) {
                                        $prodDEtails = new UploadedProducts();
                                        $saveStatus = $prodDEtails->saveProductDetails($corID, 'not_found','item_failure', $uploadOnEbay->AddItemResponseContainer->Errors);
                                        if ($saveStatus) {
                                            $this->di->getObjectManager()->get('App\Magento\Components\Helper')
                                                ->sendRequest($userId, 'rest/V1/hub-api/syncProduct', [
                                                    'magento_product_id' => $corID,
                                                    'ebay_item_id' => 'not_found',
                                                    'ebay_status' => $uploadOnEbay->AddItemResponseContainer->Errors]);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $exception) {
            $this->di->getLog()->logContent($exception->getMessage(), 'error', 'queue.log');
        }

        return true;
    }

    public function array2XML($xml_obj, $array): void
    {
        foreach ($array as $key => $value) {
            if (is_numeric($key)) {
            }

            if (is_array($value)) {
                $node = $xml_obj->addChild($key);
                $this->array2XML($node, $value);
            } else {
                $xml_obj->addChild($key, htmlspecialchars((string) $value));
            }
        }
    }
}