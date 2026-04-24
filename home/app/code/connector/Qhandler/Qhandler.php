class Qhandler extends \App\Core\Components\Base {

	public function sendMail($data) {
		return mail($data['data']['email'], $data['data']['subject'], $data['data']['content']);
	}

	public function getUrl($url = '') {
		return 'http://192.168.0.222/phalcon/public/' . $url;
	}

	public function uploadProduct($products) {
        $count = 1;
        try {
        	foreach ($products['data']['products'] as $key => $value) {
	        	$handlerData = [
	                'type' => 'class',
	                'class_name' => 'Qhandler',
	                'method' => 'createProduct',
	                'queue_name' => 'product_queue',
	                'own_weight' => 100,
	                'weight_for_parent' => 100/count($products['data']['products']),
	                'parent_id' => $products['message_id'],
	                'data' => [
	                	'product' => $value,
	                	'count' => $count,
	                	'bearer' => $products['data']['bearer'],
	                	'history_index' => $products['data']['history_index']
	                ]
	            ];
	         	$helper = $this->di->getObjectManager()->get('\App\Rmq\Components\Helper');
	       		$helper->createQueue($handlerData['queue_name'],$handlerData);
	        }
	        return true;
    	} catch(\Exception $e) {
    		return false;
    	}
	}

	public function createProduct($product) {
		$result = $this->di->getObjectManager()->get('\App\Core\Components\Helper')->curlRequest($this->getUrl('connector/product/createQueuedProducts'), $product['data'],false);
		return $result['responseCode']==200;
	}
}