<?php
namespace App\Connector\Components\Webhook;

use App\Connector\Components\ApiClient;
use App\Core\Components\Base;
use App\Core\Components\Message\Handler\Sqs as QueueManager;

class Helper extends Base
{
    const DEFAULT_HOOK_CLASS = "App\\Connector\\Components\\Webhook\\Hook";
    public function getHookClass($marketplaceName)
    {
        $marketplaceName = ucfirst($marketplaceName);
        $marketplaceHomeName = "$marketplaceName" . "home";
        $marketplaceHookClass = "App\\Connector\\$marketplaceName\\Components\\Webhook\\Hook";
        $marketplaceHomeHookClass = "App\\Connector\\$marketplaceHomeName\\Components\\Webhook\\Hook";
        if (class_exists($marketplaceHookClass)) {
            return $marketplaceHookClass;
        } else if (class_exists($marketplaceHomeHookClass)) {
            return $marketplaceHomeHookClass;
        } else {
            return self::DEFAULT_HOOK_CLASS;
        }
    }

    public function getClient($marketplaceName, $appCode)
    {
        return $this->di->getObjectManager()->get(ApiClient::class)->init(
            $marketplaceName,
            true,
            $appCode
        );
    }

    public function getShop($shopId)
    {
        $user = $this->di->getUser();
        $shops = $this->di->getUser()->getShops();
        foreach ($shops as $shop) {
            if ($shop['_id'] == $shopId) {
                return $shop;
            }
        }
        throw new \Exception("Shop not found with shopId $shopId");
    }

    public function createTask(array $data): array
    {
        return [
            'type' => 'full_class',
            'class_name' => 'App\Connector\Components\Webhook\Handler',
            'method' => 'processTask',
            'queue_name' => $data['queue_name'],
            'delay' => 1,
            'user_id' => $this->di->getUser()->id,
        ] + $data;
    }

    public function enqueueTask(array $data): bool
    {
        $queueManager = $this->di->getObjectManager()->get(QueueManager::class);
        $queueManager->pushMessage($data);
        return true;
    }

    /*
     * Log content to file
     */
    public function dearDiary(string $prefix, string $message)
    {
        $file = "connector/" . date('Y-m-d') . '/webhook.log';
        $this->di->getLog()->logContent("$prefix : $message", 'error', $file);
    }
}