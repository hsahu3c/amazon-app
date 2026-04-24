<?php
namespace App\Frontend\Controllers;
use App\Core\Controllers\BaseController;

/**
 * Will handle Profile requests.
 *
 * @since 1.0.0
 */
class RequestController extends BaseController {

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
	 * Function used to get shipping carriers
	 *
	 * @return string
	 */
	public function getShippingCarriersAction() {
		$param = $this->getRequestData();
        $source = $this->di->getRequester()->getSourceName();
        $target = $this->di->getRequester()->getTargetName();
        if ( empty( $source ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Source Marketplace not found'
                ]
            );
        }

        if ( empty( $target ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Target Marketplace not found'
                ]
            );
        }

		$refresh = $param['refresh'] ?? false;
       return  $this->prepareResponse( $this->getShippingCarriers( $target, $source, $refresh )  );	

	}

    /**
     * fetch carrier list 
     *
     * @return string
     */
    public function getCarrierListAction() {

        return $this->prepareResponse( 
               $this->getTargetShippingCarrier( $this->di->getRequester()->getTargetName())
            );

    }


    /**
    * This function is used to get shipping carriers from magento and arise.
    *
    * @return array
    */
    public function getShippingCarriers( $target, $source, $refresh ) {
        $arise = $this->getTargetShippingCarrier( $target, $refresh );
        $magento = $this->getSourceShippingCarrier( $source,  $refresh );


        $ariseCheck = false;
        $magentoCheck = false;
        $automapArr = [];
        if( ! empty( $magento['success'] ) && true === $magento['success'] ) {
            $magento = $magento['data'];
            $magentoCheck = true;
        }

        if( ! empty( $arise['success'] ) && true === $arise['success'] ) {
            $arise = $arise['data'];
            $ariseCheck = true;
        }
        if (false === $ariseCheck) {
            return [
                'success' => false,
                'msg'     => 'Oops!! There is an error in api from ' . $target . '!, Please try again later.'
            ];
        }

        if (false === $magentoCheck) {
            return [
                'success' => false,
                'msg'     => 'Oops!! There is an error in api from ' . $source . '!, Please try again later.'
            ];
        }

        $ariseServiceName = array_map( 'strtoupper', $arise );
        foreach( $magento as $k => $val ) {
            $automapArr[] = [
                'key' => $k,
                'value' => $val,
                'map_with' => $this->mapMagentoValue( strtoupper( (string) $k ), $ariseServiceName )
            ];
        }

        $finalResponse = [
            'success' => true,
            'source_carrier_list' => $automapArr,
            'target_carrier_list' => $arise
        ];
        if( $refresh ) {
            $finalResponse['msg'] = $this->di->getLocale()->_( 'Shipping Carriers Updated Successfully' );
        }

        return $finalResponse;
    }

    /**
     * This function is used to map magento value with arise value.
    *
    * @param string  $search string to be search in the arise array.
    * @param array   $totalArray arise shipping carriers array.
    * @return mixed
    */
    public function mapMagentoValue( $search, $totalArray)  {

        foreach ( $totalArray as $k => $arrayVal ) {
            if ( str_contains( (string) $arrayVal, $search ) ) {
                return $k;
            }
        }

        return false;
    }


	/**
	 * To get the source shipping carriers
	 *
	 * @param string $source source marketplace
	 * @return array
	 */
	public function getTargetShippingCarrier( $target, $refresh = false ) {


        $SourceModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')->getConnectorModelByCode( $target );
        if ( ! method_exists( $SourceModelMarketPlace, 'getShippingCarrier' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $SourceModelMarketPlace->getShippingCarrier( $refresh );

	}

	/**
	 * To get the target shipping carriers
	 *
	 * @param string $target target marketplace
	 * @return array
	 */
	public function getSourceShippingCarrier( $source , $refresh) {


        $SourceModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')->getConnectorModelByCode( $source );
        if ( ! method_exists( $SourceModelMarketPlace, 'getShippingCarrier' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $SourceModelMarketPlace->getShippingCarrier( $refresh );

	}

    /**
     * Function to update shippping carrier on woocommerce
     *
     * @return string
     */
    public function updateShippingCarrierAction() {

        $param = $this->getRequestData();
        $param['data'] = json_decode( (string) $param['data'], true );
        $param['marketplace'] = $this->di->getRequester()->getTargetName() ?? $param['marketplace'];
        $source = $this->di->getRequester()->getSourceName() ?? 'woocommerce';
        if ( empty( $source ) || 'woocommerce' !== $source ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Source Marketplace not found'
                ]
            );
        }

        $SourceModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')->getConnectorModelByCode( $source );
        if ( ! method_exists( $SourceModelMarketPlace, 'updateShippingCarrier' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

       return  $this->prepareResponse(  $SourceModelMarketPlace->updateShippingCarrier( $param )  );	
    }


    /**
     * Will fetch the source marketplace currency
     *
     * @return string
     */
    public function getStoreCurrencyAction() {

        $source = $this->di->getRequester()->getSourceName();
        if ( empty( $source ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Source Marketplace not found'
                ]
            );
        }

        $SourceModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')->getConnectorModelByCode( $source );
        if ( ! method_exists( $SourceModelMarketPlace, 'getStoreCurrency' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $this->prepareResponse( $SourceModelMarketPlace->getStoreCurrency() );

    }

    /**
	 * Function used to get brand mapping options
	 *
	 * @return string
	 */
	public function getBarndMappingOptionsAction() {
		$param = $this->getRequestData();
        $source = $this->di->getRequester()->getSourceName();
        $target = $this->di->getRequester()->getTargetName();
        if ( empty( $source ) || empty( $target ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Source Marketplace not found'
                ]
            );
        }

		$targetModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')->getConnectorModelByCode( $target );
        if ( ! method_exists( $targetModelMarketPlace, 'getBrandOptionMapping' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

       return  $this->prepareResponse( $targetModelMarketPlace->getBrandOptionMapping( $param ) );
	}

    /**
	 * Function used to get brand mapping.
	 *
	 * @return string
	 */
	public function getBrandMappingAction() {
		$param = $this->getRequestData();
        $source = $this->di->getRequester()->getSourceName();
        $target = $this->di->getRequester()->getTargetName();
        if ( empty( $source ) || empty( $target ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Source Marketplace not found'
                ]
            );
        }

		$targetModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')->getConnectorModelByCode( $target );
        if ( ! method_exists( $targetModelMarketPlace, 'getBrandMapping' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

       return  $this->prepareResponse( $targetModelMarketPlace->getBrandMapping( $param ) );
	}

    public function updateSelectedStoreAction() {
        $params     = $this->getRequestData();
        $target = $this->di->getRequester()->getTargetName();
        if ( empty( $target ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Target Marketplace not found'
                ]
            );
        }

        $SourceModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')->getConnectorModelByCode( $target );
        if ( ! method_exists( $SourceModelMarketPlace, 'updateSelectedStore' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $this->prepareResponse( $SourceModelMarketPlace->updateSelectedStore($params) );

    }

    public function getAllStorelistAction() {
        $params     = $this->getRequestData();
        $target = $this->di->getRequester()->getTargetName();
        if ( empty( $target ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Source Marketplace not found'
                ]
            );
        }

        $SourceModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')->getConnectorModelByCode( $target );
        if ( ! method_exists( $SourceModelMarketPlace, 'getAllStorelist' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $this->prepareResponse( $SourceModelMarketPlace->getAllStorelist($params) );

    }

}