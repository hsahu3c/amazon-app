<?php

namespace App\Connector\Models;

use App\Core\Models\Base;
use Phalcon\Mvc\Model\Message;
use Phalcon\Validation\Validator\Uniqueness;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\InclusionIn;
use Phalcon\Validation\Validator\InvalidValue;

class Refund extends Base
{
    protected $table = 'refund';

    const IS_EQUAL_TO = 1;

    const IS_NOT_EQUAL_TO = 2;

    const IS_CONTAINS = 3;

    const IS_NOT_CONTAINS = 4;

    const START_FROM = 5;

    const END_FROM = 6;

    const RANGE = 7;

    public $sqlConfig;

    public static $defaultPagination = 100;

    public static $defaultReasonsForRefund = [
                    'Product was ordered by mistake',
                    'Product delivered to me, was not same what I ordered',
                    'It\'s quality was not good',
                    'Product is not having features as promised', 'Others'
                ];

    public function initialize(): void
    {
        $this->sqlConfig = $this->di->getObjectManager()->get('\App\Connector\Components\Data');
        $this->di->getRegistry()->getDecodedToken();
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }

    /*$refundData = [
        'source_refund_id' => 1,
        'order_id' => 1,
        'merchant_id' => 171,
        'amount' => 750,
        'shipping_amount_refund' => 7,
        'comments' => 'Galti se order kar diya',
        'reason' => 'Product was ordered by mistake',
        'status' => 0,
        'refund_items' => [
            0 => [
                'source_refund_item_id' => 21,
                'item_id' => 64,
                'item_sku' => 'item_1',
                'item_title' => 'first item',
                'item_qty' => 1,
                'item_price' => 30,
                'item_total_price' => 30,
                'item_discount' => 2,
                'item_tax' => 1.5,
                'item_subtotal_price' => 29.5,
                'status' => 0
            ],
            1 => [
                'source_refund_item_id' => 31,
                'item_id' => 68,
                'item_sku' => 'item_2',
                'item_title' => 'second item',
                'item_qty' => 2,
                'item_price' => 47,
                'item_total_price' => 94,
                'item_discount' => 7,
                'item_tax' => 5.5,
                'item_subtotal_price' => 92.5,
                'status' => 0
            ]
        ]
    ];*/

    public function prepareRefundData($refundDetails)
    {
        $items = [];
        $itemIds = [];
        foreach ($refundDetails['refund_items'] as $key => $value) {
            if ($value['total_qty'] > 0) {
                $items[] = $value;
                $itemIds[] = $value['id'];
            }
        }

        if ($items !== []) {
            $orderId = $items[0]['order_id'];
            $user_db = $this->getMultipleDbManager()->getDb();
            $connection = $this->di->get($user_db);
            $refundDetails = [
                'order_id' => $orderId,
                'comments' => $refundDetails['comments'],
                'reason' => $refundDetails['reason'],
                'merchant_id' => $this->di->getUser()->id,
                'shipping_amount_refund' => $refundDetails['shipping_amount_refund'],
                'status' => 0
            ];
            $refundedShippingAmount = $refundDetails['shipping_amount_refund'];
            $getItems = 'SELECT ot.product_id as item_id, ot.sku as item_sku, ot.title as item_title, ot.qty, ot.total_price, ot.refund_qty, ot.tax_lines, ot.discount_codes FROM `order_item` as ot WHERE ot.order_id = ' . $orderId . ' AND ot.product_id IN (' . implode(',', $itemIds) . ')';
            $itemDetails = $connection->fetchAll($getItems);
            foreach ($itemDetails as $key => $value) {
                if (array_search($value['item_id'], $itemIds) !== false) {
                    $itemIndex = array_search($value['item_id'], $itemIds);
                    $itemDetails[$key]['item_qty'] = $items[$itemIndex]['total_qty'];
                    $itemDetails[$key]['item_price'] = $value['total_price'] / $value['qty'];
                    $itemDetails[$key]['item_total_price'] = $items[$itemIndex]['total_qty'] * ($value['total_price'] / $value['qty']);
                    $itemDetails[$key]['status'] = 0;
                    $itemDetails[$key]['tax_lines'] = json_decode($itemDetails[$key]['tax_lines'], true);
                    $itemDetails[$key]['discount_codes'] = json_decode($itemDetails[$key]['discount_codes'], true);
                }

                unset($itemDetails[$key]['total_price']);
                unset($itemDetails[$key]['qty']);
                unset($itemDetails[$key]['refund_qty']);
            }

            $itemDetails = $this->getItemTotalPrice($itemDetails);
            $refundDetails['amount'] = $this->getTotalRefundAmount($itemDetails) + $refundedShippingAmount;
            $refundDetails['refund_items'] = $itemDetails;
            return $this->createRefund($refundDetails);
        }
        return ['success' => false, 'message' => 'No items selected for refund'];
    }

