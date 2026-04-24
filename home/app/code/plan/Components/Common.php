<?php

namespace App\Plan\Components;

use App\Core\Components\Base;
use App\Core\Models\User;
use Exception;
use App\Plan\Models\Plan;
use App\Plan\Models\BaseMongo;

/**
 * class Common for any common functions of being used in plan
 */
class Common extends Base
{
    /**
     * to calculate discount in the plan's data
     */
    public function getDiscountedAmount($planDetails)
    {
        $tempAmt =$planDetails['custom_price'] ?? 0;
        $tempAmt = &$tempAmt;
        if (isset($planDetails['discounts']) && !empty($planDetails['discounts'])) {
            foreach ($planDetails['discounts'] as $discount) {
                $discountActive = isset ($discount['active']) ? $discount['active'] :  true;
                if (isset($discount['type']) && isset($discount['value']) && $discountActive) {
                    switch ($discount['type']) {
                        case 'percentage':
                            $tempAmt = ($tempAmt - ($tempAmt * ((float)$discount['value']) / 100));
                            break;
                        case 'fixed':
                            $tempAmt = $tempAmt - (float)$discount['value'];
                            break;
                        default:
                            $tempAmt += 0;
                            break;
                    }
                }
            }
        }

        return $tempAmt;
    }

    /**
     * to set di for user
     */
    public function setDiForUser($userId)
    {
        $result = [];
        try {
            $getUser = User::findFirst([['_id' => $userId]]);
            if (empty($getUser)) {
                $result = [
                    'success' => false,
                    'message' => 'User not found'
                ];
            } else {
                $getUser->id = (string) $getUser->_id;
                $this->di->setUser($getUser);
                if ($this->di->getUser()->getConfig()['username'] == 'admin') {
                    $result = [
                        'success' => false,
                        'message' => 'user not found in DB. Fetched di of admin.'
                    ];
                } else {
                    $result = [
                        'success' => true,
                        'message' => 'user set in di successfully'
                    ];
                }
            }
        } catch (Exception $e) {
            $result = [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        return $result;
    }

    /**
     * to set plan di
     */
    public function setDiForPlan($userId)
    {
        $this->di->setPlan($this->di->getObjectManager()->create('App\Plan\Models\PlanDetails')->getPlanInfo($userId));
    }

     /**
     * @return mixed
     */
    // public function getAppTag()
    // {
    //     return $this->di->getAppCode()->getAppTag();
    // }

    /**
     * to get default apptag
     */
    // public function getDefaultAppTag()
    // {
    //     return Plan::APP_TAG;
    // }

    /**
     * @return mixed
     */
    // public function getSourceId()
    // {
    //     return $this->di->getRequester()->getSourceId();
    // }

     /**
     * @return mixed
     */
    // public function initApiClient()
    // {
    //     return $this->di->getObjectManager()->get(ApiClient::class)->init(
    //         Plan::MARKETPLACE,
    //         true,
    //         Plan::MARKETPLACE_GROUP_CODE
    //     );
    // }

    /**
     * to get shop data
     */
    public function getShop($sourceId, $userId)
    {
        $filterData = [
            '_id' => (string)$sourceId
        ];
        return $this->di->getObjectManager()
            ->get('\App\Core\Models\User\Details')
            ->findShop($filterData, $userId)[0] ?? [];
    }

    /**
     * to set di, requester and app tag in one call
     */
    public function setDiRequesterAndTag($userId)
    {
        $diRes = $this->setDiForUser($userId);
        if (!$diRes['success']) {
            return $diRes;
        }

        $shopId = $this->getShopifyShopId();
        if($shopId) {
            $this->di->getRequester()->setSource(
                [
                    'source_id' => $shopId,
                    'source_name' => Plan::SOURCE_MARKETPLACE
                ]
            );
            $this->di->getAppCode()->setAppTag(Plan::APP_TAG);
            $appTag = Plan::APP_TAG;
            $appCode  = $this->di->getConfig()->app_tags->$appTag->app_code;
            $appCode = $appCode->toArray();
            $this->di->getAppCode()->set($appCode);
            return [
                'success' => true,
                'messsage' => 'All set successfully!'
            ];
        }

        return [
            'success' => false,
            'messsage' => 'Unable to find source shop id!'
        ];
    }

    /**
     * to get shopify shop id
     */
    public function getShopifyShopId($userId = false)
    {
        $shops = [];
        if ($userId && ($userId !== $this->di->getUser()->id)) {
            $userData = $this->getUserDetail($userId);
            if (!empty($userData) && isset($userData['shops'])) {
                $shops = $userData['shops'];
            }
        } else {
            $shops = $this->di->getUser()->shops;
        }

        if (!empty($shops)) {
            foreach ($shops as $shop) {
                if ($shop['marketplace'] == Plan::SOURCE_MARKETPLACE) {
                    return $shop['_id'];
                }
            }
        }

        return false;
    }

    /**
     * to get shop data
     */
    public function getSourceShop($userId = false)
    {
        $shops = [];
        if ($userId) {
            $userData = $this->getUserDetail($userId);
            if (!empty($userData) && isset($userData['shops'])) {
                $shops = $userData['shops'];
            }
        } else {
            $shops = $this->di->getUser()->shops;
        }

        if (!empty($shops)) {
            foreach ($shops as $shop) {
                if ($shop['marketplace'] == Plan::SOURCE_MARKETPLACE) {
                    return $shop;
                }
            }
        }

        return false;
    }

    /**
     * to get user details
     */
    public function getUserDetail($userId = '')
    {
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $collection = $baseMongo->getCollection(Plan::USER_DETAILS_COLLECTION_NAME);
        return $collection->findOne(["user_id" => (string)$userId], Plan::TYPEMAP_OPTIONS);
    }

    /**
     * @param $shopifyDomain
     * @return string
     */
    public function getDefaultRedirectUrl($shopifyDomain = '')
    {
        $basePath = $this->getBasePath();
        if ($shopifyDomain) {
            $shopify = explode('.', (string) $shopifyDomain);
            return Plan::SHOPIFY_BASE_PATH. $shopify[0] . $basePath;
        }

        return $this->di->getConfig()->frontend_app_url . $this->di->getConfig()->redirect_after_install;
    }

    /**
     * work need to be done on this
     */
    public function getBasePath()
    {
        $basePath = '';
        $frontendAppUrl = $this->di->getConfig()->get('frontend_app_url');
        if (!empty($frontendAppUrl)) {
            if ($frontendAppUrl == "https://amazon-by-cedcommerce.cifapps.com/") {
                $basePath = '/apps/amazon-sales-channel-1/';
            }

            if ($frontendAppUrl == "https://multi-account.sellernext.com/apps/amazon-multi/") {
                $basePath = '/apps/amazon-multi-account-demo/';
            }

            if ($frontendAppUrl == "https://staging-amazon-by-cedcommerce.cifapps.com/") {
                $basePath = '/apps/amazon-by-cedcommerce-staging/';
            }

            if ($frontendAppUrl == "https://dev-amazon-sales-channel-app-backend.cifapps.com/") {
                $basePath = '/apps/amazon-by-cedcommerce-dev-1/';
            }

            if ($frontendAppUrl == 'https://testing-amazon-by-cedcommerce.cifapps.com/') {
                $basePath = '/apps/amazon-by-cedcommerce-dev-1/';
            }
        }

        return $basePath;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getApiConnectorConfigByKey($key = '')
    {
        return $this->di->getConfig()->get('apiconnector')
            ->get(Plan::MARKETPLACE)
            ->get(Plan::MARKETPLACE_GROUP_CODE)
            ->get($key);
    }

    /**
     * for logging
     */
    public function addLog($data, $file): void
    {
        $this->di->getLog()->logContent(
            print_r($data, true),
            'info',
            $file
        );
    }
}