<?php

namespace App\Connector\Components\Emails;

use App\Core\Models\BaseMongo;
use Exception;
use MongoDB\Operation\Executable;
use App\Core\Models\Config\Config;
use App\Core\Components\Base;

class Email extends Base
{
    const EMAIL_GROUP_CODE = 'email';

    const EMAIL_UNSUBSCRIBE = 'email_unsubscribe';

    const EMAIL_SUBSCRIBE = 'email_subscribe';

    const EMAIL_NOTIFICATIONS_SETTINGS = 'send_email_notifications';

    public function updateEmailSettings($data, $additionalData = [])
    {
        $result = [];
        $configObj = $this->di->getObjectManager()->get(Config::class);
        $configObj->setGroupCode(self::EMAIL_GROUP_CODE);
        $configObj->setAppTag(null);
        $configObj->setUserId($data['user_id']);
        if (isset($data['source_shop_id']) && !is_null($data['source_shop_id'])) {
            $sourceId = $data['source_shop_id'];
            $configObj->setSourceId($sourceId);
        }

        if (isset($data['target_shop_id']) && !is_null($data['target_shop_id'])) {
            $targetId = $data['target_shop_id'];
            $configObj->setTargetId($targetId);
        }

        $emailSettings = $configObj->getConfig($data['key']);
        $settingUpdateData = [];
        if (!empty($emailSettings) && (!isset($emailSettings[0]['user_id']) == 'all')) {
            foreach ($emailSettings as $emailSetting) {
                $configData = $emailSetting;
                $configData['value'] = $data['value'];
                if (!empty($additionalData)) {
                    foreach ($additionalData as $k => $info) {
                        $configData[$k] = $info;
                    }
                }

                $settingUpdateData[] = $configData;
            }
        } else {
            $configData['user_id'] = $data['user_id'];
            $configData['group_code'] = self::EMAIL_GROUP_CODE;
            $configData['key'] = $data['key'];
            $configData['value'] = $data['value'];
            $configData['source'] = $data['source'];
            $configData['target'] = $data['target'];
            $configData['app_tag'] = $this->di->getAppCode()->getAppTag() ?? ($data['app_tag'] ?? 'default');
            (!empty($data['source_shop_id'])) && $configData['source_shop_id'] = $data['source_shop_id'];
            (!empty($data['target_shop_id'])) && $configData['target_shop_id'] = $data['target_shop_id'];
            //to add any additional information
            if (!empty($additionalData)) {
                foreach ($additionalData as $k => $info) {
                    $configData[$k] = $info;
                }
            }

            $settingUpdateData[] = $configData;
        }

        try {
            $response = $configObj->setConfig($settingUpdateData);
            if (isset($response['data'])) {
                $result = $response['data'];
            } else {
                $result = [
                    'success' => false,
                    'message' => json_decode($response, true)
                ];
            }
        } catch (\Exception) {
            $result = [
                'success' => false,
                'message' => 'Some error coccured in setting config!'
            ];
        }

        return $result;
    }


    // public function updateEmailSettings($data, $additionalData = [])
    // {
    //     $result = [];
    //     $configObj = $this->di->getObjectManager()->get(Config::class);
    //     $configObj->setGroupCode(self::EMAIL_GROUP_CODE);
    //     $configObj->setAppTag(null);
    //     $configObj->setUserId($data['user_id']);
    //     if (isset($data['source_shop_id']) && !is_null($data['source_shop_id'])) {
    //         $sourceId = $data['source_shop_id'];
    //         $configObj->setSourceId($sourceId);
    //     }
    //     if (isset($data['target_shop_id']) && !is_null($data['target_shop_id'])) {
    //         $targetId = $data['target_shop_id'];
    //         $configObj->setTargetId($targetId);
    //     }
    //     $emailSettings = $configObj->getConfig($data['key']);
    //     //$updateSettings = true;
    //     $targetNames = [];
    //     $sourceNames = [];
    //     $searchForMarketplaces = true;
    //     if (!empty($emailSettings)) {
    //         foreach($emailSettings as $setting) {
    //             if (isset($setting['source']) && isset($setting['target'])) {
    //                 $sourceNames[] = $setting['source'];
    //                 $targetNames[] = $setting['target'];
    //                 $searchForMarketplaces = false;
    //             }
    //         }
    //     }
    //     $shops = $this->di->getUser()->shops;
    //     if(!empty($shops) && $searchForMarketplaces) {
    //             foreach($shops as $shop) {
    //                 if(isset($shop['targets']) && !empty($shop['targets'])) {
    //                     $sourceNames[] = $shop['marketplace'];
    //                     $targets = $shop['targets'];
    //                     foreach($targets as $target) {
    //                         $targetNames[] = $target['code'];
    //                     }
    //                 }
    //             }
    //     }
    //     $targetNames = array_unique($targetNames);
    //     $sourceNames = array_unique($sourceNames);
    //     $appTag = $this->di->getAppCode()->getAppTag() ?? 'default';

