<?php

namespace App\Connector\Models;

use App\Core\Models\Base;

class Warehouse extends Base
{
    protected $table = 'warehouse';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }

    public function createWarehouse($data)
    {
        $userId = $this->di->getUser()->id;
        $errors = [];
        $userDb = $this->getMultipleDbManager()->getDb();
        $connection = $this->di->get($userDb);
        $connection->begin();
        try {
            $data['merchant_id'] = $userId;
            $data['handler'] = isset($data['handler']) ? $data['handler'] : 'default';
            $status = $this->create($data);
            if ($status) {
                $handle = $data['handler'];
                if (class_exists($this->di->getConfig()->warehouse_handle->$handle->source)) {
                    $source = $this->di->getObjectManager()->get($this->di->getConfig()->warehouse_handle->$handle->source);
                    $source->createWarehouse($data, $this->id);
                }
            } else {
                foreach ($this->getMessages() as $message) {
                    $errors[] = $message->getMessage();
                }

                return ['success'=>false, 'code'=>'error_in_creation', 'message'=>'Error In Saving', 'data'=>$errors];
            }

            return ['success'=>true, 'message'=>'Warehouse Created Successfully', 'data'=>[]];
        } catch (\Exception $e) {
            $connection->rollback();
            return ['success'=>false, 'code'=>'something_wrong', 'message'=>'Something Wrong', 'data'=>[$e->getMessage()]];
        }
    }

    public function getWarehousesOfProducts($products)
    {
        $userId = $this->di->getUser()->id;
        $allProducts = [];
        foreach ($products as $key => $value) {
            $allProducts[] = $value['id'];
        }

        $query = 'SELECT w.id,w.name,wp.product_id,wp.qty FROM `warehouse` as w INNER JOIN `warehouse_product` as wp ON w.merchant_id = ' . $userId .' AND w.id = wp.warehouse_id AND wp.product_id IN (' . implode(',', $allProducts) . ')';
        $queryToGetTitle = 'SELECT p.sku,p.weight,p.weight_unit,p.price,p.id,pc.title,pc.variant_attribute FROM `product_' . $userId . '` as p INNER JOIN `product_container_' . $userId . '` as pc WHERE p.id IN (' . implode(',', $allProducts) . ') AND p.product_id = pc.id';
        $userDb = $this->getMultipleDbManager()->getDb();
        $connection = $this->di->get($userDb);
        $warehouseDetails = $connection->fetchAll($query);
        $productTitles = $connection->fetchAll($queryToGetTitle);
        foreach ($productTitles as $key => $value) {
            if ($value['variant_attribute'] !== null) {
                if (strpos($value['variant_attribute'], ',')) {
                    $productTitles[$key]['config_columns'] = explode(',', $value['variant_attribute']);
                } else {
                    $productTitles[$key]['config_columns'] = [$value['variant_attribute']];
                }
            }

            $productTitles[$key]['final_title'] = $value['title'];
        }

        foreach ($productTitles as $key => $value) {
            if (isset($value['config_columns'])) {
                $productModel = Product::findFirst(
                    [
                        "id='{$value['id']}'"
                    ]
                )->toArray();
                foreach ($value['config_columns'] as $configKeys) {
                    if (isset($productModel[$configKeys])) {
                        $productTitles[$key]['final_title'] .= ' ' . $productModel[$configKeys];
                    } else {
                        $additionalFields = Product::getJson($productModel['additional_info']);
                        if ($additionalFields && is_array($additionalFields)) {
                            foreach ($additionalFields as $fieldKey => $fieldValue) {
                                if (isset($productModel[$fieldKey])) {
                                    $productTitles[$key]['final_title'] .= ' ' . $fieldValue;
                                }
                            }
                        }
                    }
                }
            }
        }

        $prodTitles = [];
        $toReturnData = [];
        foreach ($productTitles as $key => $value) {
            $prodTitles[$value['id']][] = $value;
        }

        $productWiseWarehouse = [];
        foreach ($warehouseDetails as $value) {
            $productWiseWarehouse[$value['product_id']][] = $value;
        }

        $toReturnData = [];
        foreach ($productWiseWarehouse as $singleProdWarehouse) {
            $warehouseProdDetail = [];
            foreach ($singleProdWarehouse as $value) {
                $warehouseProdDetail[] = [
                    'id' => $value['id'],
                    'name' => $value['name'],
                    'quantity' => $value['qty']
                ];
            }

            $toReturnData[] = [
                'id' => $singleProdWarehouse[0]['product_id'],
                'warehouses' => $warehouseProdDetail
            ];
        }

        foreach ($toReturnData as $key => $value) {
            if (isset($prodTitles[$value['id']])) {
                $toReturnData[$key]['title'] = $prodTitles[$value['id']][0]['final_title'];
                $toReturnData[$key]['sku'] = $prodTitles[$value['id']][0]['sku'];
                $toReturnData[$key]['price'] = $prodTitles[$value['id']][0]['price'];
                $toReturnData[$key]['subtotal_price'] = $prodTitles[$value['id']][0]['price'];
                $toReturnData[$key]['total_price'] = $prodTitles[$value['id']][0]['price'];
                $toReturnData[$key]['total_discounts'] = 0;
                $toReturnData[$key]['total_tax'] = 0;
                $toReturnData[$key]['weight'] = $prodTitles[$value['id']][0]['weight'];
                $toReturnData[$key]['weight_unit'] = $prodTitles[$value['id']][0]['weight_unit'];
                $toReturnData[$key]['quantity'] = 0;
                $toReturnData[$key]['selected_warehouse'] = '';
                $toReturnData[$key]['tax_lines'] = [];
                $toReturnData[$key]['discount_codes'] = [];
                $toReturnData[$key]['max_qty'] = $prodTitles[$value['id']][0]['max_qty'];
            }
        }

        return ['success' => true, 'data' => $toReturnData];
    }

    public function getAllWharehouses($limit = 100, $activePage = 1, $filters = [])
    {
        $user = $this->di->getUser();
        if (count($filters) > 0) {
            $query = 'SELECT * FROM \App\Connector\Models\Warehouse WHERE merchant_id = ' . $user->id . ' AND ';
            $countQuery = 'SELECT COUNT(*) FROM \App\Connector\Models\Warehouse WHERE merchant_id = ' . $user->id . ' AND ';
            $conditionalQuery = self::search($filters);
            $query .= $conditionalQuery;
            $countQuery .= $conditionalQuery . ' LIMIT ' . $limit . ' OFFSET ' . $activePage;
            $exeQuery = new Query($query, $this->di);
            $collection = $exeQuery->execute();
            $exeCountQuery = new Query($countQuery, $this->di);
            $collectionCount = $exeCountQuery->execute();
            $collection = $collection->toArray();
            $collectionCount = $collectionCount->toArray();
            $collectionCount[0] = json_decode(json_encode($collectionCount[0]), true);
            return ['success'=>true, 'message'=>'All user attributes', 'data'=>['rows'=>$collection, 'count'=>$collectionCount[0][0]]];
        }
        $allAttributes = self::find(["merchant_id='{$user->id}'", 'limit'=>$limit, 'offset'=>$activePage]);
        $count = self::count(["merchant_id='{$user->id}'"]);
        return ['success'=>true, 'message'=>'All user attributes', 'data'=>['rows'=>$allAttributes->toArray(), 'count'=>$count]];
    }

    public function getWarehouseName()
    {
        $merchant_id = $this->di->getUser()->id;
        $warehouses = Warehouse::find(["merchant_id='{$merchant_id}'", "column" => 'name, id'])->toArray();
        return $warehouses;
    }

    public function updateWarehouse($data)
    {
        $errors = [];
        $userDb = $this->getMultipleDbManager()->getDb();
        $connection = $this->di->get($userDb);
        $connection->begin();
        try {
            if (isset($data['id'])) {
                $warehouse = self::findFirst($data['id']);
                if ($warehouse) {
                    $data['handler'] = isset($data['handler']) ? $data['handler'] : 'default';
                    $status = $warehouse->save($data);
                    if ($status) {
                        $warehouseProduct = WarehouseProduct::find(["warehouse_id='" . $data['id'] . "'"])->toArray();
                        $oldProduct = [];
                        $newProduct = [];
                        foreach ($warehouseProduct as $key => $value) {
                            $oldProduct[$value['product_id']] = $value;
                        }

                        foreach ($data['products'] as $key => $value) {
                            $newProduct[$value['id']] = $value;
                        }

                        $toBeDeleted = array_diff_key($oldProduct, $newProduct);
                        $newOne = array_diff_key($newProduct, $oldProduct);
                        $toBeUpdated = array_intersect_key($newProduct, $oldProduct);

                        if (!empty($newOne)) {
                            $count = 1;
                            $values = '';
                            $query = '';
                            foreach ($newOne as $val) {
                                $end = $count == count($newOne) ? '' : ', ';
                                $values .= "(" . $data['id'] . ", " . $val['id'] . ", ". $val['quantity'].")" . $end;
                                $count++;
                            }

                            $query = "INSERT INTO warehouse_product (warehouse_id, product_id, qty) VALUES " . $values;
                            $connection->query($query);
                        }

                        foreach ($toBeUpdated as $key => $value) {
                            $warehouse_prod = WarehouseProduct::findFirst($oldProduct[$key]['id']);
                            if ($warehouse_prod) {
                                $warehouse_prod->qty = $value['quantity'];
                                $warehouse_prod->save();
                            }
                        }

                        if (!empty($toBeDeleted)) {
                            $deleteQuery = "DELETE FROM `warehouse_product` WHERE `warehouse_id` = '".$data['id']."' AND `product_id` in (" . implode(',', array_keys($toBeDeleted)) . ");";
                            $connection->query($deleteQuery);
                        }

                        $connection->commit();
                        $this->updateInventory();
                        $handle = $data['handler'];
                        if (class_exists($this->di->getConfig()->warehouse_handle->$handle->source)) {
                            $source = $this->di->getObjectManager()->get($this->di->getConfig()->warehouse_handle->$handle->source);
                            $source->updateWarehouse($data);
                        }

                        return ['success'=>true, 'message'=>'Warehouse Saved Successfully', 'data'=>[]];
                    }
                    foreach ($warehouse->getMessages() as $message) {
                        $errors[] = $message->getMessage();
                    }

                    return ['success'=>false, 'code'=>'error_in_creation', 'message'=>'Error In Saving', 'data'=>$errors];
                }
                return ['success'=>false, 'code'=>'no_warehouse_found', 'message'=>'No Wharehouse Found', 'data'=>[]];
            }
            return ['success'=>false, 'code'=>'invalid_data', 'message'=>'Invalid Data', 'data'=>[]];
        } catch (\Exception $e) {
            $connection->rollback();
            return ['success'=>false, 'code'=>'something_wrong', 'message'=>'Something Went Wrong', 'data'=>[$e->getMessage()]];
        }
    }

    public function getWarehouse($data)
    {
        if (isset($data['id'])) {
            $warehouse = self::findFirst($data['id']);
            if ($warehouse) {
                $final = $warehouse->toArray();
                $userDb = $this->getMultipleDbManager()->getDb();
                $connection = $this->di->get($userDb);
                $query = "SELECT `wp`.`product_id` as `id`, `wp`.`qty` as `quantity`, `pro`.`sku` as `sku` FROM `warehouse_product` as `wp` LEFT JOIN `product_" . $final['merchant_id'] . "` as `pro` on (`pro`.`id` = `wp`.`product_id`) WHERE `wp`.`warehouse_id` =  ".$final['id'];
                $final['products'] = $connection->fetchAll($query);
            } else {
                return ['success'=>false, 'code'=>'no_warehouse_found', 'message'=>'No Wharehouse Found', 'data'=>[]];
            }

            return ['success'=>true, 'message'=>'Warehouse Details', 'data'=>$final];
        }
        return ['success'=>false, 'code'=>'invalid_data', 'message'=>'Invalid Data', 'data'=>[]];
    }

    public function deleteWarehouse($data)
    {
        if (isset($data['id'])) {
            $warehouse = self::findFirst($data['id']);
            if ($warehouse) {
                $status = $warehouse->Delete();
                if ($status) {
                    $this->updateInventory();
                    $handle = $data['handler'];
                    if (class_exists($this->di->getConfig()->warehouse_handle->$handle->source)) {
                        $source = $this->di->getObjectManager()->get($this->di->getConfig()->warehouse_handle->$handle->source);
                        $source->deleteWarehouse($data);
                    }

                    return ['success'=>true, 'message'=>'Warehouse Deleted Successfully', 'data'=>[]];
                }
                foreach ($this->getMessages() as $message) {
                    $errors[] = $message->getMessage();
                }

                return ['success'=>false, 'code'=>'error_in_deletion', 'message'=>'Error In Deletion', 'data'=>$errors];
            }
            return ['success'=>false, 'code'=>'no_warehouse_found', 'message'=>'No Wharehouse Found', 'data'=>[]];
        }
        return ['success'=>false, 'code'=>'invalid_data', 'message'=>'Invalid Data', 'data'=>[]];
    }

    public function getWarehousesOfMerchant()
    {
        $userId = $this->di->getUser()->id;
        $warehouses = Warehouse::find(
            [
                    "merchant_id='{$userId}'",
                    'column' => 'name, id'
                ]
        );
        if ($warehouses && count($warehouses) > 0) {
            $warehouses = $warehouses->toArray();
            return ['success' => true, 'message' => 'Warehouses of this merchant', 'data' => $warehouses];
        }
        return ['success' => false, 'message' => 'No warehouse of this merchant'];
    }

    public function updateInventory(): void
    {
        $userId = $this->di->getUser()->id;
        $warehouse_ids = $this->getUserWarehouseIds();
        $variantTable = 'product_'.$userId;

        $userDb = $this->getMultipleDbManager()->getDb();
        $connection = $this->di->get($userDb);

        $query = "UPDATE `".$variantTable."` INNER JOIN ( SELECT sum(`warehouse_product`.`qty`) as `sum`, `warehouse_product`.`product_id` FROM `".$variantTable."` INNER JOIN `warehouse_product` ON `".$variantTable."`.`id` = `warehouse_product`.`product_id`  WHERE `warehouse_product`.`warehouse_id` in (".implode(',', $warehouse_ids).") GROUP BY `warehouse_product`.`product_id` ) as  `wp` ON `wp`.`product_id` = `".$variantTable."`.`id` SET `".$variantTable."`.`quantity` = `wp`.`sum`";
        $connection->query($query);
    }

    public function getUserWarehouseIds()
    {
        $merchantId = $this->di->getUser()->id;
        $warehouses = Warehouse::find(["merchant_id ='".$merchantId."'"]);
        $warehouse_ids = [];
        foreach ($warehouses as $warehouse) {
            $warehouse_ids[] = $warehouse->id;
        }

        return $warehouse_ids;
    }
}
