<?php

namespace App\Connector\Components;

class BellNotificationHelper extends \App\Core\Components\Base
{




    public function getProductsForUpload($userId = false, $target = '', $products = [], $mapping = [])
    {
        $marketPlace = ucfirst($target);
        $product = $this->di->getObjectManager()->get('App\\' . $marketPlace . '\Components\Helper')->getProductsForUpload($products, $mapping);
        return $product;
    }

    public function uploadProductstoMarketplace($userId = false, $target = '', $products = [], $shop = '')
    {
        $marketPlace = ucfirst($target);
        $productUploadStatus = $this->di->getObjectManager()->get('App\\' . $marketPlace . '\Components\Helper')->uploadProducts($userId, $products, $shop);
        return $productUploadStatus;
    }

    /**
     * Get aggregated bell count from all sources
     *
     * @param array $context Array containing user_id, shop_id, target_shop_id, marketplace_id, appTag (optional, will use DI if not provided)
     * @return array Array with 'count' (total) and 'breakdown' (individual counts)
     */
    public function getBellCount(array $context = []): array
    {
        // Get context from DI if not provided
        if (empty($context)) {
            $context = [
                'user_id' => $this->di->getUser()->id,
                'shop_id' => $this->di->getRequester()->getSourceId(),
                'target_shop_id' => $this->di->getRequester()->getTargetId(),
                'marketplace_id' => $this->di->getRegistry()->getAppConfig()['marketplace'] ?? null,
                'appTag' => $this->di->getAppCode()->getAppTag()
            ];
        }

        $notifications = $this->di->getObjectManager()->get(\App\Connector\Models\Notifications::class);
        $announcementHelper = $this->di->getObjectManager()->get(\App\Connector\Components\AnnouncementHelper::class);
        $queuedTasks = $this->di->getObjectManager()->get(\App\Connector\Models\QueuedTasks::class);

        // countUnread excludes archived notifications (is_archived !== true); announcements use scope-aware badge count (GLOBAL at user level)
        $notificationCount = $notifications->countUnread($context);
        $announcementCount = $announcementHelper->getUnreadCountForBadge($context);
        $queuedAttention = $queuedTasks->countAttentionRequired($context);

        $totalCount = $notificationCount + $announcementCount + $queuedAttention;

         return [
            'count' => $totalCount,
            'breakdown' => [
                'notifications' => $notificationCount,
                'announcements' => $announcementCount,
                'queued_tasks' => $queuedAttention
            ]
        ];
    }
}
