<?php
namespace App\Shopifyhome\Components;

use App\Core\Components\Base;
use App\Connector\Components\ApiClient;

class Helper extends Base
{
    public function eligibleForBulkApi($url, $data)
    {
        $bulkApiConfig = $this->di->getConfig()->api->product->toArray();
        $plans_array=["shopify_plus","unlimited"];
        if($data['myshopify_domain']=="20hazar.myshopify.com" || $data['myshopify_domain']=="galu-cell-phones.myshopify.com"){
            return "" ;
        }

        switch ($bulkApiConfig['bulk']){

            case 'all' :
                return $bulkApiConfig['vistar'] ? 'Vistar\\' : 'Bulk\\';
                break;

            case 'partial' :
                if(in_array( $data['plan_name'], $plans_array) || in_array($url, $bulkApiConfig['shopify_domain'])) return $bulkApiConfig['vistar'] ? 'Vistar\\' : 'Bulk\\';
                else return "";
                break;

            default :
                return "";
                break;
        }
    }

    public function sendRequestToShopify($path, $headers = [], $data = [], $type = 'GET')
    {
        $appCode = 'default';
        if (isset($data['app_code'])) {
            $appCode = $data['app_code'];
            unset($data['app_code']);
        }

        return $this->di->getObjectManager()->get(ApiClient::class)
            ->init('shopify', true, $appCode)
            ->call($path, $headers, $data, $type);
    }

    /**
    * Creates a queue if it doesnt not exist and return its name
    * @param array $awsClient
    * @return array
    */
    public function getOrCreateQueueUrl($awsClient, $queueName)
    {
        $sqsObject = $this->di->getObjectManager()->get('App\Core\Components\Sqs');
        $client = $sqsObject->getClient($awsClient['region'], $awsClient['credentials']['key'] ?? null, $awsClient['credentials']['secret'] ?? null);
        $response = $sqsObject->createQueue($queueName, $client);
        if (!$response['success']) {
            return [
                'success' => false,
                'message' => $response
            ];
        }

        return [
            'success' => true,
            'queue_url' => $response['queue_url']
        ];
    }

    /**
     * Will return Remote shop ID of current user.
     *
     * @since 1.0.0
     * @return boolean|string
     */
    public function getRemoteShopId() {
        $shops = $this->di->getUser()->shops ?? [];
        foreach ( $shops as $value ) {
            if ( isset( $value['marketplace'] ) && $value['marketplace'] == 'shopify' ) {
                return $value['remote_shop_id'] ?? false;
            }
        }

        return false;
    }

