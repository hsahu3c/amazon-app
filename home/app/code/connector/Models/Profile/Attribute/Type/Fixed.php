<?php

namespace App\Connector\Models\Profile\Attribute\Type;
use Phalcon\Events\Manager as EventsManager;

class Fixed 
{
	public function changeData($key , array $mappedData , array $data): array
	{
		$data[$key] = $mappedData['value'];
		return $data;
	}
	
}