<?php

namespace App\Connector\Models;

use App\Core\Models\BaseMongo;
use App\Connector\Models\Announcements;
use MongoDB\BSON\ObjectId;

/**
 * Announcement deliveries – table: announcement_deliveries
 *
 * Schema: announcement_id, user_id, shop_id, target_shop_id, marketplace_id,
 * is_read, is_acknowledged (critical_compliance), delivered_at, read_at, acknowledged_at
 */
class AnnouncementDeliveries extends BaseMongo
{
    protected $table = 'announcement_deliveries';

    public function initialize(): void
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDb());
    }

    /**
     * Build scope filter for queries (user_id, shop_id, target_shop_id).
     * When scope is GLOBAL, returns only user_id so read/ack is at user level.
     *
     * @param array $context user_id, shop_id, target_shop_id, marketplace_id
     * @param string|null $scope visibility scope (Announcements::SCOPE_GLOBAL for user-level only)
     * @return array
     */
    public function getScopeFilter(array $context, ?string $scope = null): array
    {
        $userId = $context['user_id'] ?? $this->di->getUser()->id;
        if ($scope === Announcements::SCOPE_GLOBAL) {
            return ['user_id' => $userId];
        }
        $filter = [
            'user_id' => $userId,
            'shop_id' => $context['shop_id'] ?? $this->di->getRequester()->getSourceId(),
        ];
        if (array_key_exists('target_shop_id', $context)) {
            $filter['target_shop_id'] = $context['target_shop_id'] ?? '';
        }
        if (!empty($context['marketplace_id'])) {
            $filter['marketplace_id'] = $context['marketplace_id'];
        }
        return $filter;
    }

    /**
     * Get one delivery for announcement + context, or null.
     * For GLOBAL scope uses user_id only (any shop).
     *
     * @param string|ObjectId $announcementId
     * @param array $context user_id, shop_id, target_shop_id, marketplace_id
     * @param string|null $scope visibility scope (Announcements::SCOPE_GLOBAL for user-level lookup)
     * @return array|null
     */
    public function getDelivery($announcementId, array $context, ?string $scope = null): ?array
    {
        $filter = $this->getScopeFilter($context, $scope);
        $filter['announcement_id'] = $announcementId instanceof ObjectId ? $announcementId : new ObjectId($announcementId);
        $opts = ['typeMap' => ['root' => 'array', 'document' => 'array']];
        return $this->getCollection()->findOne($filter, $opts);
    }

    /**
     * Get all delivery records for context (for merging read/ack state into list).
     * When scopeById is provided, GLOBAL announcements are looked up by user_id only and merged (read/ack if any).
     *
     * @param array $announcementIds array of ObjectId or string
     * @param array $context
     * @param array $scopeById map announcement_id (string) => visibility.scope (string); empty = all non-GLOBAL
     * @return array keyed by announcement_id (string)
     */
    public function getDeliveriesByAnnouncements(array $announcementIds, array $context, array $scopeById = []): array
    {
        if (empty($announcementIds)) {
            return [];
        }
        $ids = array_map(function ($id) {
            return $id instanceof ObjectId ? $id : new ObjectId($id);
        }, $announcementIds);
        $byAnnouncement = [];

        $globalIds = [];
        $otherIds = [];
        if (!empty($scopeById)) {
            foreach ($ids as $id) {
                $aid = (string)$id;
                $scope = $scopeById[$aid] ?? null;
                if ($scope === Announcements::SCOPE_GLOBAL) {
                    $globalIds[] = $id;
                } else {
                    $otherIds[] = $id;
                }
            }
        } else {
            $otherIds = $ids;
        }

        if (!empty($otherIds)) {
            $filter = $this->getScopeFilter($context, null);
            $filter['announcement_id'] = ['$in' => $otherIds];
            $cursor = $this->getCollection()->find($filter, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
            foreach ($cursor as $doc) {
                $aid = isset($doc['announcement_id']) ? (string)$doc['announcement_id'] : null;
                if ($aid) {
                    $byAnnouncement[$aid] = $doc;
                }
            }
        }

        if (!empty($globalIds)) {
            $filter = $this->getScopeFilter($context, Announcements::SCOPE_GLOBAL);
            $filter['announcement_id'] = ['$in' => $globalIds];
            $cursor = $this->getCollection()->find($filter, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
            $globalByAid = [];
            foreach ($cursor as $doc) {
                $aid = isset($doc['announcement_id']) ? (string)$doc['announcement_id'] : null;
                if (!$aid) {
                    continue;
                }
                if (!isset($globalByAid[$aid])) {
                    $globalByAid[$aid] = $doc;
                    continue;
                }
                $merged = $globalByAid[$aid];
                $merged['is_read'] = ($merged['is_read'] ?? false) || ($doc['is_read'] ?? false);
                $merged['is_acknowledged'] = ($merged['is_acknowledged'] ?? false) || ($doc['is_acknowledged'] ?? false);
                if (!empty($doc['read_at']) && (empty($merged['read_at']) || (isset($merged['read_at']) && $doc['read_at'] > $merged['read_at']))) {
                    $merged['read_at'] = $doc['read_at'];
                }
                if (!empty($doc['acknowledged_at']) && (empty($merged['acknowledged_at']) || (isset($merged['acknowledged_at']) && $doc['acknowledged_at'] > $merged['acknowledged_at']))) {
                    $merged['acknowledged_at'] = $doc['acknowledged_at'];
                }
                $globalByAid[$aid] = $merged;
            }
            foreach ($globalByAid as $aid => $doc) {
                $byAnnouncement[$aid] = $doc;
            }
        }

        return $byAnnouncement;
    }

    /**
     * Create or update a delivery (upsert). Sets delivered_at on insert.
     * For GLOBAL scope when setting read/ack, updates all deliveries for (user_id, announcement_id).
     *
     * @param string|ObjectId $announcementId
     * @param array $context
     * @param array $set e.g. ['is_read' => true, 'read_at' => new \MongoDB\BSON\UTCDateTime()]
     * @param string|null $scope visibility scope (Announcements::SCOPE_GLOBAL for user-level update)
     * @return bool
     */
    public function upsertDelivery($announcementId, array $context, array $set = [], ?string $scope = null): bool
    {
        $id = $announcementId instanceof ObjectId ? $announcementId : new ObjectId($announcementId);
        $now = new \MongoDB\BSON\UTCDateTime();
        $hasReadOrAck = isset($set['is_read']) || isset($set['is_acknowledged']);

        if ($scope === Announcements::SCOPE_GLOBAL && $hasReadOrAck) {
            $filter = $this->getScopeFilter($context, Announcements::SCOPE_GLOBAL);
            $filter['announcement_id'] = $id;
            $existing = $this->getCollection()->findOne($filter);
            $updateSet = array_merge($set, ['updated_at' => $now]);
            if (!isset($updateSet['read_at']) && !empty($set['is_read'])) {
                $updateSet['read_at'] = $now;
            }
            if (!isset($updateSet['acknowledged_at']) && !empty($set['is_acknowledged'])) {
                $updateSet['acknowledged_at'] = $now;
            }
            if (!$existing) {
                $doc = array_merge($filter, [
                    'announcement_id' => $id,
                    'shop_id' => $context['shop_id'] ?? $this->di->getRequester()->getSourceId(),
                    'is_read' => false,
                    'delivered_at' => $now,
                    'is_acknowledged' => false,
                ], $updateSet);
                $this->getCollection()->insertOne($doc);
                return true;
            }
            $this->getCollection()->updateMany($filter, ['$set' => $updateSet]);
            return true;
        }

        $filter = $this->getScopeFilter($context, null);
        $filter['announcement_id'] = $id;
        $existing = $this->getCollection()->findOne($filter);
        if (!$existing) {
            $doc = array_merge($filter, [
                'announcement_id' => $id,
                'is_read' => false,
                'delivered_at' => $now,
                'is_acknowledged' => false,
            ], $set);
            if (!isset($doc['read_at']) && !empty($doc['is_read'])) {
                $doc['read_at'] = $now;
            }
            $this->getCollection()->insertOne($doc);
            return true;
        }
        $update = ['$set' => array_merge($set, ['updated_at' => $now])];
        $this->getCollection()->updateOne($filter, $update);
        return true;
    }

    /**
     * Count unread announcement deliveries for a user (same context as getScopeFilter).
     * When visibilityScopeById is provided, GLOBAL announcements are counted at user level (unread if no delivery has is_read).
     *
     * @param array $context user_id, shop_id, target_shop_id, marketplace_id
     * @param array $visibilityScopeById map announcement_id (string) => visibility.scope; empty = context-only
     * @return int Count of unread announcements (is_read = false)
     */
    public function countUnread(array $context, array $visibilityScopeById = []): int
    {
        if (empty($visibilityScopeById)) {
            $query = $this->getScopeFilter($context, null);
            $query['is_read'] = false;
            return $this->getCollection()->countDocuments($query);
        }
        $globalIds = [];
        $otherIds = [];
        foreach (array_keys($visibilityScopeById) as $aid) {
            if (($visibilityScopeById[$aid] ?? null) === Announcements::SCOPE_GLOBAL) {
                $globalIds[] = $aid;
            } else {
                $otherIds[] = $aid;
            }
        }
        $total = 0;
        if (!empty($otherIds)) {
            $query = $this->getScopeFilter($context, null);
            $query['announcement_id'] = ['$in' => array_map(function ($id) {
                return $id instanceof ObjectId ? $id : new ObjectId($id);
            }, $otherIds)];
            $query['is_read'] = false;
            $total += $this->getCollection()->countDocuments($query);
        }
        if (!empty($globalIds)) {
            $objectIds = array_map(function ($id) {
                return $id instanceof ObjectId ? $id : new ObjectId($id);
            }, $globalIds);
            $readFilter = $this->getScopeFilter($context, Announcements::SCOPE_GLOBAL);
            $readFilter['announcement_id'] = ['$in' => $objectIds];
            $readFilter['is_read'] = true;
            $readIds = $this->getCollection()->distinct('announcement_id', $readFilter);
            $readCount = count($readIds);
            $total += count($globalIds) - $readCount;
        }
        return $total;
    }

    /**
     * Count for bell badge: unread + critical_compliance that are read but not acknowledged.
     * When visibilityScopeById is provided, GLOBAL announcements use user-level delivery match.
     *
     * @param array $context user_id, shop_id, target_shop_id, marketplace_id
     * @param array $visibilityScopeById map announcement_id (string) => visibility.scope; empty = context-only
     * @return int Count of deliveries needing attention (unread or critical and unacknowledged)
     */
    public function countUnreadForBadge(array $context, array $visibilityScopeById = []): int
    {
        $deliveriesColl = $this->getCollection();
        $needsAttentionMatch = [
            'ann.status' => Announcements::STATUS_PUBLISHED,
            '$or' => [
                ['is_read' => false],
                [
                    'ann.category' => Announcements::CATEGORY_CRITICAL_COMPLIANCE,
                    'is_acknowledged' => false
                ],
                [
                    'ann.display_rules.requires_acknowledgement' => true,
                    'is_acknowledged' => false
                ]
            ]
        ];

        if (!empty($visibilityScopeById)) {
            $globalIds = [];
            $otherIds = [];
            foreach (array_keys($visibilityScopeById) as $aid) {
                if (($visibilityScopeById[$aid] ?? null) === Announcements::SCOPE_GLOBAL) {
                    $globalIds[] = $aid;
                } else {
                    $otherIds[] = $aid;
                }
            }
            $total = 0;
            if (!empty($otherIds)) {
                $scopeFilter = $this->getScopeFilter($context, null);
                $scopeFilter['announcement_id'] = ['$in' => array_map(function ($id) {
                    return $id instanceof ObjectId ? $id : new ObjectId($id);
                }, $otherIds)];
                $pipeline = [
                    ['$match' => $scopeFilter],
                    ['$lookup' => [
                        'from' => 'announcements',
                        'localField' => 'announcement_id',
                        'foreignField' => '_id',
                        'as' => 'ann'
                    ]],
                    ['$unwind' => ['path' => '$ann', 'preserveNullAndEmptyArrays' => false]],
                    ['$match' => $needsAttentionMatch],
                    ['$group' => ['_id' => '$announcement_id']],
                    ['$count' => 'n']
                ];
                $cursor = $deliveriesColl->aggregate($pipeline, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
                $result = $cursor->toArray();
                $total += isset($result[0]['n']) ? (int)$result[0]['n'] : 0;
            }
            if (!empty($globalIds)) {
                $globalObjectIds = array_map(function ($id) {
                    return $id instanceof ObjectId ? $id : new ObjectId($id);
                }, $globalIds);
                $scopeFilter = $this->getScopeFilter($context, Announcements::SCOPE_GLOBAL);
                $scopeFilter['announcement_id'] = ['$in' => $globalObjectIds];
                $pipeline = [
                    ['$match' => $scopeFilter],
                    ['$lookup' => [
                        'from' => 'announcements',
                        'localField' => 'announcement_id',
                        'foreignField' => '_id',
                        'as' => 'ann'
                    ]],
                    ['$unwind' => ['path' => '$ann', 'preserveNullAndEmptyArrays' => false]],
                    ['$match' => $needsAttentionMatch],
                    ['$group' => ['_id' => '$announcement_id']],
                    ['$count' => 'n']
                ];
                $cursor = $deliveriesColl->aggregate($pipeline, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
                $result = $cursor->toArray();
                $total += isset($result[0]['n']) ? (int)$result[0]['n'] : 0;
            }
            return $total;
        }

        $scopeFilter = $this->getScopeFilter($context, null);
        $pipeline = [
            ['$match' => $scopeFilter],
            [
                '$lookup' => [
                    'from' => 'announcements',
                    'localField' => 'announcement_id',
                    'foreignField' => '_id',
                    'as' => 'ann'
                ]
            ],
            ['$unwind' => ['path' => '$ann', 'preserveNullAndEmptyArrays' => false]],
            ['$match' => $needsAttentionMatch],
            ['$group' => ['_id' => '$announcement_id']],
            ['$count' => 'n']
        ];

        $cursor = $deliveriesColl->aggregate($pipeline, ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        $result = $cursor->toArray();
        return isset($result[0]['n']) ? (int)$result[0]['n'] : 0;
    }

    /**
     * Mark all deliveries in context as read
     *
     * @param array $context
     * @return int Modified count
     */
    public function markAllRead(array $context): int
    {
        $filter = $this->getScopeFilter($context);
        $filter['is_read'] = false;
        $now = new \MongoDB\BSON\UTCDateTime();
        $result = $this->getCollection()->updateMany(
            $filter,
            ['$set' => ['is_read' => true, 'read_at' => $now]]
        );
        return $result->getModifiedCount();
    }
}
