<?php

namespace App\Amazon\Test;

use App\Core\Components\UnitTestApp;
/**
 * Class UnitTest
 */
class UnitTest extends UnitTestApp
{

    public function testTestCase(): void
    {
        $this->assertEquals(
            "works",
            "works",
            "This is OK in connecter"
        );

        $this->assertEquals(
            "works",
            "works",
            "This will fail connecotr"
        );
    }
}