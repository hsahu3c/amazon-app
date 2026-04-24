<?php
namespace App\Shopifyhome\Test\Components;
use App\Core\Components\UnitTestApp;
use App\Connector\Contracts\Sales\Order\RefundInterface;
use App\Core\Models\User\Details;

class OrderRefundTest extends UnitTestApp {
    protected $di;

    // public function testCreateRefund() {
    //     $payload = [
    //         'marketplace_reference_id' => '36743647',
    //         'currency' => 'USD',
    //         'note' => 'Test note',
    //         'items' => [
    //             'marketplace_item_id' => '3364736',
    //             'quantity' => 1
    //         ]
    //     ];
    //     $this->di->setUser(Details::findFirst([
    //         'username' => 'abctestvishal.myshopify.com'
    //     ]));
    //     $this->di->getAppCode()->set([
    //         'shopify' => 'shopify'
    //     ]);
    //     $orderRefund = $this->di->getObjectManager()->get(OrderRefund::class, ["4375473543"]);
    //     $response = $orderRefund->create($payload);
    //     print_r($response);
    //     $this->assertIsArray($response);
    // }

    public function testPrepareForDb(): void {
        $orderData = json_decode('{
            "id": 929361483,
            "order_id": 450789469,
            "created_at": "2024-05-14T21:21:58-04:00",
            "note": "wrong size",
            "user_id": null,
            "processed_at": "2024-05-14T21:21:58-04:00",
            "restock": false,
            "duties": [],
            "total_duties_set": {
              "shop_money": {
                "amount": "0.00",
                "currency_code": "USD"
              },
              "presentment_money": {
                "amount": "0.00",
                "currency_code": "USD"
              }
            },
            "additional_fees": [],
            "total_additional_fees_set": {
              "shop_money": {
                "amount": "0.00",
                "currency_code": "USD"
              },
              "presentment_money": {
                "amount": "0.00",
                "currency_code": "USD"
              }
            },
            "return": null,
            "refund_shipping_lines": [],
            "admin_graphql_api_id": "gid://shopify/Refund/929361483",
            "refund_line_items": [
              {
                "location_id": null,
                "restock_type": "no_restock",
                "quantity": 1,
                "id": 1058498337,
                "line_item_id": 518995019,
                "subtotal": 0,
                "total_tax": 0,
                "subtotal_set": {
                  "shop_money": {
                    "amount": "0.00",
                    "currency_code": "USD"
                  },
                  "presentment_money": {
                    "amount": "0.00",
                    "currency_code": "USD"
                  }
                },
                "total_tax_set": {
                  "shop_money": {
                    "amount": "0.00",
                    "currency_code": "USD"
                  },
                  "presentment_money": {
                    "amount": "0.00",
                    "currency_code": "USD"
                  }
                },
                "line_item": {
                  "id": 518995019,
                  "variant_id": 49148385,
                  "title": "IPod Nano - 8gb",
                  "quantity": 1,
                  "sku": "IPOD2008RED",
                  "variant_title": "red",
                  "vendor": null,
                  "fulfillment_service": "manual",
                  "product_id": 632910392,
                  "requires_shipping": true,
                  "taxable": true,
                  "gift_card": false,
                  "name": "IPod Nano - 8gb - red",
                  "variant_inventory_management": "shopify",
                  "properties": [],
                  "product_exists": true,
                  "fulfillable_quantity": 1,
                  "grams": 200,
                  "price": "199.00",
                  "total_discount": "0.00",
                  "fulfillment_status": null,
                  "price_set": {
                    "shop_money": {
                      "amount": "199.00",
                      "currency_code": "USD"
                    },
                    "presentment_money": {
                      "amount": "199.00",
                      "currency_code": "USD"
                    }
                  },
                  "total_discount_set": {
                    "shop_money": {
                      "amount": "0.00",
                      "currency_code": "USD"
                    },
                    "presentment_money": {
                      "amount": "0.00",
                      "currency_code": "USD"
                    }
                  },
                  "discount_allocations": [
                    {
                      "amount": "3.33",
                      "discount_application_index": 0,
                      "amount_set": {
                        "shop_money": {
                          "amount": "3.33",
                          "currency_code": "USD"
                        },
                        "presentment_money": {
                          "amount": "3.33",
                          "currency_code": "USD"
                        }
                      }
                    }
                  ],
                  "duties": [],
                  "admin_graphql_api_id": "gid://shopify/LineItem/518995019",
                  "tax_lines": [
                    {
                      "title": "State Tax",
                      "price": "3.98",
                      "rate": 0.06,
                      "channel_liable": null,
                      "price_set": {
                        "shop_money": {
                          "amount": "3.98",
                          "currency_code": "USD"
                        },
                        "presentment_money": {
                          "amount": "3.98",
                          "currency_code": "USD"
                        }
                      }
                    }
                  ]
                }
              }
            ],
            "transactions": [
              {
                "id": 1068278586,
                "order_id": 450789469,
                "kind": "refund",
                "gateway": "bogus",
                "status": "success",
                "message": "Bogus Gateway: Forced success",
                "created_at": "2024-05-14T21:21:58-04:00",
                "test": true,
                "authorization": null,
                "location_id": null,
                "user_id": null,
                "parent_id": 801038806,
                "processed_at": "2024-05-14T21:21:58-04:00",
                "device_id": null,
                "error_code": null,
                "source_name": "755357713",
                "receipt": {},
                "amount": "41.94",
                "currency": "USD",
                "payment_id": "c901414060.1",
                "total_unsettled_set": {
                  "presentment_money": {
                    "amount": "348.0",
                    "currency": "USD"
                  },
                  "shop_money": {
                    "amount": "348.0",
                    "currency": "USD"
                  }
                },
                "manual_payment_gateway": false,
                "admin_graphql_api_id": "gid://shopify/OrderTransaction/1068278586"
              }
            ],
            "order_adjustments": []
          }', true);
        $orderData['shop_id'] = 48;
        $sourceRefund = $this->di->getObjectManager()->get(RefundInterface::class, [], 'shopify');
        $this->di->setUser(Details::findFirst([
            'username' => 'abctestvishal.myshopify.com'
        ]));
        $response = $sourceRefund->prepareForDb($orderData);
        print_r($response);
        $this->assertEquals("", "");
    }
}