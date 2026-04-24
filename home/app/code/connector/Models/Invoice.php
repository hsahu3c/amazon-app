<?php

namespace App\Connector\Models;

use App\Core\Models\Base;
use Phalcon\Mvc\Model\Message;
use Phalcon\Validation\Validator\Uniqueness;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\InclusionIn;
use Phalcon\Validation\Validator\InvalidValue;

class Invoice extends Base
{
    protected $table = 'invoice';

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

    /*$invoiceData = [
        'order_id' => 1,
        'merchant_id' => 1,
        'source_invoice_id' => 1,
        'total_price' => 123,
        'tax_amount' => 12,
        'discount_amount' => 8,
        'subtotal_price' => 127,
        'shipping_amount' => 6,
        'grand_total' => 133,
        'invoice_items' => [
            0 => [
                'invoice_item_id' => 65,
                'invoice_item_price' => 110,
                'invoice_item_qty' => 2,
                'invoice_item_total_price' => 220,
                'invoice_item_total_discounts' => 25,
                'invoice_item_total_tax' => 15,
                'invoice_item_shipping_amount' => 5,
                'invoice_item_subtotal_price' => 210
            ],
            1 => [
                'invoice_item_id' => 66,
                'invoice_item_price' => 25,
                'invoice_item_qty' => 1,
                'invoice_item_total_price' => 25,
                'invoice_item_total_discounts' => 2,
                'invoice_item_total_tax' => 1,
                'invoice_item_shipping_amount' => 3,
                'invoice_item_subtotal_price' => 24
            ]
        ]
    ];*/

    public function prepareInvoiceData($invoiceData, $shippingAmountPaid)
    {
        $items = [];
        $itemIds = [];
        foreach ($invoiceData as $key => $value) {
            if ($value['total_qty'] > 0) {
                $items[] = $value;
                $itemIds[] = $value['id'];
            }
        }

        if ($items !== []) {
            $orderId = $invoiceData[0]['order_id'];
            $user_db = $this->getMultipleDbManager()->getDb();
            $connection = $this->di->get($user_db);
            $getItems = 'SELECT ot.product_id, ot.merchant_id, ot.sku, ot.title, ot.shipping_amount, ot.qty, ot.currency, ot.total_price, ot.discount_codes, ot.tax_lines, ot.invoiced_qty FROM `order_item` as ot INNER JOIN `order` as o ON ot.product_id IN (' . implode(',', $itemIds) . ') AND o.id = ' . $orderId . ' AND o.id = ot.order_id';
            $itemDetails = $connection->fetchAll($getItems);
            $invoicedQtys = [];
            foreach ($itemDetails as $key => $value) {
                if (array_search($value['product_id'], $itemIds) !== false) {
                    $itemIndex = array_search($value['product_id'], $itemIds);
                    $itemDetails[$key]['invoice_item_qty'] = $items[$itemIndex]['total_qty'];
                }

                $invoicedQtys[$value['product_id']] = $value['invoiced_qty'];
                $itemDetails[$key]['discount_codes'] = json_decode($value['discount_codes'], true);
                $itemDetails[$key]['tax_lines'] = json_decode($value['tax_lines'], true);
            }

            $invoiceItems = $this->getTotalDiscountAndTax($itemDetails);
            $invoiceItems = $this->getFormattedInvoiceItems($invoiceItems);
            $invoiceFields = $this->getInvoiceData($invoiceItems, $itemDetails[0]['merchant_id'], $orderId, $shippingAmountPaid);
            $invoiceFields['invoice_items'] = $invoiceItems;
            return $this->createInvoice($invoiceFields);
        }
        return ['success' => false, 'message' => 'No items selected to generate invoice'];
    }

