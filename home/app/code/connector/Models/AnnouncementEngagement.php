<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Announcement engagement – table: announcement_engagement (append-only)
 *
 * Schema: announcement_id, user_id, shop_id, target_shop_id, marketplace_id,
 * event, metadata, created_at
 */
class AnnouncementEngagement extends BaseMongo
{
    protected $table = 'announcement_engagement';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }

    /**
     * Record an engagement event (append-only)
     *
     * @param string|ObjectId $announcementId
     * @param string $event e.g. viewed, clicked, acknowledged, cta_clicked, form_started, form_submitted
     * @param array $context user_id, shop_id, target_shop_id, marketplace_id
     * @param array $metadata optional
     * @return bool
     */
    public function record($announcementId, string $event, array $context, array $metadata = []): bool
    {
        $id = $announcementId instanceof ObjectId ? $announcementId : new ObjectId($announcementId);
        $doc = [
            'announcement_id' => $id,
            'user_id' => $context['user_id'] ?? '',
            'shop_id' => $context['shop_id'] ?? '',
            'target_shop_id' => $context['target_shop_id'] ?? '',
            'marketplace_id' => $context['marketplace_id'] ?? '',
            'event' => $event,
            'metadata' => $metadata,
            'created_at' => new UTCDateTime(),
        ];
        $this->getCollection()->insertOne($doc);
        return true;
    }
}
