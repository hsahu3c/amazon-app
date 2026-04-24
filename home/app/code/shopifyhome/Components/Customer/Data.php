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

class Data extends Common
{
    public $_collection = '';

    public $_error = false;

    public $_userId = 1;

    public function prepare($userId = false): void{
        $userId = $this->di->getUser()->id;
        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $mongo->setSource("customer_container_" . $userId);

        $this->_collection = $mongo->getPhpCollection();
        $this->_userId = $userId;
    }

    public function handleCustomerData($data, $userId = null){
        $this->prepare($userId);
        if($this->_error) return $this->_error;

        switch($data['db_action'])
        {
            case 'customer_update' :
                unset($data['db_action']);
                $this->updateCustomer($data);
                break;

            case 'delete' :
                break;

            default :
                return [
                    'success' => false,
                    'message' => "No action defined"
                ];
        }
    }

    public function deleteProducts($userId = null , $locationId = []): void{
        $this->prepare($userId);
        $this->_collection->drop();
    }

    public function deleteIndividualVariants($containerId, $products = [], $deleteParent = false, $userId = null): void{
        $this->prepare($userId);
        if($deleteParent){
            $this->_collection->deleteOne(
                ['details.source_product_id' => $containerId]
            );
        } else {
            foreach ($products as $variantId){
                $res = $this->_collection->updateMany(
                    ['details.source_product_id' => $containerId],
                    ['$pull' => [
                        "variants" => [
                            "source_variant_id" => $variantId
                        ]
                    ]
                    ]
                );
            }
        }
    }

    public function getallVariantIds($id, $userId = null){
        $this->prepare($userId);
        $ids = $this->_collection->distinct("variants.source_variant_id"
            , ["details.source_product_id" => $id]
            );
        return $ids;
    }

    public function updateCustomer($data): void{

        $prefix = $data['mode'] ?? '';

        $out = $this->_collection->findOne(['details.source_customer_id' => $data['source_customer_id']], ['typeMap' => ['root' => 'array', 'document' => 'array']]);

        $helper = $this->di->getObjectManager()->get(Helper::class);
        $data = $helper->formatCoreDataForCustomerData($data);


        if(empty($out)){
            $res = $this->_collection->updateOne(
                [ 'details.source_customer_id' => $data['id']],
                [
                    '$set' => [
                        'details.is_imported' => 1
                    ]
                ]
            );
        } else {
            $res = $this->_collection->updateOne(
                [ 'details.source_customer_id' => (string)$data['source_customer_id'] ],
                [ '$set' => [
                    'details.$' => $data,
                    'details.is_imported' => 1
                ]
                ]
            );
        }


    }

    public function updateWebhookProductVariants($data, $userId = null): void{
        $this->prepare($userId);
        $updateVariant = $this->_collection->updateOne(
            ['details.source_product_id' => $data['source_product_id']],
            ['$set' => [
                'variant_attribute' => $data['attributes']
                ]
            ]
        );
        /*$this->di->getLog()->logContent('PROCESS 00323 | Data | updateProductVariant | Product modified count : '.$updateVariant->getModifiedCount(),'info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'webhook_product_import.log');*/
        $this->di->getLog()->logContent('PROCESS 00323 | Data | updateProductVariant ','info','shopify'.DS.$this->di->getUser()->id.DS.'product'.DS.date("Y-m-d").DS.'webhook_product_import.log');
        //$this->di->getLog()->logContent('variant attr update | modified count '.$updateVariant->getModifiedCount(),'info','webhook_update_variantattr.log');
    }

    public function updateWebhookProductDetails($data, $userId = null){
        $this->prepare($userId);

        $productExists = $this->_collection->findOne(
            ['details.source_product_id' => $data['source_product_id']]);

        if($productExists){
            $updateVariant = $this->_collection->updateOne(
                ['details.source_product_id' => $data['source_product_id']],
                ['$set' => [
                    'details' => $data
                ]
                ]
            );
            return [
                'success' => true
            ];
        }
        return [
            'success' => false,
            'msg' => 'product does not exist'
        ];

    }


    public function pushToQueue($data, $time = 0, $queueName = 'temp')
    {
        $data['run_after'] = $time;
        if($this->di->getConfig()->get('enable_rabbitmq_internal')){
            $helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
            return $helper->createQueue($data['queue_name'],$data);
        }

        return true;
    }
}