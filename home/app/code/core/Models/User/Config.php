<?php

namespace App\Core\Models\User;

use App\Core\models\Base;

class Config extends Base
{
    protected $table = 'user_config_data';
    public function set($userId, $path, $value, $framework = 'global')
    {
        $config = Config::findFirst("framework='{$framework}' AND path='{$path}' AND user_id='{$userId}'");
        if ($config) {
            $status = $config->setValue($value)->save();
        } else {
            $newConfig = new Config;
            $status = $newConfig->setPath($path)->setFramework($framework)
                ->setValue($value)->setUserId($userId)->save();
        }
        return $status;
    }
    public function get($userId, $framework = 'global')
    {
        $config = Config::find("framework='{$framework}' AND user_id='{$userId}'");
        if ($config) {
            return $config->toArray();
        }
        return [];
    }
    public function getConfigByPath($userId, $path, $framework = 'global')
    {
        $config = Config::findFirst("framework='{$framework}' AND path='{$path}' AND user_id='{$userId}'");
        if ($config) {
            return $config->toArray();
        }
        return false;
    }

    public function removeConfigData($userId, $path, $framework = 'global')
    {
        $config = Config::findFirst("framework='{$framework}' AND path='{$path}' AND user_id='{$userId}'");
        if ($config) {
            return $config->delete();
        }
        return false;
    }


    public function setForShop($userId, $shopId = 0, $path, $value, $framework = 'global')
    {
        $config = Config::findFirst(
            "framework='{$framework}' AND path='{$path}' AND user_id='{$userId}' AND shop_id={$shopId}"
        );
        if ($config) {
            $status = $config->setValue($value)->save();
        } else {
            $newConfig = new Config;
            $status = $newConfig->setPath($path)->setFramework($framework)
                ->setValue($value)->setUserId($userId)->setShopId($shopId)->save();
        }
        return $status;
    }

    public function getForShop($userId, $shopId = 0, $framework = 'global')
    {
        $config = Config::find(
            "framework='{$framework}' AND user_id='{$userId}' AND shop_id={$shopId}"
        );
        if ($config) {
            return $config->toArray();
        }
        return [];
    }

    public function getConfigByPathForShop($userId, $shopId = 0, $path = null, $framework = 'global')
    {
        $config = Config::findFirst(
            "framework='{$framework}' AND path='{$path}' AND user_id='{$userId}' AND shop_id={$shopId}"
        );
        if ($config) {
            return $config->toArray();
        }
        return false;
    }

    public function removeConfigDataForShop($userId, $shopId = 0, $path = null, $framework = 'global')
    {
        $config = Config::findFirst(
            "framework='{$framework}' AND path='{$path}' AND user_id='{$userId}' AND shop_id={$shopId}"
        );
        if ($config) {
            return $config->delete();
        }
        return false;
    }
}
