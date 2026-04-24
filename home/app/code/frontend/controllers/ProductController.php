<?php
namespace App\Frontend\Controllers;
use App\Frontend\Components\ProductHelper;
use Exception;
use function MongoDB\BSON\toJSON;
use function MongoDB\BSON\fromPHP;
use App\Core\Controllers\BaseController;

/**
 * Will handle product requests.
 *
 * @since 1.0.0
 */
class ProductController extends BaseController {

    /**
     * Get request data.
     *
     * @since 1.0.0
     * @return array
     */
    public function getRequestData() {
        switch ( $this->request->getMethod() ){
            case 'POST' :
                $contentType = $this->request->getHeader('Content-Type');
                if ( str_contains( (string) $contentType, 'application/json' ) ) {
                    $data = $this->request->getJsonRawBody(true);
                } else {
                    $data = $this->request->getPost();
                }

                break;
            case 'PUT' :
                $contentType = $this->request->getHeader('Content-Type');
                if ( str_contains( (string) $contentType, 'application/json' ) ) {
                    $data = $this->request->getJsonRawBody(true);
                } else {
                    $data = $this->request->getPut();
                }

                $data = array_merge( $data, $this->request->get() );
                break;

            case 'DELETE' :
                $contentType = $this->request->getHeader('Content-Type');
                if ( str_contains( (string) $contentType, 'application/json' ) ) {
                    $data = $this->request->getJsonRawBody(true);
                } else {
                    $data = $this->request->getPost();
                }

                $data = array_merge( $data, $this->request->get() );
                break;

            default:
                $data = $this->request->get();
                break;
        }

        return $data;
    }

