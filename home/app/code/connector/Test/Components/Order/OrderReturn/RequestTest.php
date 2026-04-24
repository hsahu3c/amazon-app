<?php
namespace App\Connector\Test\Components\Order\OrderReturn;

use App\Core\Components\UnitTestApp;
use App\Connector\Components\Order\OrderReturn\ReturnProcessor;

class RequestTest extends UnitTestApp {

    public function testAddReturnToSourceOrTarget(): void {
        // Initialize test data
        $target = [
            'marketplace_return_id' => '12345',
            'shop_id' => 'shop1',
            'marketplace' => 'marketplaceA',
            'order_id' => 'order1'
        ];
        
        $returnData = [
            'marketplace_return_id' => '67890',
            'shop_id' => 'shop2',
            'marketplace' => 'marketplaceB',
            'marketplace_reference_id' => 'order2'
        ];
        
        $marketplace = 'marketplaceA';
        
        // Create ReturnProcessor instance
        $returnProcessor = new ReturnProcessor([], [], 'source');
        
        // Call method
        $result = $returnProcessor->addReturnToSourceOrTarget([
            [
                'filter' => [
                    'marketplace_return_id' => $target['marketplace_return_id'],
                    'shop_id' => $target['shop_id'],
                    'marketplace' => $target['marketplace'],
                    'object_type' => [
                        '$regex' => '_return$'
                    ]
                ],
                'data' => [
                    'marketplace_return_id' => $returnData['marketplace_return_id'],
                    'shop_id' => $returnData['shop_id'],
                    'marketplace' => $marketplace,
                    'order_id' => $returnData['marketplace_reference_id']
                ]
            ],
            [
                'filter' => [
                    'marketplace_return_id' => $returnData['marketplace_return_id'],
                    'shop_id' => $returnData['shop_id'],
                    'marketplace' => $marketplace,
                    'object_type' => [
                        '$regex' => '_return$'
                    ]
                ],
                'data' => [
                    'marketplace_return_id' => $target['marketplace_return_id'],
                    'shop_id' => $target['shop_id'],
                    'marketplace' => $target['marketplace'],
                    'order_id' => $target['order_id']
                ]
            ]
        ]);

        // Assertions to validate the behavior
        $this->assertNotEmpty($result, 'Result should not be empty');
        $this->assertEquals($result->isAcknowledged(), true, "Bulk write was not accepted");
    }
}
