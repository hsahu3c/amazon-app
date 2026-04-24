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

namespace App\Shopifyhome\Components\Shop;

use App\Shopifyhome\Components\Core\Common;

#[\AllowDynamicProperties]
class ShopQL_reference extends Common
{
    public function _construct(): void
    {        
        $this->_callName = 'shopify_core';
        parent::_construct();
    }

    /**
     * @return array
     */
    protected function pullShopInfoandSave()
    {
        
    }

    public function getShopDetail()
    {
        $this->init(false);

        $query2 = "{
                      shop {
                        name
                        description
                        contactEmail
                        email
                        plan{
                          displayName
                          partnerDevelopment
                          shopifyPlus
                        }
                        primaryDomain{
                          host
                          url
                        }
                      }
                    }";
        
        $query = "query{
					  shop {
					    productTypes(first:250){
					      edges{
					        node
					      }
					    }
					  }
					}";

        $query3 = $query5 = $query7 = $query9 = $query11 = $query13 = $query15 = "query{
					  shop {
					    productTypes(first:250){
					      edges{
					        node
					      }
					    }
					  }
					}";

        $query4 = $query6 = $query8 = $query10 = $query12 =$query14 ="{
                      shop {
                        name
                        description
                        contactEmail
                        email
                        plan{
                          displayName
                          partnerDevelopment
                          shopifyPlus
                        }
                        primaryDomain{
                          host
                          url
                        }
                      }
                    }";
        
        //$shopData = $this->graph($query);

        $this->graphAsync($query);
        $this->graphAsync($query2);
        $this->graphAsync($query3);
        $this->graphAsync($query4);
        $this->graphAsync($query5);
        $this->graphAsync($query6);
        $this->graphAsync($query8);
        $this->graphAsync($query9);
        $this->graphAsync($query10);
        $this->graphAsync($query11);
        $this->graphAsync($query12);
        $this->graphAsync($query13);
        $this->graphAsync($query14);
        $this->graphAsync($query15);

        $this->fire();


        die;

        if($shopData['errors']) {
            return [
                'is_error' => true,
                'msg' => $shopData['body']
            ];
        }

        print_r($shopData['body']);die;

        //$shopData = $this->jsonDecode($shopData->body->getContents());
        //print_r($shopData->response->getHeaders());die('sds');
        return [
            'success' => true,
            'data' => $shopData->data
        ];
    }

}