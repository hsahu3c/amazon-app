<?php
/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */
namespace App\Shopifyhome\Service;

use App\Shopifyhome\Components\Helper;
use ZipArchive;
use App\Connector\Models\Product\Marketplace;
use App\Connector\Contracts\Sales\OrderInterface;
use App\Core\Models\BaseMongo;

/**
 * Interface OrderInterface
 * @services
 */
class Order implements OrderInterface
{
    protected $userDetails;

    private $attempt;

    private $userSettings;

    private $logFilePath;

    private $shopifyShopId;

    private $convertedBundleProductIds;

    private $convertedComponentProductIds;

    public const LOG_PRIORITY_LEVEL_CRITICAL = 'critical';

    public const LOG_PRIORITY_LEVEL_IMPORTANT = 'important';

    public const LOG_PRIORITY_LEVEL_STANDARD = 'standard';

    public function create(array $order): array
    {
        $this->userDetails = null;
        $this->attempt = null;
        $this->convertedBundleProductIds = [];
        $originalOrderData = $order;
        $hasDuplicateUniqueKey = $order['has_duplicate_unique_key'] ?? false;

        if (isset($order['order']['marketplace_reference_id'])) {
            $this->logFilePath = 'order/order-create/' . $this->di->getUser()->id . '/' . date('Y-m-d') . '/' . $order['order']['marketplace_reference_id'] . '.log';
        } else {
            $this->logFilePath = 'order/order-create/' . $this->di->getUser()->id . '/' . date('Y-m-d') . '/shopify.log';
        }

        $this->addLog("", $this->logFilePath, "----------- ORDER CREATE ON SHOPIFY ----------------", self::LOG_PRIORITY_LEVEL_STANDARD);

        $orderSetting = $order['order_settings'];

        $appCode = $order['app_code'] ?? 'default';

        $this->addLog("", $this->logFilePath, " >>> Step 1:   Set Order settings <<< ", self::LOG_PRIORITY_LEVEL_STANDARD);
        $orderSetting = $this->setOrderSetting($orderSetting);

        if (!empty($order['order']['fulfilled_by']) && $order['order']['fulfilled_by'] == "other" && !empty($orderSetting['use_fbo_customer_id']['enabled'])) {
            $this->handleCustomFboNames($order, $orderSetting);
        }

        $alreadyCreatedOrders = [];
        $this->addLog("", $this->logFilePath, " >>> Step 2:   Get user details <<< ", self::LOG_PRIORITY_LEVEL_STANDARD);
        $this->shopifyShopId = $order['shop_id'];
        $shopifyUserDetails = $this->getShopifyUserDetails($order['shop_id']);
        $success = false;
        $responseArray = [];
        $this->addLog($shopifyUserDetails['remote_shop_id'], $this->logFilePath, "User's remote shop id: ", self::LOG_PRIORITY_LEVEL_IMPORTANT);
        $this->addLog("", $this->logFilePath, " >>> Step 3: getAlreadyCreatedOrdersFromShopify <<< ", self::LOG_PRIORITY_LEVEL_STANDARD);

        $alreadyCreatedOrdersResp = $this->getAlreadyCreatedOrdersFromShopify(
            $shopifyUserDetails['remote_shop_id'],
            [
                'app_code' => $appCode,
                'marketplace_reference_id' => $order['order']['marketplace_reference_id']
            ]
        );

        if (isset($alreadyCreatedOrdersResp['success']) && $alreadyCreatedOrdersResp['success']) {
            $this->addLog("", $this->logFilePath, "getAlreadyCreatedOrdersFromShopify response: success", self::LOG_PRIORITY_LEVEL_IMPORTANT);
            $alreadyCreatedOrders = $alreadyCreatedOrdersResp['orders'];
        } else {
            $this->addLog("", $this->logFilePath, "getAlreadyCreatedOrdersFromShopify response:", self::LOG_PRIORITY_LEVEL_IMPORTANT);
            if (isset($alreadyCreatedOrdersResp['message']['errors']) && gettype($alreadyCreatedOrdersResp['message']['errors']) == 'string') {
                $errorMSG = $alreadyCreatedOrdersResp['message']['errors'];
                $this->addLog($errorMSG, $this->logFilePath, "errorMSG: ", self::LOG_PRIORITY_LEVEL_CRITICAL);
                if ($errorMSG == 'Exceeded 2 calls per second for api client. Reduce request rates to resume uninterrupted service.') {
                    $attempt = $this->getAttempt();
                    $this->addLog($attempt, $this->logFilePath, "Attempt count: ", self::LOG_PRIORITY_LEVEL_CRITICAL);
                    if ($attempt <= 3) {
                        sleep(3);
                        return $this->create($order);
                    }
                    $this->addLog("", $this->logFilePath, "Attempt count exceeds!!", self::LOG_PRIORITY_LEVEL_CRITICAL);
                    $this->addLog(json_encode($alreadyCreatedOrdersResp), $this->logFilePath, "Remote response: ", self::LOG_PRIORITY_LEVEL_CRITICAL);
                    $this->addLog(json_encode($order), $this->logFilePath, "Order data received: ", self::LOG_PRIORITY_LEVEL_IMPORTANT);
                    $this->addLog("", $this->logFilePath, "----------- ORDER CREATE ON SHOPIFY END ----------------", self::LOG_PRIORITY_LEVEL_STANDARD);
                    // $logMessage = 'get order response : '. PHP_EOL .json_encode($alreadyCreatedOrdersResp). PHP_EOL .'orders data : '. PHP_EOL .json_encode($order). PHP_EOL;
                    // $filePath = 'source/order-upload-error/'. $this->di->getUser()->id .'/'. date('d-m-Y').'.log';
                    // $this->di->getLog()->logContent($logMessage, 'info', $filePath);
                    return ['success' => false, 'message' => $alreadyCreatedOrdersResp['message']];
                }
            } else {
                $this->addLog(json_encode($alreadyCreatedOrdersResp), $this->logFilePath, "Remote response: ", self::LOG_PRIORITY_LEVEL_CRITICAL);
                $this->addLog(json_encode($order), $this->logFilePath, "Order data received: ", self::LOG_PRIORITY_LEVEL_IMPORTANT);
                $this->addLog("", $this->logFilePath, "----------- ORDER CREATE ON SHOPIFY END ----------------", self::LOG_PRIORITY_LEVEL_STANDARD);
                // $logMessage = 'get order response : '. PHP_EOL .json_encode($alreadyCreatedOrdersResp). PHP_EOL .'orders data : '. PHP_EOL .json_encode($order). PHP_EOL;
                // $filePath = 'source/order-upload-error/'. $this->di->getUser()->id .'/'. date('d-m-Y').'.log';
                // $this->di->getLog()->logContent($logMessage, 'info', $filePath);
                return ['success' => false, 'message' => $alreadyCreatedOrdersResp['message']];
            }
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('order_container');

        $orderId = $order['order']['marketplace_reference_id'];
        $order = $order['order'];

        if (isset($alreadyCreatedOrders[$orderId])) {
            $this->addLog("", $this->logFilePath, "Order already exists on shopify because order found on shopify for order id: " . $orderId, self::LOG_PRIORITY_LEVEL_CRITICAL);
            $this->addLog("", $this->logFilePath, "----------- ORDER CREATE ON SHOPIFY END ----------------", self::LOG_PRIORITY_LEVEL_STANDARD);
            return ['success' => false, 'message' => 'Order already exists on shopify because order found on shopify.', 'not_save' => true];
        }
        $this->addLog("", $this->logFilePath, "No already order found on shopify! Proceeding to create order data for shopify......", self::LOG_PRIORITY_LEVEL_IMPORTANT);
        $this->addLog("", $this->logFilePath, " >>> Step 4: Order create on Shopify <<< ", self::LOG_PRIORITY_LEVEL_STANDARD);
        $shippingLines = [];
        if (isset($order['shipping_details'])) {
            $shippingLines[] =
                [
                    'price' => $order['shipping_charge']['marketplace_price'],
                    'title' => !empty($order['shipping_details']['method']) ? $order['shipping_details']['method'] : "",
                    'code' => !empty($order['shipping_details']['method']) ? $order['shipping_details']['method'] : "",
                    'source' => $order['marketplace'],
                    'requested_fulfillment_service_id' => NULL,
                    // 'delivery_category' => NULL,
                    'carrier_identifier' => !empty($order['shipping_details']['method']) ? $order['shipping_details']['method'] : "",
                ];
        }
        if ($order['marketplace_status'] == 'Shipped') {
            $fulfilled_status = 'fulfilled';
        } else {
            $fulfilled_status = null;
        }
        $this->getFulfillmentStatusForShopify($fulfilled_status, $order, $orderSetting);
        $orderData = [
            'tax_lines' => $this->prepareTaxLine($order),
            'currency' => $order['marketplace_currency'],
            'total_tax' => isset($order['tax'], $order['tax']['marketplace_price']) ? $order['tax']['marketplace_price'] : 0,
            'created_at' => $order['source_created_at'],
            'fulfillment_status' => $fulfilled_status,
            'subtotal_price' => $order['sub_total']['marketplace_price'],
            'taxes_included' => $order['taxes_included'],
            'total_discounts' => $order['discount']['marketplace_price'] ?? 0,
            'total_price' => $order['total']['marketplace_price'],
            'total_weight' => $order['total_weight'] ?? 0,
            'financial_status' => 'paid',
            'inventory_behaviour' => !empty($this->getInventoryBehaviorForShopify($orderSetting, $order))
                ? $this->getInventoryBehaviorForShopify($orderSetting, $order)
                : 'decrement_obeying_policy',
            'note' => 'Source Order ID: ' . $order['marketplace_reference_id'],
            'source_name' => !empty($this->getSource()) ? $this->getSource() : $order['marketplace'],
            'source_identifier' => $order['marketplace_reference_id'] ?? '',
            'transactions' => [
                [
                    'kind' => 'sale',
                    'shopify_status' => 'success',
                    'amount' => $order['total']['marketplace_price'],
                    "gateway" => !empty($this->getSource()) ? $this->getSource() : $order['marketplace']
                ]
            ],
            'send_receipt' => false
        ];

        if (
            !empty($orderSetting['shipping_tax_in_shipping_line']['enabled']) &&
            !empty($orderSetting['shipping_tax_in_shipping_line']['shipping_tax_key']) &&
            !empty($orderData['tax_lines']) &&
            !empty($shippingLines)
        ) {
            $shippingTaxKey = $orderSetting['shipping_tax_in_shipping_line']['shipping_tax_key'];

            foreach ($orderData['tax_lines'] as $index => $tax) {
                if (!empty($tax['title']) && $tax['title'] == $shippingTaxKey) {

                    $shippingTaxAmount = $tax['price'] ?? 0;

                    if ($shippingTaxAmount > 0) {
                        $shippingLines[0]['tax_lines'][] = $tax;
                    }

                    // Remove shipping tax from order-level taxes
                    unset($orderData['tax_lines'][$index]);
                    break;
                }
            }

            $orderData['tax_lines'] = array_values($orderData['tax_lines']);
        }

        if (!empty($orderSetting['override_order_total_to_zero'])) {
            $orderData['total_price'] = 0;
        }

        if (!empty($orderSetting['custom_contact_email'])) {
            $orderData['email'] = $orderSetting['custom_contact_email'];
        }

        if (!empty($orderSetting['custom_contact_phone'])) {
            $orderData['phone'] = $orderSetting['custom_contact_phone'];
        }

        $orderData['app_code'] = $appCode;
        $orderName = $this->getOrderName($order);
        if ($orderName != '') {
            $orderData['name'] = $orderName;
        }

        if (!empty($orderSetting['add_phone_number_in_note']['enabled']) && !empty($order['shipping_address'][0]['phone'])) {
            $phoneNote = ($orderSetting['add_phone_number_in_note']['key'] ?? 'Phone Number') . ': ' . ($order['shipping_address'][0]['phone']);
            $orderData['note'] = isset($orderData['note']) ? $orderData['note'] . PHP_EOL . $phoneNote : $phoneNote;
        }

        if (!empty($orderSetting['disable_created_at_sync']['enabled'])) {
            $orderFulfilledBy = $order['fulfilled_by'] ?? null;
            $fulfilledByType = $orderSetting['disable_created_at_sync']['fulfilled_by_type'] ?? null;

            if (is_null($fulfilledByType) || is_null($orderFulfilledBy) || $fulfilledByType == $orderFulfilledBy) {
                unset($orderData['created_at']);
            }
        }

        $orderTag = $this->getTags($order);
        $this->updateNoteFromNoteAttributesIfEnabled($orderData, $order, $orderSetting);
        $orderData['note_attributes'] = $this->getNoteAttributes($order);
        if (isset($orderSetting['custom_first_name']) || isset($orderSetting['custom_last_name'])) {
            $name = '';
            if (isset($orderSetting['custom_first_name'])) {
                $name = $orderSetting['custom_first_name'];
            }

            if (isset($orderSetting['custom_last_name'])) {
                if (!empty($name)) {
                    $name = $name . ' ' . $orderSetting['custom_last_name'];
                } else {
                    $name = $orderSetting['custom_last_name'];
                }

                $order['customer']['name'] = $name;
            }
        }

        if (isset($orderSetting['customer_email_send']) && $orderSetting['customer_email_send'] == true) {
            $orderData['customer'] = [
                'email' => $order['customer']['email'] ?? '',
                'first_name' => $order['customer']['name'] ??  ''
            ];
        } else {
            $orderData['customer'] = [
                // 'email' => '',
                'first_name' => $order['customer']['name'] ?? '',
                'note' => $order['customer']['email'] ?? ''
            ];

            if (!empty($orderSetting['add_custom_email']['enabled']) && !empty($orderSetting['add_custom_email']['value'])) {
                $orderFulfilledBy = $order['fulfilled_by'] ?? null;
                $fulfilledByType = $orderSetting['add_custom_email']['fulfilled_by_type'] ?? null;
                if (is_null($fulfilledByType) || is_null($orderFulfilledBy) || $fulfilledByType == $orderFulfilledBy) {
                    $orderData['customer']['email'] = $orderSetting['add_custom_email']['value'];
                }
            }

            // $orderData['customer'] = [
            //     'email' => $order['customer']['email'] ?? '',
            //     'first_name' => $order['customer']['name']
            // ];
        }

        if (isset($order['customer']['id'])) {
            $orderData['customer']['id'] = $order['customer']['id'];
        }

        if (isset($orderSetting['disable_note_attribute']) && $orderSetting['disable_note_attribute'] == true) {
            unset($orderData['note']);
        }
        $lineItems = [];
        $createOrderWithoutProduct = true;
        $bundleComponentsTags = [];

        $bundleProductSettings = $orderSetting['bundle_product_conversion'] ?? [];

        $duplicateKeyConfig = null;
        if (!empty($hasDuplicateUniqueKey)) {
            $duplicateKeyConfig = $this->di->getConfig()->get('include_link_id_in_attributes_if_duplicate_key') ?? ['enabled' => true];
        }


        foreach ((array) $order['items'] as $itemValue) {
            $cancelledQty = $itemValue['cancelled_qty'] ?? 0;
            $quantityOrdered = $itemValue['qty'] - $cancelledQty;

            if ($quantityOrdered > 0) {
                $marketplaceItemPrice = $itemValue['marketplace_price'] ?? 0;
                if (!empty($bundleProductSettings['enabled'])) {
                    $this->updateBundleProductQuantity($quantityOrdered, $itemValue, $bundleProductSettings, $marketplaceItemPrice);
                }

                $title_max_length = 252;
                if (strlen((string) $itemValue['title']) > $title_max_length) {
                    $itemValue['title'] = substr((string) $itemValue['title'], 0, $title_max_length) . '...';
                }

                $itemData = [
                    'quantity' => $quantityOrdered,
                    'title' => $itemValue['title'],
                    'price' => $marketplaceItemPrice
                ];
                if (!empty($itemValue['attributes'])) {


                    $properties = [];
                    $propertiesResponse = $this->getItemAttributes($itemValue['attributes'], $order);
                    if (!$propertiesResponse['success']) {
                        $this->addLog($propertiesResponse, $this->logFilePath, "Error in get item attributes: ", self::LOG_PRIORITY_LEVEL_CRITICAL);
                        return $propertiesResponse;
                    }

                    $properties = $propertiesResponse['data'];
                    $itemData['properties'] = $properties;
                }

                if (!empty($hasDuplicateUniqueKey) && $this->shouldAddCustomItemAttribute($hasDuplicateUniqueKey, $itemValue, $duplicateKeyConfig)) {
                    $this->addCustomItemAttribute($itemData, $itemValue['item_link_id'], $duplicateKeyConfig);
                }

                if (isset($itemValue['product_identifier'])) {
                    $itemData['variant_id'] = $itemValue['product_identifier'];
                    if (isset($itemData['title']) && (!isset($orderSetting['source_title_as_target_title']) || !$orderSetting['source_title_as_target_title'])) {
                        unset($itemData['title']);
                    }
                } else {
                    if (isset($itemValue['sku'])) {
                        $itemData['sku'] = $itemValue['sku'];
                    }
                }

                if (isset($itemValue['parent_product_identifier'])) {
                    $itemData['product_id'] = $itemValue['parent_product_identifier'];
                }

                if (!isset($itemValue['product_identifier'])) {
                    $tags_validate = 'SKU ' . $itemValue['sku'] . ' not present';
                    if (strlen($tags_validate) > 40) {
                        $tags_validate = $itemValue['sku'];
                    }

                    $orderTag[] = $tags_validate;
                }

                if (!empty($itemValue['is_bundle_component'])) {
                    $bundleComponentsTags['has-bundle'] = 1;
                }
                if (!empty($itemValue['bundle_parent_sku'])) {
                    $bundleComponentsTags["bundle-sku: {$itemValue['bundle_parent_sku']}"] = 1;
                }

                $lineItems[] = $itemData;
            }
        }

        if (!empty($bundleComponentsTags)) {
            $orderTag = [...$orderTag, ...array_keys($bundleComponentsTags)];
        }

        if ($lineItems !== []) {
            if (!empty($orderTag)) {
                $orderData['tags'] = implode(",", $orderTag);
            }

            if (isset($order['tags'])) {
                $appendTags = $order['tags'];
                if (is_array($order['tags'])) {
                    $appendTags = implode(",", $order['tags']);
                }

                $orderData['tags'] = isset($orderData['tags'])
                    ? $orderData['tags'] . ',' . $appendTags : $appendTags;
            }

            $shippingAddress = $order['shipping_address'][0];
            $orderData['line_items'] = $lineItems;

            $shippingAddressNameRequest = [
                'full_name' => $shippingAddress['name'],
                'order' => $order,
                'settings' => $orderSetting,
                'name_type' => 'shipping_address'
            ];
            $getNameResponse = $this->getNameToSendForOrderCreate($shippingAddressNameRequest);
            $firstName = $getNameResponse['first_name'];
            $lastName = $getNameResponse['last_name'];

            $customerNameRequest = [
                'full_name' => $order['customer']['name'],
                'order' => $order,
                'settings' => $orderSetting,
                'name_type' => 'customer'
            ];
            $getCustomerNameResponse = $this->getNameToSendForOrderCreate($customerNameRequest);
            //updating customer first_name and last_name as per setting
            $orderData['customer']['first_name'] = $getCustomerNameResponse['first_name'];
            $orderData['customer']['last_name'] = $getCustomerNameResponse['last_name'];

            if (!empty($getCustomerNameResponse['note_attributes'])) {
                $orderData['note_attributes'][] = $getCustomerNameResponse['note_attributes'];
            }

            $deliveryAddress = $this->getShopifyFormattedShippingAddress($shippingAddress, $orderSetting);
            $deliveryAddress['first_name'] = $firstName;
            $deliveryAddress['last_name'] = $lastName;

            $orderData['billing_address'] = $deliveryAddress;
            $orderData['shipping_address'] = $deliveryAddress;
            if ($shippingLines) {
                $orderData['shipping_lines'] = $shippingLines;
            }

            $this->addLog("", $this->logFilePath, "Order data preparation for shopify: done ", self::LOG_PRIORITY_LEVEL_STANDARD);
            $this->addLog(json_encode($orderData), $this->logFilePath, "Order create data for shopify: ", self::LOG_PRIORITY_LEVEL_CRITICAL);

            // $logFilePath = 'source/order-upload/'. $this->di->getUser()->id .'/'. date('d-m-Y').'.log';
            // $this->di->getLog()->logContent('*****************Creating *************************'. PHP_EOL .'Creating : ID : ' . $orderId . ' Creating Request'  . json_encode($orderData), 'info', $logFilePath);
            $orderCreateResponse = $this->createOrderOnShopify($orderId, $orderData);

            // Temporary Handling of Province Code Error
            if (isset($orderCreateResponse['success'], $orderCreateResponse['message']) && !$orderCreateResponse['success'] && $orderCreateResponse['message'] == 'Error: {"errors":{"customer":["Addresses province is not valid"]}}') {
                $originalOrderData['order']['shipping_address'][0]['address_line_3'] .= " " . $originalOrderData['order']['shipping_address'][0]['state'];
                $errorLogPath = 'order/order-create/province-error/' . $this->di->getUser()->id . '/' . date('Y-m-d') . '.log';
                $this->addLog($originalOrderData['order'], $errorLogPath, "Invalid Province Error: ", self::LOG_PRIORITY_LEVEL_CRITICAL);
                unset($originalOrderData['order']['shipping_address'][0]['state']);
                return $this->create($originalOrderData);
            }

            $this->addLog(json_encode($orderCreateResponse), $this->logFilePath, "Order create response: ", self::LOG_PRIORITY_LEVEL_CRITICAL);
            $this->addLog("", $this->logFilePath, "----------- ORDER CREATE ON SHOPIFY END ----------------", self::LOG_PRIORITY_LEVEL_STANDARD);
            if ($orderCreateResponse['success'] == false && !isset($orderCreateResponse['message'])) {
                $this->addLog(json_encode($orderCreateResponse), $this->logFilePath, "Remote fail response ", self::LOG_PRIORITY_LEVEL_CRITICAL);
                $orderCreateResponse['message'] = 'Message:' . json_encode($orderCreateResponse);
            } elseif ($orderCreateResponse['success'] === false && !empty($orderCreateResponse['message'])) {
                if (str_contains((string) $orderCreateResponse['message'], '"Name can\'t be blank","Title can\'t be blank"')) {
                    $this->handleDeletedProductError($orderData, $order, $orderCreateResponse);
                }
            }

            // Check if the error message matches known transient or ignorable errors, and if so, mark the response to skip sending a failed order email
            $this->markIfShouldSkipFailedOrderEmail($orderCreateResponse, $originalOrderData);

            return $orderCreateResponse;
        }
        $this->addLog("", $this->logFilePath, "Order data preparation for shopify failed as items not found!!", self::LOG_PRIORITY_LEVEL_CRITICAL);
        $newresult = ['success' => false, 'message' => 'Items not found for order create'];
        return $newresult;

        if ($success == false) {
            $newresult = ['success' => $success, 'message' => 'Something went wrong'];
            return $newresult;
        }

        $newresult = ['success' => $success, 'data' => $responseArray];
        return $newresult;
    }


    public function getShopifyFormattedShippingAddress($shippingAddress, $orderSetting)
    {
        if (!empty($orderSetting['add_company_in_ship_address']['enabled'])) {
            $companyInShipAddrMappings = $orderSetting['add_company_in_ship_address']['mappings'] ?? [];
            if (!empty($companyInShipAddrMappings)) {
                $basedOnType = $companyInShipAddrMappings['based_on_type'] ?? false;
                $typeValue = $companyInShipAddrMappings['type_value'] ?? false;
                if (!$basedOnType || (isset($shippingAddress['type']) && $shippingAddress['type'] == $typeValue)) {
                    $keyToAddFrom = $companyInShipAddrMappings['key'] ?? 'address_line_1';
                    $shippingAddressCompanyName = $shippingAddress[$keyToAddFrom] ?? null;
                    $removeKey = $companyInShipAddrMappings['remove_key'] ?? true;
                    if (!is_null($shippingAddressCompanyName) && $removeKey) {
                        $presentAddressLineCount = 0;
                        $addressLineKeys = ['address_line_1', 'address_line_2', 'address_line_3'];
                        foreach ($addressLineKeys as $key) {
                            if (!empty($shippingAddress[$key])) {
                                $presentAddressLineCount++;
                            }
                        }

                        if ($presentAddressLineCount > 1) {
                            unset($shippingAddress[$keyToAddFrom]);
                        }
                    }
                }
            }
        }

        $addressLineOne = '';
        if (!empty($shippingAddress['address_line_1'])) {
            $addressLineOne = $shippingAddress['address_line_1'];
        } elseif (!empty($shippingAddress['address_line_2'])) {
            $addressLineOne = $shippingAddress['address_line_2'];
            $shippingAddress['address_line_2'] = '';
        } elseif (!empty($shippingAddress['address_line_3'])) {
            $addressLineOne = $shippingAddress['address_line_3'];
            $shippingAddress['address_line_3'] = '';
        }

        $addressLineTwo = '';
        if (!empty($shippingAddress['address_line_2'])) {
            $addressLineTwo = $shippingAddress['address_line_2'];
        } elseif (!empty($shippingAddress['address_line_3'])) {
            $addressLineTwo = $shippingAddress['address_line_3'];
            $shippingAddress['address_line_3'] = '';
        }

        $addressLineThree = '';
        if (!empty($shippingAddress['address_line_3'])) {
            $addressLineThree = $shippingAddress['address_line_3'];
        }

        $city = "";
        if (isset($shippingAddress['city']) && !empty($shippingAddress['city'])) {
            $city = $shippingAddress['city'];
        } elseif (isset($orderSetting['set_state_as_city']) && $orderSetting['set_state_as_city'] == true) {
            $city = $shippingAddress['state'] ?? '';
        }

        if (!empty($addressLineThree)) {
            $addressLineTwo = $addressLineTwo . " " . $addressLineThree;
        }

        if (
            (!empty($shippingAddress['phone']))
            && (isset($orderSetting['remove_ext_from_phone_number'])
                && $orderSetting['remove_ext_from_phone_number'])
        ) {
            $shippingAddress['phone'] = explode("ext", (string) $shippingAddress['phone'])[0] ?? '';
        }

        if (empty($addressLineOne)) {
            $prepareAddressLineOne = [];
            !empty($city) && $prepareAddressLineOne[] = $city;
            !empty($shippingAddress['state']) && $prepareAddressLineOne[] = $shippingAddress['state'];
            !empty($shippingAddress['country_code']) && $prepareAddressLineOne[] = $shippingAddress['country_code'];
            !empty($shippingAddress['zip']) && $prepareAddressLineOne[] = $shippingAddress['zip'];
            if (!empty($prepareAddressLineOne)) {
                $addressLineOne = implode(", ", $prepareAddressLineOne);
            }
        }

        $deliveryAddress = [
            'phone' => $shippingAddress['phone'] ?? '',
            'city' => $city ?? '',
            'province' => $shippingAddress['state'] ?? '',
            'zip' => $shippingAddress['zip'] ?? '',
            'country' => $this->getShopifyCountryForTerritory($shippingAddress['country'] ?? ''),
            'country_code' => $this->getShopifyCountryForTerritory($shippingAddress['country_code'] ?? ''),
            'address1' => $addressLineOne,
            'address2' => $addressLineTwo
        ];
        if (isset($shippingAddress['company']) && !empty($shippingAddress['company'])) {
            $deliveryAddress['company'] = $shippingAddress['company'];
        }

        if (isset($orderSetting['disable_phone_number']) && $orderSetting['disable_phone_number'] == true) {
            unset($deliveryAddress['phone']);
        }

        if (isset($shippingAddressCompanyName) && !empty($shippingAddressCompanyName)) {
            $deliveryAddress['company'] = $shippingAddressCompanyName;
        }

        $this->updateShippingAddressToCustomIfExist($deliveryAddress, $orderSetting);
        return $deliveryAddress;
    }

    public function update(array $filter, array $data): array
    {
        if (!isset($filter['shop_id']) || !isset($filter['order_id'])) {
            return ['success' => false, 'data' => 'Missing Parameters.'];
        }

        $shopifyUserDetails = $this->getShopifyUserDetails($filter['shop_id']);
        $data['id'] = $filter['order_id'];

        $shopifyHelper = $this->di->getObjectManager()->get(Helper::class);
        $remoteResponse = $shopifyHelper->sendRequestToShopify('/order', [], ['shop_id' => $shopifyUserDetails['remote_shop_id'], 'data' => $data], 'PUT');

        return ['success' => true, 'data' => $remoteResponse];
    }

    /**
     * Append text to an existing Shopify order note.
     * Appends as: existing_note . \"\\n\" . $appendText (or just $appendText if note is empty).
     */
    public function appendOrderNote(string $shopId, string $orderId, string $appendText, string $appCode = 'default'): array
    {
        if ($shopId === '' || $orderId === '') {
            return ['success' => false, 'message' => 'Missing Parameters.'];
        }

        $appendText = trim($appendText);
        if ($appendText === '') {
            return ['success' => false, 'message' => 'appendText is empty'];
        }

        $shopifyUserDetails = $this->getShopifyUserDetails($shopId);
        if (empty($shopifyUserDetails['remote_shop_id'])) {
            return ['success' => false, 'message' => 'remote_shop not found for provided shop_id!'];
        }

        $shopifyHelper = $this->di->getObjectManager()->get(Helper::class);
        $params = [
            'shop_id' => $shopifyUserDetails['remote_shop_id'],
            'id' => $orderId,
            'app_code' => $appCode
        ];
        $remoteGet = $shopifyHelper->sendRequestToShopify('/order', [], $params, 'GET');
        if (!isset($remoteGet['success']) || !$remoteGet['success']) {
            return ['success' => false, 'message' => $remoteGet['msg'] ?? $remoteGet['message'] ?? 'Unable to fetch order from Shopify'];
        }

        $orderData = $remoteGet['data'] ?? [];
        // If API returns a list for single fetch, normalize to first entry.
        if (isset($orderData[0]) && is_array($orderData[0])) {
            $orderData = $orderData[0];
        }

        $existingNote = (string)($orderData['note'] ?? '');
        // Avoid duplicate appends (idempotency)
        if (strpos($existingNote, $appendText) !== false) {
            return ['success' => true, 'message' => 'Note already contains appended text', 'data' => $remoteGet];
        }

        $newNote = $existingNote !== '' ? rtrim($existingNote) . "\n" . $appendText : $appendText;

        return $this->update(
            ['shop_id' => $shopId, 'order_id' => $orderId],
            ['note' => $newNote]
        );
    }

    public function get(array $data): array
    {

        if (!isset($data['shop_id']) || !isset($data['app_code'])) {
            return ['success' => false, 'data' => 'Missing Parameters.'];
        }

        $shopifyUserDetails = $this->getShopifyUserDetails($data['shop_id']);

        $orderId = $data['id'] ?? null;
        $shopifyHelper = $this->di->getObjectManager()->get(Helper::class);
        $params = [];
        if (!isset($shopifyUserDetails['remote_shop_id'])) {
            return [
                'success' => false,
                'message' => 'remote_shop not found for provided shop_id!'
            ];
        }

        $params['shop_id'] = $shopifyUserDetails['remote_shop_id'];
        $params['id'] = $orderId;
        $params['app_code'] = $data['app_code'] ?? 'default';
        isset($data['status']) && $params['status'] = $data['status'];
        isset($data['created_at_min']) && $params['created_at_min'] = $data['created_at_min'];
        $remoteResponse = $shopifyHelper->sendRequestToShopify('/order', [], $params, 'GET');

        return ['success' => true, 'data' => $remoteResponse];
    }

    public function getByField(array $data): array
    {

    }


    public function prepareForDb(array $data): array
    {
        if (isset($data['shop_id'])) {
            $returnData = [];
            $orderWrapper = $data['order'];
            $items = $orderWrapper['items'] ?? $orderWrapper['line_items'];
            $returnData['marketplace_reference_id'] = (string) $orderWrapper['id'];
            $returnData['order_name'] = (string) $orderWrapper['name'];
            $returnData['marketplace'] = $data['marketplace'];
            $returnData['marketplace_shop_id'] = (string) $data['shop_id'];
            $returnData['user_id'] = $this->di->getUser()->id;
            $returnData['shop_id'] = (string) $data['shop_id'];
            $returnData['marketplace_status'] = $orderWrapper['financial_status'];
            $lineItems = [];
            $marketplaceCurrency = "";
            isset($orderWrapper['created_at']) && $returnData['source_created_at'] = $orderWrapper['created_at'];
            isset($orderWrapper['updated_at']) && $returnData['source_updated_at'] = $orderWrapper['updated_at'];
            isset($orderWrapper['taxes_included']) && $returnData['taxes_included'] = $orderWrapper['taxes_included'];

            foreach ($items as $item) {
                $lineItem = [];
                $lineItem['type'] = $this->getItemType($item);
                $lineItem['marketplace_item_id'] = (string) $item['id'];
                $lineItem['sku'] = (string) $this->getSku($item);
                $lineItem['qty'] = $item['quantity'] ?? '0';
                $lineItem['cancelled_qty'] = $item['quantityCancellled'] ?? '0';
                $lineItem['price'] = $item['price'];
                $lineItem['title'] = $this->getTitle($item);
                if (isset($item['variant_id'])) {
                    $lineItem['product_identifier'] = $item['variant_id'];

                }

                if (isset($item['product_id'])) {
                    $lineItem['parent_product_identifier'] = $item['product_id'];
                }

                $marketplaceCurrency = $item['price_set']['shop_money']['currency_code'];

                $totalTax = 0.00;
                $taxes = [];
                if (isset($item['tax_lines'])) {
                    foreach ($item['tax_lines'] as $taxLine) {
                        $totalTax += $taxLine['price'];
                        $taxes[] = ['code' => $taxLine['title'], 'price' => $taxLine['price']];
                    }
                }

                $lineItem['taxes'] = $taxes;
                $lineItem['tax'] = ['price' => $totalTax];
                $totalDiscount = 0.00;
                $discounts = [];
                $lineItem['discounts'] = $discounts;
                $lineItem['discount'] = ['price' => $totalDiscount];
                $lineItem['attributes'] = $this->getItemExtraAttribute($item, $orderWrapper);

                $duplicateKeyConfig = $this->di->getConfig()->get('include_link_id_in_attributes_if_duplicate_key') ?? [];

                foreach ($item['properties'] as $property) {
                    $attributeName = $duplicateKeyConfig['attribute_name'] ?? 'ced_item_id';

                    if ($property['name'] == $attributeName) {
                        $lineItem['ced_item_id'] = $property['value'];
                    }
                }

                foreach ($lineItem['attributes'] as $attribute) {
                    $duplicateKeyConfig = $this->di->getConfig()->get('include_link_id_in_attributes_if_duplicate_key') ?? [];
                    $attributeName = $duplicateKeyConfig['attribute_name'] ?? 'ced_item_id';

                    if ($attribute['name'] == $attributeName) {
                        $lineItem['ced_item_id'] = $attribute['value'];
                    }
                }

                $lineItems[] = $lineItem;
            }

            if (!empty($lineItems)) {

                $returnData['items'] = $lineItems;
                $returnData['marketplace_currency'] = $marketplaceCurrency;
                $returnData['customer'] = $this->getCustomer($orderWrapper);
                $returnData['shipping_address'] = [$this->getShippingAddress($orderWrapper)];
                $returnData['billing_address'] = $this->getBillingAddress($orderWrapper, $returnData['shipping_address']);
                $returnData['attributes'] = $this->getExtraAttribute($orderWrapper);
                $this->updateAcknowledgeIdIfEnabled($returnData, $orderWrapper);
                $this->markAsBundledIfApplicable($returnData);
                $this->markAsComponentIfApplicable($returnData);
                $returnData = $this->updateTaxes($returnData);
                //$returnData['shipping_charge']['price'] = $this->getShipping($lineItems);
            } else {
                return ["success" => false, "message" => "Order Item missing"];
            }

            return ['success' => true, 'data' => $returnData];

        }
        return ['success' => false, 'message' => 'Shop id not exist'];

    }

    public function updateTaxes($data)
    {
        $tax = 0;
        $taxes = [];
        $items = $data['items'];
        foreach ($items as $item) {
            if (isset($item['tax']['price'])) {
                $tax += $item['tax']['price'];
            }
        }

        $data['tax']['price'] = $tax;
        return $data;
    }


    public function getItemType(array $item): string
    {
        return OrderInterface::ITEM_REAl;
    }

    public function getSku(array $item): string
    {
        if (!is_null($item['sku']))
            return $item['sku'];

        return '';
    }

    public function getTitle(array $item): string
    {
        return $item['title'];
    }

    public function getCustomer(array $order): array
    {
        $customer = [];
        if (isset($order['customer'])) {
            $customer['name'] = $order['customer']['first_name'] . $order['customer']['last_name'];
            $customer['email'] = $order['customer']['email'];
            $customer['id'] = $order['customer']['id'];
        }

        return $customer;

    }

    // public function getShippingAddress(array $order):array
    // {
    //     $shippingAddress = [];

    //     if(isset($order['shipping_details']))
    //     {
    //         $amazonShipping = $order['ShippingAddress'];

    //         $customerInfo =  $this->getCustomer($order);
    //         if(isset($customerInfo['name']))
    //         {
    //             $shippingAddress['name'] = $customerInfo['name'];
    //         }

    //         if(isset($amazonShipping['AddressType'])){
    //             $shippingAddress['type'] = $amazonShipping['AddressType'];
    //         }
    //         if(isset($amazonShipping['StateOrRegion'])){
    //             $shippingAddress['state'] = $amazonShipping['StateOrRegion'];
    //         }
    //         if(isset($amazonShipping['AddressLine1'])){
    //             $shippingAddress['address_line_1'] = $amazonShipping['AddressLine1'];
    //         }
    //         if(isset($amazonShipping['AddressLine2'])){
    //             $shippingAddress['address_line_2'] = $amazonShipping['AddressLine2'];
    //         }
    //         if(isset($amazonShipping['AddressLine3'])){
    //             $shippingAddress['address_line_3'] = $amazonShipping['AddressLine3'];
    //         }

    //         if(isset($amazonShipping['City'])){
    //             $shippingAddress['city'] = $amazonShipping['City'];
    //         }
    //         if(isset($amazonShipping['PostalCode'])){
    //             $shippingAddress['zip'] = $amazonShipping['PostalCode'];
    //         }
    //         if(isset($amazonShipping['Country'])){
    //             $shippingAddress['country'] = $amazonShipping['Country'];
    //         }
    //         if(isset($amazonShipping['CountryCode'])){
    //             $shippingAddress['country_code'] = $amazonShipping['CountryCode'];
    //         }
    //         if(isset($amazonShipping['StateCode'])){
    //             $shippingAddress['state_code'] = $amazonShipping['StateCode'];
    //         }
    //         if(isset($amazonShipping['Phone'])){
    //             $shippingAddress['phone'] = $amazonShipping['Phone'];
    //         }
    //     }
    //     return $shippingAddress;


    // }

    public function getShippingAddress($order)
    {
        $shipping_address = [];
        if (isset($order['shipping_address'])) {
            $address = $order['shipping_address'];
        } else {
            return $shipping_address;
        }

        if (isset($address['first_name'])) {
            $shipping_address['first_name'] = $address['first_name'];
        }

        if (isset($address['middle_name'])) {
            $shipping_address['middle_name'] = $address['middle_name'];
        }

        if (isset($address['last_name'])) {
            $shipping_address['last_name'] = $address['last_name'];
        }

        if ((isset($address['address1']) && ($address['address1'] !== null)) && (isset($address['address2']) && ($address['address2']) !== null)) {
            $shipping_address['address'] = $address['address1'] . " " . $address['address2'];
        } else if (isset($address['address1']) && ($address['address1'] !== null)) {
            $shipping_address['address'] = $address['address1'];
        } else if (isset($address['address2']) && ($address['address2'] !== null)) {
            $shipping_address['address'] = $address['address2'];
        } else {
            $shipping_address['address'] = null;
        }

        if (isset($address['city'])) {
            $shipping_address['city'] = $address['city'];
        }

        if (isset($address['province'])) {
            $shipping_address['state'] = $address['province'];
        }

        if (isset($address['zip'])) {
            $shipping_address['zip'] = $address['zip'];
        }

        if (isset($address['country'])) {
            $shipping_address['country'] = $address['country'];
        }

        if (isset($address['province_code'])) {
            $shipping_address['state_code'] = $address['province_code'];
        }

        if (isset($address['country_code'])) {
            $shipping_address['country_code'] = $address['country_code'];
        }

        return $shipping_address;
    }

    public function updateShippingAddressToCustomIfExist(&$shippingAddress, $orderSetting): void
    {
        if (
            !isset($orderSetting['shipping_address_mapping_override'])
            || !$orderSetting['shipping_address_mapping_override']
        ) {
            return;
        }

        $customMappings = $orderSetting['shipping_address_mapping']['keys'] ?? [];
        if (empty($customMappings)) {
            return;
        }

        $mappingUpdatedAddress = [];
        $hasAllKeys = true;
        foreach ($customMappings as $key => $value) {
            if (empty($shippingAddress[$value])) {
                $hasAllKeys = false;
                continue;
            }

            $mappingUpdatedAddress[$value] = $shippingAddress[$key];
        }

        $requireAllKeys = $orderSetting['shipping_address_mapping']['require_all_keys'] ?? false;
        if (!$requireAllKeys || $hasAllKeys) {
            $shippingAddress = array_merge($shippingAddress, $mappingUpdatedAddress);
        }
    }

    public function updateAcknowledgeIdIfEnabled(&$preparedData, $marketplaceOrderData): void
    {
        if (
            !isset($this->userSettings['add_acknowledge_id_in_attributes']) ||
            !$this->userSettings['add_acknowledge_id_in_attributes']
        ) {
            return;
        }

        $acknowledgeIdKey = 'id';
        $acknowledgeIdPrefix = '';
        if (isset($this->userSettings['acknowledge_id_mapping'])) {
            $acknowledgeIdKey = $this->userSettings['acknowledge_id_mapping']['key'] ?? $acknowledgeIdKey;
            $acknowledgeIdPrefix = $this->userSettings['acknowledge_id_mapping']['prefix'] ?? '';
        }

        $acknowledgeIdValue = $marketplaceOrderData[$acknowledgeIdKey] ?? false;
        if (!$acknowledgeIdValue) {
            return;
        }

        $preparedData['attributes'][] = [
            'key' => 'acknowledge_id',
            'value' => $acknowledgeIdPrefix . $acknowledgeIdValue,
            'sync' => false
        ];
    }

    public  function markAsBundledIfApplicable(&$preparedData): void
    {
        if (empty($this->userSettings['bundle_product_conversion']['enabled'])) {
            return;
        }

        foreach ($preparedData['items'] as &$item) {
            $itemUniqueId = $this->getConvertedBundleProductUniqueIds($item);
            if (!empty($itemUniqueId) && in_array($itemUniqueId, $this->convertedBundleProductIds)) {
                $item['is_bundled'] = true;
            }
        }
    }

    private function markAsComponentIfApplicable(&$preparedData): void
    {
        if (empty($this->convertedComponentProductIds)) {
            return;
        }

        foreach ($preparedData['items'] as &$item) {
            $itemUniqueId = $this->getConvertedBundleProductUniqueIds($item);
            if (!empty($itemUniqueId) && in_array($itemUniqueId, $this->convertedComponentProductIds)) {
                $item['is_component'] = true;
            }
        }
    }

    public function getBillingAddress(array $order, array $shipping_address): array
    {
        $billing_address = [];
        if (isset($order['billing_address'])) {
            $address = $order['billing_address'];
        } else {
            return $billing_address;
        }

        if (isset($address['first_name'])) {
            $billing_address['first_name'] = $address['first_name'];
        }

        if (isset($address['middle_name'])) {
            $billing_address['middle_name'] = $address['middle_name'];
        }

        if (isset($address['last_name'])) {
            $billing_address['last_name'] = $address['last_name'];
        }

        if ((isset($address['address1']) && ($address['address1'] !== null)) && (isset($address['address2']) && ($address['address2']) !== null)) {
            $billing_address['address'] = $address['address1'] . " " . $address['address2'];
        } else if (isset($address['address1']) && ($address['address1'] !== null)) {
            $billing_address['address'] = $address['address1'];
        } else if (isset($address['address2']) && ($address['address2'] !== null)) {
            $billing_address['address'] = $address['address2'];
        } else {
            $billing_address['address'] = null;
        }

        if (isset($address['city'])) {
            $billing_address['city'] = $address['city'];
        }

        if (isset($address['province'])) {
            $billing_address['state'] = $address['province'];
        }

        if (isset($address['zip'])) {
            $billing_address['zip'] = $address['zip'];
        }

        if (isset($address['country'])) {
            $billing_address['country'] = $address['country'];
        }

        if (isset($address['province_code'])) {
            $billing_address['state_code'] = $address['province_code'];
        }

        if (isset($address['country_code'])) {
            $billing_address['country_code'] = $address['country_code'];
        }

        if ((count($shipping_address) == 1) && ($shipping_address[0] == $billing_address)) {
            $billing_address = [];
            $billing_address['same_as_shipping'] = "1";
        }

        return $billing_address;

    }

    public function getExtraAttribute(array $order): array
    {
        $attributes = [];
        if (!empty($order['note_attributes'])) {
            foreach ($order['note_attributes'] as $noteAttributes) {
                $attributes[] = ['key' => $noteAttributes['name'], 'value' => $noteAttributes['value']];
            }
        }

        return $attributes;
    }

    public function getItemExtraAttribute(array $orderItem, array $orderData): array
    {
        $attributes = [];
        return $attributes;

    }

    public function getItemCustomizationData(array $itemData, array $orderData)
    {
        if (isset($itemData['BuyerCustomizedInfo']['CustomizedURL'])) {
            $customizedURL = $itemData['BuyerCustomizedInfo']['CustomizedURL'];

            $file_name = $itemData['OrderItemId'];

            $fileLocation = BP . DS . 'var' . DS . 'file' . DS . $this->di->getUser()->id . DS . 'order_customization' . DS . $orderData['AmazonOrderId'] . DS . $file_name;
            $filePath = "{$fileLocation}.zip";

            if (!file_exists($filePath)) {
                $dirname = dirname($filePath);
                if (!is_dir($dirname)) {
                    mkdir($dirname, 0777, true);
                }

                file_put_contents($filePath, file_get_contents($customizedURL));
            } else {
            }

            if (file_exists($filePath)) {
                $zip = new ZipArchive;
                $res = $zip->open($filePath);
                if ($res === TRUE) {
                    $zip->extractTo("{$fileLocation}/");
                    $zip->close();

                    $customizationFilePath = "{$fileLocation}/{$file_name}.json";
                    if (file_exists($customizationFilePath)) {
                        $customizationJson = file_get_contents($customizationFilePath);

                        if ($customizationJson) {
                            $customizationData = json_decode($customizationJson, true);

                            if (isset($customizationData['version3.0']['customizationInfo']['surfaces']) && is_array($customizationData['version3.0']['customizationInfo']['surfaces'])) {
                                $itemCustomizations = [];

                                $customizationInfoArray = $customizationData['version3.0']['customizationInfo']['surfaces'];

                                foreach ($customizationInfoArray as $customizationInfo) {
                                    $itemCustomizationName = $customizationInfo['name'];

                                    if (isset($customizationInfo['areas']) && is_array($customizationInfo['areas'])) {
                                        foreach ($customizationInfo['areas'] as $area) {
                                            $itemCustomizations[] = $this->getItemCustomizationValue($itemCustomizationName, $area);
                                        }
                                    } else {
                                        $itemCustomizations[] = $this->getItemCustomizationValue($itemCustomizationName, $customizationInfo);
                                    }
                                }

                                return ['success' => true, 'data' => $itemCustomizations];
                            }

                            return ['success' => false, 'message' => 'customization data not found.'];
                        }
                        return ['success' => false, 'message' => 'customization json file not found.'];
                    }
                    return ['success' => false, 'message' => 'customization file not found after extraction.'];
                }
                return ['success' => false, 'message' => 'file not extracted'];
            }
            return ['success' => false, 'message' => 'file not downloaded'];
        }
        return ['success' => false, 'message' => 'CustomizedURL not set'];
    }

    public function getItemCustomizationValue($surfaceName, $customization)
    {
        return [];
    }

    public function getAll(array $data): array
    {

    }

    public function archiveOrder(array $data): array
    {

    }

    public function getAlreadyCreatedOrdersFromShopify($remote_shop_id, $filters = [])
    {
        $createdOrdersUsingSourceIdentifier = $this->getAlreadyCreatedOrdersUsingSourceIdentifier(
            $remote_shop_id,
            $filters
        );
        if (!empty($createdOrdersUsingSourceIdentifier['orders'])) {
            return $createdOrdersUsingSourceIdentifier;
        }

        $orderFetchParams = [
            'shop_id' => $remote_shop_id,
            'status' => 'any',
            'fields' => 'id,name,created_at,app_id,number,order_number,source_name,note_attributes,line_items',
            'limit' => 250
        ];

        $orderFetchParams = array_merge($orderFetchParams, $filters);

        $shopifyHelper = $this->di->getObjectManager()->get(Helper::class);
        $remoteResponse = $shopifyHelper->sendRequestToShopify('/order', [], $orderFetchParams, 'GET');

        if (isset($remoteResponse['success']) && $remoteResponse['success']) {
            $orders = [];

            if (isset($remoteResponse['data']) && !empty($remoteResponse['data'])) {
                foreach ($remoteResponse['data'] as $orderData) {

                    if (!empty($orderData['note_attributes'])) {
                        $sourceOrderId = '';

                        $noteAttributes = $orderData['note_attributes'];
                        foreach ($noteAttributes as $value) {
                            $attr_name = strtolower((string) $value['name']);
                            if ($value['name'] == 'Source Order ID' || $value['name'] == 'Amazon Order ID' || $attr_name == 'source order id' || $attr_name == 'amazon order id') {
                                $sourceOrderId = $value['value'];
                                break;
                            }
                        }

                        if ($sourceOrderId) {
                            $orders[$sourceOrderId] = $orderData;
                        }
                    }
                }
            }

            return ['success' => true, 'orders' => $orders];
        }
        $this->addLog(PHP_EOL . "Response => " . json_encode($remoteResponse), $this->logFilePath, "In getAlreadyCreatedOrdersFromShopify    : remote response failure!", self::LOG_PRIORITY_LEVEL_CRITICAL);
        return ['success' => false, 'message' => $remoteResponse['msg'] ?? $remoteResponse['message']];
    }

    public function getAlreadyCreatedOrdersUsingSourceIdentifier($remoteShopId, $filters = [])
    {
        if (empty($remoteShopId) || empty($filters['marketplace_reference_id'])) {
            return ['success' => false, 'message' => 'remote_shop_id and marketplace_reference_id are required.'];
        }

        $orderFetchParams = [
            'shop_id' => $remoteShopId,
            'status' => 'any',
            'source_identifier' => $filters['marketplace_reference_id'],
        ];
        $orderFetchParams = array_merge($orderFetchParams, $filters);

        $remoteResponse = $this->di->getObjectManager()
            ->get(Helper::class)
            ->sendRequestToShopify('/order', [], $orderFetchParams, 'GET');

        if (!isset($remoteResponse['success']) || !$remoteResponse['success']) {
            return [
                'success' => false,
                'message' => $remoteResponse['msg'] ?? $remoteResponse['message'] ?? 'Response not found'
            ];
        }

        $returnData = [];
        foreach ($remoteResponse['data'] as $order) {
            if (isset($order['source_identifier'])) {
                $returnData[$order['source_identifier']] = $order;
            }
        }

        return ['success' => true, 'orders' => $returnData];
    }

    /**
     * Returns the inventory behavior for Shopify based on the order setting and order's fulfilled_by value.
     *
     * @param mixed $orderSetting The order setting from config.
     * @param mixed $order The order data.
     * @return string The inventory behavior to be used in API call.
     */
    public function getInventoryBehaviorForShopify($orderSetting, $order)
    {
        $inventoryBehavior = 'decrement_obeying_policy';

        $deductNegativeEnabled = $orderSetting['inventory_deduct_negative_side'] ?? false;
        $bypassEnabled = $orderSetting['inventory_bypass_enabled'] ?? false;
        $fulfilledByOther = (isset($order['fulfilled_by']) && $order['fulfilled_by'] == 'other')
            ? true : false;

        if ($deductNegativeEnabled) {
            $inventoryBehavior = 'decrement_ignoring_policy';
        }

        if ($bypassEnabled || $fulfilledByOther) {
            $inventoryBehavior = 'bypass';
        }

        $deductInventoryForFbo = $orderSetting['deduct_inventory_for_fbo'] ?? false;
        if ($deductInventoryForFbo && $fulfilledByOther) {
            $inventoryBehavior = 'decrement_ignoring_policy';
        }

        return $inventoryBehavior;
    }

    public function getFulfillmentStatusForShopify(&$fulfillmentStatus, $order, $orderSetting)
    {
        if (isset($order['is_ispu']) && $order['is_ispu']) {
            return $this->updateFulfillmentStatusForIspuOrders($fulfillmentStatus, $order, $orderSetting);
        }

        if (isset($order['fulfilled_by']) && $order['fulfilled_by'] == 'other') {
            return $this->updateFulfillmentStatusForFboOrders($fulfillmentStatus, $order, $orderSetting);
        }
    }

    public function updateFulfillmentStatusForIspuOrders(
        &$fulfillmentStatus,
        $order,
        $orderSetting
    ): void {
        if (!isset($order['is_ispu']) || !$order['is_ispu']) {
            return;
        }

        $ispuOrderFulfillmentStatusConfig = $orderSetting['ispu_order_fullfilment_status'] ?? null;

        $connectorOrderStatus = $this->di
            ->getObjectManager()
            ->get('\App\Connector\Components\Order\OrderStatus');

        $fulfillmentStatus = $connectorOrderStatus::FULFILLED == $ispuOrderFulfillmentStatusConfig
            ? 'fulfilled'
            : $fulfillmentStatus;
    }

    public function updateFulfillmentStatusForFboOrders(
        &$fulfillmentStatus,
        $order,
        $orderSetting
    ): void {
        if (!isset($order['fulfilled_by']) || $order['fulfilled_by'] != "other") {
            return;
        }

        $unfulfilledEnabled = $orderSetting['fbo_order_unfulfilled'] ?? false;

        // If enabled, set status to null (unfulfilled), otherwise set to "fulfilled"
        $fulfillmentStatus = ($unfulfilledEnabled === true) ? null : "fulfilled";
    }

    public function getShopifyUserDetails($shopId): array
    {

        foreach ($this->di->getUser()->getConfig()['shops'] as $shop) {
            if (isset($shop['_id']) && $shop['_id'] == $shopId) {
                $this->userDetails = $shop;
                break;
            }

        }

        return $this->userDetails;
    }

    public function getAttempt(): int
    {
        if (is_null($this->attempt)) {
            $this->attempt = 1;
            return $this->attempt;
        }
        $attempt = $this->attempt;
        $this->attempt = $attempt + 1;
        return $this->attempt;
    }

    public function createOrderOnShopify($sourceOrderId, $orderData)
    {
        $this->addLog("", $this->logFilePath, " >>> Step 5: Process create on Shopify <<< ", self::LOG_PRIORITY_LEVEL_STANDARD);
        if (is_null($orderData['total_price']) || $orderData['total_price'] == '0') {
            unset($orderData['transactions']);
        }

        $result = [];
        $appCode = 'default';
        if (isset($orderData['app_code'])) {
            $appCode = $orderData['app_code'];
            unset($orderData['app_code']);
        }

        $shopifyHelper = $this->di->getObjectManager()->get(Helper::class);
        $remoteResponse = $shopifyHelper->sendRequestToShopify('/order', [], ['shop_id' => $this->userDetails['remote_shop_id'], 'data' => $orderData, 'app_code' => $appCode], 'POST');

        if ($remoteResponse['success']) {
            $this->addLog("", $this->logFilePath, "Order creation successful on shopify for order Id: " . $sourceOrderId, self::LOG_PRIORITY_LEVEL_CRITICAL);
            $result = ['success' => true, 'data' => $remoteResponse['data'], 'message' => 'Order successfully created on shopify'];
        } else {
            $errorMSG = 'Error';
            $error = [];
            $this->addLog(json_encode($remoteResponse), $this->logFilePath, "Order creation failed: ", self::LOG_PRIORITY_LEVEL_CRITICAL);
            if (isset($remoteResponse['msg']['errors']) && gettype($remoteResponse['msg']['errors']) == 'string') {
                $errorMSG = $remoteResponse['msg']['errors'];
                $error = $remoteResponse['msg'];
                if (str_contains($errorMSG, 'calls per second for api client. Reduce request rates to resume uninterrupted service.') && ($this->attempt <= 3)) {
                    $this->addLog(json_encode($remoteResponse), $this->logFilePath, "trying to re-attempt({$this->attempt}) order creation on shopify", self::LOG_PRIORITY_LEVEL_CRITICAL);
                    sleep(3);
                    $orderData['app_code'] = $appCode;
                    return $this->createOrderOnShopify($sourceOrderId, $orderData);
                }
            } elseif (isset($remoteResponse['msg']['errors']['line_items'][0]) && $remoteResponse['msg']['errors']['line_items'][0] == "Unable to reserve inventory") {
                $errorMSG = "Unable to reserve inventory";
                $error = $remoteResponse['msg'];
            } elseif (isset($remoteResponse['msg']['errors']) && gettype($remoteResponse['msg']['errors']) == 'array') {
                $error = $remoteResponse['msg'];
            } else {
                if (isset($remoteResponse['msg'])) {
                    $error = $remoteResponse['msg'];
                } elseif (isset($remoteResponse['message'])) {
                    $error = $remoteResponse['message'];
                } else {
                    $error = 'Something went wrong during order creation on Shopify.';
                }
            }

            if (!empty($error)) {
                $errorMSG = $errorMSG . ': ' . json_encode($error);
            }

            if (str_contains($errorMSG, 'created_at') && str_contains($errorMSG, 'to be a Time')) {
                // Updating source_created_at field in the db
                $sourceCreatedAt = gmdate('Y-m-d\TH:i:s\Z');
                $collection = $this->di->getObjectManager()->get(BaseMongo::class)->getCollection('order_container');
                $updateResult = $collection->updateOne(
                    ['user_id' => $this->di->getUser()->id, 'marketplace_reference_id' => $sourceOrderId, 'object_type' => 'source_order'],
                    ['$set' => ['source_created_at' => $sourceCreatedAt]]
                );

                if ($updateResult->getModifiedCount() > 0) {
                    $this->addLog("", $this->logFilePath, "Updated source_created_at field for order Id: " . $sourceOrderId, self::LOG_PRIORITY_LEVEL_CRITICAL);

                    $orderData['created_at'] = $sourceCreatedAt;
                    return $this->createOrderOnShopify($sourceOrderId, $orderData);
                }
            }

            $this->addLog(json_encode($error), $this->logFilePath, "Error: ", self::LOG_PRIORITY_LEVEL_CRITICAL);
            $result = ['success' => false, 'message' => $errorMSG];
        }

        return $result;
    }

    public function validateForCreate(array $data): array
    {
        $this->convertedComponentProductIds = [];

        if (isset($data['order'])) {
            $orderSetting = $data['order_settings'];
            $orderItems = $data['order']['items'];
            $skudata = [];
            $skudata['source_shop_id'] = $data['target_shop_id'];
            $skudata['target_shop_id'] = $data['shop_id'];

            $successItem = [];
            $failedItems = [];
            $disabledItems = [];

            foreach ($orderItems as $itemKey => $item) {
                $skudata['sku'] = $item['sku'];
                $product = $this->getProductIdentifier($skudata);
                if ($product['success']) {

                    $ineligibilityReason = "";
                    if (!$this->isProductEligibleForOrder($product['data'], $item, $orderSetting, $ineligibilityReason)) {
                        $item['reason'] = $ineligibilityReason;
                        $disabledItems[] = $item;
                        continue;
                    }

                    if ($product['data']['source_product_id'] == $product['data']['container_id']) {
                        $product = $this->getProductByQuery($skudata);
                        if ($product['success']) {
                            $item['product_identifier'] = $product['data']['source_product_id'];
                            $item['parent_product_identifier'] = $product['data']['container_id'];
                            if (empty($product['data']['is_bundle']) || empty($product['data']['components']) || !is_array($product['data']['components'])) {
                                $successItem[] = $item;
                            } else {
                                $successItem = [...$successItem, ...$this->prepareItemsForBundleProduct($product['data'], $item)];
                            }
                            unset($data['order']['items'][$itemKey]);
                        } else {
                            $item['reason'] = $product['message'];
                            $failedItems[] = $item;
                        }
                    } else {
                        $item['product_identifier'] = $product['data']['source_product_id'];
                        $item['parent_product_identifier'] = $product['data']['container_id'];
                        if (empty($product['data']['is_bundle']) || empty($product['data']['components']) || !is_array($product['data']['components'])) {
                            $successItem[] = $item;
                        } else {
                            $successItem = [...$successItem, ...$this->prepareItemsForBundleProduct($product['data'], $item)];
                        }
                        unset($data['order']['items'][$itemKey]);
                    }

                    // $item['product_identifier'] = $product['data']['source_product_id'];
                    // $item['parent_product_identifier'] = $product['data']['container_id'];
                    // $successItem[] = $item;
                    // unset($data['order']['items'][$itemKey]);
                } else {
                    $item['reason'] = $product['message'];
                    $failedItems[] = $item;
                }
            }

            if (!empty($disabledItems)) {
                return ['success' => false, 'data' => $successItems, 'disabled' => $disabledItems];
            }

            if (!empty($successItem)) {
                if (isset($orderSetting['order_for_product_not_existing']) && $orderSetting['order_for_product_not_existing']['value']) {
                    foreach ($successItem as $successData) {
                        $data['order']['items'][] = $successData;
                    }

                    return ['success' => true, 'data' => $data['order']['items']];
                }

                if (!empty($failedItems)) {
                    return ['success' => true, 'data' => $successItem, 'failed' => $failedItems];
                }

                return ['success' => true, 'data' => $successItem];
            }
            if (isset($orderSetting['order_for_product_not_existing']) && $orderSetting['order_for_product_not_existing']['value']) {
                return ['success' => true, 'data' => $data['order']['items']];
            }
            return ['success' => false, 'message' => $failedItems];
        }
        return ['success' => false, 'message' => 'Order not set'];

    }

    public function getProductIdentifier($skudata)
    {
        $productBySkuObj = $this->di->getObjectManager()->create(Marketplace::class);
        $product = $productBySkuObj->getProductBySku($skudata);
        if (empty($product)) {
            return ['success' => false, 'message' => 'product not found'];
        }

        // Validate product exists on target shop if configured
        $validationResult = $this->validateProductOnTargetShop($product, $skudata);
        if ($validationResult !== null) {
            return $validationResult;
        }

        return ['success' => true, 'data' => $product];
    }


    private function validateProductOnTargetShop($product, $skudata)
    {
        $config = $this->di->getConfig();

        // Check if validation is enabled
        if (!$config->path('ensure_product_exists_on_target_shop.enabled', false)) {
            return null;
        }

        $keyToMatch = $config->path('ensure_product_exists_on_target_shop.key_to_match', false);
        if (!$keyToMatch) {
            return null;
        }

        // If product already has the key, no validation needed
        if (!empty($product[$keyToMatch])) {
            return null;
        }

        $className = $config->path('ensure_product_exists_on_target_shop.class_name', false);
        $methodName = $config->path('ensure_product_exists_on_target_shop.method_name', false);

        if (!$className || !$methodName) {
            return null;
        }

        return $this->executeProductValidation($className, $methodName, $product, $skudata, $keyToMatch);
    }

    private function executeProductValidation($className, $methodName, $product, $skuData, $keyToMatch)
    {
        if (!class_exists($className)) {
            return null;
        }

        if (!method_exists($className, $methodName)) {
            return null;
        }

        $productMarketplace = $this->di->getObjectManager()->get($className);

        $params = [
            'user_id' => $this->di->getUser()->id,
            'source_shop_id' => $skuData['target_shop_id'],
            'target_shop_id' => $skuData['source_shop_id'],
            'source_product_id' => $product['source_product_id'],
            'key_to_match' => $keyToMatch,
            'sku' => $skuData['sku'],
        ];

        $validationResponse = $productMarketplace->$methodName($params, true);

        if ($validationResponse['success']) {
            $matchedSourceProductId = $validationResponse['matched_product']['source_product_id'] ?? null;
            $currentSourceProductId = $product['source_product_id'];

            if ($matchedSourceProductId === null || $matchedSourceProductId !== $currentSourceProductId) {
                return [
                    'success' => false,
                    'message' => 'product not found', // same SKU for source and target shop but different linking case
                ];
            }
            return ['success' => true, 'data' => $product];
        }

        return ['success' => false, 'message' => 'product not found'];
    }

    public function getProductByQuery($skudata)
    {
        $productBySkuObj = $this->di->getObjectManager()->create(Marketplace::class);
        $sourceProductQuery = [
            'user_id' => $this->di->getUser()->id,
            'shop_id' => $skudata['source_shop_id'],
            'sku' => $skudata['sku']
        ];
        $sourceProducts = $productBySkuObj->getProductByQuery($sourceProductQuery, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
        if (empty($sourceProducts)) {
            return ['success' => false, 'message' => 'product not found'];
        }
        $foundProduct = [];
        foreach ($sourceProducts as $product) {
            if ($product['source_product_id'] !== $product['container_id']) {
                $foundProduct[] = $product;
            } else {
                continue;
            }
        }

        if (!empty($foundProduct) && (count($foundProduct) == 1)) {
            $targetProductQuery = [
                'user_id' => $this->di->getUser()->id,
                'shop_id' => $skudata['target_shop_id'],
                'source_product_id' => $foundProduct[0]['source_product_id']
            ];
            $targetProducts = $productBySkuObj->getProductByQuery($targetProductQuery, ["typeMap" => ['root' => 'array', 'document' => 'array']]);
            if (!empty($targetProducts)) {
                if (count($targetProducts) == 1) {
                    return ['success' => true, 'data' => $targetProducts[0]];
                }
                return ['success' => false, 'message' => 'duplicate skus found'];
            }
            return ['success' => false, 'message' => 'product not found'];
        }
        return ['success' => false, 'message' => 'duplicate skus found'];
    }

    private function isProductEligibleForOrder(array $product, array $sourceOrderProduct, array $orderSettings, string &$ineligibleReason): bool
    {
        // If no sync rules are defined, product is eligible by default
        if (empty($orderSettings['order_sync_rules']['value']['enabled'])) {
            return true;
        }

        $rules = $orderSettings['order_sync_rules']['value']['rules'] ?? [];
        $mode = $orderSettings['order_sync_rules']['value']['mode'] ?? 'any';

        $results = [];

        $errorFields = [];

        foreach ($rules as $rule) {
            $field = $rule['field'] ?? '';
            $operator = $rule['operator'] ?? '';
            $value = $rule['value'] ?? null;
            $type = strtolower($rule['type'] ?? 'restrict');

            $actualValue = $this->resolveProductField($product, $sourceOrderProduct, $field);

            $match = match ($operator) {
                '==' => $actualValue == $value,
                '!=' => $actualValue != $value,
                'in' => in_array($actualValue, (array) $value),
                'not_in' => !in_array($actualValue, (array) $value),
                default => false,
            };

            $isRulePassed = match ($type) {
                'restrict' => !$match, // if it matches a restrict rule - fail
                'allow' => $match, // if it doesn't match an allow rule - fail
                default  => $match
            };

            $results[] = $isRulePassed;

            if (!$isRulePassed) {
                $errorFields[] = $field;
            }
        }

        $isEligible = $mode === 'all'
            ? !in_array(false, $results, true)
            : in_array(true, $results, true);

        if (!$isEligible) {
            $sku = $sourceOrderProduct['sku'] ?? 'unknown SKU';
            $readableFields = array_map([$this, 'getFieldLabel'], $errorFields);
            $ineligibleReason = "This order could not be synced to Shopify, for SKU {$sku}, due to restrictions based on: " . implode(', ', $readableFields) . ".";
        }

        return $isEligible;
    }

    /**
     * Prepares individual line items for bundle components, adjusting quantities and prices.
     *
     * This method iterates through the components of a bundle product (from our DB),
     * calculates the effective quantity for each component based on the parent bundle's quantity,
     * and proportionally adjusts the price of each component so their sum matches the parent bundle's total price.
     *
     * @param array $shopifyProduct The bundle product document from our database (product_container).
     * Expected to contain a 'components' array if it's a bundle.
     * @param array $sourceOrderItem The original line item from the order representing the bundle parent.
     * Expected to contain 'qty' (quantity of the bundle) and 'price' (total price of the bundle).
     * @return array An array of new order line items representing the bundle's components, or the original
     * sourceOrderItem if components cannot be prepared (e.g., bundle has no components defined).
     */
    private function prepareItemsForBundleProduct(array $shopifyProduct, array $sourceOrderItem): array
    {
        $componentItems = [];
        $bundleComponents = $shopifyProduct['components'] ?? [];

        if (empty($bundleComponents)) {
            return $sourceOrderItem;
        }

        $sourceItemQty = (float)($sourceOrderItem['qty'] ?? 1);
        $sourceItemPrice = (float)($sourceOrderItem['price'] ?? 0);

        $calculatedComponentsTotalPrice = 0.0;
        foreach ($bundleComponents as $component) {
            $componentPrice = (float)($component['price'] ?? 0);
            $componentBundleQty = (float)($component['bundle_qty'] ?? 1);
            $calculatedComponentsTotalPrice += ($componentPrice * $componentBundleQty);
        }

        $priceAdjustmentRatio = 1.0;
        $epsilon = 0.000001; // A small value to account for floating-point inaccuracies

        if ($calculatedComponentsTotalPrice > $epsilon || $calculatedComponentsTotalPrice < -$epsilon) { // Check if not zero
            $calculatedRatio = $sourceItemPrice / $calculatedComponentsTotalPrice;

            // If the calculated ratio is very close to 1.0, force it to 1.0
            if (abs($calculatedRatio - 1.0) < $epsilon) {
                $priceAdjustmentRatio = 1.0;
            } else {
                $priceAdjustmentRatio = $calculatedRatio;
            }
        } elseif ($sourceItemPrice > $epsilon || $sourceItemPrice < -$epsilon) {
            //#todo

            // Case: Calculated components total cost is zero (or near zero), but bundle has a price.
            // This might indicate components were free but the bundle is not, or a data inconsistency.
            // Handle as per business logic: currently, it will try to distribute if prices are non-zero,
            // otherwise, a division by zero might occur if not handled.
            // If the total cost of components is effectively zero, and the bundle has a price,
            // a direct proportional adjustment isn't feasible. Components would remain at their original price (0),
            // and the bundle price would not be distributed. This scenario usually needs specific business rules.
        }

        foreach ($bundleComponents as $component) {
            $componentPrice = (float)($component['price'] ?? 0);
            $componentBundleQty = (float)($component['bundle_qty'] ?? 1);

            $finalComponentQty = $componentBundleQty * $sourceItemQty;

            $adjustedComponentPrice = $componentPrice * $priceAdjustmentRatio;

            $finalComponentLineItemPrice = $adjustedComponentPrice * $finalComponentQty;

            $newComponentOrderItem = $sourceOrderItem;

            $newComponentOrderItem['sku'] = $component['sku'] ?? $newComponentOrderItem['sku'];
            $newComponentOrderItem['qty'] = $finalComponentQty;
            $newComponentOrderItem['marketplace_price'] = round($adjustedComponentPrice, 4);
            $newComponentOrderItem['line_item_price'] = round($finalComponentLineItemPrice, 4);

            $newComponentOrderItem['product_identifier'] = (string)($component['source_product_id'] ?? null);
            $newComponentOrderItem['parent_product_identifier'] = (string)($component['container_id'] ?? null);

            $newComponentOrderItem['title'] = $component['title'] ?? 'N/A';
            $newComponentOrderItem['is_bundle_component'] = true;
            $newComponentOrderItem['bundle_parent_sku'] = $shopifyProduct['source_sku'] ?? $shopifyProduct['sku'];

            $componentItems[] = $newComponentOrderItem;

            $this->convertedComponentProductIds[] = $this->getConvertedBundleProductUniqueIds($newComponentOrderItem);
        }

        return $componentItems;
    }

    private function resolveProductField(array $product, array $sourceOrderProduct, string $field)
    {
        return match ($field) {
            'vendor' => $product['brand'] ?? '',
            'container_id' => $product['container_id'] ?? '',
            'source_product_id' => $product['source_product_id'] ?? '',
            'source_order_sku' => $sourceOrderProduct['sku'] ?? '',
            default => null
        };
    }

    private function getFieldLabel(string $field): string
    {
        return match ($field) {
            'vendor' => 'Vendor',
            'container_id' => 'Shopify Product ID',
            'source_product_id' => 'Shopify Variant ID',
            'source_order_sku' => 'SKU',
            default => ucfirst(str_replace('_', ' ', $field))
        };
    }

    public function setOrderSetting($settings)
    {
        $varsettings = [];
        if (is_array($settings)) {
            foreach ($settings as $setting) {
                $varsettings[$setting['key']] = $setting['value'];
            }
        }

        $this->userSettings = $varsettings;
        return $this->userSettings;
    }

    public function getOrderSetting()
    {
        return $this->userSettings;

    }

    public function getTags($order)
    {
        $orderSetting = $this->getOrderSetting();
        $tags = [];
        $enable_custom_tax = false;
        if (isset($orderSetting['tags_in_order_enabled']) && $orderSetting['tags_in_order_enabled'] == true) {
            if (isset($orderSetting['tagsInOrder'])) {
                foreach ($orderSetting['tagsInOrder'] as $key => $tagValue) {
                    if ($tagValue && isset($order[$key])) {
                        $tags[] = $order[$key];
                    }

                    if ($key == 'custom' && $tagValue) {
                        $enable_custom_tax = true;
                    }

                    if ($key == 'seller_id' && $tagValue) {
                        $result = $this->di->getObjectManager()->get('\App\Core\Models\User\Details')->getShop($order['shop_id'], $order['user_id']);
                        if (isset($result['warehouses']) && isset($result['warehouses'][0]['seller_id'])) {
                            $tags[] = $result['warehouses'][0]['seller_id'];
                        }
                    }

                }

            }

            if ($enable_custom_tax && isset($orderSetting['custom_tags_in_order'])) {
                $customtags = json_decode(json_encode($orderSetting['custom_tags_in_order']), true);
                if (!empty($customtags)) {
                    foreach ($customtags as $value) {
                        $tags[] = $value;
                    }

                }
            }

            if (
                isset($orderSetting['custom_tags_using_attributes'])
                && $orderSetting['custom_tags_using_attributes']
                && isset($orderSetting['custom_tags_using_attributes_keys'])
                && !empty($orderSetting['custom_tags_using_attributes_keys'])
            ) {
                $keys = $orderSetting['custom_tags_using_attributes_keys'];
                foreach ($order['attributes'] as $attribute) {
                    if (isset($keys[$attribute['key']]['enabled']) && $keys[$attribute['key']]['enabled']) {
                        $tags[] = $keys[$attribute['key']]['tag'];
                    }
                }
            }

            if (!empty($order['conditional_tags'])) {
                $tags = array_merge($tags, $order['conditional_tags']);
            }
        }

        return $tags;

    }

    public function getOrderName($order)
    {
        $orderSetting = $this->getOrderSetting();
        // var_dump($orderSetting);die;
        $name = '';
        if (isset($orderSetting['source_marketplace_order_id_as_order_name']) && $orderSetting['source_marketplace_order_id_as_order_name']) {

            if (isset($orderSetting['order_id_format']) && $orderSetting['order_id_format']) {
                if (isset($orderSetting['order_id_format']['prefix']) && $orderSetting['order_id_format']['prefix']) {
                    $name = $name . $orderSetting['order_id_format']['prefix'];
                }

                $name = $name . $order['marketplace_reference_id'];
                if (isset($orderSetting['order_id_format']['suffix']) && $orderSetting['order_id_format']['suffix']) {
                    $name = $name . $orderSetting['order_id_format']['suffix'];
                }
            } else {
                $name = $name . $order['marketplace_reference_id'];
            }

        }

        return $name;

    }

    public function getNoteAttributes($order)
    {
        $orderSetting = $this->getOrderSetting();
        if (isset($orderSetting['custom_source_identifier'])) {
            $noteAttributes = [
                [
                    'name' => 'channel',
                    'value' => $orderSetting['custom_source_identifier']
                ]
            ];
        } else {
            $noteAttributes = [
                [
                    'name' => 'channel',
                    'value' => $order['marketplace']
                ]
            ];
        }

        $allOrderAttributes = $order['attributes'];
        if (!empty($allOrderAttributes)) {
            foreach ($allOrderAttributes as $attributes) {
                if (isset($attributes['sync']) && !$attributes['sync']) {
                    continue;
                }

                if ($attributes['key'] == 'cpf') {
                    if (isset($orderSetting['sync_cpf_number']) && $orderSetting['sync_cpf_number']) {
                        $noteAttributes[] = ['name' => $attributes['key'], 'value' => $attributes['value']];
                    }
                } else {
                    $noteAttributes[] = ['name' => $attributes['key'], 'value' => $attributes['value']];
                }
            }
        }

        return $noteAttributes;
    }

    public function getItemAttributes($attributes, $order)
    {
        $itemAttributes = [];
        foreach ($attributes as $attribute) {
            if (isset($attribute['type'])) {
                if ($attribute['type'] == 'image_url') {
                    $uploadsImageResponse = $this->uploadImageOnShopifyFilesUsingUrlAndGetUrl(
                        $attribute['value'],
                        $attribute['key']
                    );
                    if (!$uploadsImageResponse['success']) {
                        return $uploadsImageResponse;
                    }

                    $imageUrl = $uploadsImageResponse['url'];
                    $attribute['value'] = $imageUrl;
                } elseif ($attribute['type'] == 'image_path') {
                    if (!file_exists($attribute['value'])) {
                        continue;
                    }

                    $uploadsImageResponse = $this->uploadImageOnShopifyFilesUsingPathAndGetUrl(
                        $attribute['value'],
                        $attribute['key']
                    );
                    if (!$uploadsImageResponse['success']) {
                        return $uploadsImageResponse;
                    }

                    $attribute['value'] = $uploadsImageResponse['url'];
                }
            }

            if (isset($attribute['sync']) && !$attribute['sync']) {
                continue;
            }

            $itemAttribute = ['name' => $attribute['key'], 'value' => $attribute['value']];
            $itemAttributes[] = $itemAttribute;
        }

        return ['success' => true, 'data' => $itemAttributes];
    }

    public function updateNoteFromNoteAttributesIfEnabled(&$shopifyRequestData, &$orderData, $orderSetting): void
    {
        if (
            isset($orderSetting['update_note_using_note_attributes'])
            && $orderSetting['update_note_using_note_attributes']
            && isset($orderSetting['update_note_using_note_attributes_keys'])
            && !empty($orderSetting['update_note_using_note_attributes_keys'])
        ) {
            $keys = $orderSetting['update_note_using_note_attributes_keys'];
            foreach ($orderData['attributes'] as $index => $attribute) {
                if (isset($keys[$attribute['key']]['enabled']) && $keys[$attribute['key']]['enabled']) {
                    $additionalNote = $attribute['key'] . ': ' . $attribute['value'];
                    $shopifyRequestData['note'] = isset($shopifyRequestData['note'])
                        ? $shopifyRequestData['note'] . PHP_EOL . $additionalNote
                        : $additionalNote;
                    if (
                        isset($keys[$attribute['key']]['remove_from_note_attributes'])
                        && $keys[$attribute['key']]['remove_from_note_attributes']
                    ) {
                        unset($orderData['attributes'][$index]);
                    }
                }
            }
        }
    }

    public function uploadImageOnShopifyFilesUsingPathAndGetUrl($imagePath, $fileName)
    {
        if (!file_exists($imagePath)) {
            return ['success' => false, 'message' => 'Image not found'];
        }

        $image = file_get_contents($imagePath);
        $encodedImage = base64_encode($image);
        $shopifyShopDetails = $this->getShopifyUserDetails($this->shopifyShopId);
        $remoteShopId = $shopifyShopDetails['remote_shop_id'];
        $commenceStagedUploadResponse = $this->commenceStagedUploadsCreate($fileName, $remoteShopId);
        if (!$commenceStagedUploadResponse['success']) {
            return $commenceStagedUploadResponse;
        }

        //todo - need to update this in future
        $stagedTargets = $commenceStagedUploadResponse['data']['stagedUploadsCreate']['stagedTargets'][0];

        $stagedTargetUrl = $stagedTargets['url'];
        $stagedTargetResourceUrl = $stagedTargets['resourceUrl'];
        $stagedTargetParams = $stagedTargets['parameters'];
        $stagedUploadsResponse = $this->stagedUploadsCreate(
            $stagedTargetParams,
            $encodedImage,
            $stagedTargetUrl,
            $remoteShopId
        );
        //todo: check if the response be validated if successful or not
        $createImageResponse = $this->createFileOnShopify($stagedTargetResourceUrl, $fileName, $remoteShopId, 'IMAGE');
        if (!$createImageResponse['success']) {
            return $createImageResponse;
        }

        $createdFiles = $createImageResponse['data']['fileCreate']['files'];
        $id = array_column($createdFiles, 'id')[0];//todo - update
        $idParts = explode('/', (string) $id);
        $id = end($idParts);
        $fileUrlResponse = $this->getFileUrlUsingId($id, $remoteShopId);
        if (!$fileUrlResponse['success']) {
            return $fileUrlResponse;
        }

        if (!isset($fileUrlResponse['data'][0]['image']['url'])) {
            return ['success' => false, 'something went wrong'];
        }

        return ['success' => true, 'url' => $fileUrlResponse['data'][0]['image']['url']];
    }

    public function uploadImageOnShopifyFilesUsingUrlAndGetUrl($fileUrl, $fileName)
    {
        $shopifyShopDetails = $this->getShopifyUserDetails($this->shopifyShopId);
        $remoteShopId = $shopifyShopDetails['remote_shop_id'];
        $createImageResponse = $this->createFileOnShopify($fileUrl, $fileName, $remoteShopId, 'IMAGE');
        if (!$createImageResponse['success']) {
            return $createImageResponse;
        }

        $createdFiles = $createImageResponse['data']['fileCreate']['files'];
        $id = array_column($createdFiles, 'id')[0];//todo - update
        $idParts = explode('/', (string) $id);
        $id = end($idParts);
        $fileUrlResponse = $this->getFileUrlUsingId($id, $remoteShopId);
        if (!$fileUrlResponse['success']) {
            return $fileUrlResponse;
        }

        if (!isset($fileUrlResponse['data'][0]['image']['url'])) {
            return ['success' => false, 'something went wrong during image upload on Shopify'];
        }

        return ['success' => true, 'url' => $fileUrlResponse['data'][0]['image']['url']];
    }

    public function commenceStagedUploadsCreate(
        $filename,
        $remoteShopId,
        $httpMethod = 'POST',
        $mimeType = 'image/jpeg',
        $resource = 'FILE'
    ) {
        $requestData = compact('filename', 'httpMethod', 'mimeType', 'resource');
        $requestData['shop_id'] = $remoteShopId;
        $requestData['call_type'] = 'QL';

        return $this->di->getObjectManager()
            ->get(Helper::class)
            ->sendRequestToShopify('files/commenceStagedUploadsCreate', [], $requestData, 'POST');
    }

    public function stagedUploadsCreate($stagedTargetParams, $file, $url, $remoteShopId)
    {
        $formParams = [];
        foreach ($stagedTargetParams as $paramName => $paramValue) {
            $formParams[$paramName] = $paramValue;
        }
        $formParams['file'] = base64_encode($file);
        $formParams['url'] = $url;
        $formParams['shop_id'] = $remoteShopId;

        return $this->di->getObjectManager()
            ->get(Helper::class)
            ->sendRequestToShopify('files/stagedUploadsCreate', [], $formParams, 'POST');
    }

    public function createFileOnShopify($url, $alt, $remoteShopId, $contentType = 'IMAGE')
    {
        $requestData['url'] = $url;
        $requestData['alt'] = $alt;
        $requestData['contentType'] = $contentType;
        $requestData['shop_id'] = $remoteShopId;
        $requestData['call_type'] = 'QL';

        return $this->di->getObjectManager()
            ->get(Helper::class)
            ->sendRequestToShopify('files/createFileOnShopify', [], $requestData, 'POST');
    }

    public function getFileUrlUsingId($id, $remoteShopId)
    {
        $requestData['id'] = $id;
        $requestData['shop_id'] = $remoteShopId;
        $requestData['call_type'] = 'QL';

        $remoteResponse = $this->di->getObjectManager()
            ->get(Helper::class)
            ->sendRequestToShopify('files/getFileUrlUsingId', [], $requestData, 'GET');
        if (isset($remoteResponse['data'][0]['fileStatus']) && $remoteResponse['data'][0]['fileStatus'] != 'READY') {
            $attempt = $this->getAttempt();
            $this->addLog($attempt, $this->logFilePath, "Get File URL Attempt count: ", self::LOG_PRIORITY_LEVEL_IMPORTANT);
            if ($attempt <= 10) {
                sleep(1);
                return $this->getFileUrlUsingId($id, $remoteShopId);
            }
        }

        return $remoteResponse;
    }

    public function getNote()
    {

    }

    public function getSource()
    {
        /*
        Shopify no longer allow the use of any random source_name functionality
        and instead only allowed values for which the form has been filled and
        approved by Shopify
        */
        return "";

        $orderSetting = $this->getOrderSetting();
        $source = "";
        if (isset($orderSetting['custom_source_identifier'])) {
            $source = $orderSetting['custom_source_identifier'];
        }

        return $source;
    }

    public function prepareTaxLine(array $order): array
    {
        $taxLine = [];
        if (isset($order['taxes'])) {
            foreach ($order['taxes'] as $tax) {
                $taxLine[] = [
                    'title' => $tax['code'],
                    'price' => $tax['marketplace_price'],
                    'rate' => $tax['rate']

                ];

            }

        }

        return $taxLine;

    }

    // public function saveInLog($data, $file, $msg = "")
    // {
    //      //$file = 'order/order-create/'.$this->di->getUser()->id.'/'.date('Y-m-d').'/'.$file;
    //      $time = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');
    //      $path = __FILE__;
    //      $this->di->getLog()->logContent($time . PHP_EOL . $path . PHP_EOL . $msg . PHP_EOL . '  ' . print_r($data, true), 'info', $file);
    // }

    public function addLog($data, $file, $msg = "", $priorityLevel = null): void
    {
        $orderLogConfig = $this->di->getConfig()->path('log_handler.order_create');
        $priorityEnabled = $orderLogConfig['priority'][$priorityLevel] ?? true;
        $isLogEnabled = $orderLogConfig['enabled'] ?? true;
        $orderLogEnabled = ($priorityEnabled && $isLogEnabled);
        if ($orderLogEnabled) {
            $this->di->getLog()->logContent($msg . ' ' . print_r($data, true), 'info', $file);
        }
    }


    public function isTargetMissing($webhookData)
    {
        $orderData = $webhookData['data'];
        if (isset($orderData['app_id'])) {
            $isOurCreatedOrder = $this->isOurCreatedOrder($orderData['app_id']);
        }

        if (isset($isOurCreatedOrder) && !$isOurCreatedOrder) {
            return [
                'success' => false,
                'message' => 'Order not created from our app'
            ];
        }

        $isSourceOrderIdInAttribute = $this->getSourceOrderIdFromAttributes($orderData);
        if (!$isSourceOrderIdInAttribute['success']) {
            return $isSourceOrderIdInAttribute;
        }

        $connectorOrderService = $this->di
            ->getObjectManager()
            ->get(OrderInterface::class);

        $targetOrderQuery = [
            'object_type' => 'target_order',
            'marketplace' => 'shopify',
            'marketplace_reference_id' => (string) $orderData['id']
        ];
        if (isset($webhookData['user_id'])) {
            $targetOrderQuery['user_id'] = (string) $webhookData['user_id'];
        }

        if (isset($webhookData['shop_id'])) {
            $targetOrderQuery['marketplace_shop_id'] = (string) $webhookData['shop_id'];
        }

        $targetOrder = $connectorOrderService->getByField($targetOrderQuery);
        if (!empty($targetOrder)) {
            return [
                'success' => false,
                'message' => 'target_order found in database'
            ];
        }

        return [
            'success' => true,
            'message' => 'target_order not found in database'
        ];
    }

    public function isOurCreatedOrder($app_id)
    {
        $shopifyAppId = $this->di->getConfig()->get('shopify_app_id');
        //$shopifyAppId = 5045527;
        if ($app_id != $shopifyAppId) {
            return false;
        }

        return true;
    }

    public function getSourceOrderIdFromAttributes($orderData)
    {
        if (empty($orderData['note_attributes'])) {
            return [
                'success' => false,
                'message' => 'Attributes not found in webhook data'
            ];
        }

        foreach ($orderData['note_attributes'] as $attribute) {
            if (
                isset($attribute['name']) && $attribute['name'] == 'Source Order Id'
                || str_contains((string) $attribute['name'], 'Order ID')
            ) {
                return ['success' => true, 'data' => $attribute['value']];
            }
        }

        return ['success' => false, 'message' => 'Source Order Id not found in note_attributes'];
    }

    public function getNameToSendForOrderCreate($data)
    {
        $fullName = $data['full_name'];
        $order = $data['order'];
        $orderSetting = $data['settings'];
        $nameType = $data['name_type'];

        $firstName = $lastName = $order['marketplace'];

        $fullName = $this->sanitiseForShopify($fullName);

        if (!empty($orderSetting['send_name_as_first_name'])) {
            $firstName = $fullName;
            $lastName = $orderSetting['send_customer_last_name'] ?? "Customer";
        } elseif (isset($orderSetting['custom_first_name']) || isset($orderSetting['custom_last_name'])) {
            $firstName = $orderSetting['custom_first_name'] ?? $firstName;
            $lastName = $orderSetting['custom_last_name'] ?? $lastName;
        } elseif (!empty($orderSetting['dynamic_last_name']['enabled']) && !empty($orderSetting['dynamic_last_name']['field']) && !empty($order[$orderSetting['dynamic_last_name']['field']])) {
            $lastName = $order[$orderSetting['dynamic_last_name']['field']];
            $prefix = $orderSetting['dynamic_last_name']['prefix'] ?? '';
            $suffix = $orderSetting['dynamic_last_name']['suffix'] ?? '';
            $lastName = trim(
                ($prefix ? $prefix . ' ' : '') .
                $lastName .
                ($suffix ? ' ' . $suffix : '')
            );
            if (!empty($orderSetting['dynamic_last_name']['use_full_as_first_name'])) {
                $firstName = $fullName;
            } else {
                $firstName = explode(' ', $fullName)[0];
            }
        } else {
            $names = explode(' ', trim((string) $fullName));
            $namesCount = count($names);

            switch ($namesCount) {
                case 1:
                    if (!empty($orderSetting['custom_last_name_for_single_name'])) {
                        $firstName = $names[0] ?? $order['marketplace'];
                        $lastName = $orderSetting['custom_last_name_for_single_name'];
                    } else {
                        $firstName = !empty($names[0]) && !empty($orderSetting['use_last_name_when_no_first_name']) ? $names[0] : $order['marketplace'];
                        $lastName = !empty($names[0]) ? $names[0] : $order['marketplace'];
                    }
                    break;
                case 2:
                    [$firstName, $lastName] = $names;
                    break;
                default:
                    $groupedName = array_chunk($names, ceil($namesCount / 2));
                    $firstName = implode(' ', $groupedName[0]);
                    $lastName = implode(' ', $groupedName[1]);
                    break;
            }
        }

        $noteAttributes = [];
        if (!empty($orderSetting['modify_customer_name_length']) && $nameType == 'customer') {
            $firstNameMaxLength = isset($orderSetting['modify_customer_name_length']['first_name']) &&
                (is_int($orderSetting['modify_customer_name_length']['first_name']) || ctype_digit((string) $orderSetting['modify_customer_name_length']['first_name']))
                ? (int) $orderSetting['modify_customer_name_length']['first_name']
                : 250;

            $lastNameMaxLength = isset($orderSetting['modify_customer_name_length']['last_name']) &&
                (is_int($orderSetting['modify_customer_name_length']['last_name']) || ctype_digit((string) $orderSetting['modify_customer_name_length']['last_name']))
                ? (int) $orderSetting['modify_customer_name_length']['last_name']
                : 250;

            if (strlen((string) $firstName) > $firstNameMaxLength) {
                $firstName = substr((string) $firstName, 0, $firstNameMaxLength);
            }

            $noteAttributes[] = ['customer_first_name' => $firstName];

            if (strlen((string) $lastName) > $lastNameMaxLength) {
                $lastName = substr((string) $lastName, 0, $lastNameMaxLength);
            }

            $noteAttributes[] = ['customer_last_name' => $lastName];
        }

        // $firstNameClean = $this->sanitiseForShopify($firstName);
        // $lastNameClean = $this->sanitiseForShopify($lastName);

        $returnData = ['first_name' => $firstName, 'last_name' => $lastName];
        if (!empty($noteAttributes)) {
            $returnData['note_attributes'] = $noteAttributes;
        }

        return $returnData;
    }

    private function sanitiseForShopify($name)
    {
        // Specific replacements
        $replacements = [
            '©' => 'c',
            '®' => 'R',
            '℗' => 'P',
        ];

        $name = strtr($name, $replacements);

        // Remove ALL emojis, ZWJ sequences, and variation selectors
        $name = preg_replace('/[\p{So}\p{Sk}\p{Cs}]/u', '', $name);
        $name = preg_replace('/\x{200D}|\x{FE0F}/u', '', $name);

        // Remove remaining 4-byte emojis and characters outside BMP
        $name = preg_replace('%(?:
            \xF0[\x90-\xBF][\x80-\xBF]{2} | # U+10000 to U+3FFFF (includes emojis)
            [\xF1-\xF3][\x80-\xBF]{3}     | # U+40000 to U+FFFFF
            \xF4[\x80-\x8F][\x80-\xBF]{2}   # U+100000 to U+10FFFF
        )%xs', '', $name);

        /**
         * Break URL-like path segments:
         * "/Nor.jewel"  → "/Nor. jewel"
         * "/com.t"      → "/com. t"
         *
         * BUT leave standalone dots untouched:
         * "asst." stays "asst."
         */
        $name = preg_replace(
            '/\/([^\s\/]*\p{L})\.(\p{L}[^\s\/]*)/u',
            '/$1. $2',
            $name
        );

        return $name;
    }

    public function getFailedOrderGridLink($emailData)
    {
        if (isset($emailData['app_tag']) && !empty($emailData['app_tag'])) {
            if ($emailData['app_tag'] == "amazon_sales_channel") {
                $basePath = $this->di->getConfig()->get('apiconnector')['shopify'][$emailData['app_tag']]['app_path'] ?? "";
                $shopifyDomain = $this->getDomainName($emailData['user_id'] ?? false);
                if (!empty($shopifyDomain) && !empty($basePath)) {
                    $shopify = explode('.', $shopifyDomain);
                    return 'https://admin.shopify.com/store/' . $shopify[0] . $basePath . 'panel/user/allSales?error=true';
                }

                return false;
            }
        }

        return false;
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

    public function createOrderGridLinkAsPerProcessAndAppTag($emailData, $linkInfo, $appTag = 'default', $process = 'order')
    {
        if ($appTag == "amazon_sales_channel") {
            $shopifyDomain = $this->getDomainName($emailData['user_id'] ?? false);
            if (!empty($shopifyDomain) && !empty($linkInfo['basepath'])) {
                $shopify = explode('.', $shopifyDomain);
                if ($process == 'shipment') {
                    return ($linkInfo['basepath'] ?? "") . $shopify[0] . ($linkInfo['app-directory'] ?? "") . 'allSales?shipment_error=true&targetShopId=' . ($emailData['target_shop_id'] ?? "");
                }
                if (isset($emailData['source_order_id'])) {
                    return ($linkInfo['basepath'] ?? "") . $shopify[0] . ($linkInfo['app-directory'] ?? "") . 'allSales?orderId=' . (($emailData['source_order_id'] ?? "") ."&targetShopId=" . ($emailData['target_shop_id'] ?? ""));
                }
            }
        }

        return false;
    }

    public function handleDeletedProductError($shopifyOrderData, $connectorOrderData, &$shopifyResponse): void
    {
        if (count($shopifyOrderData['line_items']) > 1) {
            return;
        }

        $unlinkNonExistentProdConfig = $this->di->getConfig()->path('unlink_non_existent_product', false);
        if (!$unlinkNonExistentProdConfig) {
            return;
        }

        $class = $unlinkNonExistentProdConfig['class'] ?? '';
        $method = $unlinkNonExistentProdConfig['method'] ?? '';
        if (!class_exists($class) || !method_exists($class, $method)) {
            return;
        }

        $unlinkNonExistentProdObj = $this->di->getObjectManager()->get($class);

        $shopifyResponse['message'] = 'product not found';

        $connectorMarketplaceObj = $this->di->getObjectManager()->get('\App\Connector\Models\Product\Marketplace');

        $marketplaceDeleteData = [];
        foreach ($shopifyOrderData['line_items'] as $item) {
            $removeLinkParams = [
                'data' => [
                    'user_id' => $connectorOrderData['user_id'],
                    'createOrder' => true,
                    'source_product_id' => $item['variant_id']
                ],
                'source' => [
                    'shopId' => $this->shopifyShopId,
                    'marketplace' => 'shopify'
                ],
                'target' => [
                    'shopId' => $connectorOrderData['marketplace_shop_id'],
                    'marketplace' => $connectorOrderData['marketplace']
                ]
            ];
            $response = $unlinkNonExistentProdObj->$method($removeLinkParams);
            if ($response['success']) {
                $marketplaceDeleteData['deleteArray'][] = [
                    'type' => 'Not Visible Individually',
                    'source_product_id' => $item['variant_id'] ?? ''
                ];
            }
        }

        if (!empty($marketplaceDeleteData)) {
            $connectorMarketplaceObj->marketplaceDelete($marketplaceDeleteData);
        }
    }

    public function handleCustomFboNames(&$order, $orderSetting): void
    {
        if (empty($orderSetting['use_fbo_customer_id']['enabled'])) {
            return;
        }

        unset($order['order']['customer']['email']);

        $firstName = $orderSetting['fbo_order_first_name'] ?? 'Customer';
        $lastName = $orderSetting['fbo_order_last_name'] ?? 'Customer';
        $fullName = $firstName . ' ' . $lastName;

        $order['order']['customer']['name'] = $fullName;
        array_walk($order['order']['shipping_address'], function (&$shippingAddress) use ($fullName) {
            $shippingAddress['name'] = $fullName;
        });
    }

    private function updateBundleProductQuantity(&$quantityOrdered, $item, $bundleSettings, &$itemPrice = null)
    {
        $matches = [];
        $item['qty'] = $quantityOrdered;
        if (!$this->isBundleProduct($item, $bundleSettings, $matches)) {
            return;
        }

        $calculatedBundleQtyResponse = $this->calculateBundleQuantity($item, $bundleSettings, $matches);
        if ($calculatedBundleQtyResponse['success']) {
            $quantityOrdered = $calculatedBundleQtyResponse['data'];

            if (!empty($bundleSettings['adjust_price_per_unit']) && !is_null($itemPrice)) {
                $itemPrice = ($item['qty'] / $quantityOrdered) * $itemPrice;
                $itemPrice = floor($itemPrice * 100) / 100;
            }
            $productUniqueId = $this->getConvertedBundleProductUniqueIds($item);
            if (!empty($productUniqueId)) {
                $this->convertedBundleProductIds[] = $productUniqueId;
            }
        }
    }

    // private function isBundleProduct($product, $settings, &$matches)
    // {
    //     if (in_array($product['sku'], $settings['value']['allowed_bundle_sku_list'])) {
    //         return true;
    //     }

    //     // $pattern = '/(\d+)\s*x\s*(\d+)g/';(client's pattern)

    //     $pattern = $settings['value']['bundle_sku_pattern'] ?? null;
    //     if (empty($pattern)) {
    //         return false;
    //     }

    //     if (preg_match($pattern, $product['name'], $matches)) {
    //         return true;
    //     }

    //     return false;
    // }

    private function isBundleProduct($product, $bundleSettings, &$matches)
    {
        $isBundle = false;

        if (isset($product['sku'], $bundleSettings['bundle_sku_whitelist'], $bundleSettings['bundle_sku_whitelist'][$product['sku']])) {
            $isBundle = true;
        } else {
            $namePattern = $bundleSettings['bundle_name_pattern'] ?? null;
            if (!empty($namePattern) && preg_match($namePattern, $product['title'], $matches)) {
                $isBundle = true;
            }
        }

        return $isBundle;
    }

    private function calculateBundleQuantity($product, $bundleSettings, &$matches)
    {
        if (isset($product['sku'], $bundleSettings['bundle_sku_whitelist'], $bundleSettings['bundle_sku_whitelist'][$product['sku']])) {
            $bundleSize = $bundleSettings['bundle_sku_whitelist'][$product['sku']] ?? 1;
            return ['success' => true, 'data' => $product['qty'] * $bundleSize];
        }

        if (!isset($matches[1]) || !is_numeric($matches[1])) {
            return ['success' => true, 'data' => $product['qty']];
        }

        $bundleQty = $product['qty'] * $matches[1];

        $maxBundleQty = $bundleSettings['max_bundle_quantity'] ?? PHP_INT_MAX;

        if ($bundleQty > $maxBundleQty) {
            return ['success' => false, 'message' => 'Bundle quantity exceeds the allowed maximum. Bundle Qty: ' . $bundleQty . ', Max Allowed: ' . $maxBundleQty];
        }

        return ['success' => true, 'data' => $bundleQty];
    }

    public function getConvertedBundleProductUniqueIds($item)
    {
        return $item['product_identifier'] ?? $item['sku'] ?? $item['title'] ?? null;
    }

    private function getShopifyCountryForTerritory(?string $countryInput): string
    {
        $trimmedInput = trim((string)$countryInput);
        if ($trimmedInput === '') {
            return '';
        }

        // List of US Territories (names and codes) - standardized to lowercase for comparison as Shopify is giving error for on these countries
        // Includes common variations
        static $usTerritoriesLowercaseMap = [
            'puerto rico' => true,
            'pr' => true,
            'u.s. virgin islands' => true,
            'us virgin islands' => true,
            'virgin islands, u.s.' => true,
            'virgin islands' => true,
            'vi' => true,
            'guam' => true,
            'gu' => true,
            'northern mariana islands' => true,
            'mp' => true,
            'american samoa' => true,
            'as' => true,
        ];

        $normalisedInput = strtolower($trimmedInput);

        if (isset($usTerritoriesLowercaseMap[$normalisedInput])) {
            return "United States";
        }

        return $trimmedInput;
    }

    private function markIfShouldSkipFailedOrderEmail(&$orderCreateResponse, $orderData): bool
    {
        $errorMessage = strtolower($orderCreateResponse['message'] ?? '');

        $ignorePatterns = [
            'curl',
            'could not resolve host',
            'failed to connect',
            'service unavailable',
            'unavailable',
            'refresh token',
            'rate limit',
            'throttle',
        ];

        foreach ($ignorePatterns as $pattern) {
            if (
                $pattern !== '' &&
                strpos($errorMessage, $pattern) !== false &&
                $orderData['order']['source_created_at'] >= strtotime('-4 days')
            ) {
                // Mark the response so that the connector knows not to send the email
                $orderCreateResponse['skip_failed_order_email'] = true;
                return true;
            }
        }

        return false;
    }

    private function shouldAddCustomItemAttribute($hasDuplicateUniqueKey, $itemValue, $duplicateKeyConfig): bool
    {
        return $hasDuplicateUniqueKey
            && !empty($itemValue['item_link_id'])
            && !empty($duplicateKeyConfig['enabled']);
    }

    private function addCustomItemAttribute(&$itemData, $linkId, $duplicateKeyConfig): void
    {
        $property = [
            'name' => $duplicateKeyConfig['attribute_name'] ?? 'ced_item_id',
            'value' => $linkId
        ];

        if (isset($itemData['properties'])) {
            $itemData['properties'][] = $property;
        } else {
            $itemData['properties'] = [$property];
        }
    }

}
