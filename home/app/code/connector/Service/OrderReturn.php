<?php

namespace App\Connector\Service;
use App\Connector\Contracts\Sales\Order\ReturnInterface;

class OrderReturn implements ReturnInterface
{
    public function request(array $data): array
    {
        return [
            'data' => $data,
            'items' => $data['items']
        ];
    }

    public function accept(array $data): array
    {
        return [
            'success' => true,
            'message' => 'Order return approved'
        ];
    }

    public function reject(array $data): array
    {
        return [
            'success' => true,
            'message' => 'Order return declined'
        ];
    }

    public function getRequestReason(array $sourceData, array $targetData): array
    {
        return [
            'return_reason_code' => '',
            'return_note' => ''
        ];
    }

    public function getRejectReason(array $sourceData, array $targetData): array
    {
        return [
            'return_reason_code' => '',
            'return_note' => ''
        ];
    }
}