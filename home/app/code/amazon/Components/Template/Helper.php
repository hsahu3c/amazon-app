<?php
namespace App\Amazon\Components\Template;

use App\Amazon\Components\Product\Inventory;
use MongoDB\BSON\ObjectId;
use Exception;
use App\Core\Components\Base;

class Helper extends Base
{
    public function getAmazonCategory($data)
    {
        $res = $this->di->getObjectManager()->get(Category::class)->init($data)
            ->getAmazonCategory($data);
        return $res;
    }

    public function getCategory($data)
    {
        $res = $this->di->getObjectManager()->get(Category::class)->init($data)
            ->getCategory($data);
        return $res;
    }

    public function getAmazonSubCategory($data)
    {
        $res = $this->di->getObjectManager()->get(Category::class)->init($data)
            ->getAmazonSubCategory($data);
        return $res;
    }

    public function getBrowseNodeIds($data)
    {
        $res = $this->di->getObjectManager()->get(Category::class)->init($data)
            ->getBrowseNodeIds($data);
        return $res;
    }

    public function getAmazonAttributes($data)
    {
        $res = $this->di->getObjectManager()->get(Category::class)->init($data)
            ->getAmazonAttributes($data);
        return $res;
    }

    public function categorySearch($data)
    {
        $res = $this->di->getObjectManager()->get(Category::class)->init($data)
            ->categorySearch($data);
        return $res;
    }

    public function getTemplatebyId($data)
    {
        $userId = (string)$this->di->getUser()->id;;
        if (isset($data['template_id'])) {
            $templateId = $data['template_id'];
            $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $baseMongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PROFILE_SETTINGS);
            $data = $collection->findOne([
                '_id' => (int)$templateId,
                /*'merchant_id' => (string)$userId*/
            ], ["typeMap" => ['root' => 'array', 'document' => 'array']]);
            if ($data &&
                count($data)
            ) {
                return ['success' => true, 'data' => $data];
            }

            return ['success' => false, 'message' => 'Business policy not found'];
        }

