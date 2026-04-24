<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Announcement form responses – table: announcement_form_responses
 *
 * Schema: announcement_id, form_id, user_id, shop_id, target_shop_id,
 * responses (key-value), submitted_at
 */
class AnnouncementFormResponses extends BaseMongo
{
    protected $table = 'announcement_form_responses';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }

    /**
     * Find existing response for user/shop/form (for duplicate check)
     *
     * @param string|ObjectId $announcementId
     * @param string|ObjectId $formId
     * @param array $context user_id, shop_id
     * @return array|null
     */
    public function findResponse($announcementId, $formId, array $context): ?array
    {
        $aid = $announcementId instanceof ObjectId ? $announcementId : new ObjectId($announcementId);
        $fid = $formId instanceof ObjectId ? $formId : new ObjectId($formId);
        $filter = [
            'announcement_id' => $aid,
            'form_id' => $fid,
            'user_id' => $context['user_id'] ?? '',
            'shop_id' => $context['shop_id'] ?? '',
        ];
        $opts = ['typeMap' => ['root' => 'array', 'document' => 'array']];
        return $this->getCollection()->findOne($filter, $opts);
    }

    /**
     * Insert a form response
     *
     * @param string|ObjectId $announcementId
     * @param string|ObjectId $formId
     * @param array $context user_id, shop_id, target_shop_id
     * @param array $responses key-value by field id
     * @return bool
     */
    public function insertResponse($announcementId, $formId, array $context, array $responses): bool
    {
        $aid = $announcementId instanceof ObjectId ? $announcementId : new ObjectId($announcementId);
        $fid = $formId instanceof ObjectId ? $formId : new ObjectId($formId);
        $doc = [
            'announcement_id' => $aid,
            'form_id' => $fid,
            'user_id' => $context['user_id'] ?? '',
            'shop_id' => $context['shop_id'] ?? '',
            'target_shop_id' => $context['target_shop_id'] ?? '',
            'responses' => $responses,
            'submitted_at' => new UTCDateTime(),
        ];
        $this->getCollection()->insertOne($doc);
        return true;
    }
}
