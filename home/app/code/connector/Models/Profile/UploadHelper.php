<?php

namespace App\Connector\Models\Profile;

use App\Core\Models\BaseMongo;

class UploadHelper extends BaseMongo
{
    public $processData;

    public $profileId;

    protected $profileData;

    protected $attributeMappingPath = '';

    protected $productData = null;

    public function validateData()
    {
        $requiredKey = $this->requiredKey();
        foreach ($requiredKey as $value) {
            if (isset($this->processData[$value])) {
                $this->{$value} = $this->processData[$value];
            } else {
                return ['success' => false, 'message' => "Required Key missing"];
            }
        }

    }

    public function requiredKey()
    {
        return [
            'profile_id',
            'target_marketplace',
        ];

    }

    public function process()
    {
        $validateData = $this->validateData();
        if (isset($validateData['success']) && !$validateData['success']) {
            return $validateData['message'];
        }

        $profileData = $this->getProfileData();
        if (isset($profileData['success']) && $profileData['success']) {
            $response = $this->targetMarketplaceProcess();
            return $response;
        }
        return $profileData;

    }

    public function getProfileData()
    {
        $profileParams = [];
        $profileParams['filters'] = ['id' => $this->profileId];
        $obj = new Model();
        $profileData = $obj->getProfile($profileParams);

        if (isset($profileData['data'])) {
            if (isset($profileData['data'][0])) {
                $this->profileData = $profileData['data'][0];
                return ['success' => true, "data" => $this->profileData];
            }
            return ['success' => false, "message" => "profile_id not found"];

        }

        return ['success' => false, "message" => "profile_id not found"];
    }

    public function getProductData()
    {

        if (is_null($this->productData)) {
            $profileData = [];
            var_dump($this->processData['page']);
            var_dump($this->processData['limit']);
            if (isset($this->processData['page'], $this->processData['limit'])) {
                $limit = (int) $this->processData['limit'];
                $page = (int) $this->processData['page'] - 1;
                $skip = ($limit * $page);
            } else {
                $skip = 0;
                $limit = 50;
            }

            $profileData['skip'] = $skip;
            $profileData['limit'] = $limit;
            $obj = new Helper();
            $profileData['marketplace'] = $this->processData['target_marketplace'];
            $profileData['profile_data'] = $this->profileData;
            $obj->processData = $profileData;

            $searchProduct = $obj->getProducts();
            if ($searchProduct['success']) {
                $searchProduct = $searchProduct['data'];
                $this->productData = $searchProduct;
            } else {
                return ['success' => false, "message" => "product not found for this profile id"];
            }
        } else {
            $searchProduct = $this->productData;
        }

        if ($searchProduct) {
            return ['success' => true, "data" => $searchProduct];
        }
        return ['success' => false, "message" => "product not found for this profile id"];

    }

    public function targetMarketplaceProcess()
    {
        $this->attributeMappingPath = 'targets';
        $targetMarketplace = $this->processData['target_marketplace'];

        if (isset($this->processData['target_shop_id'])) {

            $response = $this->targetShopIdProcess($targetMarketplace, $this->profileData['targets'][$targetMarketplace]['shops'][$this->processData['target_shop_id']]);
        } else {
            $targetsShopsData = $this->profileData['targets'][$targetMarketplace]['shops'];

            $response = $this->targetShopIdsProcess($targetMarketplace, $targetsShopsData);
        }

        return $response;
    }

    public function targetShopIdsProcess($targetMarketplace, $targetsShopsData)
    {
        $response = [];
        foreach ($targetsShopsData as $tagetshopIdValue) {
            if (isset($tagetshopIdValue['active']) && !$tagetshopIdValue['active']) {
                continue;
            }

            $response = $this->targetShopIdProcess($targetMarketplace, $tagetshopIdValue);
        }

        return $response;

    }

    public function targetShopIdProcess($targetMarketplace, $tagetshopIdValue)
    {

        $mappedAttributes = [];
        $prevPath = "targets.{$targetMarketplace}.shops";
        $this->attributeMappingPath = $prevPath . '.' . $tagetshopIdValue['shop_id'];
        // inventory impact
        $response = [];
        foreach ($tagetshopIdValue['warehouses'] as $targetWarehouseIdValue) {
            if (isset($targetWarehouseIdValue['active']) && !$targetWarehouseIdValue['active']) {
                continue;
            }

            $response = $this->targetWarehouseIdProcess($targetMarketplace, $targetWarehouseIdValue, $mappedAttributes);
        }

        $this->attributeMappingPath = $prevPath;

        if (!empty($mappedAttributes)) {
            $response['mappedAttributes'] = $mappedAttributes;
        }

        if ($response['success']) {
            $productData = $this->getProductData();

            if ($productData['success']) {
                $productData = $productData['data'];
                $newProductData = [];

                if (isset($response['mappedAttributes'])) {
                    $mappedAttributes = $response['mappedAttributes'];

                    foreach ($productData as $product) {
                        foreach ($mappedAttributes as $columnName => $mappedData) {

                            $namespace = "\\App\\Connector\\Models\\Profile\\Attribute\\Type\\" . ucfirst($mappedData['type']);
                            if (class_exists($namespace)) {
                                $obj = new $namespace();
                                $product = $obj->changeData($columnName, $mappedData, $product);
                            }

                        }

                        if (!empty($product['variants'])) {
                            foreach ($product['variants'] as $proCol => $variant) {
                                foreach ($mappedAttributes as $columnName => $mappedData) {
                                    $namespace = "\\App\\Connector\\Models\\Profile\\Attribute\\Type\\" . ucfirst($mappedData['type']);
                                    if (class_exists($namespace)) {
                                        $obj = new $namespace();
                                        $variant = $obj->changeData($columnName, $mappedData, $variant);
                                    }

                                }

                                $product['variants'][$proCol] = $variant;
                            }

                            $newProductData[] = $product;

                        } else {
                            $newProductData[] = $product;
                        }

                    }

                } else {
                    $newProductData = $this->getProductData();
                }

                $this->productData = null;
                if (!empty($newProductData)) {
                    $connectorHelper = $this->di->getObjectManager()
                        ->get('App\Connector\Components\Connectors')
                        ->getConnectorModelByCode($targetMarketplace);
                    $connectorHelper->startUpload($newProductData, $tagetshopIdValue['shop_id'], $this->profileData,$this->processData);
                }
            } else {

                return $productData;
            }

            return ['success' => true, 'data' => $response];
        }
        return ['success' => false, 'message' => $response['message']];
    }

