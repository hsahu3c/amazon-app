<?php

namespace App\Amazon\Test\Components\Common;

use App\Core\Components\UnitTestApp;
use App\Amazon\Components\Common\Barcode;


/**
 * Class Amazon/Components/Listings/Price.php
 */
class BarcodeTest extends UnitTestApp
{

    public function testGetBarcodeType(): void
    {
        $arr = [
            '726872029856' => 'UPC',
            '5022371572561' => 'EAN'
        ];
        $barcodeHelper = $this->di->getObjectManager()->get(Barcode::class);
        foreach ($arr as $barcodes => $expectedType) {
            $data['barcode'] = $barcodes; 
            $result = $barcodeHelper->getBarcodeType($data);
            if($result['success']){
                $this->assertEquals($result['response'], $expectedType);
                
            }
        }
    }
}