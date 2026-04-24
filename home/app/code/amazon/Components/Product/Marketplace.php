<?php

namespace App\Amazon\Components\Product;

use App\Amazon\Components\Common\Helper;
use App\Amazon\Components\Feed\Feed;
use App\Core\Components\Base as Base;
use App\Connector\Components\Helper as ConnectorHelper;

class Marketplace extends Base
{
    private $userId = null;

    private $targetShopId = null;

    private $sourceShopId = null;

    private $targetMarketplace = null;

    private $sourceMarketplace = null;

    private $marketplaceId = null;


    private $type = null;

    private $tag = null;

    private $unsetTag = null;

    public function init($shopData = [])
    {
        $this->resetClassProperties();
        if (isset($shopData['user_id'])) {
            $this->userId = (string) $shopData['user_id'];
        } else {
            $this->userId = (string) $this->di->getUser()->id;
        }
        if (isset($shopData['target'])) {
            $this->targetShopId = $shopData['target']['shopId'];
            $this->targetMarketplace = $shopData['target']['marketplace'];
        }

        if (isset($shopData['source'])) {
            $this->sourceShopId = $shopData['source']['shopId'];
            $this->sourceMarketplace = $shopData['source']['marketplace'];
        }

        if (isset($shopData['type'])) {
            $this->type = $shopData['type'];
        }

        if (isset($shopData['tag'])) {
            $this->tag = $shopData['tag'];
        }

        if (isset($shopData['marketplaceId'])) {
            $this->marketplaceId = $shopData['marketplaceId'];
        }

        if (isset($shopData['unsetTag'])) {
            $this->unsetTag = $shopData['unsetTag'];
        }

        return $this;
    }

    public function resetClassProperties()
    {
        $this->userId = null;
        $this->targetShopId = null;
        $this->targetMarketplace = null;
        $this->sourceShopId = null;
        $this->sourceMarketplace = null;
        $this->type = null;
        $this->tag = null;
        $this->marketplaceId = null;
        $this->unsetTag = null;
        return $this;
    }

    public function prepareAndProcessSpecifices($response, $productErrorList, $feedContent = [], $products = [])
    {
        $productlist = $this->getList($response, $this->marketplaceId);

        $addTagProductList = $productlist['addTagProductList'];
        $removeErrorProductList = $productlist['removeErrorProductList'];
        $removeTagProductList = $productlist['removeTagProductList'];
        $removeWarningProductList = $productlist['removeWarningProductList'];
        if (!empty($productErrorList) && empty($removeTagProductList)) {
            $removeTagProductList = array_keys($productErrorList);
        }

        $errorIds = !empty($productErrorList) ? array_keys($productErrorList) : [];
        $sourceProductIds = array_merge($addTagProductList, $removeTagProductList, $removeErrorProductList, $errorIds);
        $sourceProductIds = array_unique($sourceProductIds);

        $specifics = ['addTagProductList' => $addTagProductList, 'removeErrorProductList' => $removeErrorProductList, 'removeTagProductList' => $removeTagProductList, 'productErrorList' => $productErrorList, 'removeWarningProductList' => $removeWarningProductList, 'sourceProductIds' => $sourceProductIds, 'errorIds' => $errorIds];
        $this->updateProductStatus($specifics, $products);
        return $specifics;
    }
    public function prepareAndProcessUnifiedSpecifices($response, $productErrorList, $feedContent = [], $products = [])
    {
        $productlist = $this->getList($response, $this->marketplaceId);

        $addTagProductList = $productlist['addTagProductList'];
        $removeErrorProductList = $productlist['removeErrorProductList'];
        $removeTagProductList = $productlist['removeTagProductList'];
        $removeWarningProductList = $productlist['removeWarningProductList'];
        if (!empty($productErrorList) && empty($removeTagProductList)) {
            $removeTagProductList = array_keys($productErrorList);
        }

        $errorIds = !empty($productErrorList) ? array_keys($productErrorList) : [];
        $sourceProductIds = array_merge($addTagProductList, $removeTagProductList, $removeErrorProductList, $errorIds);
        $sourceProductIds = array_unique($sourceProductIds);

        $specifics = ['addTagProductList' => $addTagProductList, 'removeErrorProductList' => $removeErrorProductList, 'removeTagProductList' => $removeTagProductList, 'productErrorList' => $productErrorList, 'removeWarningProductList' => $removeWarningProductList, 'sourceProductIds' => $sourceProductIds, 'errorIds' => $errorIds];

        $this->MultipleupdateProductStatus($specifics, $products);
        return $specifics;
    }

