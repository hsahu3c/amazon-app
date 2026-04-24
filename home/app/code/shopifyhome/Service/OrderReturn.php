<?php
namespace App\Shopifyhome\Service;

use App\Connector\Contracts\Sales\Order\ReturnInterface;
use App\Core\Components\Base;
use App\Shopifyhome\Components\Order\Returns\Request;
use App\Shopifyhome\Components\Order\Returns\Accept;
use App\Shopifyhome\Components\Order\Returns\Decline;
class OrderReturn extends Base implements ReturnInterface
{
    public function request(array $data): array
    {
        $orderReturnRequest = $this->di->getObjectManager()->get(Request::class);
        if(isset($data['is_webhook']) && $data['is_webhook']) {
            return $orderReturnRequest->processWebhook($data);
        }

        return $orderReturnRequest->process($data);
    }

    public function accept(array $data): array
    {
        $orderReturnAccept = $this->di->getObjectManager()->get(Accept::class);
        if(isset($data['is_webhook']) && $data['is_webhook']) {
            return $orderReturnAccept->processWebhook($data);
        }

        return $orderReturnAccept->process($data);
    }

    public function reject(array $data): array
    {
        $orderReturnAccept = $this->di->getObjectManager()->get(Decline::class);
        if(isset($data['is_webhook']) && $data['is_webhook']) {
            return $orderReturnAccept->processWebhook($data);
        }

        return $orderReturnAccept->process($data);
    }

    public function getRejectReason(array $source, array $target): array
    {
        return [
            'reject_reason_code' => $source['reject_reason_code'] ?? "OTHER",
            'reject_note' => $source['reject_note'] ?? ""
        ];
    }

    public function getRequestReason(array $source, array $target): array
    {
        return [
            'return_reason_code' => $source['return_reason_code'] ?? "OTHER",
            'return_note' => $source['return_note'] ?? ""
        ];
    }
}