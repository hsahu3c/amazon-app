<?php

namespace App\Connector\Test\Api;

class UserTest extends \App\Core\Components\UnitTestApp
{
    public function testChangeUserStatus(): void
    {
        $data3 = ['userId' => "6336d5305baf9d6a45045482", "status" => "block"];
        $result3 = ["success" => true, "message" => " user status updated to block"];
        $api = $this->di->getObjectManager()->get('\App\Connector\Api\User');
        $response = $api->changeUserStatus($data3);

        $this->assertEquals(
            $response,
            $result3
        );
    }
}
