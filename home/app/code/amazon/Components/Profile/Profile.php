<?php
namespace App\Amazon\Components\Profile;

use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base;
#[\AllowDynamicProperties]
class Profile extends Base
{
    private ?string $_user_id = null;

    private $_user_details;

    private $_baseMongo;

    public function init($request=[])
    {
        if (isset($request['user_id'])) $this->_user_id = (string)$request['user_id'];
        else $this->_user_id = (string)$this->di->getUser()->id;

        $this->_user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
        if (isset($request['shop_id'])) {
            $this->_shop_id = (string)$request['shop_id'];
            $shop = $this->_user_details->getShop($this->_shop_id, $this->_user_id);
        } else {
            $shop = $this->_user_details->getDataByUserID($this->_user_id, 'amazon');
            $this->_shop_id = $shop['_id'];
        }

        $this->_remote_shop_id = $shop['remote_shop_id'];
//        $this->_site_id = $shop['warehouses'][0]['seller_id'];

        $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        return $this;
    }

    public function getProfileIdsByProductIds($ids)
    {
        $productContainer = $this->_baseMongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
        $products = $productContainer->find(['source_product_id' => ['$in' => $ids]],
            ['projection' => ['profile' => 1], "typeMap" => ['root' => 'array', 'document' => 'array']]);

        $profileIdArray = array_column($products->toArray(), 'profile');

        $profileIds = array_keys(array_shift($profileIdArray));
        $implode = implode(",", $profileIds);
        $explode = explode(',', $implode);
        $profileIds = $explode;
        return $profileIds;
    }

    public function getAccountsByProfile($profile)
    {
        $accounts = [];
        if (isset($profile['settings']['amazon']) && !empty($profile['settings']['amazon'])) {
            $accountIds = array_keys($profile['settings']['amazon']);

            foreach ($accountIds as $accountId) {
                $account = $this->_user_details->getShop($accountId, $this->_user_id);

                if (!empty($account) && isset($account['warehouses'][0]['status']) && $account['warehouses'][0]['status'] == 'active') {
                    $accounts[$accountId] = $account;
                    $accounts[$accountId]['templates'] = $profile['settings']['amazon'][$accountId]['templates'];
                }
            }
        }

        return $accounts;
    }

    public function getAssociatedProductIds($profile, $ids)
    {
        $productContainer = $this->_baseMongo->getCollectionForTable(Helper::PRODUCT_CONTAINER);
//        $products = $productContainer->find(['profile_id' => $profileId],
//            ['projection' => ['source_product_id' => 1], "typeMap" => ['root' => 'array', 'document' => 'array']]);

        $products = $productContainer->find(['profile.'.$profile['profile_id'] => $profile['name']],
            ['projection' => ['source_product_id' => 1], "typeMap" => ['root' => 'array', 'document' => 'array']]);
        $productIds = array_unique(array_column($products->toArray(), 'source_product_id'));
        $commonProductIds = array_intersect($ids, $productIds);
        return $commonProductIds;
    }

    public function getProfileIdByProduct($product)
    {
        $profileId = false;
        $profileData = reset($product['profile']);
        if (isset($profileData['profile_id'])) {
            $profileId = $profileData['profile_id'];
        }

//            $profileId = array_keys($product['profile']);
//            $profileId = reset($profileId);
        return $profileId;
    }
}