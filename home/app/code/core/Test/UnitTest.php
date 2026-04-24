<?php

namespace App\Core\Test;

/**
 * Class UnitTest
 */
class UnitTest extends \App\Core\Components\UnitTestApp
{

    public function testTestCase()
    {
        $this->assertEquals(
            "works",
            "works",
            "This is OK"
        );

        $this->assertEquals(
            "works",
            "works",
            "This will fail"
        );
    }
}