    public function migrateInventoryConfig($data): void
    {
        echo "--> migrating inventory config for user - " . $data['user_id'] . "" . PHP_EOL;
        $configCollection = $this->getCollection('config');

        $configQuery = [
            'user_id' => $data['user_id'],
            // 'app_tag' => 'amazon_sales_channel',
            'app_tag' => ['$ne' => null],
            'group_code' => 'inventory'
        ];
        $options = $this->getOptions();
        $oldSettings = $configCollection->find($configQuery, $options)->toArray();
        if (empty($oldSettings)) {
            echo "#--> no settings found for user " . $data['user_id'] . "" . PHP_EOL;
            return;
        }

        $mappedSettings = $this->mappedSettings($oldSettings);

        $newSettings = [];

        $effectiveSettings = [];

        foreach ($oldSettings as $oldSetting) {
            // Common fields for both old and new settings
            $newSetting = [
                "_id" => $oldSetting["_id"],
                "group_code" => $oldSetting["group_code"],
                "app_tag" => $oldSetting["app_tag"],
                "target" => $oldSetting["target"],
                "target_shop_id" => $oldSetting["target_shop_id"],
                "source" => $oldSetting["source"],
                "source_shop_id" => $oldSetting["source_shop_id"],
                "user_id" => $oldSetting["user_id"],
                "created_at" => $oldSetting["created_at"],
            ];
            if (isset($oldSetting["updated_at"])) {
                $newSetting["updated_at"] = $oldSetting["updated_at"];
            }

            switch ($oldSetting["key"]) {
                case "settings_enabled":
                    $newSetting["key"] = "inventory_settings";
                    $newSetting["value"] = $oldSetting["value"];
                    break;
                case "enable_max_inventory_level":
                    $newSetting["key"] = "continue_selling";
                    $newSetting["value"] = [
                        'enabled' => $oldSetting["value"],
                        'value' => $mappedSettings['max_inventory_level'][$oldSetting["target_shop_id"]]['value'] ?? false
                    ];
                    break;
                case "warehouses_settings":
                    $newSetting["key"] = "warehouses";
                    $newSetting["value"] = $oldSetting["value"];
                    break;
                case "customize_inventory":
                    $effectiveSettings[$oldSetting["target_shop_id"]]['reserved'] = $newSetting + [
                        "key" => "effective_inventory",
                        "value" => [
                            'type' => "reserved",
                            'enabled' => $mappedSettings['settings_selected'][$oldSetting["target_shop_id"]]['value']['customize_inventory'],
                            'value' => $oldSetting["value"]['value']
                        ],
                    ];
                    continue 2;
                case "fixed_inventory":
                    $effectiveSettings[$oldSetting["target_shop_id"]]['fixed'] = $newSetting + [
                        "key" => "effective_inventory",
                        "value" => [
                            'type' => "fixed",
                            'enabled' => $mappedSettings['settings_selected'][$oldSetting["target_shop_id"]]['value']['fixed_inventory'],
                            'value' => $oldSetting["value"]
                        ]
                    ];
                    continue 2;
                case "threshold_inventory":
                    $effectiveSettings[$oldSetting["target_shop_id"]]['threshold'] = $newSetting + [
                        "key" => "effective_inventory",
                        "value" => [
                            'type' => "threshold",
                            'enabled' => $mappedSettings['settings_selected'][$oldSetting["target_shop_id"]]['value']['threshold_inventory'],
                            'value' => $oldSetting["value"]
                        ]
                    ];
                    continue 2;
                case "settings_selected":
                    if (isset($oldSetting['value']['delete_out_of_stock']) || isset($oldSetting['value']['inactive_product_setting'])) {
                        $newSetting["key"] = "inactivate_product";
                        $newSetting["value"] = $oldSetting['value']['inactive_product_setting'] ?? $oldSetting['value']['delete_out_of_stock'];
                        break;
                    }

                    continue 2;
                case "max_inventory_level":
                case "reserved_inventory":
                    continue 2;
                default:
                    $newSetting["key"] = $oldSetting["key"];
                    $newSetting["value"] = $oldSetting["value"];
                    break;
            }

            $newSettings[] = $newSetting;
        }

        foreach ($effectiveSettings as $effectiveSetting) {
            $enabledEffectiveSetting = null;
            if (!empty($effectiveSetting['threshold']['value']['enabled'])) {
                $enabledEffectiveSetting = 'threshold';
            } elseif (!empty($effectiveSetting['fixed']['value']['enabled'])) {
                $enabledEffectiveSetting = 'fixed';
            } elseif (!empty($effectiveSetting['reserved']['value']['enabled'])) {
                $enabledEffectiveSetting = 'reserved';
            }

            if (!is_null($enabledEffectiveSetting) && isset($effectiveSetting[$enabledEffectiveSetting])) {
                $effectiveSetting = $effectiveSetting[$enabledEffectiveSetting];
            } else {
                $effectiveSetting = $effectiveSetting['threshold']
                ?? $effectiveSetting['fixed']
                    ?? $effectiveSetting['reserved']
                    ?? null;
                $effectiveSetting['value']['type'] = 'none';
                $effectiveSetting['value']['enabled'] = false;
                $effectiveSetting['value']['value'] = '';
            }

            if (!is_null($effectiveSetting)) {
                $newSettings[] = $effectiveSetting;
            }
        }

        echo "Old settings count - " . count($oldSettings) . PHP_EOL;
        echo "New settings count - " . count($newSettings) . PHP_EOL;

        $configCollection->deleteMany($configQuery);
        echo "Deleted old settings" . PHP_EOL;
        $configCollection->insertMany($newSettings);
        echo "Inserted new settings" . PHP_EOL;

        $this->migrateTemplateInventorySchema($data['user_id'], $newSettings);

        echo "#--> Migration completed for user" . PHP_EOL;
    }

    public function mappedSettings($settings)
    {
        $mappedSettings = [];
        foreach ($settings as $oldSetting) {
            $mappedSettings[$oldSetting["key"]][$oldSetting["target_shop_id"]] = $oldSetting;
        }

        return $mappedSettings;
    }

