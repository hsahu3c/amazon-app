<?php

namespace App\Connector\Components;

use \App\Core\Models\User\Details;
use \Firebase\JWT\JWT;

class Helper extends \App\Core\Components\Base

{
    const STEP_COMPLETED_KEY = 'step_completed';
    public function updateFeedProgress($feedId, $progress, $message = '', $addNupdate = true)
    {
        return ["success" => false, "message" => "please connect with code owner to update"];
    }

    public function addNotification($userId, $message, $severity, $url = false): void
    {
        if (is_array($message) && !empty($message) && isset($message['app_tag'])) {
            $appTag = $message['app_tag'];
            $message = $message['message'];
        } else {
            $appTag = $this->di->getAppCode()->getAppTag();
        }

        $notificationData = [
            'user_id' => $userId,
            'message' => $message,
            'severity' => $severity,
            'created_at' => date('c'),
        ];

        if ($appTag) {
            $notificationData['app_tag'] = $appTag;
        }

        if ($url) {
            $notificationData['url'] = $url;
        }

        $notification = $this->di->getObjectManager()->get('App\Connector\Models\Notifications');
        $notification->setData($notificationData);
        $notification->save();
    }

    public function saveTemporaryData($data, $userId)
    {
        return ["success" => false, "message" => "please connect with code owner to update"];
    }

    public function setQueuedTasks($message = '', $code = '', $user_id = false, $shop_id = false)
    {
        return ["success" => false, "message" => "please connect with code owner to update"];
    }

    public function getUsedTrialDays($userId)
    {
        return ["success" => false, "message" => "please connect with code owner to update"];
    }

    public function getUsedDays($userId)
    {
        return ["success" => false, "message" => "please connect with code owner to update"];
    }