    //     $settingUpdateData = [];
    //     if(!empty($sourceNames) && !empty($targetNames)) {
    //         foreach($sourceNames as $source) {
    //             foreach($targetNames as $target) {
    //                 $configData['user_id'] = $data['user_id'];
    //                 $configData['group_code'] = self::EMAIL_GROUP_CODE;
    //                 $configData['key'] = $data['key'];
    //                 $configData['value'] = $data['value'];
    //                 $configData['source'] = $source;
    //                 $configData['target'] = $target;
    //                 $configData['app_tag'] = $appTag;
    //                 (!empty($data['source_shop_id'])) && $configData['source_shop_id'] = $data['source_shop_id'];
    //                 (!empty($data['target_shop_id'])) && $configData['target_shop_id'] = $data['target_shop_id'];
    //                 //to add any additional information
    //                 if(!empty($additionalData)) {
    //                     foreach($additionalData as $k => $info) {
    //                         $configData[$k] = $info;
    //                     }
    //                 }
    //                 $settingUpdateData[] = $configData;
    //             }
    //         }
    //     }
    //     try {
    //         $response = $configObj->setConfig($settingUpdateData);
    //         if (isset($response['data'])) {
    //             $result = $response['data'];
    //         } else {
    //             $result = [
    //                 'success' => false,
    //                 'message' => json_decode($response, true)
    //             ];
    //         }
    //     } catch (\Exception $e) {
    //         $result = [
    //             'success' => false,
    //             'message' => 'Some error coccured in setting config!'
    //         ];
    //     }
    //     return $result;
    // }

    public function isValueAlreadySetForKey($query)
    {
        $options = [
            "typeMap" => ['root' => 'array', 'document' => 'array']
        ];
        $baseMongo = $this->di->getObjectManager()->get(BaseMongo::class);
        $collection = $baseMongo->getCollectionForTable('config');
        $setting = $collection->find($query, $options)->toArray();
        if (!empty($setting)) {
            return true;
        }

        return false;
    }

    public function generateTokenForEmailSubscription($data = [])
    {
        if (!isset($data['user_id'], $data['action'], $data['setting_name'])) {
            return [
                'success' => false,
                'message' => 'Data is missing to generate token!'
            ];
        }

        $filter = [
            'type' => 'email_subscription',
            'user_id' => $data['user_id'],
            'action' => $data['action'],
            'setting_name' => $data['setting_name']
        ];
        if (isset($data['source_shop_id'])) {
            $filter['source_shop_id'] = $data['source_shop_id'];
        }

        if (isset($data['source'])) {
            $filter['source'] = $data['source'];
        }

        if (isset($data['target_shop_id'])) {
            $filter['target_shop_id'] = $data['target_shop_id'];
        }

        if (isset($data['target'])) {
            $filter['target'] = $data['target'];
        }

        $baseMongo = $this->di->getObjectManager()->create(BaseMongo::class);
        $collection = $baseMongo->getCollection('action_container');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $existingTokenData = $collection->findOne($filter, $options);
        $tokenExpired = false;
        $generateNew = false;
        if (!empty($existingTokenData) && isset($existingTokenData['token'])) {
            if (isset($existingTokenData['expire_at']) && ($existingTokenData['expire_at'] > date('c'))) {
                $token = $existingTokenData['token'];
            } else {
                $tokenExpired = true;
                $generateNew = true;
            }
        } else {
            $generateNew = true;
        }

        if ($generateNew) {
            $tokenId = rand(1000, 1000000) . strtotime('c');
            $tokenData = [
                '_id' => $tokenId,
                'type' => 'email_subscription',
                'user_id' => $data['user_id'],
                'action' => $data['action'],
                'setting_name' => $data['setting_name']
            ];
            $tokenData['user_id'] = $data['user_id'] ?? "";
            isset($data['app_tag']) && $tokenData['app_tag'] = $data['app_tag'];
            if (isset($data['source_shop_id'])) {
                $tokenData['source_shop_id'] = $data['source_shop_id'];
            }

            if (isset($data['source'])) {
                $tokenData['source'] = $data['source'];
            }

            if (isset($data['target_shop_id'])) {
                $tokenData['target_shop_id'] = $data['target_shop_id'];
            }

            if (isset($data['target'])) {
                $tokenData['target'] = $data['target'];
            }

            $time = '+1 year';
            $date = new \DateTime($time);
            $tokenInfo = [
                'action_id' => $tokenId,
                'action' => $data['action'],
                'type' => 'email_subscription',
                'exp' => $date->getTimestamp(),
                'aud' => ''
            ];
            if (isset($data['user_id']) && !empty($data['user_id'])) {
                $tokenInfo['user_id'] = $data['user_id'];
            }

            $token = $this->di->getObjectManager()->get('\App\Core\Components\Helper')->getJwtToken($tokenInfo);
            $tokenData['token'] = $token;
            $tokenData['created_at'] = date('c');
            $tokenData['updated_at'] = date('c');
            $tokenData['expire_at'] = date('c', $date->getTimestamp());
            $collection->insertOne($tokenData);
            if ($tokenExpired && !empty($existingTokenData)) {
                $collection->deleteOne(['_id' => $existingTokenData['_id']]);
            }
        }

        if (isset($data['only_token']) && $data['only_token']) {
            return [
                'success' => true,
                'message' => 'Token generated successfully.',
                'token' => $token
            ];
        }

        if (isset($data['frontend_url'])) {
            $frontendUrl = $data['frontend_url'];
        } else {
            $frontendUrl = $this->di->getConfig()->base_url_frontend . 'connector/email/processEmailSubscription';
        }

        $tokenUrl = $frontendUrl . '?token=' . $token;
        return [
            'success' => true,
            'message' => 'Link generated successfully.',
            'link' => $tokenUrl
        ];
    }

