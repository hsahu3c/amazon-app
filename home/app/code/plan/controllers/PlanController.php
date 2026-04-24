<?php

namespace App\Plan\Controllers;

use App\Plan\Models\AddOn;
use App\Plan\Components\Helper;
use Exception;
use App\Core\Controllers\BaseController;
use App\Plan\Models\Plan;
use App\Plan\Models\BaseMongo;
use App\Plan\Components\Common;
use App\Plan\Components\Recommendations;
use App\Plan\Models\Plan\Services;

/**
 * Class PlanController
 * @package App\Plan\Controllers
 * to process all APIs for plan processing
 */
class PlanController extends BaseController
{
    /**
     * to fetch the plans
     *
     * @return mixed
     */
    public function getAction()
    {
        $userId = $this->di->getUser()->id;
        if (empty($userId)) {
            return $this->prepareResponse(['success' => false, 'message' => 'User not found.']);
        }

        $mongo = $this->di->getObjectManager()->create(BaseMongo::class);
        $collection = $mongo->getCollection(Plan::PLAN_COLLECTION_NAME);
        if ($planId = $this->request->get('plan_id')) {
            if ($validity = $this->request->get('val')) {
                $data = $collection->findOne(['plan_id' => $planId, 'validity' => (int)$validity], Plan::TYPEMAP_OPTIONS);
            } else {
                $data = $collection->findOne(['plan_id' => $planId], Plan::TYPEMAP_OPTIONS);
            }
        } elseif ($this->request->has('type') && ($this->request->get('type') == Plan::QUOTE_TYPE_ADD_ON)) {
            if ($this->request->has('add_on_id')) {
                $addOnId = $this->request->get('add_on_id');
                $data = $collection->findOne(['type' => Plan::QUOTE_TYPE_ADD_ON, 'add_on_id' => $addOnId], Plan::TYPEMAP_OPTIONS);
            } else {
                $params['filter']['type'][1] = 'add_on';
                $sort = [
                    "sort_order" => 1
                ];
                $data = $mongo->getFilteredResults(Plan::PLAN_COLLECTION_NAME, $params, $sort);
            }
        } else {
            $params = $this->request->get();
            $category = Plan::CATERGORY_REGULAR;
            if (isset($params['category']) && !empty($params['category'])) {
                $category  = $filter['filter']['category'][1] = $params['category'];
            }
            $filter['filter']['type'][1] = 'plan';
            $filter['filter']['category'][1] = $category;
            $basicBilledType = null;
            //to handle the case that sometime we only used to support only one type of billed type like 'monthly' only
            if ($this->di->getConfig()->has('basic_billed_type')) {
                $basicBilledType = $this->di->getConfig()->get('basic_billed_type');
            }
            if (!is_null($basicBilledType)) {
                $filter['filter']['billed_type'][1] = $basicBilledType;
            }
            if (isset($params['billed_type']) && !empty($params['billed_type'])) {
                if ($params['billed_type'] == 'all') {
                    unset($filter['filter']['billed_type']);
                } else {
                    $filter['filter']['billed_type'][1] = $params['billed_type'];
                }
            }
            $sort = [
                "sort_order" => 1
            ];
            $data = $mongo->getFilteredResults(Plan::PLAN_COLLECTION_NAME, $filter, $sort);
            if (!empty($data['data']['rows'])) {
                $planModel = $this->di->getObjectManager()->create(Plan::class);
                $userService = $planModel->getCurrentUserServices($userId, Plan::SERVICE_TYPE_ORDER_SYNC);
                $recommendedPlansInfo = $this->di->getObjectManager()->get(Recommendations::class)->getPersonalizedRecommendations($userId, $category);
                if (isset($recommendedPlansInfo['custom']) && $recommendedPlansInfo['custom']) {
                    $recommendedPlansInfo['plans'] = [];
                }
                $canShowFreePlan = true;
                $canShowFreePlan = !$planModel->trialPlanExhausted($userId);
                /**
                 * cases when listing plans - 
                 * 1. if client is on paid plan no free plan should be available
                 * 2. if used limits are more than the plan service credits restrict that plan
                 * 3. add tag for current active plan
                 * 4. list higher plan if it is required to show else not
                 */
                $serviceClass = $this->di->getObjectManager()->create(Services::class);
                $hasActivePlan = false;
                $totalUsedCredit = 0;
                if (!empty($userService)) {
                    $activePlan = $planModel->getActivePlanForCurrentUser($userId);
                    if (!empty($activePlan)) {
                        $hasActivePlan = true;
                        //to not show free plan in case of client has a paid plan
                        // if ($activePlan['plan_details']['custom_price'] > 0 && $canShowFreePlan) {
                        //     $canShowFreePlan = false;
                        // }
                        if ((($activePlan['plan_details']['custom_price'] == 0) && isset($activePlan['plan_details']['version'])) && $canShowFreePlan) {
                            $canShowFreePlan = false;
                        }
                    }
                    $totalUsedCredit = (int)($userService['prepaid']['total_used_credits'] ?? 0)
                        + (int)($userService['postpaid']['total_used_credits'] ?? 0)
                        + (int)($userService['add_on']['total_used_credits'] ?? 0);
                    $totalUsedCredit += Plan::MARGIN_ORDER_COUNT;
                    $activePlanPrice = $activePlan['plan_details']['custom_price'] ?? 0;
                    if ($canShowFreePlan && isset($userService['postpaid']['is_capped'], $userService['postpaid']['balance_used']) && $userService['postpaid']['is_capped'] && ((float)$userService['postpaid']['balance_used']) > 9.0) {
                        $canShowFreePlan = false;
                    }
                }
                foreach ($data['data']['rows'] as $key => $plan) {
                    //to restrict showing free plan
                    if (!$canShowFreePlan && ($planModel->isFreePlan($plan) || $planModel->isTrialPlan($plan))) {
                        $data['data']['rows'][$key]['active'] =  false;
                        $data['data']['rows'][$key]['message'] =  'You are not eligible to choose this plan anymore!';
                        continue;
                    }
                    if ($canShowFreePlan && ($planModel->isFreePlan($plan) || $planModel->isTrialPlan($plan)) && (($totalUsedCredit - Plan::MARGIN_ORDER_COUNT) > Plan::FREE_PLAN_CREDITS)) {
                        $data['data']['rows'][$key]['active'] =  false;
                        $data['data']['rows'][$key]['message'] =  'You are not eligible to choose this plan anymore!';
                        continue;
                    } elseif ($canShowFreePlan && ($planModel->isFreePlan($plan) || $planModel->isTrialPlan($plan))) {
                        continue;
                    }
                    $serviceCredits = $serviceClass->getServiceCredits($plan);
                    //to check if the plan is allowed or resticted as per the usage
                    if ($hasActivePlan && ($totalUsedCredit > 25) && ($serviceCredits <= $totalUsedCredit)) {
                        $data['data']['rows'][$key]['active'] =  false;
                        $data['data']['rows'][$key]['message'] =  'The plan is unavailable because you have already used ' . ($totalUsedCredit - Plan::MARGIN_ORDER_COUNT) . ' credits, while this plan is limited to ' . (int)$serviceCredits . ' credits only!';
                    }
                    //for adding recommendations
                    if (($plan['billed_type'] == Plan::BILLED_TYPE_MONTHLY) && ($plan['category'] == Plan::CATERGORY_REGULAR) && ($plan['custom_price'] == Plan::MAX_MONTHLY_PAID_PLAN_PRICE)) {
                        //for the condition to show 249 plan only for the price range of 99 to 249 active custom plan price only
                        if ($hasActivePlan && ($activePlanPrice >= 99) && ($activePlanPrice < 249)) {
                            if (!empty($recommendedPlansInfo['plans']) && isset($recommendedPlansInfo['plans'][$plan['billed_type']]) && ($recommendedPlansInfo['plans'][$plan['billed_type']]['plan_id'] == $plan['plan_id'])) {
                                $data['data']['rows'][$key]['tag'] = ['type' => 'Recommended', 'message' => $recommendedPlansInfo['message']]; //to present that the following plan is a recommendation
                            }
                        } else {
                            if (!empty($recommendedPlansInfo['plans']) && isset($recommendedPlansInfo['plans'][$plan['billed_type']]) && ($recommendedPlansInfo['plans'][$plan['billed_type']]['plan_id'] == $plan['plan_id'])) {
                                $data['data']['rows'][$key]['tag'] = ['type' => 'Recommended', 'message' => $recommendedPlansInfo['message']]; //to present that the following plan is a recommendation
                            } else {
                                unset($data['data']['rows'][$key]);
                            }
                        }
                    } else {
                        if (!empty($recommendedPlansInfo['plans']) && isset($recommendedPlansInfo['plans'][$plan['billed_type']]) && ($recommendedPlansInfo['plans'][$plan['billed_type']]['plan_id'] == $plan['plan_id'])) {
                            $data['data']['rows'][$key]['tag'] = ['type' => 'Recommended', 'message' => $recommendedPlansInfo['message']]; //to present that the following plan is a recommendation
                        }
                    }
                    if (
                        $hasActivePlan
                        && ($activePlanPrice == $plan['custom_price'])
                        && (($activePlan['plan_details']['title'] ?? 0) == $plan['title'])
                        && (($activePlan['plan_details']['billed_type'] ?? Plan::BILLED_TYPE_MONTHLY) == $plan['billed_type'])
                        && (($activePlan['plan_details']['category'] ?? plan::CATERGORY_REGULAR) == $plan['category'])
                    ) {
                        if (empty($data['data']['rows'][$key]) && !empty($plan)) {
                            $data['data']['rows'][$key] =  $plan;
                        }
                        $data['data']['rows'][$key]['active'] =  true;
                    }
                }

                //to customize plan data later
                $codes = [];
                foreach ($data['data']['rows'] as $row) {
                    $codes[] = $row['code'];
                }

                $codes = array_unique($codes);
                $updatedPlan = [];
                foreach ($data['data']['rows'] as $row) {
                    foreach ($codes as $code) {
                        if ($code == $row['code']) {
                            if (!empty($row['variation'])) {
                                $updatedPlan[$code][$row['billed_type']]['has_variations'] = true;
                                $updatedPlan[$code][$row['billed_type']]['plans'][] = $row;
                            } else {
                                $updatedPlan[$code][$row['billed_type']] = $row;
                            }
                        }
                    }
                }
                $data['data']['rows'] = $updatedPlan;
            }
        }
        return $this->prepareResponse(['success' => true, 'code' => '', 'message' => '', 'data' => $data]);
    }

