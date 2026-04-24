<?php
namespace App\Frontend\Controllers;

use App\Core\Controllers\BaseController;
use App\Frontend\Components\AdminHelper;
use App\Shopify\Models\Shop\Details;
use Phalcon\Logger;
use App\Frontend\Components\Helper;
use App\Frontend\Components\Facebook;
class AdminController extends BaseController
{
    // copy form Adminpanle
	public function getAdminConfigAction()
    {
        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->getAdminConfig());
    }

    public function getStepCompletedCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->getStepCompletedCount($rawBody));
    }

    public function getUserOrdersCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->getUserOrdersCount($rawBody));
    }

    public function getAppOrdersCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->getAppOrdersCount($rawBody));
    }

    public function getUserTypeCountAction()
    {   
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->getUserTypeCount($rawBody));
    }

    public function getAppConfigAction()
    {
        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->getAppConfig());
    }

    public function setAdminConfigAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->setAdminConfig($rawBody));
    }

    public function editAdminConfigAction()
    {
        // die('fg');
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->editAdminConfig($rawBody));
    }

    public function addAdminConfigAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        // print_r($rawBody);
        // die;

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->addAdminConfig($rawBody));
    }

    public function orderStatusAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $user_id = $this->di->getUser()->id;

        $id=$rawBody['user_id'] ?? $user_id;

        $test = $this->di->getObjectManager()->get(AdminHelper::class);
        $res = $test->orderStatusFile($id, $rawBody);
        return $this->prepareResponse($res);
    }

    //End copy from adminpanel

    public function getAllUsersAction() {
		$contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        // print_r($rawBody);
        // die;
        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->getAllUsers($rawBody));
    }

    public function getAllAmazonUsersAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->getAllAmazonUsers($rawBody));
    }

	public function getAllFacebookUsersAction() {
		$contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $start_time = microtime(true);

        $rawBody['activePage']=$rawBody['activePage'] - 1;
        $rawBody['count'] ??= 20;
        $remoteResponse = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
            ->init('shopify')
            ->call('/fbapproval',[],['filters' => $rawBody], 'GET');

        $end_time = microtime(true);
        $time = $end_time - $start_time;

        $remoteResponse['homeTimeTaken'] = $time;

        return $this->prepareResponse($remoteResponse);
	}

	public function getUserAction() {
		$contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->getUser($rawBody));
	}

    public function getLoginAsUserUrlAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);

        $responseData = $helper->getLoginAsUserUrl($rawBody);

        // if( $responseData['success'] && isset( $responseData['url'] ) ) {
        //     return $this->response->redirect( $responseData['url'] );
        // }

        return $this->prepareResponse( $responseData );
    }

    public function getPlansAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->getPlans($rawBody));
    }

    public function getAllShopsAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->getAllShops($rawBody));
    }

    public function getPaymentLinkAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->getPaymentLink($rawBody));
    }

    public function getServiceCreditsAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);

        if(isset($rawBody['db']) && $rawBody['db']==='db_bigcommerce'){
            return $this->prepareResponse($helper->getBigServiceCredits($rawBody));
        }

        return $this->prepareResponse($helper->getServiceCredits($rawBody));
    }

    public function updateUsedCreditsAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        if(isset($rawBody['db']) && $rawBody['db']==='db_bigcommerce'){
            return $this->prepareResponse($helper->updateBigUsedCredits($rawBody));
        }

        return $this->prepareResponse($helper->updateUsedCredits($rawBody));
    }

    public function addPlanAndServicesAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->addPlanAndServices($rawBody));
    }

    public function getPlanAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->getPlan($rawBody));
    }

    public function getActivePlanAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(AdminHelper::class);
        return $this->prepareResponse($helper->getActivePlan($rawBody));
    }

    public function getPaymentConfirmationLinkAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(AdminHelper::class)->getConfirmationUrlForOne($rawBody);
        return $this->prepareResponse($response);
    }

    public function getRecurryingPaymentConfirmationLinkAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(AdminHelper::class)->getRecurryingConfirmationUrlForOne($rawBody);
        return $this->prepareResponse($response);
    }

    public function adminCheckAction() {
        $request = $this->di->getRequest()->get();
        $charge_id = $request['charge_id'];
        $shopName = $request['shop'];
        $saveToDB = $request['saveToDB'] ?? false;
        $baseMongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $baseMongo->setSource('plan');

        $data = Details::findFirst("shop_url='{$shopName}'");
        $token = $data->getToken();
        $userId = $data->user_id;
        $this->di->getLog()->logContent('Charge Id => ' . $charge_id . ' For Shop => ' . $shopName, Logger::CRITICAL, 'checkCustomPayment.log');
        if (!empty($data)) {
            $shopifyClient = $this->di->getObjectManager()->create('App\Shopify\Components\ShopifyClientHelper', [
            $shopName, $token, $this->di->getConfig()->security->shopify->auth_key, $this->di->getConfig()->security->shopify->secret_key
            ]);
            $response = $shopifyClient->call('GET', '/admin/application_charges/' . $charge_id . '.json');
            $this->di->getLog()->logContent('Application charge => ' . print_r($response, true) . ' For Shop => ' . $shopName, Logger::CRITICAL, 'checkCustomPayment.log');
            if (isset($response['id']) && $response['status'] == "accepted") {
                $response = $shopifyClient->call('POST', '/admin/application_charges/' . $charge_id . '/activate.json', $response);
                if (isset($response['id'])) {
                    if ( $saveToDB && $saveToDB == 'true' ) {
                        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                        //$mongo->setSource("transaction_log");
                        $collection = $mongo->getCollectionForTable('transaction_log');
                        $response['user_id'] = (string)$userId;
                        $collection->insertOne($response);
                    }

                    return $this->response->redirect($this->di->getConfig()->frontend_app_url . $this->di->getConfig()->redirect_after_install . '?shop='.$shopName.'&success=true&message=Plan activated successfully&isPlan=true');
                }
                return $this->response->redirect($this->di->getConfig()->frontend_app_url . $this->di->getConfig()->redirect_after_install . '?shop='.$shopName.'&success=false&message=Charge was cancelled on Shopify&isPlan=true');
            }
            return $this->response->redirect($this->di->getConfig()->frontend_app_url . $this->di->getConfig()->redirect_after_install . '?shop='.$shopName.'&success=false&message=Charge was cancelled on Shopify&isPlan=true');
        }
        return $this->response->redirect($this->di->getConfig()->frontend_app_url . $this->di->getConfig()->redirect_after_install . '?shop='.$shopName.'&success=false&message=Charge was cancelled on Shopify&isPlan=true');
    }

    public function getActiveRecurryingOmniAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(AdminHelper::class)->getActiveRecurryingOmni($rawBody);
        return $this->prepareResponse($response);
    }

    public function getTempPlansAndServicesAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(AdminHelper::class)->getTempPlansAndServices($rawBody);
        return $this->prepareResponse($response);
    }

    public function getTempDataAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(AdminHelper::class)->getTempData($rawBody);
        return $this->prepareResponse($response);
    }

    public function cancelRecurryingChargeAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(AdminHelper::class)->cancelRecurryingCharge($rawBody);
        return $this->prepareResponse($response);
    }

    public function getActiveRecurryingAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(AdminHelper::class)->getActiveRecurrying($rawBody);
        return $this->prepareResponse($response);
    }

    public function updateBigcomPlanAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(AdminHelper::class)->updateBigcomPlan($rawBody);
        return $this->prepareResponse(['success' => true, 'code' => 'plan_updated', 'message' => 'Plan Upgraded!']);
    }

    public function getBigcomPlanAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(AdminHelper::class)->getBigcomPlan($rawBody);
        return $this->prepareResponse(['success' => true, 'code' => 'subscription_plan', 'message' => 'App Subscription Plan','data' => $response]);
    }

    public function addNewsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $result = $this->di->getObjectManager()->get(Helper::class)->newsFromData($rawBody);
        if ($result) {
            return $this->prepareResponse(['success'=>true,'code'=>'data found','data'=>$result]);
        }
        return $this->prepareResponse(['success'=>false,'code'=>'data Missing']);
    }

    public function deleteNewsAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $result = $this->di->getObjectManager()->get(Helper::class)->deleteData($rawBody);
        if ($result) {
            return $this->prepareResponse(['success'=>true,'code'=>'data found','data'=>$result]);
        }
        return $this->prepareResponse(['success'=>false,'code'=>'data Missing']);

    }

    public function addBlogAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $result = $this->di->getObjectManager()->get(Helper::class)->addBlogs($rawBody);
        if ($result) {
            return $this->prepareResponse(['success'=>true,'code'=>'data found','data'=>$result]);
        }
        return $this->prepareResponse(['success'=>false,'code'=>'data Missing']);
    }

    public function deleteBlogAction(){
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $result = $this->di->getObjectManager()->get(Helper::class)->deleteBlogData($rawBody);
        if ($result) {
            return $this->prepareResponse(['success'=>true,'code'=>'data found','data'=>$result]);
        }
        return $this->prepareResponse(['success'=>false,'code'=>'data Missing']);

    }

    public function addFaqAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->addFaq($rawBody);
        return $this->prepareResponse($response);
    }

    public function editFaqAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->editFaq($rawBody);
        return $this->prepareResponse($response);
    }

    public function deleteFaqAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(Helper::class)->deleteFaq($rawBody);
        return $this->prepareResponse($response);
    }

    public function getviewuserAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(Facebook::class)->getconfig($rawBody);
//        die($response);
        return $this->prepareResponse($response);
    }

    public function saveuserdataAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $response = $this->di->getObjectManager()->get(Facebook::class)->saveconfig($rawBody);
        return $this->prepareResponse($response);

    }

}