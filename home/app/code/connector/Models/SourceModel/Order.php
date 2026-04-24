<?php

namespace App\Connector\Models\SourceModel;


class Order implements \App\Connector\Contracts\Sales\OrderInterface
{

    public function create(array $data): array
    {
    
    }

    public function update(array $data): array
    {

    }

    public function get(string $orderId): array
    {

    }

    public function getByField(string $key,string $value): array{

    }
}
