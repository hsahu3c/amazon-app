<?php

/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */


namespace App\Shopifyhome\Components\RestFormatter\Order;

use App\Shopifyhome\Components\RestFormatter\Common\Helper;

class Order extends Helper
{
    /**
     * formatData function
     * To check single order or multiple order data
     * @param [array] $data
     * @return array
     */
    public function formatData($data)
    {
        if(isset($data[0])) {
            foreach($data as $order) {
                $formattedData[] = $this->moldData($order);
            }
        } else {
            $formattedData = $this->moldData($data);
        }

        return $formattedData;
    }

    /**
     * moldData function
     * Molding data in rest api format
     * @param [array] $data
     * @return array
     */
    private function moldData($data) {
        $orderData = $this->camelCaseToSnakeCaseArray($data);
        if (!empty($orderData)) {
            $methods = [
                'id' => function ($value, &$forder) {
                    $forder['id'] = $this->extractId($value);
                },
                'app' => function ($value, &$forder) {
                    unset($forder['app']);
                    $forder['app_id'] = $this->extractId($value['id']);
                },
                'line_items' => function ($value, &$forder) {
                    foreach ($value['edges'] as $edge) {
                        $edge['node']['id'] = $this->extractId($edge['node']['id']);
                        $edge['node']['variant_id'] =
                            $this->extractId($edge['node']['variant']['id'] ?? '');
                        $edge['node']['product_id'] =
                            $this->extractId($edge['node']['product']['id'] ?? '');
                        $edge['node']['price_set'] = $edge['node']['original_unit_price_set'];
                        $edge['node']['price'] = $edge['node']['original_unit_price_set']['presentment_money']['amount'] ??
                        $edge['node']['original_unit_price_set']['shop_money']['amount'] ?? '0.0';
                        unset($edge['node']['product'], $edge['node']['variant'],$edge['node']['original_unit_price_set']);
                        $lineItems[] = $edge['node'];
                    }

                    $forder['line_items'] = $lineItems;
                },
                'fulfillments' => function ($value, &$forder) {
                    foreach ($value as $item) {
                        $lineItems = [];
                        $item['id'] = $this->extractId($item['id']);
                        $item['tracking_company'] = $item['tracking_info'][0]['company'] ?? '';
                        $item['tracking_number'] = $item['tracking_info'][0]['number'] ?? '';
                        $item['tracking_url'] = $item['tracking_info'][0]['url'] ?? '';
                        $item['order_id'] = $this->extractId($item['order']['id'] ?? '');
                        foreach ($item['fulfillment_line_items']['edges'] as $edge) {
                            $edge['node']['line_item']['quantity'] = $edge['node']['quantity'];
                            $edge['node']['line_item']['id'] = $this->extractId($edge['node']['line_item']['id']);
                            $edge['node']['line_item']['product_id'] = $this->extractId($edge['node']['line_item']['product']['id'] ?? '');
                            $edge['node']['line_item']['variant_id'] = $this->extractId($edge['node']['line_item']['variant']['id'] ?? '');
                            unset($edge['node']['line_item']['product'], $edge['node']['line_item']['variant']);
                            $lineItems[] = $edge['node']['line_item'];
                        }

                        unset($item['fulfillment_line_items'], $item['tracking_info'], $item['order']);
                        $item['line_items'] = $lineItems;
                        $finalItems[] = $item;
                    }

                    $forder['fulfillments'] = $finalItems;
                },
                'customer' => function ($value, &$forder) {
                    $value['id'] = $this->extractId($value['id']);
                    $forder['customer'] = $value;
                },
                'shipping_address' => function ($value, &$forder) {
                    $value['country_code'] = $value['country_code_v2'];
                    unset($value['country_code_v2']);
                    $forder['shipping_address'] = $value;
                },
                'billing_address' => function ($value, &$forder) {
                    $value['country_code'] = $value['country_code_v2'];
                    unset($value['country_code_v2']);
                    $forder['billing_address'] = $value;
                },
                'display_financial_status' => function ($value, &$forder) {
                    unset($forder['displayFinancialStatus']);
                    $forder['financial_status'] = $value;
                },
                'display_fulfillment_status' => function ($value, &$forder) {
                    unset($forder['displayFulfillmentStatus']);
                    $forder['fulfillment_status'] = $value;
                },
                'custom_attributes' => function ($value, &$forder) {
                    foreach ($value as $attribute) {
                        $noteAttributes[] = [
                            'name' => $attribute['key'],
                            'value' => $attribute['value']
                        ];
                    }

                    unset($forder['custom_attributes']);
                    $forder['note_attributes'] = $noteAttributes;
                }
            ];
            $formattedData = [];
            $orderData = $orderData['orders'] ?? $orderData;
            foreach ($orderData as $key => $value) {
                if (isset($methods[$key]) && !empty($value)) {
                    $methods[$key]($value, $formattedData);
                } else {
                    $formattedData[$key] = $value;
                }
            }

            return $formattedData;
        }
    }
}
