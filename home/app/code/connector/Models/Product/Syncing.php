<?php

namespace App\Connector\Models\Product;

use App\Core\Models\Base;

class Syncing extends Base
{
    public $validateBeforeProductActivities;
    public function validateData($data)
    {
        return isset($data['source']['marketplace']) && isset($data['source']['shopId']) && isset($data['target']['marketplace']) && isset($data['target']['shopId']);
    }

    public function validateBeforeProductActivities($params)
    {
        if (!$this->validateData($params)) {
            return ['success' => false, 'message' => 'source_product_ids, profile_id ,source or target information missing', 'code' => 'data_missing'];
        }

        $model = $this->di->getConfig()->connectors->get($params['target']['marketplace'])->get('source_model');

        if (!(method_exists($this->di->getObjectManager()->get($model), "validateBeforeProductActivities"))) {
            return ['success' => true];
        }

        return $this->di->getObjectManager()->get($model)->validateBeforeProductActivities($params);
    }

    private function unsetSourceProductId(array &$data): void
    {
        if (isset($data['source_product_ids'])) {
            unset($data['source_product_ids']);
        }
    }

    public function getPrority($data, $key, $config)
    {

        return $data[$key] ?? $this->validateBeforeProductActivities['syncing_config'][$key] ?? $config[$data['target']['marketplace']]['syncing_config'][$key] ?? $config['global']['syncing_config'][$key];
    }

    public function setSyncingConfigPriority(&$data): void
    {
        $config = $this->di->getConfig()->connectors->toArray();

        $keyToGetPriority = $config['global']['syncing_config'];

        foreach ($keyToGetPriority as $key => $value) {
            $data[$key] = $this->getPrority($data, $key, $config);
        }
    }

    public function startSync($data)
    {
        $data['source'] = [
            'marketplace' => $data['source']['marketplace'] ?? $this->di->getRequester()->getSourceName(),
            'shopId' => (string) ($data['source']['shopId'] ?? $this->di->getRequester()->getSourceId()),
        ];
        $data['target'] = [
            'marketplace' => $data['target']['marketplace'] ?? $this->di->getRequester()->getTargetName(),
            'shopId' => (string) ($data['target']['shopId'] ?? $this->di->getRequester()->getTargetId()),
        ];

        $this->setSyncingConfigPriority($data);

        $this->validateBeforeProductActivities = $this->validateBeforeProductActivities($data);

        if (!($this->validateBeforeProductActivities['success'])) {
            return $this->validateBeforeProductActivities;
        }

        if (isset($this->validateBeforeProductActivities['syncing_config'])) {
            $this->setSyncingConfigPriority($data);
        }

        $productSync = new \App\Connector\Components\Profile\GetProductProfileMerge;

        $data['requeue_count'] = 0;

        if (isset($data['source_product_ids']) && count($data['source_product_ids'])) {

            if (isset($this->validateBeforeProductActivities['data']['updated_source_product_ids'])) {
                $data['source_product_ids'] = $this->validateBeforeProductActivities['data']['updated_source_product_ids'];
            }

            $data['workerName'] = $this->validateBeforeProductActivities['data']['workerName'] ?? $data['operationType'] . '_product_ids_wise_sync';

            if (isset($this->validateBeforeProductActivities['data']['return_product_direct']) && $this->validateBeforeProductActivities['data']['return_product_direct']) {
                return $this->validateBeforeProductActivities;
            }

            return $productSync->getproductsByProductIds($data);
        }

        if (isset($data['filter']) || isset($data['or_filter'])) {
            $data['workerName'] = $data['operationType'] . '_filter_wise_sync';
            $data['profile_id'] = 'all_products';
            $data['key_name'] = isset($data['filter']) ? 'filter' : 'or_filter';
            return $productSync->getproductsByProfile($data);
        }

        if (isset($data['profile_id'])) {
            $this->unsetSourceProductId($data);
            $data['workerName'] = $data['operationType'] . '_profile_id_wise_sync';
            $data['key_name'] = 'profile_id';
            return $productSync->getproductsByProfile($data);
        }

        if (isset($data['source_product_id'])) {
            $formattedData['data']['params'] = $data;
            $formattedData['data']['params']['source_product_ids'] = (array) $data['source_product_id'];
            $formattedData['data']['params']['aggregate_method'] = 'getProductIdsAggregate';
            $formattedData['data']['params']['activePage'] = 1;
            unset($formattedData['data']['params']['source_product_id']);
            return $productSync->getSingleProductData($formattedData);
        }

        return ['success' => false, 'message' => 'source_product_ids, profile_id ,source or target information missing', 'code' => 'data_missing'];
    }
}