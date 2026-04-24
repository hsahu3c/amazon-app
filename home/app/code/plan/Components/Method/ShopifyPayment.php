<?php

namespace App\Plan\Components\Method;

use App\Core\Components\Base;
use App\Connector\Components\ApiClient;
use App\Plan\Models\Plan;

/**
 * class ShopifyPayment for shopify payment related requirements
 */
class ShopifyPayment extends Base
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FROZEN = 'frozen';

    public const STATUS_CANCELLED = 'cancelled';

     /**
     * to process remote call
     */
    public function getPaymentData($data, $type = Plan::BILLING_TYPE_RECURRING)
    {
        $response = [];
        $callType = $this->di->getConfig()->get('plan_call_type') ?? '';
        if ($callType == 'QL') {
            $data['call_type'] = 'QL';
        }
        if ($type == Plan::BILLING_TYPE_RECURRING) {
            $response = $this->initApiClient()->call('billing/recurring', [], $data, 'GET');
        }
        if ($type == Plan::BILLING_TYPE_ONETIME) {
            $response = $this->initApiClient()->call('billing/onetime', [], $data, 'GET');
        }

        if ($callType == 'QL') {
            $response = $this->formatResponse($response);
        }

        return $response;
    }

    /**
     * @return mixed
     */
    public function initApiClient()
    {
        return $this->di->getObjectManager()->get(ApiClient::class)->init(
            Plan::MARKETPLACE,
            true,
            Plan::MARKETPLACE_GROUP_CODE
        );
    }

    public function createRemotePayment($data, $type = Plan::BILLING_TYPE_RECURRING)
    {
        $response = [];
        $callType = $this->di->getConfig()->get('plan_call_type') ?? '';
        if ($callType == 'QL') {
            $data['call_type'] = 'QL';
        }
        if ($type == Plan::BILLING_TYPE_RECURRING) {
            $response = $this->initApiClient()->call('billing/recurring', [], $data, 'POST');
        }

        if ($type == Plan::BILLING_TYPE_ONETIME) {
            $response = $this->initApiClient()->call('billing/onetime', [], $data, 'POST');
        }

        if ($callType == 'QL') {
            $response = $this->formatResponse($response);
        }

        return $response;
    }

    public function cancelPayment($data)
    {
        $callType = $this->di->getConfig()->get('plan_call_type') ?? '';
        if ($callType == 'QL') {
            $data['call_type'] = 'QL';
        }
        return $this->initApiClient()->call('billing/recurring', [], $data, 'DELETE');
    }

    public function createRefund($apiData)
    {
        $apiData['call_type'] = 'QL';
        return $this->initApiClient()->call('billing/credit', [], $apiData, 'POST');
    }

    public function formatResponse($response)
    {
        isset($response['data']['status']) && $response['data']['status'] = strtolower($response['data']['status']);
        isset($response['status']) && $response['status'] = strtolower($response['status']);
        isset($response['data']['id']) && $response['data']['id'] = (int)$response['data']['id'];
        isset($response['id']) && $response['id'] = (int)$response['id'];
        return $response;
    }

    public function createUsage($data)
    {
        $callType = $this->di->getConfig()->get('plan_call_type') ?? '';
        if ($callType == 'QL') {
            $data['call_type'] = 'QL';
        }
        return $this->initApiClient()->call('usageCharge', [], $data, 'POST');
    }
}