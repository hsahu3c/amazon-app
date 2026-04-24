<?php

namespace App\Amazon\Test\Components\Listings;

use App\Core\Components\UnitTestApp;
use App\Amazon\Components\Listings\Helper;
use App\Amazon\Components\Listings\Price;


/**
 * Class Amazon/Components/Listings/Price.php
 */
class PriceTest extends UnitTestApp
{
    public function getTestConfiguration()
    {
        /** get test configuration from config */
        $testUser = $this->di->getConfig()->get('unit_test_user');
        if ($testUser == null || !isset($testUser['user_id'], $testUser['source_shop_id'], $testUser['target_shop_id'])) {
            $testUser = [
                'user_id' => '658eb22000efc24ae7068792',
                'source_shop_id' => '1',
                'target_shop_id' => '2',
                'target_marketplace' => 'amazon'
            ];
        }

        $listingHelper = $this->di->getObjectManager()->get(Helper::class);
        if (!isset($testUser['source_product_id'])) {
            $product = $listingHelper->queryProduct($testUser['user_id'], null, $testUser['source_shop_id'], null, null, null, true);
        } else {
            /** get random product */
            $product = $listingHelper->queryProduct($testUser['user_id'], $testUser['source_product_id'], $testUser['source_shop_id'], null, null, null, true);
        }

        $testUser['product'] = $product;
        return $testUser;
    }

    public function testGetProductPriceDetails(): void
    {
        $testUser = $this->getTestConfiguration();
        $product = $testUser['product'] ?? null;
        $this->assertNotEmpty($product, 'Product not found.');
        if (!empty($product)) {
            $obj = $this->di->getObjectManager()->get(Price::class);
            $resultArray = $obj->getProductPriceDetails($product, $testUser['target_shop_id'], ${$testUser}['target_marketplace'], true);
            /** test case for StandardPrice should return */
            $this->assertArrayHasKey('StandardPrice', $resultArray, 'StandardPrice value not returned.');
            if (isset($resultArray['StandardPrice'])) {
                /** test case for StandardPrice should be numeric */
                $this->assertIsNumeric($resultArray['StandardPrice'], 'Price should be a numeric value');
            }

            if (isset($resultArray['sale']['price'])) {
                $resultArray['sale']['price'] = 'dg';
                /** test case for Sale price should be numeric */
                $this->assertIsNumeric($resultArray['sale']['price'], 'Sale price should be a numeric value');
            }

            if (isset($resultArray['MinimumPrice'])) {
                /** test case for Minimum price should be numeric */
                $this->assertIsNumeric($resultArray['MinimumPrice'], 'MinimumPrice price should be a numeric value');
            }
        }
    }

    public function testPriceRoundOff(): void
    {
        /** test case for Price round off */
        $price = 100;
        $actions = [
            'higherEndWith9' => 109,
            'lowerEndWith9' => 99,
            'higherWholeNumber' => 101,
            'higherEndWith0.49' => 100.49,
            'lowerEndWith0.49' => 99.49,
            'lowerWholeNumber' => 99,
            'higherEndWith0.99' => 100.99,
            'lowerEndWith0.99' => 99.99,
            'higherEndWith10' => 110,
            'lowerEndWith10' => 90
        ];
        $obj = $this->di->getObjectManager()->get(Price::class);
        foreach ($actions as $action => $expectedResult) {
            $result = $obj->priceRoundOff($price, $action);
            $this->assertEquals($result, $expectedResult, $action . ' test failed');
        }
    }

    public function testChangePrice(): void
    {
        /** test case for Price manipulation*/
        $price = 100;
        $changeValue = 10;
        $actions = [
            'value_increase' => 110,
            'value_decrease' => 90,
            'percentage_increase' => 110,
            'percentage_decrease' => 90
        ];
        $obj = $this->di->getObjectManager()->get(Price::class);
        foreach ($actions as $action => $expectedResult) {
            $result = $obj->changePrice($price, $action, $changeValue);
            $this->assertEquals($result, $expectedResult, $action . ' test failed');
        }
    }
}