    /**
     * Get Products.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function getProductsAction() {
        $products          = $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer')
                            ->getProducts( $this->getRequestData() );
        $mongo             = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $profilesContainer = $mongo->getCollectionForTable( 'profile' );
        $targetShopId      = $this->di->getRequester()->getTargetId() ?? false;
        $sourceShopId      = $this->di->getRequester()->getSourceId() ?? false;
        $sourceName        = $this->di->getRequester()->getSourceName() ?? false;
        $mProductContainer = $mongo->getCollectionForTable( "{$sourceName}_product_container" );
        $productContainer  = $mongo->getCollectionForTable( 'product_container' );

        if ( ! empty( $products['data']['rows'] ) ) {
            $productData = json_decode( toJSON( fromPHP( $products['data']['rows'] ) ), true );
            $configData  = [];
            if ( 'shopify' !== $sourceName ) {
                $appTag     = $this->di->getAppCode()->getAppTag() ?? 'default';
                $configData = $this->di->getUser()->$appTag ?? [];
            }

            $productData    = array_map(
                function( $product ) use ( $profilesContainer, $mProductContainer, $targetShopId, $sourceShopId, $productContainer, $configData, $sourceName ) {
                    if ( 'shopify' !== $sourceName && ! empty( $configData['weight_conversion']['value']['unit'] ) ) {
                        $product['weight_unit'] = $configData['weight_conversion']['value']['unit'];
                    }

                    $found    = false;
                    $pProfiles = $product['profile'] ?? [];
                    foreach ( $pProfiles as $pProfile ) {
                        if ( isset( $pProfile['target_shop_id'] ) && $targetShopId === $pProfile['target_shop_id'] ) {
                            $found = true;
                        }
                    }

                    if ( ! $found ) {
                        $profile = $profilesContainer->findOne( [ 'user_id' => $product['user_id'], 'name' => 'Default', 'type' => 'profile', 'shop_ids' => [ '$elemMatch' => [ 'source' => $sourceShopId, 'target' => $targetShopId ] ] ],[ 'projection' => [ 'data' => 0 ] ] );
                        if ( ! is_null( $profile ) ) {
                            $profile            = json_decode( toJSON( fromPHP( $profile ) ), true );
                            $product['profile'] = [
                                [
                                    'profile_id'     => $profile['_id'] ?? false,
                                    'type'           => 'query',
                                    'profile_name'   => 'Default',
                                    'target_shop_id' => $targetShopId
                                ]
                            ];
                        }
                    }

                    if ( $product['type'] === 'variation' && $product['visibility'] === 'Catalog and Search' ) {
                        $pChildData = $productContainer->aggregate(
                            [
                                [
                                    '$match' => [
                                        'user_id'            => $product['user_id'] ?? false,
                                        'container_id'       => $product['container_id'] ?? false,
                                        'type'               => 'simple',
                                        'shop_id'            => $product['shop_id'] ?? false,
                                        'source_marketplace' => $product['source_marketplace'] ?? false
                                    ]
                                ],
                                [
                                  '$group' => [
                                        '_id'         => null,
                                        'min_quantity' => [
                                            '$min' => '$quantity' 
                                        ],
                                        'max_quantity' => [
                                            '$max' => '$quantity'
                                        ],
                                        'min_price'    => [
                                            '$min' => '$price'
                                        ],
                                        'max_price'    => [
                                            '$max' => '$price'
                                        ],
                                        'min_compare_at_price' => [
                                            '$min' => '$compare_at_price'
                                        ],
                                        'max_compare_at_price' => [
                                            '$max' => '$compare_at_price'
                                        ],
                                        'quantity' => [
                                            '$sum' => '$quantity'
                                        ],
                                        'min_weight' => [
                                            '$min' => '$weight'
                                        ],
                                        'max_weight' => [
                                            '$max' => '$weight'
                                        ]
                                        // ,
                                        // 'min_edited_weight'    => [
                                        //     '$min' => '$edited.weight'
                                        // ],
                                        // 'max_edited_weight'    => [
                                        //     '$max' => '$edited.weight'
                                        // ]
                                    ]
                                ]
                            ]
                        );
                        $pChildData = json_decode( toJSON( fromPHP( $pChildData->toArray() ) ), true );
                        $pChildData = array_shift( $pChildData );
                        unset( $pChildData['_id'] );
                        if ( is_array( $pChildData ) ) {
                            $product = array_merge( $product, $pChildData );
                        }
                    }

                    $editedProductData = [];
                    $editedProductDocument = [];
                    // legacy support start.
                    $editedProduct = $mProductContainer->findOne( [ 'user_id' => $product['user_id'], 'source_product_id' => $product['source_product_id'] ?? false, 'shop_id' => $product['shop_id'] ?? false ] );
                    if ( ! is_null( $editedProduct ) ) {
                        $editedProduct = json_decode( toJSON( fromPHP( $editedProduct ) ), true );
                        if ( ! empty( $editedProduct ) ) {
                            unset( $editedProduct['_id'] );
                            $editedProductData = $editedProduct;
                        }
                    }

                    // legacy support end.
                    // $targetDocument = $productContainer->findOne( [ 'user_id' => $product['user_id'], 'shop_id' => $targetShopId, 'source_product_id' => $product['source_product_id'] ] );
                    // if ( ! is_null( $targetDocument ) ) {
                    //     $targetDocument = json_decode( \MongoDB\BSON\toJSON( \MongoDB\BSON\fromPHP( $targetDocument ) ), true );
                    //     if ( ! empty( $targetDocument ) ) {
                    //         unset( $targetDocument['_id'] );
                    //         $editedProductDocument = $targetDocument;
                    //     }
                    // }

                    if ( 'prestashop' === $sourceName ) {
                        if (
                            isset($product['type']) && $product['type'] === 'variation'
                        ) {
                            $product['price']            = $product['price'] ?? 0;
                            $product['quantity']         = $product['quantity'] ?? 0;
                            $product['compare_at_price'] = $product['compare_at_price'] ?? 0;
                        } elseif (
                            isset($product['type']) && $product['type'] === 'simple'
                            && (isset($product['visibility']) && $product['visibility'] === 'Not Visible Individually')
                            && !empty($product['variant_attributes'])
                            && is_array($product['variant_attributes'][0])
                        ) {
                            $currentData = $product['variant_attributes'];
                            $attributes = array_column( $product['variant_attributes'], 'key'  );
                            $product['variant_attributes']        = $attributes;
                            $product['variant_attributes_values'] = $currentData;
                        }
                    }
                    $productMarketplace = $product['marketplace'] ?? array();
                    foreach ( $productMarketplace as $mpData ) {
                        if (
                            isset( $mpData['target_marketplace'], $mpData['source_product_id'], $product['source_product_id'] )
                            && $mpData['source_product_id'] === $product['source_product_id']
                            && ( 'arise' === $mpData['target_marketplace'] || 'joom' === $mpData['target_marketplace'] )
                        ) {
                            unset( $mpData['in_app_validated'], $mpData['item_id'], $mpData['shop_sku'], $mpData['sku_id'], $mpData['status'], $mpData['target_marketplace'], $mpData['source_product_id'], $mpData['shop_id'] );
                            $editedProductDocument = $mpData;
                            break;
                        }
                    }

                    $product['edited'] = array_merge( $editedProductData, $editedProductDocument );
                    unset( $product['_id'] );
                    // legacy code for status.
                    $marketplace = $product['marketplace'] ?? [];
                    foreach ( $marketplace as $targetData ) {
                        if ( isset( $targetData['target_marketplace'] ) && 'arise' === $targetData['target_marketplace'] ) {
                            if ( ! empty( $targetData['status'] ) ) {
                                $product['status'] = $targetData['status'];
                            }

                            if ( ! empty( $targetData['warning_details'] ) ) {
                                $product['warning_details'] = $targetData['warning_details'];
                            }

                            if ( ! empty( $targetData['error_details'] ) ) {
                                $product['error_details'] = $targetData['error_details'];
                            }

                            break;
                        }
                    }

                    // legacy ends.
                    if ( ! empty( $product['source_marketplace'] ) && 'shopify' === $product['source_marketplace'] && isset($product['compare_at_price']) && ! empty( (float) $product['compare_at_price'] ) ) {
                        $temp                        = $product['price'] ?? 0;
                        $product['price']            = $product['compare_at_price'];
                        $product['compare_at_price'] = $temp;
                    }

                    // convert value mapping data.
                    if ( isset( $product['visibility'], $product['attribute_value_mappings'] ) && 'Catalog and Search' === $product['visibility'] ) {
                        $this->changeValueMappingSchemaForFrontend( $product['attribute_value_mappings'] );
                    }

                    return $product;
                },
                $productData
            );
            $products['data']['rows'] = $productData;
        }

        return $this->prepareResponse( $products );
    }

    /**
     * Will update schema for value mapping to be shown in frontend.
     *
     * @since 1.0.0
     */
    private function changeValueMappingSchemaForFrontend( &$valueMappings ): void {
        $data = [];
        foreach ( $valueMappings as $optionMapping ) {
            $finalData = [];
            $values    = $optionMapping['value'] ?? [];
            foreach ( $values as $value ) {
                if ( ! empty( $value['key'] ) ) {
                    $finalData[] = [ $value['key'] => $value['value'] ?? '' ];
                }
            }

            $data[] = [
                'key'   => $optionMapping['key'] ?? '',
                'value' => $finalData
            ];
        }

        $valueMappings = $data;
    }