    public function triggerMessage($params, $userId)
    {
        if (!isset($params['message'])) {
            return false;
        }

        $token = [];
        $token["user_id"] = $userId;
        $privateKey = file_get_contents(BP . DS . 'app' . DS . 'etc' . DS . 'security' . DS . 'connector.pem');
        $params['token'] = JWT::encode($token, $privateKey, 'RS256');
        $postParams = json_encode($params);
        $url = "https://websocket-notify.cifapps.com"; // URL changed as discussed with @poonam(DEVOPS), both(previous and new) url will work in this case
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postParams);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    }

    /**
     * @param array $data
     */
    public function handleMessage($data = []): void
    {
        if (isset($data['user_id']) && $data['user_id']) {
            if (isset($data['notification']) && isset($data['notification']['appTag'])) {
                $appTag = $data['notification']['appTag'];
            } else {
                $appTag = $this->di->getAppCode()->getAppTag();
            }

            $websocketConfig = $this->di->getConfig()->get("app_tags")
                ->get($appTag)
                ->get('websocket');
            if (isset($websocketConfig['client_id']) && $websocketConfig['client_id']) {
                $params = [];
                $params['client_id'] = $websocketConfig['client_id'];

                foreach ($websocketConfig['allowed_types'] as $type => $allowed) {
                    if (
                        isset($data[$type]) &&
                        $data[$type] &&
                        $allowed
                    ) {
                        $params['message'][$type] = $data[$type];
                        if ($type === 'notification') {
                            $params['message']['new_notification'] = true;
                        }
                    }
                }

                if (isset($params['message']) && count($params['message'])) {
                    $this->triggerMessage($params, $data['user_id']);
                }
            }
        }
    }

    public function getAllShopsToConnect($userId, $targetMarketplace, $targetMarketplaceShopId = false)
    {
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $user_details = $collection->findOne(['user_id' => (string) $userId]);
        $shops = [];
        foreach ($user_details['shops'] as $value) {
            if ($targetMarketplaceShopId) {
                if ($value['marketplace'] === $targetMarketplace && $value['_id'] == $targetMarketplaceShopId) {
                    $shops[] = $value;
                }
            }
        }

        return $shops;
    }

    public function getProfileSuggestions($productId, $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('profiles');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
        $profilesList = $collection->aggregate([
            [
                '$match' => [
                    'user_id' => (string) $userId,
                ],
            ],
            [
                '$project' => [
                    'query' => 1,
                    'profile_id' => 1,
                    'name' => 1,
                ],
            ],
        ], $options);
        $profilesList = $profilesList->toArray();
        if (count($profilesList)) {
            $profileSuggestions = [];
            $collection = $mongo->getCollectionForTable('product_container_' . $userId);
            foreach ($profilesList as $value) {
                $filterQuery = $value['query'];
                $customizedFilterQuery = $this->di->getObjectManager()->get('\App\Connector\Models\Product')->prepareQuery($filterQuery);
                $customizedFilterQuery = [
                    '$and' => [
                        [
                            "details.source_product_id" => (string) $productId,
                        ],
                        $customizedFilterQuery,
                    ],
                ];
                $response = $collection->aggregate([
                    [
                        '$match' => $customizedFilterQuery,
                    ],
                ], $options);
                $response = $response->toArray();
                if ($response && count($response)) {
                    $profileSuggestions[] = $value;
                }
            }

            return ['success' => true, 'data' => $profileSuggestions];
        }

        return ['success' => false, 'message' => 'No profiles found'];
    }

    public function formatCoreDataForDetail($details)
    {
        $formattedDetails = [];
        foreach ($details as $key => $value) {
            if ($key == "source_product_id") {
                $formattedDetails['source_product_id'] = $details['container_id'];
            } elseif ($key == "visibility") {
                $formattedDetails['visibility'] = "Catalog and Search";
            } elseif ($key == "_id") {
                continue;
            } else {
                $formattedDetails[$key] = $value;
            }
        }

        return $formattedDetails;
    }

    public function getActiveStep($data, $userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }
        if (isset($data['target']['marketplace'])) {
            $targetMarketplace = $data['target']['marketplace'];
        }
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $sourceId = $this->di->getRequester()->getSourceId();
        $collection = $mongo->getCollectionForTable('user_details');
        $user = $collection->find(['user_id' => $userId])->toArray()[0];
        $connected = false;
        foreach ($user['shops'] as $value) {
            if ($value['_id'] == $sourceId) {
                if (isset($value['targets'])) {
                    $targets = $value['targets'];
                    foreach ($targets as $v) {
                        $targetMarketplaceCode = $v['code'] ?? $v['marketplace'];
                        $dissconnect = $v['disconnected'] ?? false;
                        if ($targetMarketplaceCode == $targetMarketplace && !$dissconnect) {
                            $connected = true;
                        }
                    }
                }
            }
        }

        return ['success' => true, 'connected' => $connected, 'collections' => $user];
    }
    /**
     * Get step completed status
     */

    public function getStepCompleted()
    {
        $userId = $this->di->getUser()->id;
        if (!$userId) {
            throw new \Exception('User not found');
        }
        $resultFromUserDetails = $this->di->getObjectManager()->get(Details::class)->getConfigByKey(self::STEP_COMPLETED_KEY, $userId);
        if (!$resultFromUserDetails) {
            $resultFromUserDetails = 1;
        }
        $resultFromUserConfiguration = $this->di->getObjectManager()->get(StepsHelper::class)->getStepsDetails([
            'key' => self::STEP_COMPLETED_KEY,
        ]);
        $resultFromUserConfiguration ??= 1;
        if ($resultFromUserDetails !== $resultFromUserDetails) {
            throw new \Exception('Step completed doesn\'t match');
        }
        return [
            'success' => true,
            'data' => $resultFromUserDetails,
        ];
    }
    /**
     * Save step completed
     */
    public function saveStepCompleted($data)
    {
        if (!($userId = $this->di->getUser()->id)) {
            throw new \Exception('User not found');
        }
        if (!isset($data['step_completed'])) {
            throw new \Exception('Step completed data not provided');
        }
        $result = $this->di->getObjectManager()->get(Details::class)->setConfigByKey(self::STEP_COMPLETED_KEY, $data['step_completed'], $userId);
        if (!$result['success']) {
            return ['success' => false, 'message' => 'Failed to save step completed', 'step_completed' => $data['step_completed']];
        }
        $resultFromConfiguration = $this->di->getObjectManager()->get(StepsHelper::class)->saveStepsDetails([
            'key' => self::STEP_COMPLETED_KEY,
            'step_completed' => $data['step_completed'],
        ]);
        return $result;
    }

    public function getPlanTier($userId)
    {
        if (empty($userId)) {
            return ['success' => false];
        }
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $userDoc = $collection->findOne(
            ['user_id' => (string) $userId],
            [
                'projection' => ['plan_tier' => 1, '_id' => 0],
                'typeMap' => ['root' => 'array', 'document' => 'array'],
            ]
        );
        if (!$userDoc || empty($userDoc['plan_tier'])) {
            return ['success' => false];
        }
        return ['success' => true];
    }
}
