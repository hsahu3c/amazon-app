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

namespace App\Shopifyhome\Components\Order;

use App\Shopifyhome\Components\Core\Common;
use rdx\graphqlquery\Query as phpGraphQueryBuilder;

#[\AllowDynamicProperties]
class OrderQL extends Common
{
    public function _construct()
    {
        $this->_callName = 'shopify_core';
        parent::_construct();
    }

    public function createOrder(array $params = []): void

    {

        $draftOrder = phpGraphQueryBuilder::mutation();
        $draftOrder->fields('draftOrderCreate');

        $draftOrder->draftOrderCreate->attribute('input',
            [
                'billingAddress' => [
                    'address1' => '758/n , varddan khand',
                    'address2' => 'Gomti Nagar Vistaar',
                    'city' => 'Lucknow',
                    'company' => 'Cedcoss Technologies',
                    'country' => 'India',
                    'firstName' => 'Pankaj',
                    'lastName' => 'Aswal',
                    'phone' => '+918589632201',
                    'zip' => 'DERT00125'
                ],
                'customAttributes' => [
                    [
                        'key' => 'note1',
                        'value' => 'behind Andhra bank'
                    ],
                    [
                        'key' => 'note2',
                        'value' => 'near Shivaji Park'
                    ]
                ],
                'email' => 'pankajaswal@hotmail.com',
                'lineItems' => [
                    [
                        'customAttributes' => [
                            [
                                'key' => 'notes',
                                'value' => 'please gift wrap products',
                            ],[
                                'key' => 'extra_notes',
                                'value' => 'use authentic gift material',
                            ]
                        ],
                        'originalUnitPrice' => 100.25,
                        'quantity' => 2,
                        'title' => "Anshuman ka Product"
                    ],
                    [
                        'customAttributes' => [
                            [
                                'key' => 'notes',
                                'value' => 'handle with care',
                            ],[
                                'key' => 'extra_notes',
                                'value' => 'use extra care while loading/unloading ',
                            ]
                        ],
                        'originalUnitPrice' => 58.05,
                        'quantity' => 1,
                        'title' => "Anshuman ka Product"
                    ]
                ],
                'note' => 'PLease keep away from fire',
                'shippingAddress' => [
                    'address1' => '758/n , varddan khand',
                    'address2' => 'Gomti Nagar Vistaar',
                    'city' => 'Lucknow',
                    'company' => 'Cedcoss Technologies',
                    'country' => 'India',
                    'firstName' => 'Pankaj',
                    'lastName' => 'Aswal',
                    'phone' => '+918589632201',
                    'zip' => 'DERT00125'
                ],
                'shippingLine' => [
                    'price' => 45,
                    'shippingRateHandle' => 'DFX Ground',
                    'title' => 'DFX Fast Delivery fast service'
                ]
            ]
            );
        $draftOrder->draftOrderCreate->fields('draftOrder', 'userErrors');

        $draftOrder->draftOrderCreate->draftOrder->fields('id');
        $draftOrder->draftOrderCreate->userErrors->fields('message');
        $this->graphAsync($draftOrder->build());

        $draftOrder2 = phpGraphQueryBuilder::mutation();
        $draftOrder2->fields('draftOrderCreate');

        $draftOrder2->draftOrderCreate->attribute('input',
            [
                'billingAddress' => [
                    'address1' => '758/n , ravindrapalli',
                    'address2' => 'INdire Nagar',
                    'city' => 'Lucknow',
                    'company' => 'Cedcu Technologies',
                    'country' => 'India',
                    'firstName' => 'Anshuman',
                    'lastName' => 'Singh',
                    'phone' => '+91897845563',
                    'zip' => 'ET015478'
                ],
                'customAttributes' => [
                    [
                        'key' => 'note1',
                        'value' => 'behind Shiv mandir'
                    ]
                ],
                'email' => 'anshumansingh@hotmail.com',
                'lineItems' => [
                    [
                        'customAttributes' => [
                           [
                                'key' => 'extra_notes',
                                'value' => 'do not bend',
                            ]
                        ],
                        'originalUnitPrice' => 100.25,
                        'quantity' => 2,
                        'title' => "Anshuman ka Product"
                    ]
                ],
                'note' => 'PLease keep away from fire',
                'shippingAddress' => [
                    'address1' => '758/n , varddan khand',
                    'address2' => 'Gomti Nagar Vistaar',
                    'city' => 'Lucknow',
                    'company' => 'Cedcoss Technologies',
                    'country' => 'India',
                    'firstName' => 'Pankaj',
                    'lastName' => 'Aswal',
                    'phone' => '+918589632201',
                    'zip' => 'DERT00125'
                ],
                'shippingLine' => [
                    'price' => 45,
                    'shippingRateHandle' => 'DFX Ground',
                    'title' => 'DFX Fast Delivery fast service'
                ],
                'taxExempt' => true
            ]
        );
        $draftOrder2->draftOrderCreate->fields('draftOrder', 'userErrors');

        $draftOrder2->draftOrderCreate->draftOrder->fields('id');
        $draftOrder2->draftOrderCreate->userErrors->fields('message');
        $this->graphAsync($draftOrder2->build());


        $responseData = $this->fire();

        print_r($responseData);die('async order call');

    }

    public function completeOrder(array $params = []): void
    {
        $draftOrderComplete = phpGraphQueryBuilder::mutation();
        $draftOrderComplete->fields('draftOrderComplete');
        $draftOrderComplete->draftOrderComplete->attribute('id','gid://shopify/DraftOrder/233104343104');
        $draftOrderComplete->draftOrderComplete->attribute('paymentPending',false);
        $draftOrderComplete->draftOrderComplete->fields('draftOrder', 'userErrors');

        $draftOrderComplete->draftOrderComplete->draftOrder->fields('id');
        $draftOrderComplete->draftOrderComplete->userErrors->fields('message');
        $this->graphAsync($draftOrderComplete->build());

        /*$draftOrderComplete2 = phpGraphQueryBuilder::mutation();
        $draftOrderComplete2->fields('draftOrderComplete');
        $draftOrderComplete2->draftOrderComplete->attribute('id','gid://shopify/DraftOrder/233104310336');
        $draftOrderComplete2->draftOrderComplete->attribute('paymentPending',false);
        $draftOrderComplete2->draftOrderComplete->fields('draftOrder', 'userErrors');
        $draftOrderComplete2->draftOrderComplete->draftOrder->fields('id');
        $draftOrderComplete2->draftOrderComplete->userErrors->fields('message');
         $this->graphAsync($draftOrderComplete2->build());*/

        $responseData = $this->fire();

        print_r($responseData);die('async order call complete');
    }

    public function invoiceSend(): void
    {

        $draftOrderInvoice = phpGraphQueryBuilder::mutation();
        $draftOrderInvoice->fields('draftOrderInvoiceSend');

        $draftOrderInvoice->draftOrderInvoiceSend->attribute('id','gid://shopify/DraftOrder/233078947904');
        $draftOrderInvoice->draftOrderInvoiceSend->fields('draftOrder', 'userErrors');

        $draftOrderInvoice->draftOrderInvoiceSend->draftOrder->fields('id');
        $draftOrderInvoice->draftOrderInvoiceSend->userErrors->fields('message');
        $this->graphAsync($draftOrderInvoice->build());

        $responseData = $this->fire();

        print_r($responseData);
        die('async order call complete');

    }
}