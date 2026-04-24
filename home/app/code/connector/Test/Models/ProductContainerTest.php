<?php
namespace App\Connector\Test\Models;
use App\Core\Components\UnitTestApp;
use App\Connector\Models\ProductContainer;
use App\Core\Models\User\Details;

class ProductContainerTest extends UnitTestApp {
    public function testFetchProducts(): void {
        $productContainerModel = $this->di->getObjectManager()->get(ProductContainer::class);
        $user = Details::findFirst(
            [
                'username' => 'abctestvishal.myshopify.com'
            ]
        );
        $user->id = (string) $user->_id;
        $this->di->setUser($user);
        $this->di->getRequester()->setSource([
            'source_id' => '1',
            'source_name' => 'shopify'
        ]);
        $this->di->getRequester()->setTarget([
            'target_id' => '3',
            'target_name' => 'meta'
        ]);
        $result = $productContainerModel->fetchProducts();
        print_r($result);
        $this->assertEquals('hi', 'hi');
    }
}