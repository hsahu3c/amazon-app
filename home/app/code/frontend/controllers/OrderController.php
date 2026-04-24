<?php
namespace App\Frontend\Controllers;
use function MongoDB\BSON\toJSON;
use function MongoDB\BSON\fromPHP;
use App\Core\Controllers\BaseController;

/**
 * Will handle product requests.
 *
 * @since 1.0.0
 */
#[\AllowDynamicProperties]
class OrderController extends BaseController {

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
      * Function is used to get orders
      *
      * @return string
      */
     public function getOrdersAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $params = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $params = $this->request->getJsonRawBody(true);
        } else {
            $params = $this->getRequestData();
        }

        // $ordersModel = new \App\Connector\Models\Order;
        // $responseData = $ordersModel->getOrders($rawBody);
        $conditionalQuery = [];
        $getfulfullied = false;
        $getUnfulfullied = false;

        if (!isset($params['activePage'])) {
            $params['activePage'] = 1;
        }

        $limit = $params['activePage'] * $params['count'];
        $offset = $limit - $params['count'];
        $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $this->_baseMongo->getCollectionForTable("order_container");
//        $collection = $this->getCollection();
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $conditionalQuery['user_id'] = (string)$this->di->getUser()->id;
        $conditionalQuery['parent'] = true;

        // if ($conditionalQuery &&
        //     count($conditionalQuery)) {
        //     if ($getfulfullied) {
        //         $conditionalQuery = [
        //             '$and' => [
        //                 [
        //                     "fulfillments"=>[
        //                         '$exists'=>true
        //                     ]
        //                 ],
        //                 $conditionalQuery
        //             ]
        //         ];
        //     } elseif ($getUnfulfullied) {
        //         $conditionalQuery = [
        //             '$and' => [
        //                 [
        //                     "fulfillments" => [
        //                         '$exists' => false
        //                     ],
        //                     "target_order_id" => [
        //                         '$ne' => ''
        //                     ]
        //                 ],
        //                 $conditionalQuery
        //             ]
        //         ];
        //     }
        // }


        $aggregation[] = [
            '$match' => $conditionalQuery
        ];
        $aggregation[] = [
            '$sort' => [
                'placed_at' => -1
            ]
        ];
        $aggregation[] = [
            '$limit' => (int)$limit
        ];
        $aggregation[] = [
            '$skip' => (int)$offset
        ];

        $dbaArr = [];
        $dbsArr = [];

        $parentData = $collection->find( [ 'user_id'  => (string)$this->di->getUser()->id, 'parent' => true ] );
        if(  is_null ( $parentData) ) {
            // $orderIds = array_column( $parentData, 'marketplace_order_id' );
		}

        $parentData = json_decode( toJSON( fromPHP( $parentData->toArray() )), true);
        $tempParentData = $parentData;
        if( count( $parentData ) > 0 ) {
            foreach( $parentData as $key => $orderData ) {
                if( empty( $orderData['marketplace_order_id'] ) ) {
                    continue;
                }

                $dbs = $collection->find( [ 'user_id'  => (string)$this->di->getUser()->id, 'order_id' => $orderData['marketplace_order_id'],  'order_type' => 'dbs' ] )->toArray();
                if( ! is_null ( $dbs) ) {
                    $dbs = json_decode( toJSON( fromPHP( $dbs )), true);
                }

                $dba = $collection->find( [ 'user_id'  => (string)$this->di->getUser()->id, 'order_id' => $orderData['marketplace_order_id'], 'order_type' => 'dba' ] )->toArray();
                if( ! is_null ( $dba) ) {
                    $dba = json_decode( toJSON( fromPHP( $dba )), true);
                }

                $tempParentData[ $key ]['dba'] = $dba;
                $tempParentData[ $key ]['dbs'] = $dbs; 





            }
        }

        $countAggregation = [];
        if ($conditionalQuery &&
            count($conditionalQuery)) {
            $countAggregation[] = [
                '$match' => $conditionalQuery
            ];
        }

        $countAggregation[] = [
            '$count' => 'count'
        ];
        $totalRows = $collection->aggregate($countAggregation);
        $totalRows = $totalRows->toArray();

        $count = count($totalRows) ? iterator_to_array($totalRows[0])['count'] : 0;
        $responseData = [];
        $responseData['success'] = true;
        $responseData['data']['rows'] = $tempParentData;
        $responseData['data']['count'] = $count;   
        return $this->prepareResponse($responseData);
    }

    /**
     * This function is used to get Order details
     *
     * @return string
     */
    public function getOrderAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $order_id = $rawBody['order_id'];

        $collection = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo')->getCollectionForTable("order_container");

        $parentData = $collection->findOne( [ 'user_id'  => (string)$this->di->getUser()->id, 'parent' => true, 'marketplace_order_id' => $order_id ] );
        if(  is_null ( $parentData) ) {
            // $orderIds = array_column( $parentData, 'marketplace_order_id' );
		}

        $parentData = json_decode( toJSON( fromPHP( $parentData )), true);

        if( count( $parentData ) > 0 ) {


                $tempParentData = $parentData;
                $dbs = $collection->find( [ 'user_id'  => (string)$this->di->getUser()->id, 'order_id' => $parentData['marketplace_order_id'],  'order_type' => 'dbs' ] )->toArray();
                if( ! is_null ( $dbs) ) {
                    $dbs = json_decode( toJSON( fromPHP( $dbs )), true);
                }

                $dba = $collection->find( [ 'user_id'  => (string)$this->di->getUser()->id, 'order_id' => $parentData['marketplace_order_id'], 'order_type' => 'dba' ] )->toArray();
                if( ! is_null ( $dba) ) {
                    $dba = json_decode( toJSON( fromPHP( $dba )), true);
                }

                $tempParentData['dba'] = $dba;
                $tempParentData['dbs'] = $dbs;
        }

        $responseData [ 'success' ] = true;
        unset( $tempParentData['_id'] );
        $responseData[ 'data' ] = $tempParentData;
        // $ordersModel = new \App\Connector\Models\OrderContainer;
        // $responseData = $ordersModel->getOrderByID($rawBody);
        return $this->prepareResponse($responseData);
    }

    /**
     * This function will be used to manage fullfillment through our app
     *
     * @return string
     */
    public function manualFulfillmentAction() {
        $params = $this->getRequestData();
        $target  = $this->di->getRequester()->getTargetName() ?? false;
        
           
        if( empty( $target ) ) {
            return  $this->prepareResponse( [
                'sucess' => false,
                'msg'    => 'Target Marketplace not found'
            ] );
        }

        $connectorsHelpler      = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $SourceModelMarketPlace = $connectorsHelpler->getConnectorModelByCode( $target );
        // echo 'found here';
        // die;
        if ( ! method_exists( $SourceModelMarketPlace, 'manualFulfillment' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }
        
        return $this->prepareResponse(
            $SourceModelMarketPlace->manualFulfillment(
                $params
            )
        );	
	
    }

    /**
  * Cron callback
  *
  * @since 1.0.0
  */
 public function importOrderCronAction(): void{


     
        // $target = 'magento';
        // echo '\App\\' . ucfirst($target)  .'home\Components\Order\Order';
        // die;
		$this->di->getObjectManager()->get('\App\Arisehome\Components\Order\Order')->handleAriseWebhookOrder( $this->getRequestData() );
    }



    /**
     * Function used to update failed order on prestashop
     */
    public function updateOrderAction(): void {

        $params = $this->getRequestData();
        echo '<pre>'; print_r( $params ); echo '</pre>';

    }


    /**
     * Used to fetch order action
     *
     * @return string
     */
    public function getOrderStatusAction() {

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
       return  $this->prepareResponse( $this->getOrderStatuses( $target, $source, $refresh )  );	

    }

    public function getOrderStatuses( $target, $source, $refresh  ) {

        $arise = $this->getTargetOrderStatus( $target, $refresh );
        $magento = $this->getSourceOrderStatus( $source,  $refresh );
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
                'msg'     => 'Oops!! There is an error in api from ' . $target . '!, Please  refresh and try again later.'
            ];
        }

        if (false === $magentoCheck) {
            return [
                'success' => false,
                'msg'     => 'Oops!! There is an error in api from ' . $source . '!, Please  refresh and try again later.'
            ];
        }


        foreach( $magento as $k => $val ) {
            $magentoStatus[  $val['id_order_state'] ] = strtoupper( (string) $val['name'] );
        }

        foreach( $arise as $k => $val ) {
            $automapArr[] = [
                'key' => $k,
                'value' => $val,
                'map_with' => $this->mapOrderStatus( strtoupper( (string) $k ), $magentoStatus )
            ];
        }

        $finalResponse = [
            'success' => true,
            'source_order_status' => $automapArr,
            'target_order_status' => $magento
        ];
        if( $refresh ) {
            $finalResponse['msg'] = $this->di->getLocale()->_( 'Order Status Updated Successfully' );
        }

        return $finalResponse;
    }

    /**
	 * To get the source shipping carriers
	 *
	 * @param string $source source marketplace
	 * @return array
	 */
	public function getTargetOrderStatus( $target, $refresh = false ) {

        $SourceModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')->getConnectorModelByCode( $target );
        if ( ! method_exists( $SourceModelMarketPlace, 'getOrderStatus' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $SourceModelMarketPlace->getOrderStatus( $refresh );
	}

	/**
	 * To get the target shipping carriers
	 *
	 * @param string $target target marketplace
	 * @return array
	 */
	public function getSourceOrderStatus( $source , $refresh) {
        $SourceModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')->getConnectorModelByCode( $source );
        if ( ! method_exists( $SourceModelMarketPlace, 'getOrderStatus' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $SourceModelMarketPlace->getOrderStatus( $refresh );
	}

    /**
     * This function is used to map magento value with arise value.
    *
    * @param string  $search string to be search in the arise array.
    * @param array   $totalArray arise shipping carriers array.
    * @return mixed
    */
    public function mapOrderStatus( $search, $totalArray)  {
        foreach ( $totalArray as $k => $arrayVal ) {
            if ( str_contains( (string) $arrayVal, $search ) ) {
                return $k;
            }
        }

        return false;
    }


    /**
     * Function to get Shipping method from woocommerce
     *
     * @return void
     */
    public function getShippingMethodAction() {

        $source = $this->di->getRequester()->getSourceName() ?? 'woocommerce';

        $param = $this->getRequestData();
        if( $source !== 'woocommerce' ) {

            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marektplace does not found'
                ]
            );

        }

        $SourceModelMarketPlace = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')->getConnectorModelByCode( $source );
        if ( ! method_exists( $SourceModelMarketPlace, 'getShippingMethods' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method does not exist'
                ]
            );
        }

        return $this->prepareResponse(
            [ 'success' => true,
            'data' =>  $SourceModelMarketPlace->getShippingMethods( $param )
        ]);
        
    }

    /**
     * Function to get Target order Status of DBA
     *
     * @return void
     */
    public function getTargetOrderStatusAction() {
        $source = $this->di->getRequester()->getSourceName();
        if( 'shopify' === strtolower( (string) $source ) ) {
            return $this->prepareResponse(  $this->shopifyOrderStatus() );

        }

        if ( empty( $source ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Source Marketplace not found'
                ]
            );
        }

        return $this->prepareResponse(  $this->getSourceOrderStatus( $source,  false ) );
    }


    /**
     * Functiom to get Shopify order status
     *
     * @return void
     */
    public function shopifyOrderStatus() {
        return[
                'success' => true,
                'data' => [
                [
                    'id_order_state' => 'canceled' ,
                    'name' => 'Canceled'
                ],
                [
                    'id_order_state' => 'open' ,
                    'name' => 'Open'
                ],[
                    'id_order_state' => 'archived',
                    'name' => 'Archived'
                ],[
                    'id_order_state' => 'pending' ,
                    'name' => 'Pending'
                ],[
                    'id_order_state' => 'paid',
                    'name' => 'Paid'
                ],[
                    'id_order_state' => 'overdue',
                    'name' => 'Overdue'
                ],[
                    'id_order_state' => 'expired' ,
                    'name' => 'Expired'
                ],[
                    'id_order_state' => 'refunded' ,
                    'name' => 'Refunded'
                ],[
                    'id_order_state' =>  'partially_refunded',
                    'name' => 'Partially refunded'
                ],[
                    'id_order_state' =>  'partially_paid',
                    'name' => 'Partially paid'
                ],[
                    'id_order_state' =>  'unpaid',
                    'name' =>  'Unpaid'
                ],[
                    'id_order_state' =>  'Shipped' ,
                    'name' => 'Fulfilled'
                ],
                [
                    'id_order_state' => 'Unfulfilled',
                    'name' => 'Unfulfilled'
                ],
                [
                    'id_order_state' => 'partially_fulfilled',
                    'name' => 'Partially fulfilled'
                ],[
                    'id_order_state' =>  'scheduled' ,
                    'name' => 'Scheduled'
                ]
            ]
        ];
    }

    /**
	 * Function to manually cancel order from app 
	 *
	 * @return void
	 */
	public function manualCancelOrderAction() {

        $data		= $this->getRequestData();
        $order_id   = $data['order_id'] ?? false;
        $reason_id  = $data['reason_id'] ?? false;
        $user_id    = $data['user_id'] ?? false;
        if( ! $reason_id || ! $order_id || ! $reason_id ) {
            return $this->prepareResponse( [
                    'success' => false,
                    'msg'     => 'Required Data Is Missing!!!'
                ] 
            );

        }

        $target = $this->di->getRequester()->getTargetName();
        if( 'arise' == $target ) {
            $response   = $this->di->getObjectManager()->get('\App\Arisehome\Components\Order\Order')->cancelOrderArise($order_id, $reason_id, $user_id);
            return $this->prepareResponse( $response );
        }

        return $this->prepareResponse( [
            'success' => false,
            'msg'     => 'Target Not Found'
        ] );
    
    }

    /**
    * Function to get Tax CLasses
    *
    * @return void
    */
    public function getTaxClassAction() {
        $rawBody        = $this->getRequestData();
        $orderContainer = $this->di->getObjectManager()->get('\App\Woocommercehome\Models\SourceModel');
        return $this->prepareResponse($orderContainer->getTaxClasses($rawBody));
    }

    /**
     * Method for getting taxes from connected platforms.
     * Copyright © Cedcommerce, Inc. All rights reserved.
     * See LICENCE.txt for license details.
     * 
     * @param $rawBody
     * @return tax lists.
     */
    public function fetchTaxRateAction(){
        $rawBody		= $this->getRequestData();
        $refresh        = $rawBody['refresh'] ?? false;

        if (isset($rawBody['source']['marketplace']) || isset($rawBody['source']['shopId']) || isset($rawBody['target']['marketplace']) || isset($rawBody['target']['shopId'])) {
            $source = $rawBody['source']['marketplace'] ?? false;
            $sourceShopId = $rawBody['source']['shopId'] ?? false;
            $target = $rawBody['target']['marketplace'] ?? false;
            $targetShopId = $rawBody['target']['shopId'] ?? false;
        } else {
            $source = $this->di->getRequester()->getSourceName() ?? false;
            $sourceShopId = $this->di->getRequester()->getSourceId() ?? false;
            $target = $this->di->getRequester()->getTargetName() ?? false;
            $targetShopId = $this->di->getRequester()->getTargetId() ?? false;
        }

        if( !empty($source) || !empty($sourceShopId) || !empty($target) || !empty($targetShopId) ){
            $source_module = ucfirst($source) . 'home';
            $class = '\App\\' . $source_module . '\Models\SourceModel';
            if (class_exists($class)) {
                if (method_exists($class, 'getTaxRate')) {
                   $shops = $this->di->getUser()->shops;
                   $target_remote_shop_id = '';
                   foreach($shops as $shop){
                        if($shop['_id'] == $targetShopId)
                        {
                            $target_remote_shop_id = $shop['remote_shop_id'];
                        }
                    }
                    $retrieve_shop = array('source' => $source , 'source_shop_id' => $sourceShopId , 'target' => $target , 'target_shop_id' =>  $targetShopId , 'target_remote_shop_id' => $target_remote_shop_id );

                    return $this->prepareResponse($this->di->getObjectManager()->get($class)->getTaxRate($retrieve_shop, $refresh));
                } else {
                    $this->di->getLog()->logContent('Class found, getTaxeRate method not found. ' . json_encode($rawBody), 'info', 'MethodNotFound.log');
                    return $this->prepareResponse([ 'success' => false , 'message' => 'Class found, getTaxes method not found' ]);
                }
            } else {
                $this->di->getLog()->logContent('Class not found. ' . json_encode($rawBody), 'info', 'ClassNotFound.log');
                return $this->prepareResponse([ 'success' => false , 'message' => 'Class not found' ]);
            }
        }
        else{
            return $this->prepareResponse([ 'success' => false , 'message' => 'Required Data Missing' ]);

        }
    }
}