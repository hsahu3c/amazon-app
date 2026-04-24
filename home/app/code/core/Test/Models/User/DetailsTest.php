<?php

namespace App\Core\Test\Models\User;

/**
 * Class UnitTest
 */
class DetailsTest extends \App\Core\Components\UnitTestApp
{
    /*
     * Add shop in user users
     */
    public function testAddShop()
    {
        $helper = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $data =  [
            'name' => 'Ronald',
            'domain' => 'test123.com',
            'warehouses' => [
                ['name' => 'wh1', 'location' => 'lucknow'],
                ['name' => 'wh2', 'location' => 'Kanpur']
            ]
        ];
        $result = $helper->addShop($data);
        $this->assertTrue($result['success']);
    }

    /*
     * Update the shop of users
     */
    public function testUpdateShop()
    {
        $helper = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $data = [
            'name' => 'Ronald Walker',
            'domain' => 'test123.com',
            'warehouses' => [
                ['name' => 'wh1', 'location' => 'Lucknow'],
                ['name' => 'wh3', 'location' => 'Mumbai']
            ]
        ];
        $userId = $this->di->getUser()->id;
        $result = $helper->updateShop($data, $userId, ['domain']);
        $this->assertTrue($result['success']);
    }

    /*
     * Add warehouse in shop of users
     */
    public function testAddWarehouse()
    {
        $helper = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $shopId = 1;
        $data = ['name' => 'wh4', 'location' => 'Delhi'];
        $result = $helper->addWarehouse($shopId, $data);
        $this->assertTrue($result['success']);
    }

    /*
     * Update warehouse in shop of users
     */
    public function testUpdateWarehouse()
    {
        $helper = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $shopId = 1;
        $data = ['_id' => 4, 'name' => 'wh3', 'location' => 'New Delhi'];
        $result = $helper->updateWarehouse($data);
        $this->assertTrue($result['success']);
    }

    /*
     * Get all details of user_details for a user
     */
    public function testGetConfig()
    {
        $helper = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $userId = 1;
        $result = $helper->getConfig($userId);
        $this->assertNotFalse($result);
    }

    /*
     * Get all details of the shop of a user
     */
    public function testGetShop()
    {
        $helper = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $shopId = 1;
        $result = $helper->getShop($shopId);
        $this->assertNotFalse($result);
    }

    /*
     * Get all details of warehouse of shop of a user
     */
    public function testGetWarehouse()
    {
        $helper = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $shopId = 1;
        $warehouseId = 4;
        $result = $helper->getWarehouse($shopId, $warehouseId);
        $this->assertNotFalse($result);
    }

    /*
     * Get user detail by key
     */
    public function testGetConfigByKey()
    {
        $helper = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $userId = 1;
        $key = 'user_id';
        $result = $helper->getConfigByKey($userId);
        $this->assertNotFalse($result);
    }

    /*
     * Set user detail by key
     */
    public function testSetConfigByKey()
    {
        $helper = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $value = 25;
        $key = 'new_key';
        $result = $helper->setConfigByKey($key, $value);
        $this->assertTrue($result['success']);
    }

    /*
     * Delete the warehouse of a shop
     */
    public function testDeleteWarehouse()
    {
        $helper = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $shopId = 1;
        $warehouseId = 4;
        $result = $helper->deleteWarehouse($shopId, $warehouseId);
        $this->assertTrue($result['success']);
    }

    /*
     * Delete the shop of a user
     */
    public function testDeleteShop()
    {
        $helper = $this->di->getObjectManager()->create('\App\Core\Models\User\Details');
        $shopId = 1;
        $result = $helper->deleteShop($shopId);
        $this->assertTrue($result['success']);
    }
}
