<?php

namespace App\Connector\Test\Components;


/**
 * Class UnitTest
 */
class ConnectorsTest extends \App\Core\Components\UnitTestApp
{

    public function testGetConnectorModelByCode(): void
    {
        $connector = $this->di->getObjectManager()->get('App\Connector\Components\Connectors');
        $connectorModel = $connector->getConnectorModelByCode('global');
        $this->assertInstanceOf(
            "App\Core\Models\SourceModel",
            $connectorModel,
            "Found the model"
        );
    }


}