    public function migrateTemplateInventorySchema($userId, $updatedSettings): void
    {
        echo "Initiating migrating template inventory schema" . PHP_EOL;

        $profileCollection = $this->getCollection('profile');
        $query = [
            'user_id' => $userId,
        ];
        $options = $this->getOptions();
        $allProfiles = $profileCollection->find($query, $options)->toArray();
        if (empty($allProfiles)) {
            echo "No profiles found for user - " . $userId . PHP_EOL;
            return;
        }

        echo "Found " . count($allProfiles) . " profiles for user - " . $userId . PHP_EOL;
        foreach ($allProfiles as $profile) {
            if (empty($profile['data'])) {
                echo "No data found for a profile" . PHP_EOL;
                continue;
            }

            foreach ($profile['data'] as $key => $data) {
                if (!is_array($data) || !isset($data['data']['data_type'])) {
                    continue;
                }

                if ($data['data']['data_type'] != 'inventory_settings') {
                    continue;
                }

                $updatedSchema = [
                    'data_type' => 'inventory_settings'
                ];
                if (!isset($data['data'])) {
                    echo 'error in processing - ' . $profile['user_id'] . PHP_EOL;
                    continue;
                }

                foreach ($data['data'] as $key => $value) {
                    if ($key == 'data_type') {
                        continue;
                    }

                    switch ($key) {
                        case "settings_enabled":
                            $updatedSchema['inventory_settings'] = $value;
                            break;
                        case "enable_max_inventory_level":
                            $updatedSchema['continue_selling'] = [
                                'enabled' => $value,
                                'value' => $data['data']['max_inventory_level']
                            ];
                            break;
                        case "warehouses_settings":
                            $updatedSchema['warehouses'] = $value;
                            break;
                        case "customize_inventory":
                        case "threshold_inventory":
                        case "fixed_inventory":
                            if (isset($data['data']['settings_selected']['threshold_inventory']) && $data['data']['settings_selected']['threshold_inventory']) {
                                $updatedSchema['effective_inventory'] = [
                                    'type' => 'threshold',
                                    'enabled' => true,
                                    'value' => $data['data']['threshold_inventory']
                                ];
                            } elseif (isset($data['data']['settings_selected']['fixed_inventory']) && $data['data']['settings_selected']['fixed_inventory']) {
                                $updatedSchema['effective_inventory'] = [
                                    'type' => 'fixed',
                                    'enabled' => true,
                                    'value' => $data['data']['fixed_inventory']
                                ];
                            } elseif (isset($data['data']['settings_selected']['customize_inventory']) && $data['data']['settings_selected']['customize_inventory']) {
                                $updatedSchema['effective_inventory'] = [
                                    'type' => 'reserved',
                                    'enabled' => true,
                                    'value' => $data['data']['customize_inventory']['value']
                                ];
                            } else {
                                $updatedSchema['effective_inventory'] = [
                                    'type' => 'none',
                                    'enabled' => false,
                                    'value' => ""
                                ];
                            }

                            break;

                        case "settings_selected":
                            if (isset($data['data']['settings_selected']['delete_out_of_stock']) || isset($data['data']['settings_selected']['inactive_product_setting'])) {
                                $updatedSchema["inactivate_product"] = $data['data']['settings_selected']['delete_out_of_stock'] ?? $data['data']['settings_selected']['inactive_product_setting'];
                                break;
                            }

                            break;
                        case "max_inventory_level":
                        case "reserved_inventory":
                            break;
                        default:
                            $updatedSchema[$key] = $value;
                            break;
                    }
                }

                $profileCollection->updateOne(
                    [
                        '_id' => $profile['_id'],
                        'data.data_type' => 'inventory_settings',
                    ],
                    [
                        '$set' => [
                            'data.$[index].data' => $updatedSchema,
                        ]
                    ],
                    [
                        'arrayFilters' => [
                            ['index.data_type' => 'inventory_settings'],
                        ]
                    ]
                );
            }
        }

        echo "Completed migrating template inventory schema" . PHP_EOL;
    }

    public function getCollection($collection)
    {
        return $this->di->getObjectManager()
            ->create('\App\Core\Models\BaseMongo')
            ->getCollectionForTable($collection);
    }

    public function getOptions()
    {
        return ["typeMap" => ['root' => 'array', 'document' => 'array']];
    }

    public function getAppLink($userId, $linkInfo, $appTag = 'default', $process = '', $queryParams = "")
    {
        $link = null;
        if ($appTag == "amazon_sales_channel") {
            $shopifyDomain = $this->getDomainName($userId ?? false);
            if (!empty($shopifyDomain) && !empty($linkInfo['basepath'])) {
                $shopify = explode('.', (string) $shopifyDomain);
                if (!empty($linkInfo['process_wise_section'][$process])) {
                    $link = ($linkInfo['basepath'] ?? ""). $shopify[0] . ($linkInfo['app-directory'] ?? "") . $linkInfo['process_wise_section'][$process];
                } else {
                    $link = ($linkInfo['basepath'] ?? ""). $shopify[0] . ($linkInfo['app-directory'] ?? "");
                }
            }
        }

        if (!is_null($link) && !empty($queryParams)) {
            $link = $link . '?'. $queryParams;
        }

        return $link;
    }

    public function getDomainName($userId = false)
    {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $shopifyDomain = '';
        $userData = $this->di->getUser()->shops;
        foreach ($userData as $shops) {
            if ($shops['marketplace'] == 'shopify') {
                $shopifyDomain = $shops['domain'] ?? "";
                break;
            }
        }

        return $shopifyDomain;
    }
}