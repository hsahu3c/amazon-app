<?php

namespace App\Connector\Models;

use App\Core\Models\Base;
use Phalcon\Mvc\Model\Message;
use Phalcon\Validation\Validator\Uniqueness;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\InclusionIn;
use Phalcon\Validation\Validator\InvalidValue;

class Shipment extends Base
{

    protected $table = 'shipment';

    const IS_EQUAL_TO = 1;

    const IS_NOT_EQUAL_TO = 2;

    const IS_CONTAINS = 3;

    const IS_NOT_CONTAINS = 4;

    const START_FROM = 5;

    const END_FROM = 6;

    const RANGE = 7;

    public $sqlConfig;

    public static $defaultPagination = 100;

    public function initialize(): void
    {
        $this->sqlConfig = $this->di->getObjectManager()->get('\App\Connector\Components\Data');
        $this->di->getRegistry()->getDecodedToken();
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }

    public static function search($filterParams = [], $fullTextSearchColumns = null)
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim($key);
                if (array_key_exists(Shipment::IS_EQUAL_TO, $value)) {
                    $conditions[] = "`" . $key . "` = '" . trim(addslashes($value[Shipment::IS_EQUAL_TO])) . "'";
                } elseif (array_key_exists(Shipment::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[] = "`" . $key . "` != '" . trim(addslashes($value[Shipment::IS_NOT_EQUAL_TO])) . "'";
                } elseif (array_key_exists(Shipment::IS_CONTAINS, $value)) {
                    $conditions[] = "`" . $key . "` LIKE '%" . trim(addslashes($value[Shipment::IS_CONTAINS])) . "%'";
                } elseif (array_key_exists(Shipment::IS_NOT_CONTAINS, $value)) {
                    $conditions[] = "`" . $key . "` NOT LIKE '%" . trim(addslashes($value[Shipment::IS_NOT_CONTAINS])) . "%'";
                } elseif (array_key_exists(Shipment::START_FROM, $value)) {
                    $conditions[] = "`" . $key . "` LIKE '" . trim(addslashes($value[Shipment::START_FROM])) . "%'";
                } elseif (array_key_exists(Shipment::END_FROM, $value)) {
                    $conditions[] = "`" . $key . "` LIKE '%" . trim(addslashes($value[Shipment::END_FROM])) . "'";
                } elseif (array_key_exists(Shipment::RANGE, $value)) {
                    if (trim($value[Shipment::RANGE]['from']) && !trim($value[Shipment::RANGE]['to'])) {
                        $conditions[] = "`" . $key . "` >= '" . $value[Shipment::RANGE]['from'] . "'";
                    } elseif (trim($value[Shipment::RANGE]['to']) && !trim($value[Shipment::RANGE]['from'])) {
                        $conditions[] = "`" . $key . "` >= '" . $value[Shipment::RANGE]['to'] . "'";
                    } else {
                        $conditions[] = "`" . $key . "` between '" . $value[Shipment::RANGE]['from'] . "' AND '" . $value[Shipment::RANGE]['to'] . "'";
                    }
                }
            }
        }

        if (isset($filterParams['search']) && $fullTextSearchColumns) {
            $conditions[] = "MATCH(" . $fullTextSearchColumns . ") AGAINST ('" . trim(addslashes($filterParams['search'])) . "' IN NATURAL LANGUAGE MODE)";
        }

        $conditionalQuery = "";
        if (is_array($conditions) && count($conditions)) {
            $conditionalQuery = implode(' AND ', $conditions);
        }

        return $conditionalQuery;
    }

    public function getShipments($params)
    {
        $limit = $params['count'] ?? $this->defaultPagination;
        $page = $params['activePage'] ?? 1;
        $userId = $this->di->getUser()->id;
        $offset = ($page - 1) * $limit;
        $query = "SELECT * FROM `shipment` WHERE `merchant_id` = '{$userId}' AND ";
        $conditionalQuery = "";
        if (isset($params['filter']) || isset($params['search'])) {
            $fullTextSearchColumns = "`tracking_url`, `tracking_number`";
            $conditionalQuery = $this->search($params, $fullTextSearchColumns);
        }

        if ($conditionalQuery) {
            $query .= $conditionalQuery;
        } else {
            $query .= 1;
        }

        $query .= " LIMIT " . $limit . " OFFSET " . $offset;
        $responseData = [];
        $collection = $this->sqlConfig->sqlRecords($query, "all");
        $responseData['success'] = true;
        $responseData['data']['count'] = 0;
        $responseData['data']['rows'] = [];
        if (is_array($collection) && count($collection)) {
            $responseData['data']['rows'] = $collection;
            if ($conditionalQuery) {
                $countRecords = $this->sqlConfig->sqlRecords("SELECT COUNT(*) as `count` FROM `shipment` WHERE " . $conditionalQuery . " LIMIT 0,1", "one");
            } else {
                $countRecords = $this->sqlConfig->sqlRecords("SELECT COUNT(*) as `count` FROM `shipment` LIMIT 0,1", "one");
            }

            if (isset($countRecords['count'])) {
                $responseData['data']['count'] = $countRecords['count'];
            }
        }

        return $responseData;
    }

    public function getShipmentByOrderId($orderDetails)
    {
        $orderId = $orderDetails['id'];
        $shipments = Shipment::find(["order_id='{$orderId}'"]);
        if ($shipments && count($shipments)) {
            return ['success' => true, 'messages' => 'All shipments', 'data' => $shipments->toArray()];
        }
        return ['success' => true, 'messages' => 'No shipments found', 'data' => []];
    }

    /*$shipmentData = [
        'order_id' => 1,
        'merchant_id' => 171,
        'tracking_number' => 'DX2990P',
        'tracking_url' => 'cedcommerce.com',
        'total_qty' => 21,
        'shipment_items' => [
            0 => [
                'source_shipment_item_id' => 11,
                'item_id' => 45,
                'order_id' => 1,
                'item_qty' => 3,
                'item_price' => 55,
                'item_total_price' => 165,
                'item_total_tax' => 15.5,
                'item_total_discounts' => 20,
                'item_subtotal_price' => 160.5,
                'status' => 0
            ],
            1 => [
                'source_shipment_item_id' => 16,
                'item_id' => 46,
                'order_id' => 1,
                'item_qty' => 2,
                'item_price' => 10,
                'item_total_price' => 20,
                'item_total_tax' => 3.5,
                'item_total_discounts' => 5,
                'item_subtotal_price' => 18.5,
                'status' => 0
            ]
        ]
    ];*/

    public function prepareShipmentData($shipmentData)
    {
        $items = [];
        $itemIds = [];
        $userId = $this->di->getUser()->id;
        foreach ($shipmentData['shipmentData'] as $value) {
            if ($value['total_qty'] > 0) {
                $items[] = $value;
                $itemIds[] = $value['id'];
            }
        }

        if ($items !== []) {
            $user_db = $this->getMultipleDbManager()->getDb();
            $connection = $this->di->get($user_db);
            $orderId = $items[0]['order_id'];
            $shipmentDetails = [
                'order_id' => $orderId,
                'merchant_id' => $userId,
                'tracking_number' => $shipmentData['trackingNo'],
                'tracking_url' => $shipmentData['trackingUrl']
            ];
            $getItemDetailsQuery = 'SELECT ot.discount_codes, ot.tax_lines, ot.qty, ot.total_price, ot.product_id FROM `order_item` as ot WHERE ot.order_id = ' . $orderId . ' AND product_id IN (' . implode(',', $itemIds) . ')';
            $allItemDetails = $connection->fetchAll($getItemDetailsQuery);
            foreach ($allItemDetails as $singleItem) {
                if (array_search($singleItem['product_id'], $itemIds) !== false) {
                    $itemIndex = array_search($singleItem['product_id'], $itemIds);
                    $items[$itemIndex]['unit_price'] = $singleItem['total_price'] / $singleItem['qty'];
                    $items[$itemIndex]['tax_lines'] = $singleItem['tax_lines'];
                    $items[$itemIndex]['discount_codes'] = $singleItem['discount_codes'];
                }
            }

            $shipmentItemData = $this->getShipmentItems($items, $orderId);
            $shipmentItems = $shipmentItemData['shipped_items'];
            $shipmentDetails['total_qty'] = $shipmentItemData['total_qty_shipped'];
            $shipmentDetails['shipment_items'] = $shipmentItems;
            return $this->createShipment($shipmentDetails);
        }
        return ['success' => false, 'message' => 'Select items to ship'];
    }

    public function createShipment($shipmentData)
    {
        $shipmentItems = $shipmentData['shipment_items'];
        $orderId = $shipmentData['order_id'];
        unset($shipmentData['shipment_items']);
        $shippedQtys = $this->getAlreadyShippedQtys($orderId);
        $shipmentModel = new Shipment();
        $shipmentModel->set($shipmentData);

        $shipmentStatus = $shipmentModel->save();
        if ($shipmentStatus) {
            $user_db = $this->getMultipleDbManager()->getDb();
            $connection = $this->di->get($user_db);
            $permittedKeys = ['shipment_id', 'item_id', 'order_id', 'item_qty', 'item_price', 'item_total_price', 'item_total_tax', 'item_total_discounts', 'item_subtotal_price', 'status'];
            $count = 1;
            $updateQry = '';
            $values = '';
            $shippedItemIds = [];
            foreach ($shipmentItems as $item) {
                $end = $count == count($shipmentItems) ? '' : ', ';
                $values .= "(" . $shipmentModel->id . ", " . $item['item_id'] . ", " . $item['order_id'] . ", ". $item['item_qty'] . ", " . $item['item_price'] . ", ". $item['item_total_price'] . ", ". $item['item_total_tax'] . ", " . $item['item_total_discounts'] . ", ". $item['item_subtotal_price'] . ", ". $item['status'] . ")" . $end;
                $updateQry .= "when product_id = " . $item['item_id'] . " then " . ($shippedQtys[$item['item_id']] + $item['item_qty']) . " ";
                $shippedItemIds[] = $item['item_id'];
                $count++;
            }

            $updateQuery = "UPDATE `order_item`
                                SET `shipped_qty` = (case " . $updateQry . "
                                                end)
                                WHERE product_id in (" . implode(',', $shippedItemIds) . ") AND order_id = " . $orderId;
            try {
                $insertQuery = "INSERT INTO shipment_item (" . implode(',', $permittedKeys) . ") VALUES " . $values;
                $connection->query($insertQuery);
                $connection->query($updateQuery);
                return ['success' => true, 'message' => 'Shipment Created Successfully'];
            } catch (\Exception $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        } else {
            $errors = '';
            foreach ($shipmentModel->getMessages() as $value) {
                $errors .= ' ' . $value;
            }

            return ['success' => false, 'message' => $errors];
        }
    }

    public function getAlreadyShippedQtys($orderId)
    {
        $shippedQtys = [];
        $orderItems = \App\Connector\Models\Order\Item::find(
            [
                    "order_id='{$orderId}'",
                    'column' => 'shipped_qty, product_id'
                ]
        )->toArray();
        foreach ($orderItems as $value) {
            $shippedQtys[$value['product_id']] = $value['shipped_qty'];
        }

        return $shippedQtys;
    }

    public function viewShipment($shipmentDetails)
    {
        $shipmentId = $shipmentDetails['id'];
        $user_db = $this->getMultipleDbManager()->getDb();
        $connection = $this->di->get($user_db);
        $shipmentDetailQuery = 'SELECT sh.id as shipment_id, o.id as order_id, o.created_at as order_created_at, sh.created_at as shipment_created_at, o.client_details, o.total_shipment_amount, o.currency FROM `shipment` as sh INNER JOIN `order` as o ON sh.id = ' . $shipmentId . ' AND sh.order_id = o.id';
        $shipmentItemQuery = 'SELECT DISTINCT oi.sku, oi.title, shi.item_qty, oi.fulfillment_service FROM `shipment_item` as shi INNER JOIN `order_item` as oi ON shi.shipment_id = ' . $shipmentId . ' AND oi.order_id = shi.order_id AND oi.product_id = shi.item_id';
        $shipmentDetail = $connection->fetchAll($shipmentDetailQuery);
        $shipmentItem = $connection->fetchAll($shipmentItemQuery);
        if (count($shipmentDetail) > 0 && count($shipmentItem) > 0) {
            $shipmentDetail = $shipmentDetail[0];
            $shipmentDetail['shipment_items'] = $shipmentItem;
            $shipmentDetail['client_details'] = json_decode($shipmentDetail['client_details'], true);
            $finalHtml = $this->prepareHtmlOfShipmentForPdf($shipmentDetail);
            $shipmentFileName = 'shipment_file_' . $this->di->getUser()->id . '_' . time();
            if (!file_exists(BP . DS . 'var' . DS . 'shipment' . DS . 'temp')) {
                $oldmask = umask(0);
                mkdir(BP . DS . 'var' . DS . 'shipment' . DS . 'temp', 0777, true);
                umask($oldmask);
            }

            $filePath = BP . DS . 'var' . DS . 'shipment' . DS . 'temp' . DS . $shipmentFileName . '.php';
            $handle = fopen($filePath, 'w+');
            fwrite($handle, '<?php return ' . var_export($finalHtml, true) . ';');
            fclose($handle);

            $date1 = new \DateTime('+15 min');
            $shipmentFileDetails = [
                'user_id' => $this->di->getUser()->id,
                'fileName' => $shipmentFileName,
                'exp' => $date1->getTimestamp()
            ];
            $token = $this->di->getObjectManager()->get('App\Core\Components\Helper')->getJwtToken($shipmentFileDetails, 'RS256', false);
            return ['success' => true, 'message' => 'Shipment filename', 'data' => $token];
        }
        return ['success' => false, 'message' => 'Invalid shipment id'];
    }

    public function prepareHtmlOfShipmentForPdf($shipmentContent)
    {
        $shipmentItemHtml = '';
        $clientDetailHtml = '';
        $shipmentIdHtml  = '';

        $shipmentIdHtml .= '
          <div>
              <table style="width: 100%; display: inline-block; margin-top: 20px; margin-bottom: 20px; color: white;">
                <tr>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>Shipment Id</span>
                  </td>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>' . $shipmentContent["shipment_id"] . '</span>
                  </td>
                </tr>
                <tr>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>Shipment Date</span>
                  </td>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>' . $shipmentContent["shipment_created_at"] . '</span>
                  </td>
                </tr>
                <tr>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>Order Id</span>
                  </td>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>' . $shipmentContent["order_id"] . '</span>
                  </td>
                </tr>
                <tr>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>Order Date</span>
                  </td>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>' . $shipmentContent["order_created_at"] . '</span>
                  </td>
                </tr>
              </table>
          </div>
        ';
        $clientDetailHtml .= '
          <div>
              <h1>Client Details</h1>
              <table style="width: 100%; display: inline-block; margin-top: 20px; margin-bottom: 20px;">
                <tr style="background-color: #e3e3e3;">
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    <span>Customer Email</span>
                  </td>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    ' . $shipmentContent["client_details"]["contact_email"] . '
                  </td>
                </tr>
                <tr>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    <span>Shipping Address</span>
                  </td>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    ' . $shipmentContent["client_details"]["shipping_address"] . '
                  </td>
                </tr>
              </table>
          </div>
        ';

        $itemTrHtml = '';
        $count = 1;
        foreach ($shipmentContent['shipment_items'] as $value) {
            $trStyle = '';
            if (($count % 2) === 0) {
                $trStyle = 'style="background-color: #e3e3e3;"';
            }

            $itemTrHtml .= '
                <tr ' . $trStyle . '>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["item_qty"] . '</span>
                    </td>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["title"] . '</span>
                    </td>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["sku"] . '</span>
                    </td>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["fulfillment_service"] . '</span>
                    </td>
                  </tr>
            ';
            $count++;
        }

        $shipmentItemHtml .= '
            <h4>Shipment Items</h4>
            <table style="width: 100%; display: block;">
              <tr style="background-color: #e3e3e3;">
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>Qty</span>
                </td>
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>Title</span>
                </td>
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>SKU</span>
                </td>
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>Shipping Method</span>
                </td>
              </tr>
              ' . $itemTrHtml . '
            </table>
        ';

        $finalHtml = '
            <div style="height: 60px; background-color: #808080; color: white; text-align: center; margin-bottom: 15px;">
              <table>
                <tr>
                  <td>
                    ' . $shipmentIdHtml . '
                  </td>
                  <td>
                  </td>
                </tr>
              </table>
            </div>
            <div>
                <table style="width: 100%; display: block;">
                  <tr>
                    <td style="width: 100%; height: 220px;">
                      ' . $clientDetailHtml . '
                    </td>
                    <td style="width: 100%; height: 220px;">

                    </td>
                  </tr>
                </table>
            </div>
            <div>
                ' . $shipmentItemHtml . '
            </div>
        ';

        return $finalHtml;
    }

    public function getShipmentItems($shipmentItems, $orderId)
    {
        $shippedItems = [];
        $totalQty = 0;
        foreach ($shipmentItems as $key => $singleItem) {
            $discountAndTaxAmount = $this->getDiscountAndTaxAmount($singleItem);
            $shippedItems[$key]['item_id'] = $singleItem['id'];
            $shippedItems[$key]['order_id'] = $orderId;
            $shippedItems[$key]['item_qty'] = $singleItem['total_qty'];
            $shippedItems[$key]['item_price'] = $singleItem['unit_price'];
            $shippedItems[$key]['item_total_price'] = $singleItem['unit_price'] * $singleItem['total_qty'];
            $shippedItems[$key]['item_total_tax'] = $discountAndTaxAmount['total_tax'];
            $shippedItems[$key]['item_subtotal_price'] = $discountAndTaxAmount['subtotal_price'];
            $shippedItems[$key]['item_total_discounts'] = $discountAndTaxAmount['total_discount'];
            $shippedItems[$key]['status'] = 0;
            $totalQty += $singleItem['total_qty'];
        }

        return [
            'shipped_items' => $shippedItems,
            'total_qty_shipped' => $totalQty
        ];
    }

    public function getDiscountAndTaxAmount($singleItem)
    {
        $itemTotalPrice = $singleItem['unit_price'] * $singleItem['total_qty'];
        $itemSubtotalPrice = $singleItem['unit_price'] * $singleItem['total_qty'];
        $productModel = new \App\Connector\Models\Product();
        if ($productModel->getJson($singleItem['discount_codes']) !== false) {
            $discountCodes = $productModel->getJson($singleItem['discount_codes']);
        } else {
            $discountCodes = [];
        }

        if ($productModel->getJson($singleItem['tax_lines']) !== false) {
            $taxLines = $productModel->getJson($singleItem['tax_lines']);
        } else {
            $taxLines = [];
        }

        $totalDiscount = 0;
        $totalTax = 0;
        foreach ($discountCodes as $key => $singleDiscount) {
            if ($singleDiscount['type'] == 'percentage') {
                $totalDiscount += $singleDiscount['amount'] * $itemTotalPrice;
            } elseif ($singleDiscount['type'] == 'fixed') {
                $totalDiscount += $singleDiscount['amount'];
            }
        }

        $itemSubtotalPrice = $itemSubtotalPrice - $totalDiscount;
        foreach ($taxLines as $singleTax) {
            $totalTax += $singleTax['rate'] * $itemSubtotalPrice;
        }

        $itemSubtotalPrice = $itemSubtotalPrice + $totalTax;
        return [
                    'subtotal_price' => $itemSubtotalPrice,
                    'total_discount' => $totalDiscount,
                    'total_tax' => $totalTax
                ];
    }
}