    public function MultipleupdateProductStatus($data, $products, $feedData = [])
    {
        $date = date(DATE_RFC2822);
        $time = str_replace("+0000", "GMT", $date);
        $res = [];
        $message = [];
        if (is_array($this->tag)) {
            foreach ($this->tag as $tag) {

                $message[$tag] = $this->di->getObjectManager()->get(Product::class)->getMessage($tag);
            }
        }

        if (!isset($this->targetShopId) && isset($feedData['specifics']['shop_id'])) {
            $this->targetShopId = $feedData['specifics']['shop_id'];
        }

        if (empty($this->type) && is_array($this->type)) {
            foreach ($this->type as $type) {
                $feedType = $feedData['type'];

                $this->type[$type] = Feed::FEED_TYPE[$feedType];
            }
        }
        // print_r($this->type);die;
        if (!$this->userId && isset($feedData['user_id'])) {
            $this->userId = $feedData['user_id'];
        }

        if (!$this->sourceShopId) {
            $this->sourceShopId = $feedData['source_shop_id'];
        }

        $saveError = false;
        $addTagProductList = $data['addTagProductList'] ?? [];
        $removeErrorProductList = $data['removeErrorProductList'] ?? [];
        $productWarnList = $data['productWarnList'] ?? [];
        $warnIds = array_keys($productWarnList);

        $removeTagProductList = $data['removeTagProductList'] ?? [];
        $productErrorList = $data['productErrorList'] ?? [];
        $errorIds = $data['errorIds'] ?? [];
        $amazonProductData = false;
        $sourceProductIds = $data['sourceProductIds'] ?? [];
        $removeWarningProductList = $data['removeWarningProductList'] ?? [];
        if (empty($sourceProductIds)) {
            if (isset($feedData['type']) && ($feedData['type'] == 'JSON_LISTINGS_FEED')) {
                $ids = $feedData['specifics']['ids'];
                $messageIds = [];
                foreach ($ids as $messageId) {
                    $msgs = explode('_', (string) $messageId);
                    $messageIds[$msgs[1]] = $msgs['0'];
                }

                $sourceProductIds = array_values($messageIds);
            } else {
                $sourceProductIds = $feedData['specifics']['ids'] ?? [];
            }
        }

        if (empty($errorIds) && !empty($feedData) && !empty($productErrorList)) {
            $errorIds = array_keys($productErrorList);
            $saveError = true;
        }

        $productInsert = [];
        foreach ($sourceProductIds as $sourceProductId) {
            $flag = false;
            $foundProduct = null;
            $processTag = [];
            $alreadySavedProcessTag = [];
            $foundProduct = $this->findProduct($sourceProductId, $products);


            if (!empty($foundProduct)) {
                $product = $this->buildProductData($foundProduct, $sourceProductId);
                if (!empty($foundProduct['marketplace'])) {
                    foreach ($foundProduct['marketplace'] as $marketplace) {
                        if ($marketplace['source_product_id'] == $sourceProductId && $marketplace['shop_id'] == $this->targetShopId && isset($marketplace['target_marketplace']) && $marketplace['target_marketplace'] == 'amazon') {
                            $amazonProductData = $marketplace;
                        }
                    }
                } else {
                    if (!empty($foundProduct['edited'])) {
                        $amazonProductData = $foundProduct['edited'];
                    } else {
                        $amazonProductData = $this->getMarketplaceProduct($sourceProductId, $this->targetShopId, $foundProduct['container_id'] ?? "");
                    }
                }

                //i need to discuss what if the amazon product data not exist?

                if (in_array($sourceProductId, $removeTagProductList)) {
                    $finalTag = $this->unsetTag ?: $this->tag;
                    $tags = [$finalTag];
                    $alreadySavedProcessTag = [];
                    $processTag = [];

                    if (!empty($amazonProductData['process_tags'])) {
                        $processTag = $amazonProductData['process_tags'];
                        $alreadySavedProcessTag = $amazonProductData['process_tags'];
                    }

                    if (!empty($tags) && !empty($processTag)) {
                        foreach ($tags as $tag) {
                            if (isset($processTag[$tag])) {
                                unset($processTag[$tag]);
                            }
                        }
                    }

                    if (!empty(array_diff(array_keys($alreadySavedProcessTag),  array_keys($processTag)))) {
                        $flag = true;
                        if (empty($processTag)) {
                            $product['unset']['process_tags'] = 1;
                        } else {
                            $product['process_tags'] = $processTag;
                        }
                    }
                }


                if (in_array($sourceProductId, $errorIds)) {
                    $errorMsg = $productErrorList[$sourceProductId] ?? [];
                    $errors = [];
                    foreach ($errorMsg as $err) {
                        if (!empty($feedData)) {
                            $errData = explode(" : ", (string) $err);
                            $code = count($errData) == 2 ? $errData[0] : $errData[0];
                            $msg = count($errData) > 2 ? implode(':', array_slice($errData, 1)) : $errData[1];
                        } else {
                            $msg = $this->di->getObjectManager()->get(Product::class)->getErrorByCode($err);
                            $code = $msg ? $err : "AmazonError001";
                            $msg = $msg ?: $err;
                        }

                        $errors[] = ['type' => $this->type, 'code' => $code, 'message' => $msg, 'time' => $time];
                    }

                    if (!empty($errors)) {
                        $product['error'] = $errors;
                        $flag = true;
                    }
                }

                if (in_array($sourceProductId, $warnIds)) {
                    // $warning = [];
                    $warnMsg = $productWarnList[$sourceProductId];
                    $warnings = [];
                    foreach ($warnMsg as $war) {
                        if (!empty($feedData)) {
                            $warnings[] = ['type' => $this->type, 'code' => $war['code'] ?? '', 'message' => $war['message'] ?? ''];
                        }
                    }

                    if (!empty($warnings)) {
                        $product['warning'] = $warnings;
                        $flag = true;
                    }
                }

                if (in_array($sourceProductId, $removeErrorProductList)) {
                    $alreadySavedError = [];
                    $error = [];
                    if (isset($amazonProductData['error'])) {
                        $alreadySavedError = $amazonProductData['error'];
                        $error = $amazonProductData['error'];
                        // condition for product delete
                        if ($this->type == "delete" || $this->type == "product") {
                            $error = [];
                        } else {
                            foreach ($error as $unsetKey => $singleError) {
                                if (isset($singleError['type']) && $singleError['type'] === $this->type) {
                                    unset($error[$unsetKey]);
                                }
                            }
                        }
                    }

                    if (!empty(array_diff(array_keys($alreadySavedError), array_keys($error)))) {
                        $flag = true;
                        if (empty($error)) {
                            $product['unset']['error'] = 1;
                        } else {
                            $product['error'] = $error;
                        }
                    }
                }

                if (in_array($sourceProductId, $removeWarningProductList)) {
                    $alreadySavedWarning = [];
                    $warning = [];
                    if (isset($amazonProductData['warning'])) {
                        $alreadySavedWarning = $amazonProductData['warning'];
                        $warning = $amazonProductData['warning'];
                        // condition for product delete
                        if ($this->type == "delete" || $this->type == "product") {
                            $warning = [];
                        } else {
                            foreach ($warning as $unsetKey => $singleWarning) {
                                if (isset($singleWarning['type']) && $singleWarning['type'] === $this->type) {
                                    unset($warning[$unsetKey]);
                                }
                            }
                        }
                    }

                    if (!empty(array_diff(array_keys($alreadySavedWarning), array_keys($warning)))) {
                        $flag = true;
                        if (empty($warning)) {
                            $product['unset']['warning'] = 1;
                        } else {
                            $product['warning'] = $warning;
                        }
                    }
                }

                if (in_array($sourceProductId, $addTagProductList)) {

                    $processTag = [];
                    foreach ($this->tag as $tag) {

                        if ($amazonProductData) {
                            $alreadySavedProcessTag = [];
                            if (isset($amazonProductData['process_tags'])) {
                                $processTag = $amazonProductData['process_tags'];

                                if (!in_array($tag, array_keys($processTag))) {
                                    $processTag = $amazonProductData['process_tags'];
                                    $alreadySavedProcessTag = $processTag;
                                } elseif ($this->unsetTag) {
                                    unset($processTag[$this->unsetTag]);
                                }
                            }

                            if (empty(($alreadySavedProcessTag)) || array_search($this->tag, array_keys($alreadySavedProcessTag)) === false) {
                                $tagData = [
                                    'msg' => $message[$tag],
                                    'time' => $time
                                ];
                                $processTag[$tag] = $tagData;
                                print_r($processTag);
                            }
                        } else {

                            $tagData = [
                                'msg' => $message[$tag],
                                'time' => $time
                            ];
                            $processTag[$tag] = $tagData;
                        }
                    }


                    if (
                        !empty($processTag) &&
                        !empty(array_diff(array_keys($processTag), array_keys($alreadySavedProcessTag)))
                    ) {
                        if (in_array($this->unsetTag, array_keys($processTag))) {
                            unset($processTag[$this->unsetTag]);
                        }

                        $product['process_tags'] = $processTag;
                        $flag = true;
                    }
                }

                if ($flag) {
                    array_push($productInsert, $product);
                }
            }
        }

        if (!empty($productInsert)) {
            $appTag = $this->di->getAppCode()->getAppTag();
            if (!empty($appTag) && $this->di->getConfig()->get("app_tags")->get($appTag) !== null) {
                /* Trigger websocket (if configured) */
                $websocketConfig = $this->di->getConfig()->get("app_tags")
                    ->get($appTag)
                    ->get('websocket');
                if (
                    isset($websocketConfig['client_id'], $websocketConfig['allowed_types']['notification']) &&
                    $websocketConfig['client_id'] &&
                    $websocketConfig['allowed_types']['notification']
                ) {

                    $helper = $this->di->getObjectManager()->get('App\Connector\Components\Helper');
                    $helper->handleMessage([
                        'user_id' => $this->userId,
                        'notification' => $productInsert
                    ]);
                }

                /* Trigger websocket (if configured) */
            }

            $additionalData['source'] = [
                'marketplace' => $this->sourceMarketplace,
                'shopId' => (string)$this->sourceShopId
            ];
            $additionalData['target'] = [
                'marketplace' => $this->targetMarketplace,
                'shopId' => (string)$this->targetShopId
            ];
            if (!empty($productInsert['unset'])) {
                foreach ($productInsert['unset'] as $field => $value) {
                    if (isset($productInsert[$field])) {
                        unset($productInsert[$field]);
                    }
                }
            }

            $res = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit')->saveProduct($productInsert, $this->userId, $additionalData);
        }

        if ($saveError) {
            $res = $this->di->getObjectManager()->get(Feed::class)->removeErrorFromProduct($errorIds, $feedData);
        }

        return $res ?? [];
    }

