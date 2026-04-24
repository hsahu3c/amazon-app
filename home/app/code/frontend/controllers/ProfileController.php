<?php
namespace App\Frontend\Controllers;
use App\Connector\Components\Profile\ProfileHelper;
use App\Connector\Components\Profile\DefaultSettings;
use App\Connector\Components\Profile\Validation;
use App\Connector\Components\Profile\QueryProducts;
use App\Frontend\Components\ProductHelper;
use function MongoDB\BSON\toJSON;
use function MongoDB\BSON\fromPHP;
use App\Core\Controllers\BaseController;

/**
 * Will handle Profile requests.
 *
 * @since 1.0.0
 */
class ProfileController extends BaseController {

    public const IS_EQUAL_TO = 1;

    public const IS_NOT_EQUAL_TO = 2;

    public const IS_CONTAINS = 3;

    public const IS_NOT_CONTAINS = 4;

    public const START_FROM = 5;

    public const END_FROM = 6;

    public const RANGE = 7;

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
     * Will return all profiles data.
     *
     * @since 1.0.0
     * @return array
     */
    public function getProfileDataAction() {
        $rawBody      = $this->getRequestData();

        $profileModel = new ProfileHelper;


        if (isset($rawBody['filter']) || isset($rawBody['search'])) {
            $conditionalQuery = self::search($rawBody);

            $conditionalQuery['user_id'] = (string)$this->di->getUser()->id;
            $conditionalQuery[ 'shop_ids' ] = [
                '$elemMatch' => [
                    'target' => $this->di->getRequester()->getTargetId(),
                    'source' => $this->di->getRequester()->getSourceId()
                ]
            ];
            $conditionalQuery['type'] = 'profile';
                    $aggregation = [
                        [
                            '$project' => [
                                'targets' => 0,
                                'marketplaceAttributes' => 0,
                                // '_id' => 1
                            ]
                        ],
                        ['$match' => $conditionalQuery],
                    ];
            $mongo   = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo' );
            $collection = $mongo->getCollectionForTable("profile");
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $rows = $collection->aggregate($aggregation, $options);
            $rows = $rows->toArray();
            $aggregationForCount = [
                ['$match' => $conditionalQuery],
                ['$count' => 'count']
            ];
            $totalRows = $collection->aggregate($aggregationForCount, $options);
            $totalRows = $totalRows->toArray();
            $profiledata = [];
            $totalCount = count($totalRows) ? $totalRows[0]['count'] : 0;

            foreach ( $rows as $k => $val ) {
                    $profiledata[]    = $profileModel->getProfileData( ['id' => (string)$val['_id'] ] );
            }

            $profiledata =  array_values( $profiledata );
            if( ! empty( $profiledata[0]['success'] ) ) {
                $main_arr = [];
                foreach( $profiledata as $val ) {
                    $main_arr['rows'][] = array_shift( $val['data'] );
                }
            } else {
                return $this->prepareResponse([
                    'success' => false,
                    'msg'     => 'No Profile Found'
                ] );
            }


            $productCollection = $mongo->getCollectionForTable('product_container');
            $defaultCount = $productCollection->count( [ '$or' => [ [ 'profile' =>  [ '$exists' => true,'$size' => 0 ] ], [ 'profile' => ['$elemMatch' => ['profile_name' => 'Default', 'target_shop_id' => $this->di->getRequester()->getTargetId() ]] ], [ 'profile' => [ '$exists' => false ] ]], '$and' => [[ 'visibility' => 'Catalog and Search' ], [ 'user_id' => $this->di->getUser()->id ], [ 'source_marketplace' => $this->di->getRequester()->getSourceName()] ] ]);
            foreach( $main_arr as $k => $val ) {
                if( 'default' === strtolower( $val['name'] ) ) {
                    $main_arr[$k]['product_count'] = [['count'=> $defaultCount]];
                }
            } 

            return $this->prepareResponse([
                'success' => true,
                'data'    => $main_arr,
                'total_count'   => $totalCount
            ]);

        }

        $returnArr    = $profileModel->getProfileData($rawBody);
        if( ! empty( $returnArr['success'] ) ) {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('product_container');
            $defaultCount = $collection->count( [ '$or' => [ [ 'profile' =>  [ '$exists' => true,'$size' => 0 ] ], [ 'profile' => ['$elemMatch' => ['profile_name' => 'Default', 'target_shop_id' => $this->di->getRequester()->getTargetId() ]] ], [ 'profile' => [ '$exists' => false ] ]], '$and' => [[ 'visibility' => 'Catalog and Search' ], [ 'user_id' => $this->di->getUser()->id ], [ 'source_marketplace' => $this->di->getRequester()->getSourceName()] ] ]);
            $data = $returnArr['data']['rows'] ?? [];
            $data = json_decode( toJSON( fromPHP( $data )), true);
            $profileName = [];
            $allProfileNames = [];
            foreach( $data as $k => $val ) {
                $allProfileNames[] = $val['name'];
                if( 'default' === strtolower( (string) $val['name'] ) ) {
                    $data[$k]['product_count']        = [['count'=>$defaultCount]];
                    if ( $defaultCount ) {
                        $profileName[$val['_id']['$oid']] = $val['name'];
                    }

                    continue;
                }

                $checkcount = array_column( $val['product_count'], 'count' );
                if( $checkcount !== [] ) {
                    $profileName[$val['_id']['$oid']] = $val['name'];
                }
            }

            $returnArr['names'] = $profileName;
            $returnArr['data']['rows'] = $data;
            $returnArr['all_names'] = $allProfileNames;

        }


        return $this->prepareResponse($returnArr);
    }