    public function targetWarehouseIdProcess($targetMarketplace, $targetWarehouseIdValue, &$mappedAttributes)
    {
        $allSources = $targetWarehouseIdValue['sources'];
        $sourceWareHouses = [];
        $response = [];
        $prevPath = $this->attributeMappingPath;
        $this->attributeMappingPath = $prevPath . '.warehouses.' . $targetWarehouseIdValue['warehouse_id'];

        foreach ($allSources as $sourceMarketplaceId => $sourceMarketplaceIdValue) {
            $sourceWareHouses[] = $sourceMarketplaceId;
            $response = $this->sourceMarketplaceIdProcess($targetMarketplace, $sourceMarketplaceIdValue, $mappedAttributes, $sourceWareHouses);
        }

        $this->attributeMappingPath = $prevPath;

        if (empty($response)) {
            return ['success' => false, 'message' => 'No source setup'];
        }
        return ['success' => true, 'data' => $response];

    }

    public function sourceMarketplaceIdProcess($targetMarketplace, $sourceMarketplaceIdValue, &$mappedAttributes, &$sourceWareHouses)
    {

        $response = [];
        $prevPath = $this->attributeMappingPath;
        $this->attributeMappingPath = $prevPath . '.sources.' . $sourceMarketplaceIdValue['source_marketplace_name'];

        foreach ($sourceMarketplaceIdValue['shops'] as $sourceMarketplaceShopIdValue) {
            if (isset($sourceMarketplaceShopIdValue['active']) && !$sourceMarketplaceShopIdValue['active']) {
                continue;
            }

            $response = $this->sourceMarketplaceShopIdProcess($targetMarketplace, $sourceMarketplaceShopIdValue, $mappedAttributes, $sourceWareHouses);

        }

        $this->attributeMappingPath = $prevPath;
        if (empty($response)) {
            return ['success' => false, 'message' => 'Source marketplace id not setuped'];
        }
        return ['success' => true, 'data' => $response];

    }

    public function sourceMarketplaceShopIdProcess($targetMarketplace, $sourceMarketplaceShopIdValue, &$mappedAttributes, &$sourceWareHouses)
    {
        $response = [];
        $prevPath = $this->attributeMappingPath;
        $this->attributeMappingPath = $prevPath . '.shops.' . $sourceMarketplaceShopIdValue['shop_id'];

        foreach ($sourceMarketplaceShopIdValue['warehouses'] as $sourceMarketplaceWarehouseId => $sourceMarketplaceWarehouseIdValue) {
            if (isset($sourceMarketplaceWarehouseIdValue['active']) && !$sourceMarketplaceWarehouseIdValue['active']) {
                continue;
            }

            $sourceWareHouses[] = $sourceMarketplaceWarehouseId;
            $this->sourceMarketplaceWarehouseId($targetMarketplace, $sourceMarketplaceWarehouseIdValue, $mappedAttributes, $sourceWareHouses);

        }

        $this->attributeMappingPath = $prevPath;

        return ['success' => true, 'data' => $response];
    }

    public function sourceMarketplaceWarehouseId($targetMarketplace, $sourceMarketplaceWarehouseIdValue, &$mappedAttributes, &$sourceWareHouses): void
    {

        $prevPath = $this->attributeMappingPath;
        $this->attributeMappingPath = $prevPath . '.warehouses.' . $sourceMarketplaceWarehouseIdValue['warehouse_id'] . '.attributes_mapping';

        $helperDataArr = [];

        $helperDataArr['profile_data'] = $this->profileData;
        $helperDataArr['marketplace'] = $targetMarketplace;
        $helperDataArr['source_marketplace'] = $targetMarketplace;

        $profileHelperObj = new Helper();
        $profileHelperObj->processData = $helperDataArr;

        $attributeRes = $profileHelperObj->getProfileAttribute($this->attributeMappingPath);

        if ($attributeRes['success']) {
            $mappedAttributes = array_merge($mappedAttributes, $attributeRes['data']);
        }

        $this->attributeMappingPath = $prevPath;

    }

}