    /**
     * to update the plans in db using the plan.json file in utility
     * @return mixed
     */
    public function addAmazonDefaultPlanAction()
    {
        try {
            $result = [];
            $rawBody = $this->getRequestData();
            if (!isset($rawBody['force_sync'])) {
                $result = [
                    'success' => false,
                    'message' => '\'force_sync\' parameter not set.'
                ];
            } elseif (trim(strtolower((string) $rawBody['force_sync'])) != 'yes') {
                $result = [
                    'success' => false,
                    'message' => '\'force_sync\' must be \'yes\' for syncing.'
                ];
            } else {
                $planModel = $this->di->getObjectManager()->get(Plan::class);
                $result = $planModel->addAmazonDefaultPlan($rawBody);
            }

            return $this->prepareResponse($result);
        } catch (Exception $exception) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'message' => $exception->getMessage()
                ]
            );
        }
    }

    /**
     * to settle the services
     *
     * @return mixed
     */
    public function settleServicesAction()
    {
        $rawBody = $this->getRequestData();
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $validateResponse = $planModel->validateRequiredParams();
        if ($validateResponse['success'] === false) {
            return $this->prepareResponse($validateResponse);
        }

        return $this->prepareResponse($planModel->settleServices($rawBody));
    }

    /**
     * when user selects/choose any plan
     *
     * @return mixed
     */
    public function chooseAction()
    {
        $rawBody = $this->getRequestData();
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $validateResponse = $planModel->validateRequiredParams();
        if ($validateResponse['success'] === false) {
            return $this->prepareResponse($validateResponse);
        }

        return $this->prepareResponse($planModel->choosePlan($rawBody));
    }

    /**
     * @return array
     */
    public function processQuotePaymentAction()
    {
        $rawBody = $this->getRequestData();
        if (!isset($rawBody['payment_token'])) {
            return ['success' => false, 'message' => 'payment_token parameter not set'];
        }

        $paymentToken = $rawBody['payment_token'];
        $response = $this->di->getObjectManager()
            ->get('\App\Core\Components\Helper')
            ->decodeToken($paymentToken, false);

        if (isset($response['success']) && $response['success']) {
            $type = isset($response['data']['plan_type']) && ($response['data']['plan_type'] == Plan::QUOTE_TYPE_PLAN) ? Plan::QUOTE_TYPE_PLAN : "";
            $quoteResponse = $this->di->getObjectManager()->get(Plan::class)->processQuoteForCustom($response['data']['quote_id'], $type);
            if ($quoteResponse['success'] && isset($quoteResponse['data']['confirmation_url'])) {
                return $this->response->redirect($quoteResponse['data']['confirmation_url']);
            }

            if (isset($quoteResponse['redirect_url'])) {
                return $this->response->redirect($quoteResponse['redirect_url'] . '?success=false&message=' . ($quoteResponse['message'] ?? ""));
            }

            return $this->prepareResponse($quoteResponse);
        }

        return $this->prepareResponse([
            'success' => false,
            'message' => 'Invalid token.'
        ]);
    }

    public function getPaymentLinkForAdminAction()
    {
        $rawBody = $this->getRequestData();
        $planModel = $this->di->getObjectManager()->get(Plan::class);

        if (
            !isset($rawBody['user_id']) ||
            ((($paramResponse = $planModel->validateRequiredParams($rawBody['user_id'])) &&
                $paramResponse['success'])
                ? false : true
            )
        ) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $paramResponse['message'] ?? 'user_id is required'
            ]);
        }

        return $this->prepareResponse($planModel->getPaymentLinkForAdmin($rawBody));
    }

    /**
     * @return mixed
     */
    public function createCustomQuoteAction()
    {
        $rawBody = $this->getRequestData();
        $message = "";
        $result = [];
        if (!isset($rawBody['user_id'])) {
            $message = 'user_id parameter not set.';
        }

        if (!isset($rawBody['plan_id'])) {
            $message = 'plan_id parameter not set.';
        }

        if (!isset($rawBody['plan_details']['custom_price'])) {
            $message = 'custom_price parameter not set.';
        }

        if (!isset($rawBody['plan_details']['title'])) {
            $message = 'title parameter not set.';
        }

        if ($message !== "") {
            return $this->prepareResponse([
                'success' => false,
                'message' => $message
            ]);
        }

        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $validateResponse = $planModel->validateRequiredParams($rawBody['user_id']);
        if ($validateResponse['success'] === false) {
            $result = $validateResponse;
        } else {
            $setRes = $this->di->getObjectManager()->get(Common::class)->setDiRequesterAndTag($rawBody['user_id']);
            if ($setRes['success'] == false) {
                return $this->prepareResponse($setRes);
            }

            $rawBody['plan_details']['custom_price'] = round($rawBody['plan_details']['custom_price'], 2);
            /*prepare quote as per provided data*/
            $quoteId = $planModel->setQuoteDetails(
                Plan::QUOTE_STATUS_ACTIVE,
                $rawBody['user_id'],
                $rawBody['plan_id'],
                $rawBody['plan_details'],
                Plan::QUOTE_TYPE_CUSTOM
            );
            /* prepare token info for generated quote id*/
            $result = $planModel->generateCustomPaymentLinkAsPerQuoteId($quoteId, Plan::QUOTE_TYPE_CUSTOM);
        }

        return $this->prepareResponse($result);
    }

    /**
     * to fetch the active plan
     *
     * @return mixed
     */
    public function getActiveAction()
    {
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $validateResponse = $planModel->validateRequiredParams();
        if ($validateResponse['success'] === false) {
            return $this->prepareResponse($validateResponse);
        }

        return $this->prepareResponse($planModel->getActivePlan());
    }

    /**
     * to process ontime payment for user
     *
     * @return mixed
     */
    public function OneTimePaymentCheckAction()
    {
        $request = $this->di->getRequest()->get();
        $data = [
            'user_id' => $request['state'] ?? "",
            'charge_id' => $request['charge_id'] ?? "",
            'quote_id' => $request['quote_id'] ?? null
        ];
        $onetimePaymentRes = $this->di->getObjectManager()->get(Plan::class)->activatePaymentBasedOnType($data);
        return $this->response->redirect($onetimePaymentRes['redirect_url'] . '?shop=' . $onetimePaymentRes['shop'] . '&success=' . $onetimePaymentRes['success'] . '&message='  . $onetimePaymentRes['message']);
    }

    /**
     * for processing custom payments
     * @return mixed
     */
    public function customPaymentCheckAction()
    {
        $request = $this->di->getRequest()->get();
        $data = [
            'quote_id' => $request['process_id'] ?? "",
            'charge_id' => $request['charge_id'] ?? ""
        ];
        $customRes = $this->di->getObjectManager()->get(Plan::class)->activatePaymentBasedOnType($data);
        return $this->response->redirect($customRes['redirect_url'] . '?shop=' . $customRes['shop'] . '&success=' . $customRes['success'] . '&message=' . $customRes['message'] . '&isPlan=' . $customRes['is_plan']);
    }

    /**
     * @return mixed
     */
    public function checkAction()
    {
        $request = $this->di->getRequest()->get();
        $quoteId = $request['quote_id'];
        $chargeId = $request['charge_id'];
        $shopName = $request['shop'];
        $userId = $request['state'];
        isset($request['plan_type']) && $planType = $request['plan_type'];
        $data = [
            'quote_id' => $quoteId,
            'charge_id' => $chargeId,
            'shop_name' => $shopName,
            'user_id' => $userId,
            'payment_type' => Plan::BILLING_TYPE_RECURRING
        ];
        $activatePayment = $this->di->getObjectManager()->get(Plan::class)->activatePaymentBasedOnType($data);
        return $this->response->redirect($activatePayment['redirect_url'] . '?shop=' . $shopName . '&success=' . $activatePayment['success'] . '&message=' . $activatePayment['message'] . '&isPlan=' . $activatePayment['isPlan']);
    }


    /**
     *
     * @return mixed
     */
    public function isPlanActiveAction()
    {
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $validateResponse = $planModel->validateRequiredParams();
        if ($validateResponse['success'] === false) {
            return $this->prepareResponse($validateResponse);
        }

        return $this->prepareResponse($planModel->isPlanActive());
    }

    /**
     * used in admin panel but of no use
     *
     * @return mixed
     */
    public function deleteAction()
    {
        $collection = $this->di->getObjectManager()->create(BaseMongo::class)->getCollection(Plan::PLAN_COLLECTION_NAME);
        if ($planId = $this->request->get('plan_id')) {
            if ($validity = $this->request->get('val')) {
                $collection->deleteOne(['plan_id' => $planId, 'validity' => (int)$validity]);
            } else {
                $collection->deleteOne(['plan_id' => $planId]);
            }

            return $this->prepareResponse(['success' => true, 'code' => '', 'message' => '']);
        }

        return $this->prepareResponse(['success' => false, 'code' => 'missing_required_params', 'message' => '']);
    }

    /**
     * used to activate free plan forcefully if client has no active plan
     */
    public function forcefullyFreePlanPurchaseAction()
    {
        $response = [
            'success' => false,
            'message' => 'Not supported for now!'
        ];
        return $this->prepareResponse($response);
        $rawBody = $this->getRequestData();
        $response = $this->di->getObjectManager()->get(Plan::class)->forcefullyFreePlanPurchase($rawBody);
        return $this->prepareResponse($response);
    }

    /**
     * used in admin panel but is of no use
     *
     * @return mixed
     */
    public function saveAction()
    {
        $mongo = $this->di->getObjectManager()->create(BaseMongo::class);
        $collection = $mongo->getCollection(Plan::PLAN_COLLECTION_NAME);
        $rawBody = $this->getRequestData();
        $rawBody['plan_id'] ??= (string)$mongo->getCounter('plan_id');
        $rawBody['validity'] = (int)$rawBody['validity'];
        $collection->replaceOne(
            ['plan_id' => $rawBody['plan_id'], 'validity' => $rawBody['validity']],
            $rawBody,
            ['upsert' => true]
        );
        return $this->prepareResponse(['success' => true, 'code' => '', 'message' => 'Plan Saved Successfully']);
    }

    /**
     * for add on activation
     */
    public function chooseAddonAction()
    {
        $rawBody = $this->getRequestData();
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $validateResponse = $planModel->validateRequiredParams();
        if ($validateResponse['success'] === false) {
            return $this->prepareResponse($validateResponse);
        }

        $addonModel = $this->di->getObjectManager()->get(AddOn::class);
        if (!isset($rawBody['add_on_id'])) {
            return ['success' => false, 'message' => 'provide add_on_id'];
        }

        return $this->prepareResponse($addonModel->chooseAddon($rawBody));
    }

    /**
     * to get personalized recommendations, not used right now need enhancement for future use
     */
    public function getPersonalizedRecommendationsAction()
    {
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $validateResponse = $planModel->validateRequiredParams();
        if ($validateResponse['success'] === false) {
            return $this->prepareResponse($validateResponse);
        }

        return $this->prepareResponse($planModel->getPersonalizedRecommendations());
    }

    /**
     * to make refund of credits
     */
    public function refundApplicationCreditsAction()
    {
        // $rawBody = $this->getRequestData();
        // $planModel = $this->di->getObjectManager()->get(Plan::class);
        // $validateResponse = $planModel->validateRequiredParams();
        // if ($validateResponse['success'] === false) {
        //     return $this->prepareResponse($validateResponse);
        // }
        // return $this->prepareResponse($planModel->refundApplicationCredits($rawBody));
        $rawBody = $this->getRequestData();
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        return $this->prepareResponse($planModel->refundApplicationCreditsQL($rawBody));
    }

    /**
     * to make custom payments
     */
    public function customPaymentAction()
    {
        $helper = $this->di->getObjectManager()->get(Helper::class);
        $rawBody = $this->getRequestData();
        $get = isset($rawBody['get']) &&  ((int)$rawBody['get'] === 1) ? true : false;
        return $this->prepareResponse($helper->prepareCustomPaymentLink($rawBody, $get));
    }

    /**
     * to proceed import for products if restrictions is removed
     */
    public function proceedImportAction()
    {
        $rawBody = $this->getRequestData();
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        return $this->prepareResponse($planModel->proceedImport($rawBody));
    }

    /**
     * test call for testers to update any changes from their end
     */
    public function creditUpdateTestAction()
    {
        $rawBody = $this->getRequestData();
        $rawBody['method'] = $this->request->getMethod();
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        return $this->prepareResponse($planModel->updateCreditTestCall($rawBody));
    }

    public function processCustomUserPlanAction()
    {
        $rawBody = $this->getRequestData();
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        return $this->prepareResponse($planModel->processCustomUserPlan($rawBody));
    }

    public function validateCouponAction()
    {
        $rawBody = $this->getRequestData();
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        if (isset($rawBody['coupon_code'], $rawBody['billed_type'], $rawBody['plan_id']) && !empty($rawBody['coupon_code'])) {
            $response = $planModel->validateAndGetCouponData($rawBody);
        } else {
            (!isset($rawBody['coupon_code']) || empty(trim($rawBody['coupon_code']))) && $message = 'Coupon code not provided!';
            (!isset($rawBody['billed_type']) || empty(trim($rawBody['billed_type']))) && $message = 'Billing type not provided!';
            (!isset($rawBody['plan_id']) || empty(trim($rawBody['plan_id']))) && $message = 'Plan Id not provided!';
            $response = [
                'success' => false,
                'message' => $message
            ];
        }
        return $this->prepareResponse($response);
    }

    public function activateCustomRecommendedPlanAction()
    {
        $rawBody = $this->getRequestData();
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $data['user_id'] = $this->di->getUser()->id;
        $response = $planModel->recommendationsBasedOnOrderPaymentLink($data);
        return $this->prepareResponse($response);;
    }
}
