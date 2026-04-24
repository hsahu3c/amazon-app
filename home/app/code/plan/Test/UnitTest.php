<?php

namespace App\Plan\Test;

use App\Core\Components\UnitTestApp;
use App\Plan\Models\Plan;

/**
 * Class UnitTest
 */
class UnitTest extends UnitTestApp
{
    public function testPrioritySync(): void
    {
        $planModel = $this->di->getObjectManager()->get(Plan::class);
        $remoteShopId = '2';
        $value = 0;
        $res = $planModel->updateEntryInDynamo($remoteShopId, $value);
        $this->assertIsArray($res, "Response is array");
        $this->assertArrayHasKey("success", $res);
        $this->assertArrayHasKey("message", $res);
        $this->assertIsBool($res['success']);
        $this->assertTrue($res['success'], 'Success is true');
        $remoteShopId = '2';
        $value = 1;
        $res = $planModel->updateEntryInDynamo($remoteShopId, $value);
        $this->assertIsArray($res, "Response is array");
        $this->assertArrayHasKey("success", $res);
        $this->assertArrayHasKey("message", $res);
        $this->assertIsBool($res['success']);
        $this->assertTrue($res['success'], 'Success is false');
        $remoteShopId = '32';
        $value = 1;
        $res = $planModel->updateEntryInDynamo($remoteShopId, $value);
        $this->assertIsArray($res, "Response is array");
        $this->assertArrayHasKey("success", $res);
        $this->assertArrayHasKey("message", $res);
        $this->assertIsBool($res['success']);
        $this->assertTrue($res['success'], 'Success is false');
    }
}