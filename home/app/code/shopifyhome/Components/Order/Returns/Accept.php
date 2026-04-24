<?php
namespace App\Shopifyhome\Components\Order\Returns;
use App\Core\Components\Base;
use App\Shopifyhome\Components\Order\Returns\Utility;

class Accept extends Base {
    public function process($data) {
        $utility = $this->di->getObjectManager()->get(Utility::class);
        if(!isset($data['marketplace_return_id'])) {
            return [
                'success' => false,
                'message' => "Required fields are missing"
            ];
        }

        $params = [
            'shop_id' => $utility->getRemoteShopId($data['shop_id']),
            'return_id' => $data['marketplace_return_id'],
        ];

        $response = $utility->getClient()->call('return/accept',[], $params, "PUT", "json");

        if(!$response['success'] || !isset($response['data']['returnApproveRequest']['return']['id'])) {
            return [
                'success' => false,
                'message' => 'Failed to accept order return',
                'data' => [
                    'approve_error' => $response['msg'] ?? "",
                    'marketplace_return_id' => $data['marketplace_return_id']
                ]
            ];
        }

        $data = [
            'marketplace_return_id' => $response['data']['returnApproveRequest']['return']['id'] ?? null,
            'marketplace_status' => $response['data']['returnApproveRequest']['return']['status'] ?? "open",
        ];

        $data['marketplace_status'] = strtolower((string) $data['marketplace_status']);

        return [
            'success' => true,
            'data' => $data
        ];
    }

    public function processWebhook($data) {
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
            'marketplace_status' => strtolower((string) $marketplaceStatus)
        ];

        return [
            'success' => true,
            'data' => $data
        ];
    }
}