    public function getList($response, $feedContent)
    {
        $addTagProductList = [];
        $removeErrorProductList = [];
        $removeTagProductList = [];
        $removeWarningProductList = [];
        if (isset($response['success']) && $response['success']) {

            if (isset($response['result'][$this->marketplaceId]['response'])) {
                $remoteResponse = $response['result'][$this->marketplaceId]['response'];
                if (isset($response['result'][$this->marketplaceId]['response'], $response['result'][$this->marketplaceId]['newFunction']) && $response['result'][$this->marketplaceId]['response'] && $response['result'][$this->marketplaceId]['newFunction']) {
                    foreach ($remoteResponse as $productResponse) {
                        $addTagProductList[] = $productResponse;
                        $removeErrorProductList[] = $productResponse;
                        $removeWarningProductList[] = $productResponse;
                    }
                } else {
                    foreach ($remoteResponse as $sourceProductId => $productResponse) {
                        if (isset($productResponse['success']) && $productResponse['success']) {
                            $addTagProductList[] = $sourceProductId;
                            $removeErrorProductList[] = $sourceProductId;
                            $removeWarningProductList[] = $sourceProductId;
                        } elseif (isset($productResponse['message'])) {
                            $removeTagProductList[] = $sourceProductId;
                            $productErrorList[$sourceProductId] = [$productResponse['message']];
                        } else {
                            $removeTagProductList[] = $sourceProductId;
                            $productErrorList[$sourceProductId] = ['AmazonError900'];
                        }
                    }
                }
            }
        } else {
            //array_keys(): Argument #1 ($array) must be of type array, string given
            // if (isset($response['msg']) && !empty($feedContent)) {
            if (isset($response['msg']) && !empty($feedContent) && is_array($feedContent)) {
                $removeTagProductList = array_keys($feedContent);
                $productErrorList = array_fill_keys($removeTagProductList, ['AmazonError900']);
            }
        }

        return [
            'addTagProductList' => $addTagProductList,
            'removeErrorProductList' => $removeErrorProductList,
            'removeTagProductList' => $removeTagProductList,
            'removeWarningProductList' => $removeWarningProductList
        ];
    }