    public function processEmailSubscriptionUsingToken($data, $additionalData = [])
    {
        $result = [];
        if (!isset($data['action']) || !isset($data['user_id']) || !isset($data['setting_name'])) {
            return [
                'success' => false,
                'message' => 'Invalid data!'
            ];
        }

        $action = $data['action'];
        $settingData = [];
        $settingData['user_id'] = $data['user_id'];
        $diRes = $this->setDiForUser($data['user_id']);

        if (!$diRes['success']) {
            return [
                'success' => false,
                'message' => 'Invalid user!'
            ];
        }

        //$settingData['app_tag'] = $data['app_tag'];
        $settingData['key'] = $data['setting_name'];
        isset($data['target_shop_id']) && $settingData['target_shop_id'] = $data['target_shop_id'];
        isset($data['source_shop_id']) && $settingData['source_shop_id'] = $data['source_shop_id'];
        isset($data['target']) && $settingData['target'] = $data['target'];
        isset($data['source']) && $settingData['source'] = $data['source'];
        if ($action == self::EMAIL_SUBSCRIBE) {
            $settingData['value'] = true;
        } elseif ($action == self::EMAIL_UNSUBSCRIBE) {
            $settingData['value'] = false;
        } else {
            return [
                'success' => false,
                'message' => 'Not a valid action!'
            ];
        }

        if (!empty($settingData)) {
            $res = $this->updateEmailSettings($settingData, $additionalData);
            if ($res['success']) {
                $result = [
                    'success' => true,
                    'message' => 'Settings updated!'
                ];
            } else {
                $result = [
                    'success' => false,
                    'message' => $res['message']
                ];
            }
        }

        return $result;
    }

    public function isSettingsAlreadyImplemented($data)
    {
        $action = $data['action'];
        $settingData['user_id'] = $data['user_id'];
        //$settingData['app_tag'] = $data['app_tag'];
        $settingData['key'] = $data['setting_name'];
        $settingData['group_code'] = 'email';
        isset($data['target_shop_id']) && $settingData['target_shop_id'] = $data['target_shop_id'];
        isset($data['source_shop_id']) && $settingData['source_shop_id'] = $data['source_shop_id'];
        isset($data['target']) && $settingData['target'] = $data['target'];
        isset($data['source']) && $settingData['source'] = $data['source'];
        if ($action == self::EMAIL_SUBSCRIBE) {
            $settingData['value'] = true;
        } elseif ($action == self::EMAIL_UNSUBSCRIBE) {
            $settingData['value'] = false;
        } else {
            return false;
        }

        return $this->isValueAlreadySetForKey($settingData);
    }

    public function validateandDecodeToken($token)
    {
        try {
            $decodeToken = $this->di->getObjectManager()
                ->get('\App\Core\Components\Helper')
                ->decodeToken($token, false);

            if ($decodeToken['success'] && isset($decodeToken['data'])) {
                return $decodeToken;
            }

            $result = [
                'sucess' => false,
                'message' => $decodeToken['message'] ?? 'Unable to decode token!'
            ];
        } catch (Exception $e) {
            $result = [
                'success' => false,
                'message' => 'Token decode error => ' . json_encode($e->getMessage())
            ];
        }

        return $result;
    }

