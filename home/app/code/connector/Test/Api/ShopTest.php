<?php

namespace App\Connector\Test\Api;

class ShopTest extends \App\Core\Components\UnitTestApp
{
    public function testChangeShopStatus(): void
    {
        $data4 = ['userId' => "6336d5305baf9d6a45045482", "shopStatus" => "uninstall","shopId"=>"97"];
        $result4 = ["success" => true, "message" => " shop status updated to uninstall"];
        $api = $this->di->getObjectManager()->get('\App\Connector\Api\Shop');
        $response = $api->changeShopStatus($data4);

        $this->assertEquals(
            $response,
            $result4
        );
    }
}
