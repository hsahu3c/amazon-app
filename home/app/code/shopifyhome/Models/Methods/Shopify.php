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
 * @package     Ced_Shoifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */


namespace App\Shopifyhome\Models\Methods;

use App\Payment\Models\Method\Type\Redirect;

class Shopify extends Redirect
{
    protected $_code = "shopify";

    public function processPayment($quote,$requestVar)
    {

        $prepareData = $this->prepareShopifyData($quote,$requestVar);

        $response = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($target, true)
                ->call('/shopifygql/query',[],['shop_id'=> $requestVar['remote_shop_id'], 'query' => $prepareData], 'POST');

        if (isset($response['errors'])) {
            return ['success'=> false,"message"=>'Something Went Wrong , Getting error while connecting to Shopify.'];
        }

        if ($response && !(isset($response['errors']))) {
            return ['success'=> true,"data"=>$response,"redirect"=>$response['appSubscriptionCreate']['confirmationUrl']];
        }
    }


    public function prepareShopifyData($quote,$requestVar)
    {
        $planInfo = $quote['details'];

        $paymentType = $requestVar['type'];
        $planName = $planInfo['label'];
        $trialdays = $planInfo['trial_days'] ?? 0;

        $test_payment = 'false';
        if (isset($_GET['cedcommerce']) && $_GET['cedcommerce'] == 'cedcoss') {
            $test_payment = 'true';
        }
        
        $interval = $planInfo['interval'];
        $query = 'mutation {
                    appSubscriptionCreate(
                        name: "' . $planName . '"
                        returnUrl: "' . $planInfo['return_url'] . '"
                        test: ' . $test_payment . '
                        trialDays: ' . $trialdays . '
                        lineItems: [
                            {
                            plan: {
                            appRecurringPricingDetails: {
                                price: { amount: ' . $planInfo['price'] . ', currencyCode: USD }
                                interval: ' . $interval . '
                                }
                            }
                        }
                        ]
                    ) {
                    appSubscription {
                        id
                    }
                    confirmationUrl
                    userErrors {
                        field
                        message
                    }
                }
            }';

        return $query;
    }


    public function confirmationUrl($requestVar)
    {
        $charge_id = $requestVar['charge_id'];

        $query = 'query {
                      node(id: "gid://shopify/AppSubscription/' . $charge_id . '") {
                        ...on AppSubscription {
                          createdAt
                          currentPeriodEnd
                          id
                          name
                          status
                          test
                          lineItems {
                            plan {
                              pricingDetails {
                                ...on AppRecurringPricing {
                                  interval
                                  price {
                                    amount
                                    currencyCode
                                  }
                                }
                                ...on AppUsagePricing {
                                  terms
                                  cappedAmount {
                                    amount
                                    currencyCode
                                  }
                                  balanceUsed {
                                    amount
                                    currencyCode
                                  }
                                }
                              }
                            }
                          }
                        }
                      }
                    }';

        $response = $this->di->getObjectManager()->get("\App\Connector\Components\ApiClient")
                ->init($target, true)
                ->call('/shopifygql/query',[],['shop_id'=> $requestVar['remote_shop_id'], 'query' => $query], 'POST');
        if (!isset($response['errors'])) {
            $isPayment = true;
            $response = $response['node'] ?? $response;
            if (!isset($response['errors']) && $response['status'] == 'ACTIVE') {

                return ['success'=> true,"message"=>$response['name']. " activated successfully"];

            }
            return ['success'=> false,"message"=>'Something Went Wrong , Getting error while connecting to Shopify.'];
        }
        if (isset($response['id']) && $response['status'] == "declined") {
            return ['success'=> false,"message"=>'payment declined'];
        }

        if (isset($response['errors'])) {
            return ['success'=> false,"message"=>$response['errors']];
        }
        else {

            return ['success'=> false,"message"=>'Something went wrong'];
        }
    }
}
