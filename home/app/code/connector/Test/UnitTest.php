<?php

namespace App\Connector\Test;


/**
 * Class UnitTest
 */
class UnitTest extends \App\Core\Components\UnitTestApp
{

    public function testTestCase(): void
    {
        $this->assertEquals(
            "works",
            "works",
            "This is OK vin connecter"
        );

        $this->assertEquals(
            "works",
            "works",
            "This will fail connecotr"
        );
    }
}