    /**
     * Function is used to save profile.
     *
     * @return string
     */
    public function saveProfileAction() {

        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->getRequestData();
            if (!empty($rawBody)) {
                $profileModel = new ProfileHelper;
                $res = $profileModel->saveProfile($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $returnData = ['success' => false, 'code' => 'header_missing', 'message' => 'Header missing'];
        }

        if ( ! empty( $rawBody['data']['name'] ) ) {
            $targetName             = $this->di->getRequester()->getTargetName() ?? false;
            $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
            $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $targetName );
            if ( method_exists( $SourceModelMarketPlace, 'initiateProductValidation' ) ) {
                $SourceModelMarketPlace->initiateProductValidation( $rawBody, true );
            }
        }

        return $this->prepareResponse($returnData);

    }

    /**
     * Function to save default profile data.
     *
     * @return string
     */
    public function saveDefaultAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = $this->getRequestData();
        if (str_contains((string) $contentType, 'application/json')) {
            if (!empty($rawBody)) {
                $profileModel = new DefaultSettings;
                $res = $profileModel->saveDefaultSettings($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $profileModel = new DefaultSettings;
            $returnData = $profileModel->saveDefaultSettings($rawBody);

        }

        return $this->prepareResponse($returnData);
    }

    /**
     * This function is used to validate profile name
     *
     * @return string
     */
    public function validateProfileNameAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = $this->getRequestData();
        if (str_contains((string) $contentType, 'application/json')) {
            if (!empty($rawBody)) {
                $profileModel = new Validation;
                $res = $profileModel->validateProfieName($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $returnData = ['success' => false, 'code' => 'header_missing', 'message' => 'Header missing'];
        }

        return $this->prepareResponse($returnData);
    }

    /**
     * Get Products count.
     *
     * @return string
     */
    public function getQueryProductsCountAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $userId = $this->di->getUser()->getId();
        $rawBody = $this->getRequestData();
        if (str_contains((string) $contentType, 'application/json')) {
            if (!empty($rawBody)) {
                $profileModel = new QueryProducts;
                $res = $profileModel->getQueryProductsCount($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $profileModel = new QueryProducts;
            $returnData = $profileModel->getQueryProductsCount($rawBody);
        }

        return $this->prepareResponse($returnData);
    }

    /**
     * Function to delete Profile
     *
     * @return string
     */
    public function deleteProfileAction()
    {
        $rawBody = $this->getRequestData();
        $profileModel = new ProfileHelper;
        $returnArr = $profileModel->deleteProfile($rawBody);
        return $this->prepareResponse($returnArr);
    }

    public static function search($filterParams = [])
        {
            $conditions = [];
            if (isset($filterParams['filter'])) {
                foreach ($filterParams['filter'] as $key => $value) {
                    $key = trim((string) $key);
                    if (array_key_exists(self::IS_EQUAL_TO, $value)) {
                        $conditions[$key] = [
                            '$regex' => '^' . $value[self::IS_EQUAL_TO] . '$',
                            '$options' => 'i'
                        ];
                    } elseif (array_key_exists(self::IS_NOT_EQUAL_TO, $value)) {
                        $conditions[$key] = [
                            '$not' => [
                                '$regex' => '^' . trim(addslashes((string) $value[self::IS_NOT_EQUAL_TO])) . '$',
                                '$options' => 'i'
                            ]
                        ];
                    } elseif (array_key_exists(self::IS_CONTAINS, $value)) {
                        $conditions[$key] = [
                            '$regex' =>  trim(addslashes((string) $value[self::IS_CONTAINS])),
                            '$options' => 'i'
                        ];
                    } elseif (array_key_exists(self::IS_NOT_CONTAINS, $value)) {
                        $conditions[$key] = [
                            '$regex' => "^((?!" . trim(addslashes((string) $value[self::IS_NOT_CONTAINS])) . ").)*$",
                            '$options' => 'i'
                        ];
                    } elseif (array_key_exists(self::START_FROM, $value)) {
                        $conditions[$key] = [
                            '$regex' => "^" . trim(addslashes((string) $value[self::START_FROM])),
                            '$options' => 'i'
                        ];
                    } elseif (array_key_exists(self::END_FROM, $value)) {
                        $conditions[$key] = [
                            '$regex' => trim(addslashes((string) $value[self::END_FROM])) . "$",
                            '$options' => 'i'
                        ];
                    } elseif (array_key_exists(self::RANGE, $value)) {
                        if (trim((string) $value[self::RANGE]['from']) && !trim((string) $value[self::RANGE]['to'])) {
                            $conditions[$key] =  ['$gte' => trim((string) $value[self::RANGE]['from'])];
                        } elseif (trim((string) $value[self::RANGE]['to']) &&
                            !trim((string) $value[self::RANGE]['from'])) {
                            $conditions[$key] =  ['$lte' => trim((string) $value[self::RANGE]['to'])];
                        } else {
                            $conditions[$key] =  [
                                '$gte' => trim((string) $value[self::RANGE]['from']),
                                '$lte' => trim((string) $value[self::RANGE]['to'])
                            ];
                        }
                    }
                }
            }

        if (isset($filterParams['search'])) {
            $conditions['$text'] = ['$search' => trim(addslashes((string) $filterParams['search']))];
        }

        return $conditions;
    }

    /**
     * Function to get profile data count
     *
     * @return string
     */
    public function getProfileDataCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (!empty($rawBody)) {
                $profileModel = new ProfileHelper;
                $res = $profileModel->getProfileDataCount($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $rawBody = $this->di->getRequest()->get();
            $profileModel = new ProfileHelper;
            $returnData = $profileModel->getProfileDataCount($rawBody);
            // $returnData = ['success' => false, 'code' => 'header_missing', 'message' => 'Header missing'];
        }

        return $this->prepareResponse($returnData);
    }

    /**
     * Function used to validate profile name and query.
     *
     * @return string
     */
    public function validateProfileAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $userId = $this->di->getUser()->getId();

        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
            // print_r($rawBody);die;
            if (!empty($rawBody)) {
                $profileModel = new Validation;
                $res = $profileModel->validateProfile($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $returnData = ['success' => false, 'code' => 'header_missing', 'message' => 'Header missing'];
        }

        return $this->prepareResponse($returnData);
    }

    /**
     * Will get product variant attributes.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function getVariantAttributesAction() {
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

        if ( 'shopify' === $sourceName ) {
            return $this->prepareResponse(
                $this->di->getObjectManager()->get( ProductHelper::class )
                ->getVariantAttributes( $params )
            );
        }

        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $sourceName );
        if ( ! method_exists( $SourceModelMarketPlace, 'getVariantAttributes' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist',
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->getVariantAttributes( $params )
        );
    }

    /**
     * Will save variant attributes mapping data.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function saveVariantAttributesMappingAction() {
        $params     = $this->getRequestData();
        $sourceName = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $sourceName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }

        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $sourceName );
        if ( ! method_exists( $SourceModelMarketPlace, 'saveVariantAttributesMapping' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist',
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->saveVariantAttributesMapping( $params )
        );
    }

    /**
     * WIll get variant attribute value mappings data.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function getVariantAttributesValueMappingsAction() {
        $params     = $this->getRequestData();
        $sourceName = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $sourceName ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found',
                ]
            );
        }

        $connectorsHelper       = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelper->getConnectorModelByCode( $sourceName );
        if ( ! method_exists( $SourceModelMarketPlace, 'getVariantAttributesValueMappings' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist',
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->getVariantAttributesValueMappings( $params )
        );
    }
}