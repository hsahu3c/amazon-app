<?php

namespace App\Core\Test\Models;
use App\Core\Models\Config\Config;

/**
 * @group cache
 * Class CacheTest
 */
class ConfigTest extends \App\Core\Components\UnitTestApp
{
    public function testGetConfig()
    {
        $config = $this->di->getObjectManager()->get(Config::class);
        $config->setUserId("66053e945e9666212a05e894");
        $config->setGroupCode("order");
        $config->setAppTag("shopify_meta");
        print_r($config->getConfig(["order_refund", "order_cancel"]));
        $this->assertEquals("test", "test");
    }
}