    /**
     * Will get target product data.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function getTargetProductAction() {
        $params     = $this->getRequestData();
        $targetName = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $targetName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }

        $params['marketplace']  = $targetName;
        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $targetName );
        if ( ! method_exists( $SourceModelMarketPlace, 'getTargetProductData' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->getTargetProductData( $params )
        );

    }

    /**
     * Will return products count.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function getProductsCountAction() {
        return $this->prepareResponse(
            $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer')
            ->getProductsCount(
                $this->getRequestData()
            )
        );
    }

    /**
     * Will return all prduct statuses.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function getAllProductStatusesAction() {
        $params                       = $this->getRequestData();
        $params['target_marketplace'] = $this->di->getRequester()->getTargetName() ?? false;
        return $this->prepareResponse(
            $this->di->getObjectManager()->get('\App\Connector\Models\ProductContainer')
            ->getAllProductStatuses(
                $params
            )
        );
    }

    /**
     * Will initiate import action for product attribute import and then product import.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function importAction() {
        $params     = $this->getRequestData();
        $sourceName = $this->di->getRequester()->getSourceName() ?? false;
        if ( empty( $sourceName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }

        $params['marketplace']  = $sourceName;
        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $sourceName );
        if ( ! method_exists( $SourceModelMarketPlace, 'initiateImport' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->initiateImport( $params )
        );
    }

    /**
     * Will update product at target.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function updateAction() {
        $params     = $this->getRequestData();
        $targetName = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $targetName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }

        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $targetName );
        if ( ! method_exists( $SourceModelMarketPlace, 'updateProductSingle' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist',
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->updateProductSingle( $params )
        );
    }

    /**
     * Upload product to the target marketplace.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function  uploadAction() {
        $params     = $this->getRequestData();
        $targetName = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $targetName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }

        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $targetName );
        if ( ! method_exists( $SourceModelMarketPlace, 'initiateProductUpload' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist',
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->initiateProductUpload( $params )
        );
    }

    /**
     * Attribute set import.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function attributeSetImportAction() {

        $params     = $this->getRequestData();
        $sourceName = $this->di->getRequester()->getSourceName() ?? false;
        if ( empty( $sourceName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found'
                ]
            );
        }

        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $sourceName );
        if ( ! method_exists( $SourceModelMarketPlace, 'attributeSetImport' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->attributeSetImport(
                $params
            )
        );
    }

    /**
     * Will sync status from target to app.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function syncStatusAction() {
        $params     = $this->getRequestData();
        $targetName = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $targetName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }

        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $targetName );
        if ( ! method_exists( $SourceModelMarketPlace, 'initiateProductStatusSync' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist',
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->initiateProductStatusSync( $params )
        );
    }

    /**
     * Will initiate product validation.
     *
     * @deprecated 1.0.2
     *
     * @since 1.0.0
     * @return array
     */
    public function initiateValidationAction() {
        return $this->prepareResponse(
            [
                'success' => false,
                'msg'     => 'This endpoint is deprecated, Please contact admin',
            ]
        );
        $params     = $this->getRequestData();
        $targetName = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $targetName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }

        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $targetName );
        if ( ! method_exists( $SourceModelMarketPlace, 'initiateProductValidation' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist',
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->initiateProductValidation( $params )
        );
    }

    /**
     * Will save product attributes.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function saveProductAttributesAction() {
        return $this->prepareResponse(
            $this->di->getObjectManager()->get( ProductHelper::class )
            ->saveProductAttibutes(
                $this->getRequestData()
            )
        );
    }

    /**
     * Will handle product status change actions.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function changeStatusAction() {
        $params     = $this->getRequestData();
        $targetName = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $targetName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }

        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $targetName );
        if ( ! method_exists( $SourceModelMarketPlace, 'changeProductStatus' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist',
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->changeProductStatus( $params )
        );
    }

    /**
     * Will save edited product data.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function editedSaveAction() {
        $params                       = $this->getRequestData();
        $params['target_marketplace'] = $this->di->getRequester()->getSourceName();
        $response                     = $this->di->getObjectManager()->get( '\App\Connector\Models\Product\Edit' )
                                        ->saveProduct( $params );
        if ( ! empty( $response['success'] ) && ! empty( $params[0]['container_id'] ) ) {
            $targetName             = $this->di->getRequester()->getTargetName() ?? false;
            $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
            $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $targetName );
            if ( method_exists( $SourceModelMarketPlace, 'initiateProductValidation' ) ) {
                $SourceModelMarketPlace->initiateProductValidation( [ 'container_id' => $params[0]['container_id'] ] );
            }
        }

        return $this->prepareResponse( $response );
    }

    /**
     * Will get current url.
     *
     * @since 1.0.0
     * @return string
     */
    public function currentUrl(){
        return sprintf(
            "%s://%s",
            isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',
            $_SERVER['HTTP_HOST'] ?? 'urlnotfound'
        );
    }

    /**
     * Will upload image from content.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function uploadImageAction() {
        try {
            if (  $this->request->hasFiles() ) {
                $userId    = $this->di->getUser()->id ?? false;
                $files     = $this->request->getUploadedFiles();
                if ( count( $files ) > 8 ) {
                    return $this->prepareResponse(
                        [
                            'success' => false,
                            'msg'     => $this->di->getLocale()->_( 'Maximum limit of 8 images reached. Please remove extra images.' )
                        ]
                    );
                }

                $fileData = [];
                $dir = BP . DS . 'public' . DS . 'assets' . DS . 'images' . DS . $userId . DS;
                if ( ! is_dir( $dir ) ) {
                    mkdir( $dir, 0755, true );
                }

                $supportedFiles = ['png', 'jpg', 'jpeg'];
                $homeUrl        = $this->currentUrl();
                foreach ( $files as $file ) {
                    $fileName = $file->getName();
                    $info     = strtolower( pathinfo( (string) $fileName, PATHINFO_EXTENSION ) );
                    if ( ! in_array( $info, $supportedFiles, true ) ) {
                        continue;
                    }

                    $encodeFileName = pathinfo( str_replace( ' ', '_', $fileName ), PATHINFO_FILENAME ) . '.' . $info;
                    $filePath       = $dir . $encodeFileName;
                    if ( ! file_exists( $filePath ) ) {
                        $file->moveTo(
                            $filePath
                        );
                    }

                    $imageUrl   = "{$homeUrl}/assets/images/{$userId}/" . $encodeFileName;
                    $targetName = $this->di->getRequester()->getTargetName() ?? '';
                    $targetName = ucfirst((string) $targetName);
                    $className  = "\App\\{$targetName}home\Components\Product\ProductHelper";
                    $method     = 'uploadImageTarget';
                    if (method_exists($className, $method)) {
                        $res = $this->di->getObjectManager()->get($className)
                        ->$method(
                            [
                                'image_url' => $imageUrl,
                                'file_path' => $filePath
                            ]
                        );
                        if (! empty($res['success']) && ! empty($res['data'])) {
                            @unlink($filePath);
                            $imageUrl = $res['data'];
                        }
                    }

                    $fileData[] = $imageUrl;
                }

                if ( empty( $fileData ) ) {
                    return $this->prepareResponse(
                        [
                            'success' => false,
                            'msg'     => $this->di->getLocale()->_( 'Oops! File not found' )
                        ]
                    );
                }

                return $this->prepareResponse( [ 'success' => true, 'data' => $fileData ] );
            }

            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => $this->di->getLocale()->_( 'Oops! File not found' )
                ]
            );

        } catch ( Exception $e ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => $e->getMessage()
                ]
            );
        }
    }

    /**
     * Will get url encoded image.
     *
     * @param string $image current image url.
     * @since 1.0.0
     * @return string
     */
    public function getUrlEncodedImage( $image = '' ) {
        if ( empty( $image ) ) {
            return $image;
        }

        $imageDescription = pathinfo( $image );
        if ( isset( $imageDescription['dirname'], $imageDescription['filename'], $imageDescription['extension'] ) ) {
            return $imageDescription['dirname'] . '/' . urlencode( $imageDescription['filename'] ) . '.' . $imageDescription['extension'];
        }

        return $image;
    }

    /**
     * Will validate single product.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function validateSingleAction() {
        $params     = $this->getRequestData();
        $targetName = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $targetName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }

        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $targetName );
        if ( ! method_exists( $SourceModelMarketPlace, 'validateSingleProduct' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist',
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->validateSingleProduct( $params )
        );
    }

    /**
     * Will update single product.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function updateSelectedAction() {
        $params     = $this->getRequestData();
        $targetName = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $targetName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }

        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $targetName );
        if ( ! method_exists( $SourceModelMarketPlace, 'updateSingleSelectedProduct' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist',
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->updateSingleSelectedProduct( $params )
        );
    }

    /**
     * Will get image content.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function getImageContentAction() {
        try {
            $params = $this->getRequestData();
            if ( empty( $params['image'] ) || ! is_array( $params['image'] ) ) {
                return $this->prepareResponse(
                    [
                        'success' => false,
                        'msg'     => 'Image URLs are required to get the content.'
                    ]
                );
            }

            $images  = array_unique( array_filter( $params['image'] ) );
            if( ( $this->di->getUser()->id  ?? false ) === '63515ea418d3800c240f4a7d'){
                return $this->prepareResponse(
                    [
                        'success' => true,
                        'data'     => array_combine($images,$images)
                    ]
                );
            }

            $imgData = [];
            foreach ( $images as $image ) {
                $type              = @mime_content_type( $image );
                $data              = @file_get_contents( $image );
                $imgData[ $image ] = empty( $data ) ? $image :'data:image/' . $type . ';base64,' . base64_encode( $data );
            }

            return $this->prepareResponse(
                [
                    'success' => true,
                    'data'    => $imgData
                ]
            );
        } catch ( Exception $e ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'    => $e->getMessage()
                ]
            );
        }
    }

    /**
     * Will update price and quantity.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function updatePriceQuantityAction() {
        $params     = $this->getRequestData();
        $targetName = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $targetName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }

        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $targetName );
        if ( ! method_exists( $SourceModelMarketPlace, 'updateSelectedProductsPriceQuantity' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist',
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->updateSelectedProductsPriceQuantity( $params )
        );
    }

    /**
     * Function to get all Shipping Destination on target marketplace.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function getAllShippingDestinationsAction() {
        $params     = $this->getRequestData();
        $targetName = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $targetName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }

        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $targetName );
        if ( ! method_exists( $SourceModelMarketPlace, 'getAllShippingDestinations' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist',
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->getAllShippingDestinations( $params )
        );
    }

    /**
     * Function to create Shipping Destination on target marketplace.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function createShippingDestinationAction() {
        $params     = $this->getRequestData();
        $targetName = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $targetName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }

        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $targetName );
        if ( ! method_exists( $SourceModelMarketPlace, 'createShippingDestination' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist',
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->createShippingDestination( $params )
        );
    }

    /**
     * Upload product Using Smart Posting to the target marketplace.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function uploadUsingSmartPostAction() {
        $params     = $this->getRequestData();
        $targetName = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $targetName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }
        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $targetName );
        if ( ! method_exists( $SourceModelMarketPlace, 'initiateSmartProductUploadOrUpdate' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist',
                ]
            );
        }
        return $this->prepareResponse(
            $SourceModelMarketPlace->initiateSmartProductUploadOrUpdate( $params )
        );
    }

    /**
     * Function to get Shopify Product Inventory.
     *
     */
    public function getInventorybyIDAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Frontend\Components\AdminpanelamazonmultiHelper')->getInventorybyId($rawBody);
        return $this->prepareResponse($response);
    }

    /**
     * Function to get Verify Sales Channel Assignment.
     *
     */
    public function checkSalesChannelAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Frontend\Components\AdminpanelamazonmultiHelper')->verifySalesChannel($rawBody);
        return $this->prepareResponse($response);
    }

    /**
     * Function to delete given Container ID from Product Container and Refine Product Collection.
     *
     */
    public function checkAndDeleteProductAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Frontend\Components\AdminpanelamazonmultiHelper')->deleteProductbyProductId($rawBody);
        return $this->prepareResponse($response);
    }

    /**
     * Function to get Shopify Webhook Data
     *
     */
    public function getShopifyWebhookDataAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Frontend\Components\AdminpanelamazonmultiHelper')->getShopifyWebhookData($rawBody);
        return $this->prepareResponse($response);
    }

    /**
     * Function to get Amazon Webhook Data
     *
     */
    public function getAmazonWebhookDataAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Frontend\Components\AdminpanelamazonmultiHelper')->getAmazonWebhookData($rawBody);
        return $this->prepareResponse($response);
    }

    /**
     * Function to get Metafield Definition
     *
     */
    public function getMetafieldTypeAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Frontend\Components\AdminpanelamazonmultiHelper')->getMetafieldType($rawBody);
        return $this->prepareResponse($response);
    }

    public function getFulfillmentDetailsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $response = $this->di->getObjectManager()->get('\App\Frontend\Components\AdminpanelamazonmultiHelper')->getFulfillmentDetails($rawBody);
        return $this->prepareResponse($response);
    }
}