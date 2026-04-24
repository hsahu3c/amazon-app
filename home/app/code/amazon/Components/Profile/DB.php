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
 * @package     Ced_Ebay
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Amazon\Components\Profile;

use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base as Base;

#[\AllowDynamicProperties]
class DB extends Base
{


    private $_baseMongo;

    public function init($queueData = [])
    {
        if (!isset($queueData['user_id'])) {
        }
        $this->_baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $this->_collection = $this->_baseMongo->getCollectionForTable(Helper::PROFILES);
        return $this;
    }

    public function update($filter, $data, $options){
        return $this->_collection->updateOne($filter,  $data,  $options);
    }

    public function insertMany($data)
    {
        return $this->_collection->insertMany($data);
    }

    public function findOne($data, $options)
    {
        return $this->_collection->findOne($data, $options);
    }

    public function find($data, $options)
    {
        return $this->_collection->find($data, $options);
    }

    public function remove($data)
    {
        return $this->_collection->remove($data);
    }

    public function deleteMany($data)
    {
        return $this->_collection->deleteMany($data);
    }
}