    public function getUserDetail($userId = '')
    {
        $baseMongo = $this->di->getObjectManager()->create(BaseMongo::class);
        $collection = $baseMongo->getCollection('user_details');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        return $collection->findOne(["user_id" => (string) $userId], $options);
    }

    public function getActionData($filter)
    {
        $baseMongo = $this->di->getObjectManager()->create(BaseMongo::class);
        $collection = $baseMongo->getCollection('action_container');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        return $collection->findOne($filter, $options);
    }

    public function setDiForUser($userId)
    {
        try {
            $getUser = \App\Core\Models\User::findFirst([['_id' => $userId]]);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        if (empty($getUser)) {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }

        $getUser->id = (string) $getUser->_id;
        $this->di->setUser($getUser);
        if ($this->di->getUser()->getConfig()['username'] == 'admin') {
            return [
                'success' => false,
                'message' => 'user not found in DB. Fetched di of admin.'
            ];
        }

        return [
            'success' => true,
            'message' => 'user set in di successfully'
        ];
    }

    public function updateUserEmail($rawBody)
    {
        if (isset($rawBody['user_id'])) {
            if ($rawBody['user_id'] == $this->di->getUser()->id) {
                $userId =  $this->di->getUser()->id;
            } elseif (isset($this->di->getUser()->getConfig()['username']) && $this->di->getUser()->getConfig()['username'] == 'admin') {
                $userId =  $rawBody['user_id'];
                $userDetails = $this->getUserDetail($userId);
                if (empty($userDetails)) {
                    return [
                        'success' => false,
                        'message' => 'User not found!'
                    ];
                }
            } else {
                return ([
                    'success' => false, 'message' => 'You are not an authorized user to perform this action!'
                ]);
            }
        } else {
            $userId =  $this->di->getUser()->id ?? null;
        }
        if (isset($rawBody['alternate_email']) && !empty($rawBody['alternate_email'])) {
            if ($this->isValidEmail($rawBody['alternate_email'])) {
                if (isset($rawBody['otp'])) {
                    $userOtp = new \App\Core\Models\UserOtp;
                    $otpData = [
                        'email' => $rawBody['alternate_email'],
                        'otp' => (int)$rawBody['otp']
                    ];
                    $res = $userOtp->validateOtp($otpData);
                    if (isset($res['success']) && !$res['success']) {
                        return $res;
                    }
                }
                $baseMongo = $this->di->getObjectManager()->create(BaseMongo::class);
                $collection = $baseMongo->getCollection('user_details');
                $collection->updateOne(["user_id" => (string)$userId], ['$set' => ['alternate_email' => $rawBody['alternate_email']]]);
                return [
                    'success' => true,
                    'message' => 'Alternate email updated successfully!'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Provided email is not valid!'
                ];
            }
        }
        return [
            'success' => false,
            'message' => 'Alternate email not provided!'
        ];
    }

    public function isValidEmail($email) {
        $pattern = "/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/";
        return preg_match($pattern, $email);
    }

    public function getUserEmail($rawBody)
    {
        if (isset($rawBody['user_id'])) {
            if ($rawBody['user_id'] == $this->di->getUser()->id) {
                $userId =  $this->di->getUser()->id;
            } elseif (isset($this->di->getUser()->getConfig()['username']) && $this->di->getUser()->getConfig()['username'] == 'admin') {
                $userId =  $rawBody['user_id'];
            } else {
                return ([
                    'success' => false, 'message' => 'You are not an authorized user to perform this action!'
                ]);
            }
        } else {
            $userId =  $this->di->getUser()->id ?? null;
        }
        $data = [];
        if ($userId == $this->di->getUser()->id) {
            $data['source_email'] = $this->di->getUser()->source_email ?? ($this->di->getUser()->email ?? "");
            $data['alternate_email'] = $this->di->getUser()->alternate_email ?? "";
        } else {
            $userDetails = $this->getUserDetail($userId);
            if (!empty($userDetails)) {
                $data['source_email'] = $userDetails['source_email'] ?? ($userDetails['email'] ?? "");
                $data['alternate_email'] = $userDetails['alternate_email'] ?? "";
            } else {
                return [
                    'success' => false,
                    'message' => 'User not found!'
                ];
            }
        }
        return [
            'success' => true,
            'data' => $data
        ];
    }
}
