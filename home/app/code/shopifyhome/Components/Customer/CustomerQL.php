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

namespace App\Shopifyhome\Components\Customer;

use App\Shopifyhome\Components\Core\Common;
use rdx\graphqlquery\Query as phpGraphQueryBuilder;

#[\AllowDynamicProperties]
class CustomerQL extends Common
{
    public function _construct()
    {
        $this->_callName = 'shopify_core';
        parent::_construct();
    }

    public function createCustomer(array $params = []): void
    {
        $customerQuery = phpGraphQueryBuilder::mutation();
        $customerQuery->fields('customerCreate');

        $customerQuery->customerCreate->attribute('input', [
            'email' => 'pankajaswal@hotmail.com',
            'firstName' => 'Pankaj',
            'lastName' => 'Aswal',
            'note' => 'pankaj, aswal',
            'taxExempt' => false,
            'addresses' => [
                [
                    'address1' => 'h no 487, somnath lines',
                    'address2' => 'Sadar Bazaar',
                    'city' => 'Ranikhet',
                    'company' => 'himalaya Healthcare',
                    'country' => 'India',
                    /*'countryCode' => IN,*/
                    'firstName' => 'Pankaj',
                    'lastName' => 'Aswal',
                    'phone' => '+918005498987',
                    'province' => 'Uttrakhand',
                    'provinceCode' => 'UK',
                    'zip' => '263645'
                ]
            ]
        ]);
        $customerQuery->customerCreate->fields('customer','userErrors');

        $customerQuery->customerCreate->customer->fields('id');
        $customerQuery->customerCreate->userErrors->fields('message');
        $this->graphAsync($customerQuery->build());
        /*$query1 = $customerQuery->build();*/


        $customerQuery2 = phpGraphQueryBuilder::mutation();
        $customerQuery2->fields('customerCreate');

        $customerQuery2->customerCreate->attribute('input', [
            'email' => 'anshumansingh@gmail.com',
            'firstName' => 'Anshuman',
            'lastName' => 'Singh',
            'note' => 'anshuman, singh',
            'taxExempt' => true,
            'addresses' => [
                [
                    'address1' => '2/456, ravindrapalli',
                    'address2' => 'Bhootnath',
                    'city' => 'Lucknow',
                    'company' => '',
                    'country' => 'India',
                    /*'countryCode' => IN,*/
                    'firstName' => 'Anshuman',
                    'lastName' => 'Singh',
                    'phone' => '+917859664579',
                    'province' => 'Uttar Pradesh',
                    'provinceCode' => 'UP',
                    'zip' => '226013'
                ]
            ]
        ]);
        $customerQuery2->customerCreate->fields('customer','userErrors');

        $customerQuery2->customerCreate->customer->fields('id');
        $customerQuery2->customerCreate->userErrors->fields('message');
        $this->graphAsync($customerQuery2->build());

        $customerQuery3 = phpGraphQueryBuilder::mutation();
        $customerQuery3->fields('customerCreate');

        $customerQuery3->customerCreate->attribute('input', [
            'email' => 'swati@shukla.com',
            'firstName' => 'Swati',
            'lastName' => 'Shukla',
            'note' => 'swati, shukla',
            'taxExempt' => true,
            ]);
        $customerQuery3->customerCreate->fields('customer','userErrors');

        $customerQuery3->customerCreate->customer->fields('id');
        $customerQuery3->customerCreate->userErrors->fields('message');
        $this->graphAsync($customerQuery3->build());

        $customerQuery4 = phpGraphQueryBuilder::mutation();
        $customerQuery4->fields('customerCreate');

        $customerQuery4->customerCreate->attribute('input', [
            'email' => 'deepti@shukla.com',
            'firstName' => 'Deepti',
            'lastName' => 'Shukla',
            'note' => 'deepti, shukla',
            'taxExempt' => true,
        ]);
        $customerQuery4->customerCreate->fields('customer','userErrors');

        $customerQuery4->customerCreate->customer->fields('id');
        $customerQuery4->customerCreate->userErrors->fields('message');
        $this->graphAsync($customerQuery4->build());

        /*echo $query;die;
        $customQuery2 = "";
        $customQuery3 = "";
        $customQuery4 = "";*/
        /*$this->graphAsync($query1);*/

        $responseData = $this->fire();
        print_r($responseData);die('async call');
        
    }
}