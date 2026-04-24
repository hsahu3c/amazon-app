<?php

namespace App\Amazon\Controllers;

use App\Amazon\Components\ValueMapping;
use App\Amazon\Components\ProductHelper;
use Exception;
use App\Amazon\Components\Product\Product;
use App\Amazon\Components\Listings\CategoryAttributes;
use App\Amazon\Components\GlobalValueMapping;
use App\Amazon\Components\Product\ProductLinkingViaCsv;
use App\Core\Controllers\BaseController;
use App\Amazon\Components\Common\Helper;
use App\Amazon\Components\Listings\Helper as ListingsHelper;
use App\Amazon\Components\Listings\Migration;
use App\Amazon\Components\Listings\ProductType;



class ProductController extends BaseController
{
    public function getAllAttributesofValueMappingCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ValueMapping::class);

        return $this->prepareResponse($helper->getAllAttributesofValueMappingCount($rawBody));
    }

    public function getAllAttributesofValueMappingAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ValueMapping::class);

        return $this->prepareResponse($helper->getAllAttributesofValueMapping($rawBody));
    }

    public function getProductTypeforTitleAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(CategoryAttributes::class);
        return $this->prepareResponse($helper->getProductType($rawBody));
    }

    public function getTotalCountSourceValuesofAttributeAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ValueMapping::class);

        return $this->prepareResponse($helper->getTotalCountSourceValuesofAttribute($rawBody));
    }

    public function getSourceValuesofAttributeAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ValueMapping::class);
        return $this->prepareResponse($helper->getSourceValuesofAttribute($rawBody));
    }
    public function ValidateFeedAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ListingsHelper::class);

        return $this->prepareResponse($helper->getValidateFeed($rawBody));
    }

    public function valueMappingAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ValueMapping::class);
        return $this->prepareResponse($helper->valueMapping($rawBody));
    }

    public function valueMappingSaveInProfileAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ValueMapping::class);
        return $this->prepareResponse($helper->valueMappingSaveInProfile($rawBody));
    }

    public function saveProductAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ProductHelper::class);

        return $this->prepareResponse($helper->saveProduct($rawBody));
    }

    public function getEditedProductAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ProductHelper::class);

        return $this->prepareResponse($helper->getEditedProduct($rawBody));
    }

    public function validateProductsForBulkEditAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ProductHelper::class);

        return $this->prepareResponse($helper->validateProductsForBulkEdit($rawBody));
    }

    public function getProductsForBulkEditAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ProductHelper::class);

        return $this->prepareResponse($helper->getProductsForBulkEdit($rawBody));
    }

    public function saveBulkProductEditAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ProductHelper::class);

        return $this->prepareResponse($helper->saveBulkProductEdit($rawBody));
    }

    /**
     * Get Product (productsOnly) list based on search and filter
     * @return mixed
     */
    public function getProductsCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ProductHelper::class);

        return $this->prepareResponse($helper->getProductsCount($rawBody));
    }

    /**
     * Get Product (productsOnly) list based on search and filter
     * @return mixed
     */
    public function getProductsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ProductHelper::class);
        return $this->prepareResponse($helper->getProducts($rawBody));
        // return $this->prepareResponse($helper->getProductsNew($rawBody));
    }

    /**
     * Get Product for product_edit
     * @return mixed
     */
    public function getProductAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ProductHelper::class);
        return $this->prepareResponse($helper->getProduct($rawBody));
    }

    public function getAmazonListingAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $response = $this->di->getObjectManager()->get(ProductHelper::class)->getAmazonListing($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function manualMapAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $response = $this->di->getObjectManager()->get(ProductHelper::class)->manualMap($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        $this->di->getObjectManager()->get("\App\Connector\Components\EventsHelper")->createActivityLog("Manual Map", "Product Manually Mapped", $rawBody);
        return $this->prepareResponse($response);
    }

    public function getVariantsAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $response = $this->di->getObjectManager()->get(ProductHelper::class)->getVariants($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }
    public function getListingAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ListingsHelper::class);
        return $this->prepareResponse($helper->getListingFromAmazon($rawBody));
    }

    public function getListingCountAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $response = $this->di->getObjectManager()->get(ProductHelper::class)->getListingCount($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getVariantsCountAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $response = $this->di->getObjectManager()->get(ProductHelper::class)->getVariantsCount($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getVariantAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ProductHelper::class);

        return $this->prepareResponse($helper->getVariant($rawBody));
    }

    public function getFaqAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        try {
            $path = BP . DS . 'app' . DS . 'code' . DS . 'amazon' . DS . 'utility' . DS . 'faq.json';
            $faqJson = file_get_contents($path);
            $faqs = json_decode($faqJson, true);
            $result = [
                'success' => true,
                'response' => $faqs,
                'message' => "FAQ fetched"
            ];
        } catch (Exception $e) {
            $result = [
                'success' => false,
                'response' => [],
                'message' => $e->getMessage()
            ];
        }

        return $this->prepareResponse($result);
    }

    public function manualUnmapAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $rawBody['data']['click'] = true;
            $this->di->getObjectManager()->get("\App\Connector\Components\EventsHelper")->createActivityLog("Manual Unmap", "Product Manually Unmapped", $rawBody);
            $response = $this->di->getObjectManager()->get(ProductHelper::class)->manualUnmap($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getStepsDetailsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ProductHelper::class);
        return $this->prepareResponse($helper->getStepsDetails($rawBody));
    }

    public function saveStepsDetailsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ProductHelper::class);
        return $this->prepareResponse($helper->saveStepsDetails($rawBody));
    }

    public function saveProductSyncingDetailsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ProductHelper::class);
        return $this->prepareResponse($helper->saveProductSyncingDetails($rawBody));
    }

    public function approveAllMapAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $response = $this->di->getObjectManager()->get(ProductHelper::class)->approveAllMap($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function declineCloseMatchAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $response = $this->di->getObjectManager()->get(ProductHelper::class)->declineCloseMatch($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function manualCloseMatchAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $response = $this->di->getObjectManager()->get(ProductHelper::class)->manualCloseMatch($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getMatchStatusCountAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $response = $this->di->getObjectManager()->get(ProductHelper::class)->getMatchStatusCount($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getAmazonStatusAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $response = $this->di->getObjectManager()->get(ProductHelper::class)->getAmazonStatus($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }


    public function uploadImageInS3BucketAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
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

            $productHelper = $this->di->getObjectManager()->get(ProductHelper::class);

            $checkBeforeS3ImageUploadResponse = $productHelper->checkBeforeS3ImageUpload($this->request);
            if (!$checkBeforeS3ImageUploadResponse['success']) {
                return $this->prepareResponse($checkBeforeS3ImageUploadResponse);
            }

            $allImages = [];
            if ($this->request->hasFiles()) {
                foreach ($this->request->getUploadedFiles() as $file) {
                    $file->moveTo($basePath . $file->getName());
                    $img = (string)$file->getName();
                    $imageResponse = $productHelper->uploadImageInS3Bucket($img);
                    array_push($allImages, $imageResponse);
                }
            }

            $response = ['success' => true, 'message' => "Images uploaded successfully", 'images' => $allImages];
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function browseNodeAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!isset($rawBody['user_id'], $rawBody['target'], $rawBody['browser_node_id'], $rawBody['primary_category'], $rawBody['sub_category'])) {
            return $this->prepareResponse(" All required fields not given");
        }

        $commonHelper = $this->di->getObjectManager()->get(Helper::class);
        $response = $commonHelper->getBrowseNode($rawBody);
        return $this->prepareResponse($response);
    }

    //
    public function getCustomValueMappingAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(Product::class);
        return $this->prepareResponse($helper->getCustomValueMapping($rawBody));
    }

    public function deleteCustomValueMappingAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(Product::class);
        return $this->prepareResponse($helper->deleteCustomValueMapping($rawBody));
    }

    public function saveCustomValueMappingAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(Product::class);
        return $this->prepareResponse($helper->saveCustomValueMapping($rawBody));
    }

    public function checkIfUserAndTargetExistsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(Product::class);
        return $this->prepareResponse($helper->checkIfUserAndTargetExists($rawBody));
    }

    public function automateSyncAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(\App\Amazon\Components\Report\Helper::class);
        return $this->prepareResponse($helper->setAutomateSync($rawBody));
    }

    public function getVariationThemeAttributeAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(CategoryAttributes::class);
        return $this->prepareResponse($helper->getVariationThemeAttribute($rawBody));
    }

    public function getVariantsIncludingLinkedAction()
    {
        try {
            $rawBody = $this->getRequestData();
            $response = $this->di->getObjectManager()
                ->get(ProductHelper::class)
                ->getVariantsIncludingLinked($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }
    public function bulkSwitchAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            // Validate required fields
            if (!isset($rawBody['source']) || !isset($rawBody['target']) || 
                !isset($rawBody['source_product_ids']) || !isset($rawBody['attributes'])) {
                return $this->prepareResponse([
                    'success' => false,
                    'data' => '',
                    'message' => 'Missing required fields: source, target, source_product_id, or attributes'
                ]);
            }

            // Validate source structure
            if (!isset($rawBody['source']['shopId']) || !isset($rawBody['source']['marketplace'])) {
                return $this->prepareResponse([
                    'success' => false,
                    'data' => '',
                    'message' => 'Source must contain shop_id and marketplace'
                ]);
            }

            // Validate target structure
            if (!isset($rawBody['target']['shopId']) || !isset($rawBody['target']['marketplace'])) {
                return $this->prepareResponse([
                    'success' => false,
                    'data' => '',
                    'message' => 'Target must contain shop_id and marketplace'
                ]);
            }

            // Validate attributes is an array
            if (!is_array($rawBody['attributes']) || empty($rawBody['attributes'])) {
                return $this->prepareResponse([
                    'success' => false,
                    'data' => '',
                    'message' => 'Attributes must be a non-empty array'
                ]);
            }

            $helper = $this->di->getObjectManager()->get(ProductHelper::class);
            $response = $helper->bulkSwitch($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function refreshErrorAction()
    {
        try {
            $rawBody = $this->getRequestData();
            $response = $this->di->getObjectManager()
                ->get(ProductHelper::class)
                ->refreshError($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getFeedViewAction()
    {
        try {
            $rawBody = $this->getRequestData();
            $response = $this->di->getObjectManager()
                ->get(ProductHelper::class)
                ->getFeedDataDirect($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function markErrorResolvedAction()
    {
        try {
            $rawBody = $this->getRequestData();
            $response = $this->di->getObjectManager()
                ->get(ProductHelper::class)
                ->markErrorResolved($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getViewModalDataAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get(Product::class)
            ->getViewModalData($rawBody);
        return $this->prepareResponse($response);
    }

    public function getSkuCountAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $helper = $this->di->getObjectManager()->get('\App\Amazon\Components\ProductHelper');
        return $this->prepareResponse($helper->skucount($rawBody));
    }

    public function getAttributeDataAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get(Product::class)
            ->getAttributeData($rawBody);
        return $this->prepareResponse($response);
    }
    public function MigrationAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $helper = $this->di->getObjectManager()->get(Migration::class);
        // print_r($rawBody);
        // die("hh");
        if (isset($rawBody['is_template']) && $rawBody['is_template']) {

            return $this->prepareResponse($helper->ProfileMigrate($rawBody));
        } else {
            // return $helper->ProductEditedMigrate($rawBody);
            return $this->prepareResponse($helper->ProductEditedMigrate($rawBody));
        }
    }

    public function initiateProductTypeAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            $productType = $this->di->getObjectManager()->get(ProductType::class);
            $response = $productType->initiateProductType($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function globalValueMappingSaveAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(GlobalValueMapping::class);
        return $this->prepareResponse($helper->saveGlobalValueMapping($rawBody));
    }
    public function getListsAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(GlobalValueMapping::class);
        return $this->prepareResponse($helper->getLists($rawBody));
    }
    public function GlobalValueMappingGetAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(GlobalValueMapping::class);
        return $this->prepareResponse($helper->getGlobalValueMapping($rawBody));
    }
    public function deleteGlobalValueMappingAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(GlobalValueMapping::class);
        return $this->prepareResponse($helper->deleteGlobalValueMapping($rawBody));
    }
    public function closeListingAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ListingsHelper::class);
        return $this->prepareResponse($helper->closeListingOnAmazon($rawBody));
    }

    public function reactivateListingAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(ListingsHelper::class);
        return $this->prepareResponse($helper->reactivateListingOnAmazon($rawBody));
    }
    public function getProductTypeListAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $response = $this->di->getObjectManager()->get(ProductHelper::class)->getProductTypeList($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

      public function deleteS3ImagesAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $response = $this->di->getObjectManager()->get(ProductHelper::class)->deleteS3Images($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }


    public function extractLabelAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $response = $this->di->getObjectManager()->get(ProductHelper::class)->extractLabels($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function LinkingViaCSVAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        if ($this->request->hasFiles()) {
            $files = $this->request->getUploadedFiles();
            if (!empty($files)) {
                $userId = $this->di->getUser()->id;
                $rawBody['user_id'] = $userId;
                $rawBody['file'] = $files[0];
            }
        }
        $helper = $this->di->getObjectManager()->get(ProductLinkingViaCsv::class);
        return $this->prepareResponse($helper->bulkManualLinkingCSV($rawBody));
    }

    public function exportUnlinkedCsvProductAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (str_contains((string) $contentType, 'application/json')) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            $response = $this->di->getObjectManager()->get('App\Amazon\Components\Product\ExportUnlinkedProduct')->exportUnlinkedProductsCSV($rawBody);
        } catch (Exception $e) {
            $response = ['success' => false, 'data' => '', 'message' => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }
}
