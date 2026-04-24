<?php
namespace App\Shopifyhome\Components\Order\Returns;
use App\Core\Components\Base;
use App\Shopifyhome\Components\Order\Returns\Utility;

class Decline extends Base {
    public function process(array $data) {
        $utility = $this->di->getObjectManager()->get(Utility::class);
        if(!isset($data['marketplace_return_id'], $data['reject_reason_code'])) {
            return [
                'success' => false,
                'message' => "Required fields are missing"
            ];
        }

        $params = [
            'shop_id' => $utility->getRemoteShopId($data['shop_id']),
            'return_id' => $data['marketplace_return_id'],
            'reject_reason_code' => strtoupper((string) $data['reject_reason_code'])
        ];

        $response = $utility->getClient()->call('return/reject',[], $params, "PUT", "json");
        if(!$response['success'] || !isset($response['data']['returnDeclineRequest']['return']['id'])) {
            return [
                'success' => false,
                'message' => $response['message'] ?? 'Failed to reject order return',
                'data' => [
                    'reject_error' => $response['message'] ?? "",
                    'marketplace_return_id' => $data['marketplace_return_id']
                ]
            ];
        }

        $data = [
            'marketplace_return_id' => $response['data']['returnDeclineRequest']['return']['id'] ?? null,
            'reject_reason_code' => $response['data']['returnDeclineRequest']['return']['decline']['reason'] ?? null,
            'reject_note' => $response['data']['returnDeclineRequest']['return']['decline']['note'] ?? null,
            'marketplace_status' => $response['data']['returnDeclineRequest']['return']['status'] ?? "DECLINED"
        ];

        $data['marketplace_status'] = strtolower((string) $data['marketplace_status']);

        return [
            'success' => true,
            'data' => $data
        ];
    }

    public function processWebhook(array $data) {
        if(!isset($data['data'])) {
            return [
                'success' => false,
                'message' => 'No data found'
            ];
        }

        $marketplaceReturnId = $data['data']['admin_graphql_api_id'];
        $marketplaceStatus = $data['data']['status'];
        $data = [
            'marketplace_return_id' => $marketplaceReturnId,
            'marketplace_status' => $marketplaceStatus,
            'reject_reason_code' => $data['data']['decline']['reason'] ?? null,
            'reject_note' => $data['data']['decline']['note'] ?? null
        ];

        $data['marketplace_status'] = strtolower((string) $data['marketplace_status']);

        return [
            'success' => true,
            'data' => $data
        ];
    }
}