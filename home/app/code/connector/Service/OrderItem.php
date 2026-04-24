<?php
/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */
namespace App\Connector\Service;
use App\Connector\Contracts\Sales\OrderInterface;
/**
 * Interface OrderInterface
 * @services
 */
class OrderItem implements OrderInterface
{
    public function create(array $data): array
    {
        if (empty(isset($data['order_id'],$data['items']))) {
            return ['success'=>false,"message"=>"Order item not created because Order id /item missing"];
        }

    }

    public function update(array $data): OrderInterface
    {
        
    }

    public function get(string $orderId): array
    {
       
    }

    public function getByField(string $key,string $value): array
    {
        return ['success'=>false,"message"=>"Order item not found by given filter"];
        
    }


    public function prepareForDb(array $data):array
    {
        
    }
}