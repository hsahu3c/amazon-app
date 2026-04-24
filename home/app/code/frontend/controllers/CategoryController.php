<?php
namespace App\Frontend\Controllers;
use App\Core\Controllers\BaseController;

/**
 * Will handle Category requests.
 *
 * @since 1.0.0
 */
class CategoryController extends BaseController {

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
     * Will initiate call to import category.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function categoryImportAction() {
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

        $connectorsHelpler      = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelpler->getConnectorModelByCode( $sourceName );
        if ( ! method_exists( $SourceModelMarketPlace, 'categoryImport' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->categoryImport(
                $params
            )
        );
    }

    /**
     * This function will validate category
     *
     * @return array
     */
    public function validateCategoryAction() {
        $params                = $this->getRequestData();
        $params['marketplace'] = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $params['marketplace'] ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found'
                ]
            );
        }

        $connectorsHelpler      = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelpler->getConnectorModelByCode( $params['marketplace'] );
        if ( ! method_exists( $SourceModelMarketPlace, 'validateCategory' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->validateCategory(
                $params
            )
        );
    }

    /**
     * This function is used to get target category list.
     *
     * @return string
     */
    public function getTargetCategoryListAction() {
        $params                = $this->getRequestData();
        $params['marketplace'] = $this->di->getRequester()->getTargetName() ?? false;
		$params['appLocale'] = strtolower( (string) $this->request->getHeader('appLocale') ) ?? 'en' ;
        if ( empty( $params['marketplace'] ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found'
                ]
            );
        }

        $connectorsHelpler      = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelpler->getConnectorModelByCode( $params['marketplace'] );
        if ( ! method_exists( $SourceModelMarketPlace, 'getTargetCategoryList' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->getTargetCategoryList(
                $params
            )
        );
	}

    /**
	 * This function is used to get arise attribute
	 *
	 * @return string
	 */
	public function getAttributesAction() {
        $params                = $this->getRequestData();
        $params['marketplace'] = $this->di->getRequester()->getTargetName() ?? false;
		$params['appLocale'] = strtolower( (string) $this->request->getHeader('appLocale') ) ?? 'en' ;
        if ( empty( $params['marketplace'] ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found'
                ]
            );
        }

        $connectorsHelpler      = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelpler->getConnectorModelByCode( $params['marketplace'] );
        if ( ! method_exists( $SourceModelMarketPlace, 'getAttributes' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }
        
        return $this->prepareResponse(
            $SourceModelMarketPlace->getAttributes(
                $params
            )
        );	
	
	}

    /**
     * Get attribute options.
     * 
     * @since 1.0.0
     * @param string|boolean $presetMarketplace either used internally or called by API.
     * @return mixed
     */
    public function getAttributesOptionsAction( $presetMarketplace = false ) {
        $params                       = $this->getRequestData();
        $params['source_marketplace'] = $this->di->getRequester()->getSourceName() ?? false;
        if ( empty( $params['source_marketplace'] ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Source Marketplace not found'
                ]
            );
        }

        if ( ! $presetMarketplace ) {
            $marketplace = $params['source_marketplace'];
        } else {
            $marketplace = $presetMarketplace;
        }

        if ( $marketplace ) {
            $SourceModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')->getConnectorModelByCode( $marketplace );
            if ( ! method_exists( $SourceModelMarketPlace, 'getAttributesOptions' ) ) {
                return $this->prepareResponse(
                    [
                        'success' => false,
                        'msg'     => 'Method does not exist'
                    ]
                );
            }

            if ( $presetMarketplace ) {
                return $SourceModelMarketPlace->getAttributesOptions( $marketplace );
            }

            return $this->prepareResponse(
                [
                    'success' => true,
                    'code'    => '',
                    'message' => '',
                    'data'    => $SourceModelMarketPlace->getAttributesOptions( $marketplace )
                ]
            );
        }

        return $this->prepareResponse(
            [
                'success' => false,
                'code'    => 'missing_required_params',
                'message' => 'Missing Marketplace Code'
            ]
        );
    }

    /**
     * Get data for rule group query.
     *
     * @since 1.0.0
     * @return array.
     */
    public function getRuleGroupAction() {
        $marketplace = $this->di->getRequester()->getSourceName() ?? false;
        if ( empty( $marketplace ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'code'    => 'missing_required_params',
                    'message' => 'Missing Marketplace Code'
                ]
            );
        }

        $SourceModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')
                                ->getConnectorModelByCode( $marketplace );
        if ( ! method_exists( $SourceModelMarketPlace, 'getRuleGroup' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $this->prepareResponse(
            [
               'success' => true,
               'data'    => $SourceModelMarketPlace->getRuleGroup()
            ]
        );
    }

    /**
     * Get data for rule group query.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function getTitleRuleGroupAction() {
        $marketplace = $this->di->getRequester()->getSourceName() ?? false;
        if ( empty( $marketplace ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'code'    => 'missing_required_params',
                    'message' => 'Missing Marketplace Code'
                ]
            );
        }

        $SourceModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')
                                ->getConnectorModelByCode( $marketplace );
        if ( ! method_exists( $SourceModelMarketPlace, 'getTitleRuleGroup' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $this->prepareResponse(
            [
                'success' => true,
                'data'    => $SourceModelMarketPlace->getTitleRuleGroup()
            ]
        );
    }

    /**
     * Get data for rule group query.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function getPriceRuleGroupAction() {
        $marketplace = $this->di->getRequester()->getTargetName() ?? false;
        if ( empty( $marketplace ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'message' => $this->di->getLocale()->_( 'Missing Marketplace Code' ),
                    'code'    => 'missing_required_params'
                ]
            );
        }

        $SourceModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')
                                ->getConnectorModelByCode( $marketplace );
        if ( ! method_exists( $SourceModelMarketPlace, 'getPriceRuleGroup' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'message' => 'Method does not exist'
                ]
            );
        }

        return $this->prepareResponse(
            [
                'success' => true,
                'data'    => $SourceModelMarketPlace->getPriceRuleGroup()
            ]
        );
    }

    /**
     * Brand import.
     *
     * @return array
     */
    public function brandImportAction(){
		$marketplace = $this->di->getRequester()->getTargetName() ?? 'arise';
        if ( $marketplace ) {
            $connectorsHelpler = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
            $data = $connectorsHelpler->getConnectorModelByCode( $marketplace )->initiateBrandImport();

            return $this->prepareResponse($data);
        }

        return $this->prepareResponse(['success' => false, 'code' => 'missing_required_params', 'message' => $this->di->getLocale()->_( 'The marketplace code is missing.' ) ]);
    }

    /**
     * Will initiate call to sync category.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function syncCategoryImportAction() {
        $params     = $this->getRequestData();
        $sourceName = $this->di->getRequester()->getSourceName() ?? false;
        if ( empty( $sourceName ) || 'magento' !== $sourceName ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace not found'
                ]
            );
        }

        $connectorsHelpler      = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelpler->getConnectorModelByCode( $sourceName );
        if ( ! method_exists( $SourceModelMarketPlace, 'categoryImport' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $this->prepareResponse(
            $SourceModelMarketPlace->categoryImport(
                $params
            )
        );
    }

}