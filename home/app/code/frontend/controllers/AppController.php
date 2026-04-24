<?php
namespace App\Frontend\Controllers;
use App\Core\Controllers\BaseController;
use App\Frontend\Components\Helper;
use App\Shopify\Models\Shop\Details;
use Exception;
use Phalcon\Logger;
class AppController extends BaseController
{
    public function testAction(): void {
        die('tes564t');
    }

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

    public function stepCompletedAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $this->di->getLog()->logContent( 'stepCompleted::frontend/controller/App: Response - ' . print_r( $rawBody , true ), 'info', 'frontend' . DS . $this->di->getUser()->id . DS . 'userstep' . date( 'Y-m-d' ) . 'error.log' );
        $response = $this->di->getObjectManager()->get(Helper::class)->setActiveStep($rawBody);
        return $this->prepareResponse($response);
    }

    public function getNecessaryDetailsAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $rawBody['user_id'] = $this->di->getUser()->id;
        $response = $this->di->getObjectManager()->get(Helper::class)->getNecessaryDetails($rawBody);
        return $this->prepareResponse($response);
    }

    public function getStepCompletedAction() {

        $under_maintenance = false;
        if ($this->di->getConfig()->get('under_maintenance')) {
            $under_maintenance = $this->di->getConfig()->under_maintenance;
        }

        if ( isset($under_maintenance) && $under_maintenance == true ) {
            return $this->prepareResponse(['success' => false,
                'code' => 'under_maintenance',
                'message' => 'Under Maintenance, Please Comeback in approx 30 min.']);
        }

        $contentType = $this->request->getHeader('Content-Type');

        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->getActiveStep($rawBody);
        return $this->prepareResponse($response);
    }

    public function getProductsImportedDataAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->getProductsImportedData($rawBody, $this->di->getUser()->id);
        return $this->prepareResponse($response);
    }

    public function getProductsUploadedDataAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->getProductsUploadedData($rawBody);
        return $this->prepareResponse($response);
    }

    public function getInstalledConnectorDetailsAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->getInstalledConnectorDetails($rawBody);
        return $this->prepareResponse($response);
    }

    public function getTransactionLogAction() {
        $response = $this->di->getObjectManager()->get(Helper::class)->getTransactionLog();
        return $this->prepareResponse($response);
    }

    public function checkAccountAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->checkAccount($rawBody);
        return $this->prepareResponse($response);
    }

    public function checkDefaultConfigurationAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->checkDefaultConfiguration($rawBody);
        return $this->prepareResponse($response);
    }

    public function getGoogleOrderConfigAction() {
        $response = $this->di->getObjectManager()->get(Helper::class)->getGoogleOrderConfig();
        return $this->prepareResponse($response);
    }

    public function getShopDetailsAction() {
        $response = $this->di->getObjectManager()->get(Helper::class)->getShopDetails();
        return $this->prepareResponse($response);
    }

    public function submitReportAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get('\App\Core\Models\User')->reportIssue($rawBody);
        return $this->prepareResponse($response);
    }

    public function getOrdersDataAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->getOrdersHelper($rawBody, $this->di->getUser()->id);
        return $this->prepareResponse($response);
    }

    public function getAttributesForItemIdAction() {
        $response = $this->di->getObjectManager()->get(Helper::class)->getAttributesForItemId();
        return $this->prepareResponse($response);
    }

    public function getCurrentItemIdFormatAction() {
        $response = $this->di->getObjectManager()->get(Helper::class)->getCurrentItemIdFormat();
        return $this->prepareResponse($response);
    }

    public function setCurrentItemIdFormatAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->setCurrentItemIdFormat($rawBody);
        return $this->prepareResponse($response);
    }

    public function getOrderAnalyticsAction() {
        $response = $this->di->getObjectManager()->get(Helper::class)->getOrderAnalytics();
        return $this->prepareResponse($response);
    }

    public function getImportedProductCountAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->getImportedProductCount($rawBody, $this->di->getUser()->id);
        return $this->prepareResponse($response);
    }

    public function getUploadedProductsCountAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->getUploadedProductsCount($rawBody, $this->di->getUser()->getShop()->id);
        return $this->prepareResponse($response);
    }

    public function getShopIDAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $rawBody['user_id'] = $this->di->getUser()->id;
        $rawBody['shop_id'] = $this->di->getUser()->getShop()->id;
        $response = $this->di->getObjectManager()->get(Helper::class)->getShopID($rawBody);
        return $this->prepareResponse($response);
    }

    public function getSKUCountAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $rawBody['user_id'] = $this->di->getUser()->id;
        $rawBody['shop_id'] = $this->di->getUser()->getShop()->id;
        $response = $this->di->getObjectManager()->get(Helper::class)->getSKUCount($rawBody);
        return $this->prepareResponse($response);
    }

    public function getActiveRecurryingAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->getActiveRecurrying();
        return $this->prepareResponse($response);
    }

    public function getServiceCreditsAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->getServiceCredits($rawBody);
        return $this->prepareResponse($response);
    }

    public function updateYearlyPlanAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->updateYearlyPlan($rawBody);
        return $this->prepareResponse($response);
    }

    public function getYearlyPlanAction() {
        $response = $this->di->getObjectManager()->get(Helper::class)->getYearlyPlan();
        return $this->prepareResponse($response);
    }

    public function getAllYearlyPlansAction() {
        $response = $this->di->getObjectManager()->get(Helper::class)->getAllYearlyPlans();
        return $this->prepareResponse($response);
    }

    public function giftServicesAction() {
        $filters = $this->request->get('filters');
        $response = $this->di->getObjectManager()->get(Helper::class)->giftServices($filters);
        return $this->prepareResponse($response);
    }

    public function getOrderDatewiseAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->getOrderDatewise($rawBody);
        return $this->prepareResponse($response);
    }

    public function getOrderRevenueRangewiseAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->getOrderRevenueRangewise($rawBody);
        return $this->prepareResponse($response);
    }

    public function setConfigurationsAction() {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->setConfigurationsApp($rawBody);
        return $this->prepareResponse($response);
    }

    public function getConfigurationsAction() {
        $response = $this->di->getObjectManager()->get(Helper::class)->getConfigurationsApp();
        return $this->prepareResponse($response);
    }

    public function makePaymentAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $errors = [];
        if (isset($rawBody['shop']) && isset($rawBody['amount'])) {
            $shopUrl = $rawBody['shop'];
            $amount = (double)$rawBody['amount'];
            $shopDetails = Details::findFirst(["shop_url='{$shopUrl}'"]);
            if ($shopDetails) {
                $shopName = $shopDetails->shop_url;
                $token = $shopDetails->token;
                $shopifyClient = $this->di->getObjectManager()->get('App\Shopify\Components\ShopifyClientHelper', [
                                ['type' => 'parameter', 'value' => $shopName], ['type' => 'parameter', 'value' => $token], ['type' => 'parameter', 'value' => $this->di->getConfig()->security->shopify->auth_key], ['type' => 'parameter', 'value' => $this->di->getConfig()->security->shopify->secret_key]
                            ]);
                $plan = [
                    'application_charge' => [
                        'name' => 'Custom Charge',
                        'price' => $amount,
                        'return_url' => $this->di->getUrl()->get('frontend/admin/adminCheck?shop=' . $shopName)
                    ]
                ];
                $response = $shopifyClient->call('POST', '/admin/application_charges.json', $plan);
                if ($response && !(isset($response['errors']))) {
                    return $this->response->redirect($response['confirmation_url']);
                }
                $errors[] = 'Failed to process payment. Please come back later.';
            } else {
                $errors[] = 'App is not installed, kindly install the app.';
            }
        } else {
            $errors[] = 'Invalid request data';
        }

        $errorMessage = implode(' , ', $errors);
        return $this->response->redirect($this->di->getConfig()->frontend_app_url.'/show/message?success=false&message=' . $errorMessage);
    }

    public function getLastLoginAction()
    {
        $response = $this->di->getObjectManager()->get(Helper::class)->getLastLoginDetails();
        return $this->prepareResponse($response);
    }

    public function changeStepAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->setActiveStep($rawBody,$rawBody['user_id']);
        return $this->prepareResponse($response);
    }

    public function saveSellerInfoAction()
    {
        $postData = $this->request->get();
        unset($postData['_url'], $postData['bearer']);
        if(!isset($postData['sAppId']) || !isset($postData['shop_url'])){
            return $this->prepareResponse([
                'success' => false,
                'message' => 'Field Missing. Kindly fill all required data.'
            ]);
        }

        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify', 'true')
            ->call('/fbapproval/save',[],['filters' => ['data' => $postData]], 'GET');

        $this->di->getLog()->logContent(' Seller Approval | Response : '.print_r($remoteResponse, true),'info','shopify'.DS.'1'.DS.date("Y-m-d").DS.'seller.log');
        if ($remoteResponse['success'] && ($remoteResponse['type'] == 'created')) {
            $this->di->getObjectManager()->get("\App\Shopifyhome\Components\Mail\Seller")->welcome($postData);
            return $this->prepareResponse([
                'success' => true,
                'message' => 'Your Application has been successfully Submitted.'
            ]);
        }

        if ($remoteResponse['success'] && ($remoteResponse['type'] == 'updated')) {
            // may be show success message
            if(isset($postData['status']) && (($postData['status'] == 'ACCEPTED') || ($postData['status'] == 'NOT_ELIGIBLE'))){
                try{
                    switch ($postData['status'])
                    {
                        case 'ACCEPTED' :
                            $catalog_sync=$this->di->getObjectManager()->get("\App\Shopifyhome\Components\Core\Hook")->checkUser_CatalogSync($this->di->getUser()->id,$postData['shop_url']);
                            if(!$catalog_sync){
                                $response=$this->di->getObjectManager()->get("\App\Shopifyhome\Components\Mail\Seller")->approved($remoteResponse);
                            }

                            break;

                        case 'NOT_ELIGIBLE' :
                            break;
                    }
                } catch (Exception $e){
                    $this->di->getLog()->logContent('PROCESS 00000 | App\Frontend\Controllers\AppController |Error while sending mail : ' . $e->getMessage(), Logger::CRITICAL, 'mail.log');
                }

            }

            if($response){
                return $this->prepareResponse([
                    'success' => true,
                    'message' => 'Your Application has been successfully Updated.'
                ]);
            }
            return $this->prepareResponse([
                'success' => false,
                'message' => 'EMAIL NOT SEND'
            ]);
        }
        else {
            return $this->prepareResponse([
                'success' => false,
                'message' => 'Already Submitted.'
            ]);
        }
    }

    /**
     * set active step by admin
     *
     * @return string
     */
    public function stepSetByAdminAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $user_id = $rawBody['userid'] ?? '';
        $step = isset( $rawBody['step'] ) ? (int)$rawBody['step'] : 0;
        if( empty( $user_id ) ) {

            return $this->prepareResponse(
                [
                   'success' => false,
                   'msg'     => 'User not found'
                ]
            );
        }

        $mongo          = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $userCollection = $mongo->getCollectionFortable('user_details');
        $is_user_find   = $userCollection->findOne( ['user_id' => $user_id ] );
        if ( is_null( $is_user_find ) || empty( $is_user_find ) ) {
            return $this->prepareResponse(
                [
                   'success' => false,
                   'msg'     => 'User not found'
                ]
            );
        }

       $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
       $step_completed = $user_details->setConfigByKey('step_completed', $step, $user_id);

       if ($step_completed['success']) {
           $response = ['success' => true, 'code' => 'active_step_updated', 'message' => 'Active step updated'];
       } else {
           $response = ['success' => false, 'code' => 'active_step_update_failed', 'message' => 'Active step update failed'];
       }

       return $this->prepareResponse( $response );
    }

}