    public function createRefund($refundDetails)
    {
        $itemDetails = $refundDetails['refund_items'];
        $orderId = $refundDetails['order_id'];
        unset($refundDetails['refund_items']);
        $refundQtys = $this->getAlreadyRefundedQtys($orderId);

        $user_db = $this->getMultipleDbManager()->getDb();
        $connection = $this->di->get($user_db);

        $refundModel = new Refund();
        $refundModel->set($refundDetails);

        $refundStatus = $refundModel->save();
        if ($refundStatus) {
            $permittedKeys = ['refund_id', 'item_id', 'item_sku', 'item_title', 'item_qty', 'item_price', 'item_total_price', 'item_discount', 'item_tax', 'item_subtotal_price', 'status'];
            $count = 1;
            $updateQry = '';
            $values = '';
            $refundItemIds = [];
            foreach ($itemDetails as $item) {
                $end = $count == count($itemDetails) ? '' : ', ';
                $values .= "(" . $refundModel->id . ", " . $item['item_id'] . ", '" . $item['item_sku'] . "', '". $item['item_title'] . "', " . $item['item_qty'] . ", ". $item['item_price'] . ", ". $item['item_total_price'] . ", ". $item['item_discount'] . ", ". $item['item_tax'] . ", ". $item['item_subtotal_price'] . ", " . $item['status'] . ")" . $end;
                $updateQry .= "when product_id = " . $item['item_id'] . " then " . ($refundQtys[$item['item_id']] + $item['item_qty']) . " ";
                $refundItemIds[] = $item['item_id'];
                $count++;
            }

            $updateQuery = "UPDATE `order_item`
                                SET `refund_qty` = (case " . $updateQry . "
                                                end)
                                WHERE product_id in (" . implode(',', $refundItemIds) . ") AND order_id = " . $orderId;
            try {
                $insertQuery = "INSERT INTO refund_item (" . implode(',', $permittedKeys) . ") VALUES " . $values;
                $connection->query($insertQuery);
                $connection->query($updateQuery);
                return ['success' => true, 'message' => 'Refund Created Successfully'];
            } catch (\Exception $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        } else {
            $errors = '';
            foreach ($refundModel->getMessages() as $value) {
                $errors .= $value;
            }

            return ['success' => false, 'message' => $errors];
        }
    }

    public function getAlreadyRefundedQtys($orderId)
    {
        $refundedQtys = [];
        $orderItems = \App\Connector\Models\Order\Item::find(
            [
                    "order_id='{$orderId}'",
                    'column' => 'refund_qty, product_id'
                ]
        )->toArray();
        foreach ($orderItems as $value) {
            $refundedQtys[$value['product_id']] = $value['refund_qty'];
        }

        return $refundedQtys;
    }

    public function getTotalRefundAmount($refundItems)
    {
        $totalRefund = 0;
        foreach ($refundItems as $value) {
            $totalRefund += $value['item_subtotal_price'];
        }

        return $totalRefund;
    }

    public function getItemTotalPrice($refundItems)
    {
        foreach ($refundItems as $key => $value) {
            $refundItems[$key]['item_discount'] = $this->getDiscountOfItem($value['discount_codes'], $value['item_total_price']);
            $refundItems[$key]['item_tax'] = $this->getTaxOfItem($value['tax_lines'], $value['item_total_price'], $refundItems[$key]['item_discount']);
            $refundItems[$key]['item_subtotal_price'] = $refundItems[$key]['item_total_price'] - $refundItems[$key]['item_discount'] + $refundItems[$key]['item_tax'];
            unset($refundItems[$key]['discount_codes']);
            unset($refundItems[$key]['tax_lines']);
        }

        return $refundItems;
    }

    public function getDiscountOfItem($discountCodes, $totalPrice)
    {
        $totalDiscount = 0;
        foreach ($discountCodes as $singleDiscount) {
            if ($singleDiscount['type'] == 'percentage') {
                $totalDiscount += $singleDiscount['amount'] * $totalPrice;
            } elseif ($singleDiscount['type'] == 'fixed') {
                $totalDiscount += $singleDiscount['amount'];
            }
        }

        return $totalDiscount;
    }

    public function getTaxOfItem($taxLines, $totalPrice, $totalDiscount)
    {
        $subtotalPrice = $totalPrice - $totalDiscount;
        $total_tax = 0;
        foreach ($taxLines as $singleTax) {
            $total_tax += $singleTax['rate'] * $subtotalPrice;
        }

        return $total_tax;
    }

    public function getRefundByOrderId($orderDetails)
    {
        $orderId = $orderDetails['id'];
        $refunds = Refund::find(["order_id='{$orderId}'"]);
        if ($refunds && count($refunds)) {
            return [
                        'success' => true,
                        'messages' => 'All refunds',
                        'data' =>
                                [
                                    'reasons_for_refund' => self::$defaultReasonsForRefund,
                                    'refund_items' => $refunds
                                ]
                    ];
        }
        return [
                    'success' => true,
                    'messages' => 'No refunds found',
                    'data' =>
                            [
                                'reasons_for_refund' => self::$defaultReasonsForRefund,
                                'refund_items' => []
                            ]
                ];
    }

    public static function search($filterParams = [], $fullTextSearchColumns = null)
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim($key);
                if (array_key_exists(Refund::IS_EQUAL_TO, $value)) {
                    $conditions[] = "`" . $key . "` = '" . trim(addslashes($value[Refund::IS_EQUAL_TO])) . "'";
                } elseif (array_key_exists(Refund::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[] = "`" . $key . "` != '" . trim(addslashes($value[Refund::IS_NOT_EQUAL_TO])) . "'";
                } elseif (array_key_exists(Refund::IS_CONTAINS, $value)) {
                    $conditions[] = "`" . $key . "` LIKE '%" . trim(addslashes($value[Refund::IS_CONTAINS])) . "%'";
                } elseif (array_key_exists(Refund::IS_NOT_CONTAINS, $value)) {
                    $conditions[] = "`" . $key . "` NOT LIKE '%" . trim(addslashes($value[Refund::IS_NOT_CONTAINS])) . "%'";
                } elseif (array_key_exists(Refund::START_FROM, $value)) {
                    $conditions[] = "`" . $key . "` LIKE '" . trim(addslashes($value[Refund::START_FROM])) . "%'";
                } elseif (array_key_exists(Refund::END_FROM, $value)) {
                    $conditions[] = "`" . $key . "` LIKE '%" . trim(addslashes($value[Refund::END_FROM])) . "'";
                } elseif (array_key_exists(Refund::RANGE, $value)) {
                    if (trim($value[Refund::RANGE]['from']) && !trim($value[Refund::RANGE]['to'])) {
                        $conditions[] = "`" . $key . "` >= '" . $value[Refund::RANGE]['from'] . "'";
                    } elseif (trim($value[Refund::RANGE]['to']) && !trim($value[Refund::RANGE]['from'])) {
                        $conditions[] = "`" . $key . "` >= '" . $value[Refund::RANGE]['to'] . "'";
                    } else {
                        $conditions[] = "`" . $key . "` between '" . $value[Refund::RANGE]['from'] . "' AND '" . $value[Refund::RANGE]['to'] . "'";
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

    public function getRefunds($params)
    {
        $limit = $params['count'] ?? $this->defaultPagination;
        $page = $params['activePage'] ?? 1;
        $userId = $this->di->getUser()->id;
        $offset = ($page - 1) * $limit;
        $query = "SELECT * FROM `refund` WHERE `merchant_id` = '{$userId}' AND ";
        $conditionalQuery = "";
        if (isset($params['filter']) || isset($params['search'])) {
            $fullTextSearchColumns = "";
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
                $countRecords = $this->sqlConfig->sqlRecords("SELECT COUNT(*) as `count` FROM `refund` WHERE " . $conditionalQuery . " LIMIT 0,1", "one");
            } else {
                $countRecords = $this->sqlConfig->sqlRecords("SELECT COUNT(*) as `count` FROM `refund` LIMIT 0,1", "one");
            }

            if (isset($countRecords['count'])) {
                $responseData['data']['count'] = $countRecords['count'];
            }
        }

        return $responseData;
    }

    public function viewRefund($refundDetails)
    {
        $refundId = $refundDetails['id'];
        $refundDetailsQuery = 'SELECT rf.order_id, o.client_details, o.currency, rf.id as refund_id, rf.reason, rf.comments , rf.amount, o.created_at as order_created_at, rf.created_at as refund_created_at FROM `refund` as rf INNER JOIN `order` as o ON rf.id = ' . $refundId . ' AND o.id = rf.order_id';
        $refundItemQuery = 'SELECT rft.item_sku as sku, rft.item_title as title, rft.item_qty, rft.item_total_price, rft.item_discount, rft.item_tax, rft.item_subtotal_price FROM `refund_item` as rft WHERE rft.refund_id = ' . $refundId . '';
        $user_db = $this->getMultipleDbManager()->getDb();
        $connection = $this->di->get($user_db);
        $refundDetails = $connection->fetchAll($refundDetailsQuery);
        if (count($refundDetails) > 0) {
            $refundDetails = $refundDetails[0];
            $refundDetails['client_details'] = json_decode($refundDetails['client_details'], true);
            $refundDetails['refund_items'] = $connection->fetchAll($refundItemQuery);
            $finalHtml = $this->prepareHtmlOfPdfForRefund($refundDetails);
            $refundFileName = 'refund_file_' . $this->di->getUser()->id . '_' . time();
            if (!file_exists(BP . DS . 'var' . DS . 'refund' . DS . 'temp')) {
                $oldmask = umask(0);
                mkdir(BP . DS . 'var' . DS . 'refund' . DS . 'temp', 0777, true);
                umask($oldmask);
            }

            $filePath = BP . DS . 'var' . DS . 'refund' . DS . 'temp' . DS . $refundFileName . '.php';
            $handle = fopen($filePath, 'w+');
            fwrite($handle, '<?php return ' . var_export($finalHtml, true) . ';');
            fclose($handle);

            $date1 = new \DateTime('+15 min');
            $refundFileDetails = [
                'user_id' => $this->di->getUser()->id,
                'fileName' => $refundFileName,
                'exp' => $date1->getTimestamp()
            ];
            $token = $this->di->getObjectManager()->get('App\Core\Components\Helper')->getJwtToken($refundFileDetails, 'RS256', false);
            return ['success' => true, 'message' => 'Refund filename', 'data' => $token];
        }
        return ['success' => false, 'message' => 'Refund with this id not present'];
    }

    public function prepareHtmlOfPdfForRefund($refundContent)
    {
        $refundItemHtml = '';
        $clientDetailHtml = '';
        $refundIdHtml  = '';
        $refundAmountHtml = '';
        $refundReasons = '';

        $refundIdHtml .= '
          <div>
              <table style="width: 100%; display: inline-block; margin-top: 20px; margin-bottom: 20px; color: white;">
                <tr>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>Refund Id</span>
                  </td>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>' . $refundContent["refund_id"] . '</span>
                  </td>
                </tr>
                <tr>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>Refund Date</span>
                  </td>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>' . $refundContent["refund_created_at"] . '</span>
                  </td>
                </tr>
                <tr>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>Order Id</span>
                  </td>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>' . $refundContent["order_id"] . '</span>
                  </td>
                </tr>
                <tr>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>Order Date</span>
                  </td>
                  <td style="height: 20px; font-size: 13px; padding-left: 5px;">
                    <span>' . $refundContent["order_created_at"] . '</span>
                  </td>
                </tr>
              </table>
          </div>
        ';
        $clientDetailHtml .= '
          <div>
              <h1>Client Details</h1>
              <table style="width: 100%; display: inline-block; margin-top: 20px; margin-bottom: 40px;">
                <tr style="background-color: #e3e3e3;">
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    <span>Customer Email</span>
                  </td>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    ' . $refundContent["client_details"]["contact_email"] . '
                  </td>
                </tr>
                <tr>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    <span>Shipping Address</span>
                  </td>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    ' . $refundContent["client_details"]["shipping_address"] . '
                  </td>
                </tr>
              </table>
          </div>
        ';

        $refundReasons .= '
            <div>
              <h1>Refund Generalisation</h1>
              <table style="width: 100%; display: inline-block; margin-top: 20px; margin-bottom: 20px;">
                <tr style="background-color: #e3e3e3;">
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    <span>Refund Reason</span>
                  </td>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    ' . $refundContent["reason"] . '
                  </td>
                </tr>
                <tr>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    <span>Comment</span>
                  </td>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    ' . $refundContent["comments"] . '
                  </td>
                </tr>
              </table>
          </div>
        ';

        $refundSubtotalAmount = 0;

        $itemTrHtml = '';
        $count = 1;
        foreach ($refundContent['refund_items'] as $value) {
            $trStyle = '';
            if (($count % 2) === 0) {
                $trStyle = 'style="background-color: #e3e3e3;"';
            }

            $refundSubtotalAmount += ($value['item_total_price'] - $value['item_discount']);
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
                      <span>' . $value["item_total_price"] . '</span>
                    </td>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["item_discount"] . '</span>
                    </td>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["item_tax"] . '</span>
                    </td>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["item_subtotal_price"] . '</span>
                    </td>
                  </tr>
            ';
            $count++;
        }

        $refundItemHtml .= '
            <h4>Refund Items</h4>
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
                  <span>Total Price</span>
                </td>
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>Discount</span>
                </td>
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>Tax</span>
                </td>
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>Subtotal Price</span>
                </td>
              </tr>
              ' . $itemTrHtml . '
            </table>
        ';

        $refundAmountHtml .= '
          <div>
              <h1>Refund Details</h1>
              <table style="width: 100%; display: inline-block; margin-top: 20px; margin-bottom: 20px;">
                <tr style="background-color: #e3e3e3;">
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    <span>Subtotal</span>
                  </td>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    ' . $refundSubtotalAmount . '
                  </td>
                </tr>
                <tr>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    <span>Total Tax</span>
                  </td>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    ' . ($refundContent["amount"] - $refundSubtotalAmount) . '
                  </td>
                </tr>
                <tr style="background-color: #e3e3e3;">
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    <span style="font-weight: bold;">Grand Total</span>
                  </td>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    <span style="font-weight: bold;">' . $refundContent["amount"] . '  ' . $refundContent["currency"] . '</span>
                  </td>
                </tr>
              </table>
          </div>
        ';

        $finalHtml = '
            <div style="height: 60px; background-color: #808080; color: white; text-align: center; margin-bottom: 15px;">
              <table>
                <tr>
                  <td>
                    ' . $refundIdHtml . '
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
                      ' . $refundAmountHtml . '
                    </td>
                  </tr>
                </table>
            </div>
            <div>
                <table style="width: 100%; display: block;">
                  <tr>
                    <td style="width: 100%; height: 220px;">
                      ' . $refundReasons . '
                    </td>
                    <td style="width: 100%; height: 220px;">

                    </td>
                  </tr>
                </table>
            </div>
            <div>
                ' . $refundItemHtml . '
            </div>
        ';

        return $finalHtml;
    }
}
