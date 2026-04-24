<?php

namespace App\Connector\Controllers;

use App\Core\Controllers\BaseController;

class WarehouseController extends BaseController
{
    public function createAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $warehouse = $this->di->getObjectManager()->create('\App\Connector\Models\Warehouse');
        return $this->prepareResponse($warehouse->createWarehouse($rawBody));
    } 

    public function getAction()
    {
        $warehouse = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $rawBody = $this->request->get();
        if (isset($rawBody['marketplace']) && $rawBody['marketplace'] !== 'all') {
            $warehouses = $warehouse->getWarehouseMarketplaceWise($rawBody['marketplace']);
            $response = [
                'success' => true,
                'warehouses' => [
                    $rawBody['marketplace'] => $warehouses,
                ],
            ];
        } else if (isset($rawBody['shop_id'])) {
            $shops = $warehouse->getShop($rawBody['shop_id']);
            if (!isset($shops['warehouses'])) {
                return ['success' => false, 'no warehouses find'];
            }

            foreach ($shops['warehouses'] as $warehouse) {
                $warehouses[] = [
                    'id' => $warehouse['_id'],
                    'name' => $warehouse['name'] ?? $warehouse['_id'],
                ];
            }

            $response = [
                'success' => true,
                'warehouses' => [
                    $shops['marketplace'] => $warehouses,
                ],
            ];
        } else {
            $response = [
                'success' => false,
                'warehouses' => 'required param marketplace is missing.',
            ];
        }

        return $this->prepareResponse($response);
    }

    public function getAllWharehousesAction()
    {
        $warehouse = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $this->request->get();
        $user_data = $warehouse->getConfig();

        if (!isset($user_data['shops']) || count($user_data['shops']) <= 0) {
            $response = [
                'success' => false,
                'warehouses' => 'required param marketplace is missing.',
            ];
        } else {
            foreach ($user_data['shops'] as $shop) {
                $warehouses = [
                    $shop['marketplace'] => $warehouse->getWarehouseMarketplaceWise($shop['marketplace']),
                ];
            }

            $response = [
                'success' => true,
                'warehouses' => $warehouse,
            ];
        }

        return $this->prepareResponse($response);
    }

    public function getWarehouseDetailAction()
    {
        $warehouse = $this->di->getObjectManager()->create('\App\Connector\Models\Warehouse');
        return $this->prepareResponse($warehouse->getWarehouse($this->request->get()));
    }

    public function getWarehousesOfProductsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $warehouse = $this->di->getObjectManager()->create('\App\Connector\Models\Warehouse');
        return $this->prepareResponse($warehouse->getWarehousesOfProducts($rawBody));
    }

    public function getCountriesAction()
    {
        $countries = $this->di->get('\App\Core\Components\Helper')->getAllCountry();
        return $this->prepareResponse(['success' => true, 'data' => $countries]);
    }

    public function updateWarehouseAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $warehouse = $this->di->getObjectManager()->create('\App\Connector\Models\Warehouse');
        return $this->prepareResponse($warehouse->updateWarehouse($rawBody));
    }

    public function deleteWarehouseAction()
    {
        $warehouse = $this->di->getObjectManager()->create('\App\Connector\Models\Warehouse');
        return $this->prepareResponse($warehouse->deleteWarehouse($this->request->get()));
    }
}