    public function updateProductStatus($data, $products, $feedData = [])
    {
        $date = date(DATE_RFC2822);
        $time = str_replace("+0000", "GMT", $date);
        $res = [];
        $message = '';
        if ($this->tag) {
            $message = $this->di->getObjectManager()->get(Product::class)->getMessage($this->tag);
        }

        if (!isset($this->targetShopId) && isset($feedData['specifics']['shop_id'])) {
            $this->targetShopId = $feedData['specifics']['shop_id'];
        }

        if (empty($this->type)) {
            $feedType = $feedData['type'];
            $this->type = Feed::FEED_TYPE[$feedType];
        }

        if (!$this->userId && isset($feedData['user_id'])) {
            $this->userId = $feedData['user_id'];
        }

        if (!$this->sourceShopId) {
            $this->sourceShopId = $feedData['source_shop_id'];
        }

        $saveError = false;
        $addTagProductList = $data['addTagProductList'] ?? [];
        $removeErrorProductList = $data['removeErrorProductList'] ?? [];
        $productWarnList = $data['productWarnList'] ?? [];
        $warnIds = array_keys($productWarnList);

        $removeTagProductList = $data['removeTagProductList'] ?? [];
        $productErrorList = $data['productErrorList'] ?? [];
        $errorIds = $data['errorIds'] ?? [];
        $amazonProductData = false;
        $sourceProductIds = $data['sourceProductIds'] ?? [];
        $removeWarningProductList = $data['removeWarningProductList'] ?? [];
        if (empty($sourceProductIds)) {
            if (isset($feedData['type']) && ($feedData['type'] == 'JSON_LISTINGS_FEED')) {
                $ids = $feedData['specifics']['ids'];
                $messageIds = [];
                foreach ($ids as $messageId) {
                    $msgs = explode('_', (string) $messageId);
                    $messageIds[$msgs[1]] = $msgs['0'];
                }

                $sourceProductIds = array_values($messageIds);
            } else {
                $sourceProductIds = $feedData['specifics']['ids'] ?? [];
            }
        }

        if (empty($errorIds) && !empty($feedData) && !empty($productErrorList)) {
            $errorIds = array_keys($productErrorList);
            $saveError = true;
        }

        $productInsert = [];
        foreach ($sourceProductIds as $sourceProductId) {
            $flag = false;
            $foundProduct = null;
            $processTag = [];
            $alreadySavedProcessTag = [];
            $foundProduct = $this->findProduct($sourceProductId, $products);


            if (!empty($foundProduct)) {
                $product = $this->buildProductData($foundProduct, $sourceProductId);
                if (!empty($foundProduct['marketplace'])) {
                    foreach ($foundProduct['marketplace'] as $marketplace) {
                        if ($marketplace['source_product_id'] == $sourceProductId && $marketplace['shop_id'] == $this->targetShopId && isset($marketplace['target_marketplace']) && $marketplace['target_marketplace'] == 'amazon') {
                            $amazonProductData = $marketplace;
                        }
                    }
                } else {
                    if (!empty($foundProduct['edited'])) {
                        $amazonProductData = $foundProduct['edited'];
                    } else {
                        $amazonProductData = $this->getMarketplaceProduct($sourceProductId, $this->targetShopId, $foundProduct['container_id'] ?? "");
                    }
                }

                //i need to discuss what if the amazon product data not exist?

                if (in_array($sourceProductId, $removeTagProductList)) {
                    $finalTag = $this->unsetTag ?: $this->tag;
                    $tags = [$finalTag];
                    $alreadySavedProcessTag = [];
                    $processTag = [];

                    if (!empty($amazonProductData['process_tags'])) {
                        $processTag = $amazonProductData['process_tags'];
                        $alreadySavedProcessTag = $amazonProductData['process_tags'];
                    }

                    if (!empty($tags) && !empty($processTag)) {
                        foreach ($tags as $tag) {
                            if (isset($processTag[$tag])) {
                                unset($processTag[$tag]);
                            }
                        }
                    }

                    if (!empty(array_diff(array_keys($alreadySavedProcessTag),  array_keys($processTag)))) {
                        $flag = true;
                        if (empty($processTag)) {
                            $product['unset']['process_tags'] = 1;
                        } else {
                            $product['process_tags'] = $processTag;
                        }
                    }
                }


                if (in_array($sourceProductId, $errorIds)) {
                    $errorMsg = $productErrorList[$sourceProductId] ?? [];
                    $errors = [];
                    foreach ($errorMsg as $err) {
                        if (!empty($feedData)) {
                            $errData = explode(" : ", (string) $err);
                            $code = count($errData) == 2 ? $errData[0] : $errData[0];
                            $msg = count($errData) > 2 ? implode(':', array_slice($errData, 1)) : $errData[1];
                        } else {
                            $msg = $this->di->getObjectManager()->get(Product::class)->getErrorByCode($err);
                            $code = $msg ? $err : "AmazonError001";
                            $msg = $msg ?: $err;
                        }

                        $errors[] = ['type' => $this->type, 'code' => $code, 'message' => $msg, 'time' => $time];
                    }

                    if (!empty($errors)) {
                        $product['error'] = $errors;
                        $flag = true;
                    }
                }

                if (in_array($sourceProductId, $warnIds)) {
                    // $warning = [];
                    $warnMsg = $productWarnList[$sourceProductId];
                    $warnings = [];
                    foreach ($warnMsg as $war) {
                        if (!empty($feedData)) {
                            $warnings[] = ['type' => $this->type, 'code' => $war['code'] ?? '', 'message' => $war['message'] ?? ''];
                        }
                    }

                    if (!empty($warnings)) {
                        $product['warning'] = $warnings;
                        $flag = true;
                    }
                }

                if (in_array($sourceProductId, $removeErrorProductList)) {
                    $alreadySavedError = [];
                    $error = [];
                    if (isset($amazonProductData['error'])) {
                        $alreadySavedError = $amazonProductData['error'];
                        $error = $amazonProductData['error'];
                        // condition for product delete
                        if ($this->type == "delete" || $this->type == "product") {
                            $error = [];
                        } else {
                            foreach ($error as $unsetKey => $singleError) {
                                if (isset($singleError['type']) && $singleError['type'] === $this->type) {
                                    unset($error[$unsetKey]);
                                }
                            }
                        }
                    }

                    if (!empty(array_diff(array_keys($alreadySavedError), array_keys($error)))) {
                        $flag = true;
                        if (empty($error)) {
                            $product['unset']['error'] = 1;
                        } else {
                            $product['error'] = $error;
                        }
                    }
                }

                if (in_array($sourceProductId, $removeWarningProductList)) {
                    $alreadySavedWarning = [];
                    $warning = [];
                    if (isset($amazonProductData['warning'])) {
                        $alreadySavedWarning = $amazonProductData['warning'];
                        $warning = $amazonProductData['warning'];
                        // condition for product delete
                        if ($this->type == "delete" || $this->type == "product") {
                            $warning = [];
                        } else {
                            foreach ($warning as $unsetKey => $singleWarning) {
                                if (isset($singleWarning['type']) && $singleWarning['type'] === $this->type) {
                                    unset($warning[$unsetKey]);
                                }
                            }
                        }
                    }

                    if (!empty(array_diff(array_keys($alreadySavedWarning), array_keys($warning)))) {
                        $flag = true;
                        if (empty($warning)) {
                            $product['unset']['warning'] = 1;
                        } else {
                            $product['warning'] = $warning;
                        }
                    }
                }

                if (in_array($sourceProductId, $addTagProductList)) {
                    if ($amazonProductData) {
                        $processTag = [];
                        $alreadySavedProcessTag = [];
                        if (isset($amazonProductData['process_tags'])) {
                            $processTag = $amazonProductData['process_tags'];
                            if (!in_array($this->tag, array_keys($processTag))) {
                                $processTag = $amazonProductData['process_tags'];
                                $alreadySavedProcessTag = $processTag;
                            } elseif ($this->unsetTag) {
                                unset($processTag[$this->unsetTag]);
                            }
                        }

                        if (empty(($alreadySavedProcessTag)) || array_search($this->tag, array_keys($alreadySavedProcessTag)) === false) {
                            $tagData = [
                                'msg' => $message,
                                'time' => $time
                            ];
                            $processTag[$this->tag] = $tagData;
                        }
                    } else {
                        $tagData = [
                            'msg' => $message,
                            'time' => $time
                        ];
                        $processTag[$this->tag] = $tagData;
                    }

                    if (
                        !empty($processTag) &&
                        !empty(array_diff(array_keys($processTag), array_keys($alreadySavedProcessTag)))
                    ) {
                        if (in_array($this->unsetTag, array_keys($processTag))) {
                            unset($processTag[$this->unsetTag]);
                        }

                        $product['process_tags'] = $processTag;
                        $flag = true;
                    }
                }
                if (!empty($product['unset'])) {
                    foreach ($product['unset'] as $field => $value) {
                        if (isset($product[$field])) {
                            unset($product[$field]);
                        }
                    }
                }

                if ($flag) {
                    array_push($productInsert, $product);
                }
            }
        }

        if (!empty($productInsert)) {
            $appTag = $this->di->getAppCode()->getAppTag();
            if (!empty($appTag) && $this->di->getConfig()->get("app_tags")->get($appTag) !== null) {
                /* Trigger websocket (if configured) */
                $websocketConfig = $this->di->getConfig()->get("app_tags")
                    ->get($appTag)
                    ->get('websocket');
                if (
                    isset($websocketConfig['client_id'], $websocketConfig['allowed_types']['notification']) &&
                    $websocketConfig['client_id'] &&
                    $websocketConfig['allowed_types']['notification']
                ) {

                    $helper = $this->di->getObjectManager()->get('App\Connector\Components\Helper');
                    $helper->handleMessage([
                        'user_id' => $this->userId,
                        'notification' => $productInsert
                    ]);
                }

                /* Trigger websocket (if configured) */
            }

            $additionalData['source'] = [
                'marketplace' => $this->sourceMarketplace,
                'shopId' => (string)$this->sourceShopId
            ];
            $additionalData['target'] = [
                'marketplace' => $this->targetMarketplace,
                'shopId' => (string)$this->targetShopId
            ];
            
            $res = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Edit')->saveProduct($productInsert, $this->userId, $additionalData);
        }

        if ($saveError) {
            $res = $this->di->getObjectManager()->get(Feed::class)->removeErrorFromProduct($errorIds, $feedData);
        }

        return $res ?? [];
    }

