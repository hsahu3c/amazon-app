<?php

namespace App\Connector\Controllers;

use App\Core\Controllers\BaseController;

class ProductController extends BaseController
{
    /**
     * Create/Update Product Action
     * @return string
     */
    public function createAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $product = $this->di->getObjectManager()->create('\App\Connector\Models\ProductContainer');
        return $this->prepareResponse($product->createProductsAndAttributes([$rawBody], "cedcommerce"));
    }

    /**
     * Function to initiate the importing of product from source marketplace
     * @return array
     */
    public function importAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $postData = $this->request->getJsonRawBody(true);
        } else {
            $postData = $this->request->get();
        }

        $getRequester = $this->di->getRequester();
        $rawBody = [
            "source" => [
                "marketplace" => $getRequester->getSourceName() ?: ((isset($postData['source']['marketplace']) && $postData['source']['marketplace']) ? $postData['source']['marketplace'] : false),
                "shopId" => $getRequester->getSourceId() ?: ((isset($postData['source']['shopId']) && $postData['source']['shopId']) ? $postData['source']['shopId'] : false),
                "data" => isset($postData['source']['data']) ? $postData['source']['data'] : []
            ],
            "target" => [
                "marketplace" => $getRequester->getTargetName() ?: ((isset($postData['target']['marketplace']) && $postData['target']['marketplace']) ? $postData['target']['marketplace'] : false),
                "shopId" => $getRequester->getTargetId() ?: ((isset($postData['target']['shopId']) && $postData['target']['shopId']) ? $postData['target']['shopId'] : false),
                "data" => isset($postData['target']['data']) ? $postData['target']['data'] : []
            ],
            "data" => (isset($postData['data'])) ? $postData['data'] : []
        ];
        $userId = (isset($postData['user_id']) && $postData['user_id']) ? $postData['user_id'] : null;
        $planTier = $this->di->getObjectManager()->get('\App\Connector\Components\Helper')->getPlanTier($userId);
        if(!$planTier['success']) {
            return $this->prepareResponse(['success' => false, 'message' => 'Plan not found.']);
        }
        $product = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
        return $this->prepareResponse($product->initiateImport($rawBody, $userId));
    }



    public function downloadReportAction(): void
    {
        $reportToken = $this->di->getRequest()->get('file_token');
        try {
            $token = $this->di->getObjectManager()->get('App\Core\Components\Helper')->decodeToken($reportToken, false);
            $filePath = $token['data']['file_path'];
            $contentType = 'text/csv';
            if (file_exists($filePath)) {
                header('Content-Type: ' . $contentType . '; charset=utf-8');
                header('Content-Disposition: attachment; filename=google_report_' . time() . '.csv');
                @readfile($filePath);
                die;
            }
            die('Invalid Url. No report found.');
        } catch (\Exception) {
            die('Invalid Url. No report found.');
        }
    }

    public function syncProductAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (isset($rawBody['source_product_id']) && !empty($rawBody['source_product_id'])) {
            $product = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');

            return $this->prepareResponse($product->uploadProducts($rawBody, 'PartialUpdate'));
        }
        $this->prepareResponse(['success' => false, 'message' => 'Product Ids not found.']);
    }

    /**
     * Upload Product Action
     * @return string
     */
    public function uploadAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        // die(json_encode(($rawBody)));
        $product = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');
        if (!isset($rawBody['operationType'])) {
            $rawBody['operationType'] = 'product_upload';
        }

        return $this->prepareResponse($product->startSync($rawBody));
    }

    /**
     * Upload Single Product Action
     *
     * @return string
     */
    public function uploadSingleProductAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $product = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');
        $rawBody['operationType'] = 'single_product_upload';
        return $this->prepareResponse($product->startSync($rawBody));
    }

    /**
     * upload product by selection
     */

    public function inventorySyncAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $product = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');
        $rawBody['operationType'] = 'inventory_sync';
        return $this->prepareResponse($product->startSync($rawBody));
    }

    public function priceSyncAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $product = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');
        $rawBody['operationType'] = 'price_sync';
        return $this->prepareResponse($product->startSync($rawBody));
    }

    public function productSyncAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $product = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');
        $rawBody['operationType'] = 'product_update';
        return $this->prepareResponse($product->startSync($rawBody));
    }

    public function imageSyncAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $product = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');
        $rawBody['operationType'] = 'image_sync';
        return $this->prepareResponse($product->startSync($rawBody));
    }

    public function deleteFromMarketplaceAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $product = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');
        $rawBody['operationType'] = 'product_delete';
        return $this->prepareResponse($product->startSync($rawBody));
    }

    /**
     * Upload Product Through CSV
     * @return string
     */
    public function uploadCSVAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $product = $this->di->getObjectManager()->create('\App\Connector\Models\ProductContainer');
        return $this->prepareResponse($product->uploadProductsCSV($rawBody));
    }

    /**
     * upload product by selection
     */
    public function selectUploadAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $rawBody['user_id'] = $this->di->getUser()->id;
        $product = $this->di->getObjectManager()->get('\App\Connector\Models\SourceModel');
        return $this->prepareResponse($product->uploadProducts($rawBody));


        /*$contentType = $this->request->getHeader('Content-Type');
        $userId = $this->di->getUser()->id;
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if ($this->di->getConfig()->enable_rabbitmq) {
            $product = $this->di->getObjectManager()->get('\App\Shopifyhome\Models\SourceModel');
            return $this->prepareResponse($product->selectProductAndUpload($rawBody, $userId));
        } else {
            // nothing can be done as of now, need to make sure rabbitMQ is always UP and RUNNIG :P
            return ['success' => false, 'message' => 'RMQ_DISABLE : Internal Server Error . We are working hard and will be up shortly'];
        }*/
    }

    public function editedSaveAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        $this->di->getUser()->id;
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $products = new \App\Connector\Models\ProductContainer();
        return $this->prepareResponse($products->editedProduct($rawBody));
    }

    /**
     * Get a single product by id/source_product_id
     * @return string
     */
    public function getProductAction()
    {
        $productId = $this->di->getRequest()->get();
        $products = new \App\Connector\Models\Product\Edit;
        // $products = new \App\Connector\Models\ProductContainer();
        return $this->prepareResponse($products->getProduct($productId));
    }

    public function saveProductAction()
    {
        $this->di->getRequest()->get();
        $products = new \App\Connector\Models\Product\Edit;
        $rawBody = $this->request->getJsonRawBody(true);
        // $products = new \App\Connector\Models\ProductContainer();
        return $this->prepareResponse($products->saveProduct($rawBody));
    }

    public function getProductByIdAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $rawBody['user_id'] = $this->di->getUser()->id;

        $product = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');
        return $this->prepareResponse($product->getProductById($rawBody));
    }

    public function downloadExportedProductAction(): void
    {
        $userDetails = $this->di->getRequest()->get('token');
        $token = $this->di->getObjectManager()->get('App\Core\Components\Helper')->decodeToken($userDetails, false);
        $contentType = 'text/csv';
        if ($token['data']['extension'] === 'xlsx') {
            $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }

        $filePath = BP . DS . 'var' . DS . 'exports' . DS . $token['data']['user_id'] . DS . $token['data']['fileName'];
        if (file_exists($filePath)) {
            header('Content-Type: ' . $contentType . '; charset=utf-8');
            header('Content-Disposition: attachment; filename=' . $token['data']['fileName'] . '');
            @readfile($filePath);
            die;
        }
        echo 'File not found at specified path.';
        die;
    }

    /**
     * @return string
     */
    public function getProductHeadersAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $products = new \App\Connector\Models\Product();
        return $this->prepareResponse($products->getHeadersFromCsv($rawBody));
    }

    /**
     * Get Config Attribute during profile mapping
     * @return string
     */
    public function getConfigAttributesAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $products = new \App\Connector\Models\Product();
        return $this->prepareResponse($products->getConfigAttributes($rawBody));
    }

    public function saveConfigAttributeMappingAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $products = new \App\Connector\Models\Product();
        return $this->prepareResponse($products->saveConfigAttributeMapping($rawBody));
    }

    public function saveValueMappingAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $products = new \App\Connector\Models\Product();
        return $this->prepareResponse($products->saveValueMapping($rawBody));
    }

    /**
     * Delete product in mass
     * @return mixed
     */
    public function deleteMultipleProductsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $products = new \App\Connector\Models\ProductContainer();
        return $this->prepareResponse($products->deleteMultipleProducts($rawBody));
    }

    public function createQueuedProductsAction()
    {
        $productData = $this->request->get();
        $productModel = new \App\Connector\Models\Product();
        $status = $productModel->createProducts($productData['product'], $productData['count'], $productData['history_index']);
        return $status['status'];
    }

    /**
     * Delete Product by Id
     * @return mixed
     */
    public function deleteProductAction()
    {
        $productId = $this->di->getRequest()->get();
        $products = new \App\Connector\Models\ProductContainer();
        return $this->prepareResponse($products->deleteProduct($productId));
    }

    public function getProductFormAction()
    {
        $attribute = $this->di->getObjectManager()->create('\App\Connector\Models\ProductAttribute');
        return $this->prepareResponse($attribute->getProductForm());
    }

    public function clearAllFeedsAction()
    {
        $productModel = new \App\Connector\Models\Product();
        $responseData = $productModel->clearAllFeeds();
        return $this->prepareResponse($responseData);
    }

    public function downloadFeedDataAction(): void
    {
        $fileRelativePath = $this->request->get('fileName');
        $filePath = BP . DS . 'var' . DS . $fileRelativePath;
        $fileName = substr($fileRelativePath, strrpos($fileRelativePath, '/'));
        $ext = explode('.', $fileName);
        $contentType = 'text/csv';
        if ($ext[1] === 'xlsx') {
            $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }

        if (file_exists($filePath)) {
            header('Content-Type: ' . $contentType . '; charset=utf-8');
            header('Content-Disposition: attachment; filename=' . $fileName . '');
            @readfile($filePath);
            die;
        }
        echo 'File not found at specified path.';
        die;
    }

    public function getProductFeedsAction()
    {
        $productModel = new \App\Connector\Models\Product();
        $responseData = $productModel->getProductFeeds($this->request->get());
        return $this->prepareResponse($responseData);
    }

    /**
     * Get Product list based on search and filter
     * @return mixed
     */
    public function getProductsCountAction()
    {
        $containerModel = new \App\Connector\Models\ProductContainer();
        $responseData = $containerModel->getProductsCount($this->request->get());
        return $this->prepareResponse($responseData);
    }

    /**
     * Get Product list based on search and filter
     * @return mixed
     */
    public function getProductsAction()
    {
        $containerModel = new \App\Connector\Models\ProductContainer();
        $responseData = $containerModel->getProducts($this->request->get());
        return $this->prepareResponse($responseData);
    }

    /**
     * Get Product list based on search and filter
     * @return mixed
     */
    public function getChildProductsAction()
    {
        $containerModel = new \App\Connector\Models\ProductContainer();
        $responseData = $containerModel->getChildProducts($this->request->get());
        return $this->prepareResponse($responseData);
    }


    public function getAllVariantAction()
    {
        $productModel = new \App\Connector\Models\Product();
        $responseData = $productModel->getAllVariant($this->request->get());
        return $this->prepareResponse($responseData);
    }

    /**
     * Get All Attributes of Merchant.
     * @return mixed
     */
    public function getAllAttributesAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $pageSettings = $this->request->getJsonRawBody(true);
        } else {
            $pageSettings = $this->request->get();
        }

        $attribute = $this->di->getObjectManager()->create('\App\Connector\Models\ProductAttribute');
        if (isset($pageSettings['count']) && isset($pageSettings['activePage'])) {
            if (isset($pageSettings['filter']) || isset($pageSettings['search'])) {
                $response = $attribute->getAllAttributes($pageSettings['count'], ($pageSettings['count'] * $pageSettings['activePage']) - $pageSettings['count'], $pageSettings);
            } else {
                $response = $attribute->getAllAttributes($pageSettings['count'], ($pageSettings['count'] * $pageSettings['activePage']) - $pageSettings['count']);
            }
        } else {
            $response = $attribute->getAllAttributes();
        }

        return $this->prepareResponse($response);
    }

    /**
     * Create Product Attribute
     * @return string
     */
    public function createAttributeAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $attribute = $this->di->getObjectManager()->create('\App\Connector\Models\ProductAttribute');
        return $this->prepareResponse($attribute->createAttribute($rawBody));
    }

    /**
     * Update Product Attribute
     * @return string
     */
    public function updateAttributeAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $attribute = $this->di->getObjectManager()->create('\App\Connector\Models\ProductAttribute');
        return $this->prepareResponse($attribute->updateAttribute($rawBody));
    }

    /**
     * Delete Product Attribute
     * @return string
     */
    public function deleteAttributeAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $attribute = $this->di->getObjectManager()->create('\App\Connector\Models\ProductAttribute');
        return $this->prepareResponse($attribute->deleteAttribute($rawBody));
    }

    /**
     * Get Product Attribute Option
     * @return string
     */
    public function getAttributeOptionAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $attribute = $this->di->getObjectManager()->create('\App\Connector\Models\ProductAttribute');
        return $this->prepareResponse($attribute->getAttributeOptions($rawBody));
    }

    /**
     * Delete Attribute Option
     * @return string
     */
    public function deleteAttributeOptionAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $attribute = $this->di->getObjectManager()->create('\App\Connector\Models\ProductAttribute');
        return $this->prepareResponse($attribute->deleteAttributeOption($rawBody));
    }


    public function exportProductAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $product = $this->di->getObjectManager()->create('\App\Connector\Models\Product');
        return $this->prepareResponse($product->exportProduct($rawBody));
    }

    public function getColumnsAction()
    {
        $product = new \App\Connector\Models\Product();
        return $this->prepareResponse($product->getProductColumns());
    }

    public function ifRequiredAttributeMappedAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $product = new \App\Connector\Models\Product();
        return $this->prepareResponse($product->ifRequiredAttributeMapped($rawBody));
    }

    public function saveUnmappedAttributesAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $product = new \App\Connector\Models\Product();
        return $this->prepareResponse($product->saveUnmappedAttributes($rawBody));
    }

    public function getProductsByQueryAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $product = new \App\Connector\Models\Product();
        return $this->prepareResponse($product->getProductsByQuery($rawBody));
    }

    public function getAttributesByProductQueryAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $product = new \App\Connector\Models\Product();
        return $this->prepareResponse($product->getAttributesByProductQuery($rawBody));
    }

    public function downloadCSVAction(): void
    {

        $userDetails = $this->di->getRequest()->get('file_token');
        try {
            $token = $this->di->getObjectManager()->get('App\Core\Components\Helper')->decodeToken($userDetails, false);
            $filePath = $token['data']['file_path'];
            $filenameextract_array = explode('/', $filePath);
            $filename = $filenameextract_array[count($filenameextract_array) - 1];
            if ($filePath) {
                $contentType = 'text/csv';
                if (file_exists($filePath)) {
                    header('Content-Type: ' . $contentType . '; charset=utf-8');
                    header('Content-Disposition: attachment; filename=' . $filename . '');
                    @readfile($filePath);
                    die;
                }
                echo 'File not found at specified path.';
                die;
            }
            die('No feed found');
        } catch (\Exception) {
            die('Invalid Token');
        }
    }

    public function updateProductAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $productContainer = new \App\Connector\Models\ProductContainer;
        return $this->prepareResponse($productContainer->updateProduct($rawBody));
    }

    public function syncWithSourceAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $productContainer = new \App\Connector\Models\ProductContainer;
        return $this->prepareResponse($productContainer->syncWithSource($rawBody));
    }

    public function importCSVAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $product = $this->di->getObjectManager()->create('\App\Connector\Models\ProductContainer');
        return $this->prepareResponse($product->importCSV($rawBody));
    }

    public function exportProductCSVAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $product = $this->di->getObjectManager()->create('\App\Connector\Models\ProductContainer');
        return $this->prepareResponse($product->exportProductCSV($rawBody));
    }

    public function enableDisableAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $product = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer');
        return $this->prepareResponse($product->enableDisable($rawBody));
    }

    public function selectAndSyncAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $productContainer = new \App\Connector\Models\ProductContainer;
        return $this->prepareResponse($productContainer->syncData($rawBody));
    }

    public function syncCatalogAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $productContainer = new \App\Connector\Models\ProductContainer;
        return $this->prepareResponse($productContainer->syncCatalog($rawBody));
    }

    public function getAllProductStatusesAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $containerModel = new \App\Connector\Models\ProductContainer();
        $responseData = $containerModel->getAllProductStatuses($rawBody);
        $response = $this->prepareResponse($responseData);
        return $response;
    }

    public function getStatusWiseFilterCountAction()
    {
        $containerModel = new \App\Connector\Models\ProductContainer();
        $responseData = $containerModel->getStatusWiseFilterCount($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function getProductAttributesAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\ProductContainer();
            $responseData = $containerModel->getProductAttributes($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    // multi account amazon status sync

    public function matchProductAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (isset($rawBody['target']['marketplace'])) {
            $code = $rawBody['target']['marketplace'];
            $rawBody['code'] = $code;
            $containerModel = new \App\Connector\Models\ProductContainer();
            $queuedTaskModel = new \App\Connector\Models\QueuedTasks;

            if ($queuedTaskModel->checkQueuedTaskwithProcessTag("product_import")) {
                return $this->prepareResponse(['success' => false, 'message' => 'product import is in progress please wait for it to finish']);
            }
            $responseData = $containerModel->getStatus($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        }
        return $this->prepareResponse(['success' => false, 'code' => 'missing_required_params', 'message' => 'Missing Code.']);
    }


    public function searchProductAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            if (isset($rawBody['target']['marketplace'])) {
                $code = $rawBody['target']['marketplace'];
                $rawBody['code'] = $code;
                $containerModel = new \App\Connector\Models\ProductContainer();
                $queuedTaskModel = new \App\Connector\Models\QueuedTasks;
                if ($queuedTaskModel->checkQueuedTaskwithProcessTag("product_import")) {
                    return $this->prepareResponse(['success' => false, 'message' => 'product import is in progress please wait for it to finish']);
                }
                $responseData = $containerModel->getLookup($rawBody);
                $response = $this->prepareResponse($responseData);
                return $response;
            }
            return $this->prepareResponse(['success' => false, 'code' => 'missing_required_params', 'message' => 'Missing Code.']);
        } catch (\Exception $e) {
            return ['success' => false, "message" => $e->getMessage()];
        }
    }

    public function updateMarketplaceTargetValuesAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\Product\TargetUpdates;
            $responseData = $containerModel->updateMarketplaceTargetValues($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getSearchSuggestionsAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\ProductContainer;
            $responseData = $containerModel->getAtlasSearchSuggestions($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getStatusWiseCountAction()
    {
        try {
            // Get request data with content-type handling
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            // Validate request
            if (empty($rawBody)) {
                return $this->prepareResponse(['success' => false, 'message' => 'Incorrect request format']);
            }

            $userId = $this->di->getUser()->id;
            $targetShopId = $this->di->getRequester()->getTargetId();


            $cacheKey = "statusWiseCount_uid_{$userId}_shop_{$targetShopId}";

            $cache = $this->di->getCache();
            $refresh = filter_var($rawBody['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);

            // Force fresh data if refresh requested
            if ($refresh) {
                $cache->delete($cacheKey);
            }

            // Try to get cached data (if not refreshing)
            $cachedData = $refresh ? false : $cache->get($cacheKey);

            if ($cachedData) {
                $response = [
                    'success' => true,
                    'data' => $cachedData['data'],
                    'error_count' => $cachedData['error_count'],
                    'from_cache' => true,
                    'cached_at' => $cachedData['cached_at'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null
                ];
                return $this->prepareResponse($response);
            }

            // Fetch fresh data
            $containerModel = new \App\Connector\Models\ProductContainer;
            $freshData = $containerModel->getStatusWiseCount($rawBody);



            // Cache the fresh data (only if successful)
            if ($freshData['success']) {

                $cachedData = [
                    "data" => $freshData['data'],
                    "error_count" => $freshData['error_count'],
                    "cached_at" => date("c"),
                ];

                $cache->set($cacheKey, $cachedData); // 1 hour TTL
            }

            // Prepare response
            $response = [
                'success' => true,
                'data' => $freshData['data'] ?? [],
                'error_count' => $freshData['error_count'] ?? 0,
                'from_cache' => false,
                'cached_at' => date("c"),
            ];

            return $this->prepareResponse($response);
        } catch (\Exception $e) {
            $this->di->getLogger()->error("StatusWiseCount Error: " . $e->getMessage());
            $response = ['success' => false, "message" => $e->getMessage()];
            return $this->prepareResponse($response);
        }
    }


    public function getRefineProductsAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\ProductContainer;
            $responseData = $containerModel->getRefineProducts($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getRefineProductCountAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\ProductContainer;
            $responseData = $containerModel->getRefineProductCount($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    /**
     * syncing Source Products
     */
    public function syncSourceProductAction(): object
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $rawBody = [
            "source" => [
                "marketplace" => $this->di->getRequester()->getSourceName() ?? ($rawBody['source']['marketplace'] ?? false),
                "shopId" => $this->di->getRequester()->getSourceId() ?? ($rawBody['source']['shopId'] ?? false),
                "data" => $rawBody['source']['data'] ?? []
            ],
            "target" => [
                "marketplace" => $this->di->getRequester()->getTargetName() ?? ($rawBody['target']['marketplace'] ?? false),
                "shopId" => $this->di->getRequester()->getTargetId() ?? ($rawBody['target']['shopId'] ?? false),
                "data" => $rawBody['target']['data'] ?? []
            ],
            "data" => $rawBody['data'] ?? []
        ];

        $product = $this->di->getObjectManager()->get('\App\Connector\Components\Product\Helper');
        return $this->prepareResponse($product->startSourceProductSync($rawBody));
    }

    public function getAllErrorMsgCountAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\ProductContainer;
            $responseData = $containerModel->getAllErrorMsgCount($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getAllErrorCountAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\ProductContainer;
            $responseData = $containerModel->getAllErrorCount($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    /**
     * @param array of source_product_ids
     *
     * @return array of object for edited doc
     */
    public function getEditedInBulkAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            $products = new \App\Connector\Models\Product\Edit;
            return $this->prepareResponse($products->getEditedInBulk($rawBody));
        }
        return $this->prepareResponse(['success' => false, 'message' => 'Invalid Request Type']);
    }

    public function getErrorSolutionAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\ProductContainer;
            $responseData = $containerModel->getErrorSolution($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getAllProductErrorsAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\ProductContainer;
            $responseData = $containerModel->getAllProductErrors($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function createErrorSolutionAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\ProductContainer;
            $responseData = $containerModel->createErrorSolution($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function deleteErrorSolutionAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\ProductContainer;
            $responseData = $containerModel->deleteErrorSolution($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getAllbulkCategoriesAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\Product\BulkEdit;
            $responseData = $containerModel->getAllbulkCategories($rawBody);
            return $this->prepareResponse($responseData);
        } catch (\Exception $e) {
            return $this->prepareResponse(['success' => false, "message" => $e->getMessage()]);
        }
    }

    public function getAllbulkProductsAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\Product\BulkEdit;
            $responseData = $containerModel->getAllbulkProducts($rawBody);
            return $this->prepareResponse($responseData);
        } catch (\Exception $e) {
            return $this->prepareResponse(['success' => false, "message" => $e->getMessage()]);
        }
    }

    public function saveAllBulEditProductAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $bulkedit = new \App\Connector\Models\Product\BulkEdit;
            $responseData = $bulkedit->saveAllBulEditProduct($rawBody);
            return $this->prepareResponse($responseData);
        } catch (\Exception $e) {
            return $this->prepareResponse(['success' => false, "message" => $e->getMessage()]);
        }
    }

    public function getAllRefineErrorCountAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $bulkedit = new \App\Connector\Models\ProductContainer;
            $responseData = $bulkedit->getAllRefineErrorCount($rawBody);
            return $this->prepareResponse($responseData);
        } catch (\Exception $e) {
            return $this->prepareResponse(['success' => false, "message" => $e->getMessage()]);
        }
    }

    public function manageSkuUpdateAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $responseData = $this->di->getObjectManager()->get('\App\Connector\Components\ProductHelper')->manageSkuUpdate($rawBody);
            return $this->prepareResponse($responseData);
        } catch (\Exception $e) {
            return $this->prepareResponse(['success' => false, "message" => $e->getMessage()]);
        }
    }

    public function convertFulfilmentTypeAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }
            $files = $this->request->getUploadedFiles();
            $uploadedFile = $files[0];
            $targetMarketplace = $rawBody['target']['marketplace'] ?? $this->di->getRequester()->getTargetName();

            $targetSourceModel = $this->di->getObjectManager()->get($this->di->getConfig()->connectors->get($targetMarketplace)->get('source_model'));
            if (method_exists($targetSourceModel, 'uploadfulfilmentFiletoS3')) {
                $responseData = $targetSourceModel->uploadfulfilmentFiletoS3($rawBody, $uploadedFile ?? []);
                return $this->prepareResponse($responseData);
            } else {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'method does not exists'
                ]);
            }
        } catch (\Exception $e) {
            return $this->prepareResponse(['success' => false, "message" => $e->getMessage()]);
        }
    }

    public function getRefineVariantsAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\ProductContainer;
            $responseData = $containerModel->getRefineVariant($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getRefineVariantsCountAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\ProductContainer;
            $responseData = $containerModel->getRefineVariantCount($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }
    public function getErrorProcessTagAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\ProductContainer;
            $responseData = $containerModel->getErrorProcessTag($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function getSourceProductIdWiseImagesAction()
    {
        try {
            $contentType = $this->request->getHeader('Content-Type');
            $rawBody = [];
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = $this->request->getJsonRawBody(true);
            } else {
                $rawBody = $this->request->get();
            }

            $containerModel = new \App\Connector\Models\ProductContainer;
            $responseData = $containerModel->getSourceProductIdWiseImages($rawBody);
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }

    public function checkForDuplicateSKUExistsAction()
    {
        try {
            $containerModel = new \App\Connector\Models\ProductContainer;
            $responseData = $containerModel->checkForDuplicateSKUExists();
            $response = $this->prepareResponse($responseData);
            return $response;
        } catch (\Exception $e) {
            $response = ['success' => false, "message" => $e->getMessage()];
        }

        return $this->prepareResponse($response);
    }
     public function groupAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $product = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Syncing');  
        return $this->prepareResponse($product->startSync($rawBody));
    }
}