        return ['success' => false, 'message' => 'Invalid data'];
    }


    public function deleteTemplate($data)
    {
        $userId = (string)$this->di->getUser()->id;
        $data['user_id'] = $userId;
        if (isset($data['template_id'])) {
            $templateId = $data['template_id'];
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PROFILE_SETTINGS);
            $response = $collection->deleteMany([
                '_id' => (int)$templateId,
//                'merchant_id' => (string)$userId
            ]);
            if ($response /*&& $templateRemovefromAllProfiles*/) {
                return ['success' => true, 'message' => 'Template Deleted.'];
            }
            return ['success' => false, 'message' => 'Failed to delete template'];
        }

        return ['success' => false, 'message' => 'Invalid data format'];
    }

    public function saveTemplate($data)
    {

        if (isset($data['data']) &&
            isset($data['title']) &&
            isset($data['type']) && isset($data['marketplace'])) {
            $mongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable(\App\Amazon\Components\Common\Helper::PROFILE_SETTINGS);
            $settingsData = [
                'type' => $data['type'],
                'title' => $data['title'],
                'data' => $data['data'],
                'marketplace' => $data['marketplace'],
                'merchant_id' => (string)$this->di->getUser()->id
            ];
            $settingsData = $this->modifyNotAcceptedValues($settingsData);
            $TemplateId = "";
            if (isset($data['_id'])) {
                $status = $collection->updateOne([
                    '_id' => (int)$data['_id']
                ], [
                    '$set' => $settingsData
                ], [
                    'upsert' => true
                ]);
                $TemplateId = (int)$data['_id'];
            } else {
                $settingsId = $mongo->getCounter('settings_id');
                $settingsData['_id'] = $settingsId;
                $status = $collection->insertMany([$settingsData]);
                $TemplateId = $settingsId;
            }

            if ($status) {
                return ['success' => true, 'message' => ucfirst((string) $data['type']) . ' template saved successfully', "id" => (string)$TemplateId];
            }

            return ['success' => false, 'message' => 'Failed to save template. Please try again later.'];
        }

        return ['success' => false, 'message' => 'Invalid data format.'];
    }

    public function modifyNotAcceptedValues($data)
    {
        $restrictedCharacter = '.';
        $replacedCharacter = '_';
        foreach ($data as $key => $value) {
            if (str_contains((string) $key, $restrictedCharacter)) {
                $newKey = str_replace($restrictedCharacter, $replacedCharacter, $key);
                $data[$newKey] = $value;
                unset($data[$key]);
                $key = $newKey;
            }

            if (is_array($value)) {
                $data[$key] = $this->modifyNotAcceptedValues($value);
            }
        }

        return $data;
    }

    public function warehouseCheck($rawBody)
    {
        $userId = (string) $this->di->getUser()->id;
        $sourceShopId = (string)$this->di->getRequester()->getSourceId() ?? false;

        $inventoryComponent = $this->di->getObjectManager()->get(Inventory::class);
        $activeWarehouses = $inventoryComponent->getActiveWarehouses($userId , $sourceShopId);
        $activeWarehouses = $activeWarehouses['active'] ?? []; 

        $configHelper = $this->di->getObjectManager()->get('\App\Connector\Components\ConfigHelper');
        $profile = $this->di->getObjectManager()->get('App\Connector\Components\Profile\ProfileHelper');
        $data = $configHelper->getConfigData($rawBody);
        $data = json_decode(json_encode($data),true);

        $update = false;
        $profileName = [];
        $result = [];
        if(isset($data['success']) && $data['success'])
        {
            $disabledWarehouse = [];
            $templateData = $data['data'][0]['value'] ?? [];
            if(isset($templateData['warehouse_disabled']) && !empty($templateData['warehouse_disabled']))
            {
                foreach ($templateData['warehouse_disabled'] as $key => $template) {
                    # code...
                    $isActive = false;
                    if($template == 'Product Settings')
                    {
                        if(!empty($templateData['warehouses']))
                        {
                            $isActive = array_intersect($templateData['warehouses'] , $activeWarehouses);
                            if($isActive)
                            {
                                $update = true;
                                unset($templateData['warehouse_disabled'][$key]);
                            }
                            else
                            {
                                $profileName[] = 'Product Settings';
                            }
                        }
                    }
                    else
                    {
                        if(is_string($template) && strlen($template) == 24) {
                            $invData = [];
                            $profileData = $profile->getProfileByProfileId(new ObjectId($template));
                            $profileData = json_decode(json_encode($profileData[0]) ,true);
                            foreach ($profileData['data'] as $key => $value) {
                                # code...
                                if($value['data_type'] == 'inventory_settings')
                                {
                                    $invData = $value;
                                    break;
                                }
                            }

                            $warehouseInTemp = isset($invData['data']['warehouses']) && !empty($invData['data']['warehouses']) ? $invData['data']['warehouses'] : false;
                            if($warehouseInTemp)
                            {
                                $isActive = array_intersect($warehouseInTemp , $activeWarehouses);
                                if($isActive)
                                {
                                    $update = true;
                                    unset($templateData['warehouse_disabled'][$key]);
                                }
                                else
                                {
                                    $profileName[$template] = $profileData['name'];
                                }
                            }
                        }
                    }
                }

                if(!empty($profileName))
                {
                    $result = ['success' => true , 'data' => $profileName];
                }
                else
                {
                    $result = ['success' => false , 'msg' => 'No inactive profile'];
                }
            }
            else
            {
                $result = ['success' => false , 'msg' => 'No inactive profile'];
            }
        }

        if($update)
        {
            $savedData = [
                'group_code' => 'inventory',
                'data' => [
                    'warehouse_disabled' => $templateData['warehouse_disabled']
                    ]
            ];
            $configData['data'][0] = $savedData;
            $res = $configHelper->saveConfigData($configData); 
        }

        return $result;
    }

    public function addNewAttribute($data)
    {
        try {
            if (isset($data['category'], $data['subcategory'], $data['endpoint'], $data['attributes']) && !empty($data['attributes'])) {
                $commonHelper = $this->di->getObjectManager()->get(\App\Amazon\Components\Common\Helper::class);
                $params = [
                    'category' => $data['category'],
                    'subcategory' => $data['subcategory'],
                    'endpoint' => $data['endpoint'],
                    'attributes' => $data['attributes']
                ];
                $result = $commonHelper->sendRequestToAmazon('add-attribute', $params, 'POST');
                if (isset($result['success']) && $result['success']) {
                    if(isset($result['marketplaceId']))
                    {
                        $this->di->getObjectManager()->get(CategoryAttributeCache::class)->deleteAttributesFromCache($result['marketplaceId'], $data['category'],  $data['subcategory']); 
                    }

                    return ['success' => true, 'message' => $result['response'] ?? "Attribute's condition Changed successfully."];
                }

                return ['success' => false, 'message' => $result['message'] ?? 'Error in changing attributes successfully.'];
            }
            return ['success' => false, '$data' => $data, 'message' => 'Required param(s) missing : shop_id, category, sub_category, endpoint, attributes'];
        } catch (Exception $e) {
            return [
                'success' => false,
                'data' => $data,
                'message' => $e->getMessage()
            ];
        }
    }
}