    private function findProduct($sourceProductId, $products)
    {
        $foundProduct = [];
        if (!empty($products)) {
            $productKey = array_search($sourceProductId, array_column($products, 'source_product_id'));
            if ($productKey !== false) {
                $foundProduct = $products[$productKey];
            }
        } else {
            $productMarketplace = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
            $query = [
                'user_id' => $this->userId,
                'shop_id' => (string)$this->sourceShopId,
                'source_product_id' => (string)$sourceProductId
            ];
            $productFetch = $productMarketplace->getproductbyQuery($query, $options);
            $foundProduct = $productFetch[0] ?? [];
        }

        if (!empty($foundProduct)) {
            return json_decode(json_encode($foundProduct), true);
        }
        return $foundProduct;
    }

    private function buildProductData($foundProduct, $sourceProductId): array
    {
        if (empty($foundProduct)) {
            return [
                'source_product_id' => (string) $sourceProductId,
                "target_shop_id" => (string) $this->targetShopId,
                'source_shop_id' => (string) $this->sourceShopId,
                'user_id' => (string) $this->userId
            ];
        }

        return [
            'source_product_id' => (string) $sourceProductId,
            'shop_id' => (string) $this->targetShopId,
            'target_marketplace' => 'amazon',
            'user_id' => (string) $this->userId,
            'source_shop_id' => (string) $this->sourceShopId,
            'container_id' => (string) $foundProduct['container_id']
        ];
    }

    public function getMarketplaceProduct($sourceProductId, $targetShopId, $containerId)
    {
        $productCollection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollection(Helper::PRODUCT_CONTAINER);
        $query = [
            'user_id' => $this->di->getUser()->id,
            'shop_id' => $targetShopId,
            'container_id' => $containerId,
            'source_product_id' => $sourceProductId,
            'target_marketplace' => 'amazon'
        ];
        return $productCollection->findOne($query, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
    }
}
