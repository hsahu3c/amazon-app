<?php

namespace App\Core\Test\Components\Message\Handler;

use App\Core\Components\Message\Handler\Sqs;

class SqsTest extends \App\Core\Components\UnitTestApp
{
    /**
     * function to test the checkstatus method
     *
     * @return void
     */
    public function testCheckStatus()
    {
        /**Test case when userId ,shopId and appCode is passed */
        $arr03 = [
            'user_id' => '63231b08ec08027f900586bb',
            'shop_id' => '66',
            'app_code' => 'Eraser',
        ];

        /**expected output when user status and shop status is active but shop app status is uninstall */
        $expectedOutput03 = [
            'success' => false,
            'message' => 'App status is uninstall',
        ];

        $obj = $this->di->getObjectManager()->get(Sqs::class);
        $response = $obj->checkStatus($arr03);

        $this->assertEquals($expectedOutput03, $response);
    }
}
