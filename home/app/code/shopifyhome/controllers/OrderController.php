<?php

namespace App\Shopifyhome\Controllers;

use App\Core\Controllers\BaseController;
use App\Core\Models\BaseMongo;
use App\Shopifyhome\Service\Order as OrderService;
use App\Connector\Components\Order\Order as OrderComponent;

class OrderController extends BaseController
{
    public function getCustomerInfoAction()
    {
        $params = $this->getRequestData();
        $userId = $this->di->getUser()->id;

        $order = $this->getOrder($userId, $params);
        if (empty($order)) {
            return $this->prepareResponse(['success' => false, 'message' => "Order not found."]);
        }

        $order = $order[0];
        $orderSetting = $this->di->getObjectManager()->get(OrderComponent::class)->checkOrderSyncEnable($order['targets'][0]['shop_id'], $order['marketplace_shop_id']);

        $customerInfo = $this->di->getObjectManager()->get(OrderService::class)->getNameToSendForOrderCreate([
            'full_name' => $order['customer']['name'],
            'order' => $order,
            'settings' => $orderSetting,
            'name_type' => 'customer'
        ]);

        $orderServiceObj = $this->di->getObjectManager()->get(OrderService::class);
        $shippingCustomerInfo = $orderServiceObj->getNameToSendForOrderCreate([
            'full_name' => $order['shipping_address'][0]['name'],
            'order' => $order,
            'settings' => $orderSetting,
            'name_type' => 'shipping_address'
        ]);

        $shippingAddress = $order['shipping_address'][0];
        $formattedShippingAddress = $orderServiceObj->getShopifyFormattedShippingAddress($shippingAddress, $orderSetting);
        return $this->prepareResponse([
            'success' => true,
            'data' => [
                'customer' => array_merge(['email' => $order['customer']['email']], $customerInfo),
                'shipping_address' => array_merge($formattedShippingAddress, $shippingCustomerInfo)
            ],
        ]);
    }

    public function getOrder($userId, $params)
    {
        $collection = $this->di->getObjectManager()->get(BaseMongo::class)->getCollection('order_container');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        if (!$userId || !isset($params['filter'])) {
            return $this->prepareResponse(['success' => false, 'message' => 'Missing Required params: user_id,filter']);
        }
        $conditionalQuery = [];
        $conditionalQuery['user_id'] = $userId;
        $conditionalQuery = array_merge($conditionalQuery, BaseMongo::search($params));

        $aggregation = [];
        if (!empty($conditionalQuery)) {
            $aggregation[] = ['$match' => $conditionalQuery];
        }

        $order = $collection->aggregate($aggregation, $options)->toArray();

        return $order;
    }


    public function updateCustomerInfoAction()
    {
        $params = $this->getRequestData();
        $userId = $this->di->getUser()->id;

        if (!$userId || empty($params)) {
            return $this->prepareResponse(['success' => false, 'message' => 'Missing Required params: user_id or data']);
        }

        $order = $this->getOrder($userId, $params);
        if (empty($order)) {
            return $this->prepareResponse(['success' => false, 'message' => "Order not found to update."]);
        }

        // Storing current customer and shipping address in old_customer_data and old_shipping_address
        $cifOrderId = $order[0]['cif_order_id'];
        $oldCustomerData = $order[0]['customer'] ?? [];
        $oldShippingAddress = $order[0]['shipping_address'] ?? [];

        // Preparing the new customer and shipping address data if provided
        $updateData = [];
        if (isset($params['customer'])) {
            $newCustomerData = [
                'name' => $params['customer']['first_name'] . " " . $params['customer']['last_name'],
                'email' => $params['customer']['email']
            ];
            $updateData['customer'] = $newCustomerData;
            $updateData['old_customer_data'] = $oldCustomerData;
        }

        if (isset($params['shipping_address'])) {
            $newShippingAddress = [
                [
                    'name' => $params['shipping_address']['first_name'] . " " . $params['shipping_address']['last_name'],
                    'phone' => $params['shipping_address']['phone'],
                    'city' => $params['shipping_address']['city'],
                    'state' => $params['shipping_address']['province'],
                    'zip' => $params['shipping_address']['zip'],
                    'country' => $params['shipping_address']['country'],
                    'country_code' => $params['shipping_address']['country_code'],
                    'address_line_1' => $params['shipping_address']['address1'],
                    'address_line_2' => $params['shipping_address']['address2']
                ]
            ];
            $updateData['shipping_address'] = $newShippingAddress;
            $updateData['old_shipping_address'] = $oldShippingAddress;
        }

        if (empty($updateData)) {
            return $this->prepareResponse(['success' => false, 'message' => 'No data provided to update.']);
        }

        // update query
        $updateQuery = ['$set' => [], '$push' => []];
        if (isset($updateData['customer'])) {
            $updateQuery['$set']['customer'] = $updateData['customer'];
            $updateQuery['$push']['old_customer_data'] = $updateData['old_customer_data'];
        }

        if (isset($updateData['shipping_address'])) {
            $updateQuery['$set']['shipping_address'] = $updateData['shipping_address'];
            $updateQuery['$push']['old_shipping_address'] = $updateData['old_shipping_address'];
        }

        $collection = $this->di->getObjectManager()->get(BaseMongo::class)->getCollection('order_container');
        $updateResult = $collection->updateOne(
            ['user_id' => $userId, 'cif_order_id' => $cifOrderId, 'object_type' => 'source_order'],
            $updateQuery
        );

        if ($updateResult->getModifiedCount() > 0) {
            return $this->prepareResponse(['success' => true, 'message' => 'Customer information updated successfully']);
        }

        return $this->prepareResponse(['success' => false, 'message' => 'Failed to update customer information']);
    }



}