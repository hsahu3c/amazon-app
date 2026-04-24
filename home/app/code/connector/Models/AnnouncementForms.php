<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;
use MongoDB\BSON\ObjectId;

/**
 * Announcement forms – table: announcement_forms (one per announcement)
 *
 * Schema: announcement_id, title, description, fields (array of {id, label, type, required, options?, scale?}),
 * created_by, created_at
 */
class AnnouncementForms extends BaseMongo
{
    protected $table = 'announcement_forms';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }

    /**
     * Get form by announcement id
     *
     * @param string|ObjectId $announcementId
     * @return array|null
     */
    public function getByAnnouncementId($announcementId): ?array
    {
        $id = $announcementId instanceof ObjectId ? $announcementId : new ObjectId($announcementId);
        $opts = ['typeMap' => ['root' => 'array', 'document' => 'array']];
        return $this->getCollection()->findOne(['announcement_id' => $id], $opts);
    }
}