    public function createInvoice($invoiceData)
    {
        $invoiceItems = $invoiceData['invoice_items'];
        $orderId = $invoiceData['order_id'];
        unset($invoiceData['invoice_items']);
        $totalShippingAmountPaid = $this->getTotalShippingAmountPaid($orderId, $invoiceData['shipping_amount']);
        if ($this->checkShippingAmountPaid($invoiceData, $invoiceItems, $totalShippingAmountPaid)) {
            $invoicedQtys = $this->getAlreadyInvoicedQty($orderId);
            $invoiceModel = new Invoice();
            $invoiceModel->set($invoiceData);
            $invoiceStatus = $invoiceModel->save();
            if ($invoiceStatus) {
                $permittedKeys = ['invoice_id', 'invoice_item_qty', 'invoice_item_total_discounts', 'invoice_item_total_price', 'invoice_item_price', 'invoice_item_subtotal_price', 'invoice_item_shipping_amount', 'invoice_item_id', 'invoice_item_total_tax'];
                $count = 1;
                $updateQry = '';
                $values = '';
                $orderItemIds = [];
                foreach ($invoiceItems as $item) {
                    $end = $count == count($invoiceItems) ? '' : ', ';
                    $values .= "(" . $invoiceModel->id . ", " . $item['invoice_item_qty'] . ", " . $item['invoice_item_total_discounts'] . ", ". $item['invoice_item_total_price'] . ", " . $item['invoice_item_price'] . ", ". $item['invoice_item_subtotal_price'] . ", ". $item['invoice_item_shipping_amount'] . ", " . $item['invoice_item_id'] . ", ". $item['invoice_item_total_tax'] . ")" . $end;
                    $updateQry .= "when product_id = " . $item['invoice_item_id'] . " then " . ($invoicedQtys[$item['invoice_item_id']] + $item['invoice_item_qty']) . " ";
                    $orderItemIds[] = $item['invoice_item_id'];
                    $count++;
                }

                $updateQuery = "UPDATE `order_item` SET `invoiced_qty` = (case " . $updateQry . "
                                 end) WHERE product_id IN (" . implode(',', $orderItemIds) . ") AND order_id = " . $orderId;
                $updateOrderShipmentAmountPaidQuery = 'UPDATE `order` SET shipping_amount_paid = ' . $totalShippingAmountPaid . ' WHERE id = ' . $orderId;
                $user_db = $this->getMultipleDbManager()->getDb();
                $connection = $this->di->get($user_db);
                try {
                    $insertQuery = "INSERT INTO invoice_item (" . implode(',', $permittedKeys) . ") VALUES " . $values;
                    $connection->query($insertQuery);
                    $connection->query($updateQuery);
                    $connection->query($updateOrderShipmentAmountPaidQuery);
                    return ['success' => true, 'message' => 'Invoice Created Successfully'];
                } catch (\Exception $e) {
                    return ['success' => false, 'message' => $e->getMessage()];
                }
            } else {
                $errors = '';
                foreach ($invoiceModel->getMessages() as $value) {
                    $errors .= ' ' . $value;
                }

                return ['success' => false, 'message' => $errors];
            }
        } else {
            return ['success' => false, 'message' => 'Pay unpaid shipping amount before last invoice generation'];
        }
    }

    public function checkShippingAmountPaid($invoice, $invoiceItems, $totalShippingAmountPaid)
    {
        $invoiceQtyOfCurrentInvoice = 0;
        foreach ($invoiceItems as $value) {
            $invoiceQtyOfCurrentInvoice += $value['invoice_item_qty'];
        }

        $invoicedQtys = 0;
        $totalQtys = 0;
        $orderItems = \App\Connector\Models\Order\Item::find(
            [
                    "order_id='{$invoice['order_id']}'",
                    'column' => 'invoiced_qty, qty'
                ]
        );
        $totalShippingAmount = Order::findFirst(
            [
                    "id='{$invoice['order_id']}'",
                    'column' => 'shipping_amount_paid'
                ]
        );
        if ($orderItems && count($orderItems)) {
            $orderItems = $orderItems->toArray();
            $totalShippingAmount = $totalShippingAmount->toArray();
            foreach ($orderItems as $value) {
                $invoicedQtys += $value['invoiced_qty'];
                $totalQtys += $value['qty'];
            }

            if (($totalQtys == ($invoicedQtys + $invoiceQtyOfCurrentInvoice)) &&
                $totalShippingAmount > $totalShippingAmountPaid) {
                return false;
            }
            return true;
        }
        return false;
    }

    public function getAlreadyInvoicedQty($orderId)
    {
        $invoicedQtys = [];
        $orderItems = \App\Connector\Models\Order\Item::find(
            [
                    "order_id='{$orderId}'",
                    'column' => 'invoiced_qty, product_id'
                ]
        )->toArray();
        foreach ($orderItems as $value) {
            $invoicedQtys[$value['product_id']] = $value['invoiced_qty'];
        }

        return $invoicedQtys;
    }

    public function getTotalShippingAmountPaid($orderId, $shippingAmountPaid)
    {
        $paidShippingAmount = Order::findFirst(
            [
                    "id='{$orderId}'",
                    'column' => 'shipping_amount_paid'
                ]
        )->toArray();
        return ($paidShippingAmount['shipping_amount_paid'] + $shippingAmountPaid);
    }

    public function getTotalDiscountAndTax($itemsDetails)
    {
        foreach ($itemsDetails as $key => $item) {
            $discountAmount = $this->getTotalDiscount($item['discount_codes'], $item['total_price'] / $item['qty'], $item['invoice_item_qty']);
            $itemsDetails[$key]['invoice_item_total_discounts'] = $discountAmount['total_discounts'];
            $itemsDetails[$key]['invoice_item_total_price'] = $discountAmount['total_price'];
            $itemsDetails[$key]['invoice_item_price'] = $discountAmount['single_unit_price'];
            $itemsDetails[$key]['invoice_item_subtotal_price'] = $discountAmount['total_price'] - $itemsDetails[$key]['invoice_item_total_discounts'];
            $itemsDetails[$key]['invoice_item_shipping_amount'] = $itemsDetails[$key]['shipping_amount'];
            $itemsDetails[$key]['invoice_item_id'] = $itemsDetails[$key]['product_id'];
            $itemsDetails[$key]['invoice_item_total_tax'] = $this->getTotalTax($item['tax_lines'], $itemsDetails[$key]['invoice_item_subtotal_price']);
            $itemsDetails[$key]['invoice_item_subtotal_price'] = $itemsDetails[$key]['invoice_item_subtotal_price'] + $itemsDetails[$key]['invoice_item_total_tax'];
        }

        return $itemsDetails;
    }

    public function getTotalDiscount($discountCodes, $singleUnitPrice, $invoiceQty)
    {
        $itemTotalPrice = $singleUnitPrice * $invoiceQty;
        $totalDiscount = 0;
        foreach ($discountCodes as $singleDiscount) {
            if ($singleDiscount['type'] == 'percentage') {
                $totalDiscount += $singleDiscount['amount'] * $itemTotalPrice;
            } elseif ($singleDiscount['type'] == 'fixed') {
                $totalDiscount += $singleDiscount['amount'];
            }
        }

        return ['total_discounts' => $totalDiscount, 'total_price' => $itemTotalPrice, 'single_unit_price' => $singleUnitPrice];
    }

    public function getFormattedInvoiceItems($invoiceItems)
    {
        $permittedKeys = ['invoice_item_qty', 'invoice_item_total_discounts', 'invoice_item_total_price', 'invoice_item_price', 'invoice_item_subtotal_price', 'invoice_item_shipping_amount', 'invoice_item_id', 'invoice_item_total_tax'];
        foreach ($invoiceItems as $key => $singleItem) {
            foreach ($singleItem as $itemKey => $itemValue) {
                if (!in_array($itemKey, $permittedKeys)) {
                    unset($invoiceItems[$key][$itemKey]);
                }
            }
        }

        return $invoiceItems;
    }

    public function getInvoiceData($invoiceItems, $merchantId, $orderId, $shippingAmountPaid)
    {
        $invoiceData = [
            'order_id' => $orderId,
            'merchant_id' => $merchantId,
            'total_price' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'subtotal_price' => 0,
            'shipping_amount' => $shippingAmountPaid,
            'grand_total' => 0
        ];
        foreach ($invoiceItems as $item) {
            $invoiceData['total_price'] += $item['invoice_item_total_price'];
            $invoiceData['tax_amount'] += $item['invoice_item_total_tax'];
            $invoiceData['discount_amount'] += $item['invoice_item_total_discounts'];
            $invoiceData['subtotal_price'] += $item['invoice_item_subtotal_price'];
        }

        $invoiceData['grand_total'] = $invoiceData['subtotal_price'] + $invoiceData['shipping_amount'];
        return $invoiceData;
    }

    public function getTotalTax($taxLines, $subtotalPrice)
    {
        $total_tax = 0;
        foreach ($taxLines as $singleTax) {
            $total_tax += $singleTax['rate'] * $subtotalPrice;
        }

        return $total_tax;
    }

    public function getInvoiceByOrderId($orderDetails)
    {
        $orderId = $orderDetails['id'];
        $invoice = Invoice::find(["order_id='{$orderId}'"]);
        if ($invoice && count($invoice)) {
            return ['success' => true, 'messages' => 'All invoices', 'data' => $invoice->toArray()];
        }
        return ['success' => true, 'messages' => 'No invoices found', 'data' => []];
    }

    public function viewInvoice($invoiceId)
    {
        $invId = $invoiceId['id'];
        $invoiceQuery = 'SELECT i.id, i.order_id, i.total_price, i.tax_amount, i.discount_amount, i.subtotal_price, i.shipping_amount, i.grand_total, i.created_at, o.client_details, o.currency FROM `invoice` as i INNER JOIN `order` as o ON i.id = ' . $invId . ' AND o.id = i.order_id';
        $invoiceItemQuery = 'SELECT DISTINCT ot.title as item_title, it.invoice_item_price, it.invoice_item_qty, it.invoice_item_total_price, it.invoice_item_total_discounts, it.invoice_item_total_tax, it.invoice_item_shipping_amount, it.invoice_item_subtotal_price FROM `invoice` as i INNER JOIN `invoice_item` as it INNER JOIN `order` as o INNER JOIN `order_item` as ot ON i.id = ' . $invId . ' AND i.order_id = o.id AND i.id = it.invoice_id AND it.invoice_item_id = ot.product_id';
        $user_db = $this->getMultipleDbManager()->getDb();
        $connection = $this->di->get($user_db);
        $invoice = $connection->fetchAll($invoiceQuery);
        if (count($invoice) > 0) {
            $invoiceItems = $connection->fetchAll($invoiceItemQuery);
            $invoiceDetails = $invoice[0];
            $invoiceDetails['client_details'] = json_decode($invoiceDetails['client_details'], true);
            $invoiceDetails['invoice_items'] = $invoiceItems;
            $finalHtml = $this->getHtmlForInvoicePdf($invoiceDetails);

            $invoiceFileName = 'invoice_file_' . $this->di->getUser()->id . '_' . time();
            if (!file_exists(BP . DS . 'var' . DS . 'invoice' . DS . 'temp')) {
                $oldmask = umask(0);
                mkdir(BP . DS . 'var' . DS . 'invoice' . DS . 'temp', 0777, true);
                umask($oldmask);
            }

            $filePath = BP . DS . 'var' . DS . 'invoice' . DS . 'temp' . DS . $invoiceFileName . '.php';
            $handle = fopen($filePath, 'w+');
            fwrite($handle, '<?php return ' . var_export($finalHtml, true) . ';');
            fclose($handle);

            $date1 = new \DateTime('+15 min');
            $invoiceFileDetails = [
                'user_id' => $this->di->getUser()->id,
                'fileName' => $invoiceFileName,
                'exp' => $date1->getTimestamp()
            ];
            $token = $this->di->getObjectManager()->get('App\Core\Components\Helper')->getJwtToken($invoiceFileDetails, 'RS256', false);
            return ['success' => true, 'message' => 'Invoice filename', 'data' => $token];
        }
        return ['success' => false, 'message' => 'Invoice with this id not present'];
    }

    public function getHtmlForInvoicePdf($invoiceContent)
    {
        $invoiceItemHtml = '';
        $mainAmountHtml = '';
        $clientDetailHtml = '';
        $invoiceIdHtml  = '';

        $invoiceIdHtml .= '
          <div>
              <h1>Invoice Details</h1>
              <table style="width: 100%; display: inline-block; margin-top: 20px; margin-bottom: 20px;">
                <tr style="background-color: #e3e3e3;">
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    <span>Invoice ID</span>
                  </td>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    <span>' . $invoiceContent["id"] . '</span>
                  </td>
                </tr>
                <tr>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    <span>Invoice Date</span>
                  </td>
                  <td style="padding: 10px; height: 38px; font-size: 25px;">
                    <span>' . $invoiceContent["created_at"] . '</span>
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
                    ' . $invoiceContent["client_details"]["contact_email"] . '
                  </td>
                </tr>
              </table>
          </div>
        ';
        $mainAmountHtml .= '
            <h1>Price Details</h1>
            <table style="width: 100%; display: block; margin-top: 20px; margin-bottom: 20px;">
              <tr>
                <td style="padding: 10px; height: 38px; font-size: 25px;">

                </td>
                <td style="padding: 10px; height: 38px; font-size: 25px;">
                  <span>Amount (in ' . $invoiceContent["currency"] . ')</span>
                </td>
              </tr>
              <tr style="background-color: #e3e3e3;">
                <td style="padding: 10px; height: 38px; font-size: 25px;">
                  <span>Total Price</span>
                </td>
                <td style="padding: 10px; height: 38px; font-size: 25px;">
                  <span>' . $invoiceContent["total_price"] . '</span>
                </td>
              </tr>
              <tr>
                <td style="padding: 10px; height: 38px; font-size: 25px;">
                  <span>Total Tax</span>
                </td>
                <td style="padding: 10px; height: 38px; font-size: 25px;">
                  <span>' . $invoiceContent["tax_amount"] . '</span>
                </td>
              </tr>
              <tr style="background-color: #e3e3e3;">
                <td style="padding: 10px; height: 38px; font-size: 25px;">
                  <span>Total Discount</span>
                </td>
                <td style="padding: 10px; height: 38px; font-size: 25px;">
                  <span>' . $invoiceContent["discount_amount"] . '</span>
                </td>
              </tr>
              <tr>
                <td style="padding: 10px; height: 38px; font-size: 25px;">
                  <span>Subtotal Price</span>
                </td>
                <td style="padding: 10px; height: 38px; font-size: 25px;">
                  <span>' . $invoiceContent["subtotal_price"] . '</span>
                </td>
              </tr>
              <tr style="background-color: #e3e3e3;">
                <td style="padding: 10px; height: 38px; font-size: 25px;">
                  <span>Shipping Amount</span>
                </td>
                <td style="padding: 10px; height: 38px; font-size: 25px;">
                  <span>' . $invoiceContent["shipping_amount"] . '</span>
                </td>
              </tr>
              <tr>
                <td style="padding: 10px; height: 41px; font-size: 28px;">
                  <span style="font-weight: bold;">Grand Total</span>
                </td>
                <td style="padding: 10px; height: 41px; font-size: 28px;">
                  <span style="font-weight: bold;">' . $invoiceContent["grand_total"] . '   ' . $invoiceContent["currency"] . '</span>
                </td>
              </tr>
            </table>
        ';

        $itemTrHtml = '';
        $count = 1;
        foreach ($invoiceContent['invoice_items'] as $value) {
            $trStyle = '';
            if (($count % 2) === 0) {
                $trStyle = 'style="background-color: #e3e3e3;"';
            }

            $itemTrHtml .= '
                <tr ' . $trStyle . '>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["item_title"] . '</span>
                    </td>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["invoice_item_price"] . '</span>
                    </td>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["invoice_item_qty"] . '</span>
                    </td>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["invoice_item_total_price"] . '</span>
                    </td>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["invoice_item_total_discounts"] . '</span>
                    </td>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["invoice_item_total_tax"] . '</span>
                    </td>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["invoice_item_shipping_amount"] . '</span>
                    </td>
                    <td style="padding: 8px; height: 38px; font-size: 13px;">
                      <span>' . $value["invoice_item_subtotal_price"] . '</span>
                    </td>
                  </tr>
            ';
            $count++;
        }

        $invoiceItemHtml .= '
            <h4>Invoice Items</h4>
            <table style="width: 100%; display: block;">
              <tr style="background-color: #e3e3e3;">
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>Item</span>
                </td>
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>Unit Price</span>
                </td>
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>Quantity</span>
                </td>
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>Total Price</span>
                </td>
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>Total Discount</span>
                </td>
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>Total Tax</span>
                </td>
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>Shipping Amount</span>
                </td>
                <td style="padding: 8px; height: 38px; font-size: 13px;">
                  <span>Subtotal Price</span>
                </td>
              </tr>
              ' . $itemTrHtml . '
            </table>
        ';

        $finalHtml = '
            <div style="height: 75px; background-color: #808080; color: white; text-align: center; padding: 15px;">
              <h1>INVOICE</h1>
            </div>
            <div>
                <table style="width: 100%; display: block;">
                  <tr>
                    <td style="width: 100%; height: 220px;">
                      ' . $clientDetailHtml . '
                    </td>
                    <td style="width: 100%; height: 220px;">
                      ' . $invoiceIdHtml . '
                    </td>
                  </tr>
                  <tr>
                    <td style="width: 100%; height: 220px;">

                    </td>
                    <td style="width: 100%; height: 220px;">
                      ' . $mainAmountHtml . '
                    </td>
                  </tr>
                </table>
            </div>
            <div>
                ' . $invoiceItemHtml . '
            </div>
        ';

        return $finalHtml;
    }

    public static function search($filterParams = [], $fullTextSearchColumns = null)
    {
        $conditions = [];
        if (isset($filterParams['filter'])) {
            foreach ($filterParams['filter'] as $key => $value) {
                $key = trim($key);
                if (array_key_exists(Invoice::IS_EQUAL_TO, $value)) {
                    $conditions[] = "`" . $key . "` = '" . trim(addslashes($value[Invoice::IS_EQUAL_TO])) . "'";
                } elseif (array_key_exists(Invoice::IS_NOT_EQUAL_TO, $value)) {
                    $conditions[] = "`" . $key . "` != '" . trim(addslashes($value[Invoice::IS_NOT_EQUAL_TO])) . "'";
                } elseif (array_key_exists(Invoice::IS_CONTAINS, $value)) {
                    $conditions[] = "`" . $key . "` LIKE '%" . trim(addslashes($value[Invoice::IS_CONTAINS])) . "%'";
                } elseif (array_key_exists(Invoice::IS_NOT_CONTAINS, $value)) {
                    $conditions[] = "`" . $key . "` NOT LIKE '%" . trim(addslashes($value[Invoice::IS_NOT_CONTAINS])) . "%'";
                } elseif (array_key_exists(Invoice::START_FROM, $value)) {
                    $conditions[] = "`" . $key . "` LIKE '" . trim(addslashes($value[Invoice::START_FROM])) . "%'";
                } elseif (array_key_exists(Invoice::END_FROM, $value)) {
                    $conditions[] = "`" . $key . "` LIKE '%" . trim(addslashes($value[Invoice::END_FROM])) . "'";
                } elseif (array_key_exists(Invoice::RANGE, $value)) {
                    if (trim($value[Invoice::RANGE]['from']) && !trim($value[Invoice::RANGE]['to'])) {
                        $conditions[] = "`" . $key . "` >= '" . $value[Invoice::RANGE]['from'] . "'";
                    } elseif (trim($value[Invoice::RANGE]['to']) && !trim($value[Invoice::RANGE]['from'])) {
                        $conditions[] = "`" . $key . "` >= '" . $value[Invoice::RANGE]['to'] . "'";
                    } else {
                        $conditions[] = "`" . $key . "` between '" . $value[Invoice::RANGE]['from'] . "' AND '" . $value[Invoice::RANGE]['to'] . "'";
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

    public function getInvoices($params)
    {
        $limit = $params['count'] ?? $this->defaultPagination;
        $page = $params['activePage'] ?? 1;
        $userId = $this->di->getUser()->id;
        $offset = ($page - 1) * $limit;
        $query = "SELECT * FROM `invoice` WHERE `merchant_id` = '{$userId}' AND ";
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
                $countRecords = $this->sqlConfig->sqlRecords("SELECT COUNT(*) as `count` FROM `invoice` WHERE " . $conditionalQuery . " LIMIT 0,1", "one");
            } else {
                $countRecords = $this->sqlConfig->sqlRecords("SELECT COUNT(*) as `count` FROM `invoice` LIMIT 0,1", "one");
            }

            if (isset($countRecords['count'])) {
                $responseData['data']['count'] = $countRecords['count'];
            }
        }

        return $responseData;
    }
}
