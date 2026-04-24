<?php

namespace App\Connector\Components;

use App\Connector\Models\Announcements;
use App\Connector\Models\AnnouncementDeliveries;
use App\Connector\Models\AnnouncementEngagement;
use App\Connector\Models\AnnouncementForms;
use App\Connector\Models\AnnouncementFormResponses;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class AnnouncementHelper extends \App\Core\Components\Base
{
    /**
     * Build context from DI (user, requester, app)
     *
     * @param array $context Optional override; if empty, resolved from DI
     * @return array user_id, shop_id, target_shop_id, marketplace_id, appTag
     */
    public function getContext(array $context = []): array
    {
        if (!empty($context) && isset($context['user_id'])) {
            return $context;
        }
        return [
            'user_id' => $this->di->getUser()->id,
            'shop_id' => $this->di->getRequester()->getSourceId(),
            'target_shop_id' => $this->di->getRequester()->getTargetId(),
            'marketplace_id' => $this->di->getRegistry()->getAppConfig()['marketplace'] ?? null,
            'appTag' => $this->di->getAppCode()->getAppTag()
        ];
    }

    /**
     * Visibility filter for announcements: published, not expired, visibility matches context.
     * Single source of truth for "who can see this announcement".
     *
     * @param array $context user_id, shop_id, target_shop_id, marketplace_id
     * @return array MongoDB filter (full $and)
     */
    public function getVisibilityFilter(array $context): array
    {
        $now = new UTCDateTime();
        $nowIso = $now->toDateTime()->format('Y-m-d\TH:i:s.v\Z');
        // Expiry: not set, or (date > now) or (string expiry_at > nowIso). Use $expr for type-safe comparison.
        $expiryExpr = [
            '$or' => [
                ['$in' => [['$type' => '$display_rules.expiry_at'], ['missing', 'undefined']]],
                [
                    '$and' => [
                        ['$eq' => [['$type' => '$display_rules.expiry_at'], 'date']],
                        ['$gt' => ['$display_rules.expiry_at', $now]]
                    ]
                ],
                [
                    '$and' => [
                        ['$eq' => [['$type' => '$display_rules.expiry_at'], 'string']],
                        ['$gt' => ['$display_rules.expiry_at', $nowIso]]
                    ]
                ]
            ]
        ];
        $conditions = [
            ['status' => Announcements::STATUS_PUBLISHED],
            ['$expr' => $expiryExpr],
        ];

        $visibilityOr = [['visibility.scope' => Announcements::SCOPE_GLOBAL]];
        if (!empty($context['shop_id'])) {
            $visibilityOr[] = [
                'visibility.scope' => Announcements::SCOPE_SHOP,
                'visibility.shop_ids' => $context['shop_id']
            ];
        }
        if (isset($context['target_shop_id']) && (string)$context['target_shop_id'] !== '') {
            $visibilityOr[] = [
                'visibility.scope' => Announcements::SCOPE_TARGET,
                'visibility.target_shop_ids' => $context['target_shop_id']
            ];
        }
        if (!empty($context['marketplace_id'])) {
            $visibilityOr[] = [
                'visibility.scope' => Announcements::SCOPE_MARKETPLACE,
                'visibility.marketplace_ids' => $context['marketplace_id']
            ];
        }
        if (!empty($context['user_id'])) {
            $visibilityOr[] = [
                'visibility.scope' => Announcements::SCOPE_USER,
                'visibility.user_ids' => $context['user_id']
            ];
        }
        $conditions[] = ['$or' => $visibilityOr];

        return ['$and' => $conditions];
    }

    /**
     * Get announcements for bell list with delivery create-on-first-seen and read/ack merge.
     * Rows = announcements that need attention (unread OR read but not acknowledged).
     * count = number of rows (synced with bell badge); unread_count = how many of those are unread.
     *
     * @param array $context From getContext()
     * @return array ['rows' => array, 'count' => int, 'unread_count' => int]
     */
    public function getAnnouncementsForBell(array $context = []): array
    {
        $context = $this->getContext($context);
        $filter = $this->getVisibilityFilter($context);

        /** @var Announcements $announcementsModel */
        $announcementsModel = $this->di->getObjectManager()->get(Announcements::class);
        /** @var AnnouncementDeliveries $deliveriesModel */
        $deliveriesModel = $this->di->getObjectManager()->get(AnnouncementDeliveries::class);

        $coll = $announcementsModel->getCollection();
        $cursor = $coll->find($filter, [
            'sort' => ['created_at' => -1],
            'limit' => 50,
            'typeMap' => ['root' => 'array', 'document' => 'array']
        ]);
        $rows = iterator_to_array($cursor);

        $announcementIds = array_map(function ($row) {
            return $row['_id'] ?? null;
        }, $rows);
        $announcementIds = array_filter($announcementIds);

        $scopeById = [];
        foreach ($rows as $row) {
            $aid = isset($row['_id']) ? (string)$row['_id'] : null;
            if ($aid) {
                $scopeById[$aid] = $row['visibility']['scope'] ?? '';
            }
        }
        $deliveriesByAnnouncement = $deliveriesModel->getDeliveriesByAnnouncements($announcementIds, $context, $scopeById);

        $unreadCount = 0;
        foreach ($rows as $i => $row) {
            $aid = isset($row['_id']) ? (string)$row['_id'] : null;
            if (!$aid) {
                continue;
            }
            $delivery = $deliveriesByAnnouncement[$aid] ?? null;
            $scope = $scopeById[$aid] ?? null;
            if (!$delivery) {
                $deliveriesModel->upsertDelivery($row['_id'], $context, [], $scope);
                $deliveriesByAnnouncement = $deliveriesModel->getDeliveriesByAnnouncements([$row['_id']], $context, [$aid => $scope]);
                $delivery = $deliveriesByAnnouncement[$aid] ?? [];
            }
            $rows[$i]['is_read'] = $delivery['is_read'] ?? false;
            $rows[$i]['read_at'] = $delivery['read_at'] ?? null;
            $rows[$i]['is_acknowledged'] = $delivery['is_acknowledged'] ?? false;
            $rows[$i]['acknowledged_at'] = $delivery['acknowledged_at'] ?? null;
            $rows[$i]['delivered_at'] = $delivery['delivered_at'] ?? null;
            if (empty($rows[$i]['is_read'])) {
                $unreadCount++;
            }
        }

        // Exclude only announcements that are both read AND acknowledged (align with badge: show unread or read-but-not-acknowledged)
        $rows = array_values(array_filter($rows, function ($row) {
            $read = $row['is_read'] ?? false;
            $ack = $row['is_acknowledged'] ?? false;
            return !$read || !$ack;
        }));

        $unreadCount = 0;
        foreach ($rows as $row) {
            if (empty($row['is_read'])) {
                $unreadCount++;
            }
        }

        return [
            'rows' => $rows,
            'count' => count($rows),
            'unread_count' => $unreadCount
        ];
    }

    /**
     * Mark one announcement as read for the context.
     * For GLOBAL scope, updates all deliveries for (user_id, announcement_id).
     *
     * @param string|ObjectId $announcementId
     * @param array $context
     * @return bool Success
     */
    public function markAnnouncementRead($announcementId, array $context = []): bool
    {
        $context = $this->getContext($context);
        /** @var Announcements $announcementsModel */
        $announcementsModel = $this->di->getObjectManager()->get(Announcements::class);
        $id = $announcementId instanceof ObjectId ? $announcementId : new ObjectId($announcementId);
        $announcement = $announcementsModel->getCollection()->findOne(
            ['_id' => $id],
            ['projection' => ['visibility.scope' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array']]
        );
        $scope = $announcement['visibility']['scope'] ?? null;
        /** @var AnnouncementDeliveries $deliveriesModel */
        $deliveriesModel = $this->di->getObjectManager()->get(AnnouncementDeliveries::class);
        $now = new UTCDateTime();
        $deliveriesModel->upsertDelivery($announcementId, $context, [
            'is_read' => true,
            'read_at' => $now
        ], $scope);
        /** @var AnnouncementEngagement $engagement */
        $engagement = $this->di->getObjectManager()->get(AnnouncementEngagement::class);
        $engagement->record($announcementId, 'viewed', $context, []);
        return true;
    }

    /**
     * Mark all announcements as read for the context.
     *
     * @param array $context
     * @return array ['modified_count' => int]
     */
    public function markAllAnnouncementsRead(array $context = []): array
    {
        $context = $this->getContext($context);
        /** @var AnnouncementDeliveries $deliveriesModel */
        $deliveriesModel = $this->di->getObjectManager()->get(AnnouncementDeliveries::class);
        $modifiedCount = $deliveriesModel->markAllRead($context);
        return ['modified_count' => $modifiedCount];
    }

    /**
     * Unread announcement count (scope-aware: GLOBAL at user level).
     *
     * @param array $context From getContext()
     * @return int
     */
    public function getUnreadCount(array $context = []): int
    {
        $context = $this->getContext($context);
        $filter = $this->getVisibilityFilter($context);
        /** @var Announcements $announcementsModel */
        $announcementsModel = $this->di->getObjectManager()->get(Announcements::class);
        $cursor = $announcementsModel->getCollection()->find($filter, [
            'projection' => ['_id' => 1, 'visibility.scope' => 1],
            'typeMap' => ['root' => 'array', 'document' => 'array']
        ]);
        $scopeById = [];
        foreach ($cursor as $row) {
            $aid = isset($row['_id']) ? (string)$row['_id'] : null;
            if ($aid) {
                $scopeById[$aid] = $row['visibility']['scope'] ?? '';
            }
        }
        /** @var AnnouncementDeliveries $deliveriesModel */
        $deliveriesModel = $this->di->getObjectManager()->get(AnnouncementDeliveries::class);
        return $deliveriesModel->countUnread($context, $scopeById);
    }

    /**
     * Badge count: unread + critical/requires_ack not acknowledged (scope-aware: GLOBAL at user level).
     *
     * @param array $context From getContext()
     * @return int
     */
    public function getUnreadCountForBadge(array $context = []): int
    {
        $context = $this->getContext($context);
        $filter = $this->getVisibilityFilter($context);
        /** @var Announcements $announcementsModel */
        $announcementsModel = $this->di->getObjectManager()->get(Announcements::class);
        $cursor = $announcementsModel->getCollection()->find($filter, [
            'projection' => ['_id' => 1, 'visibility.scope' => 1],
            'typeMap' => ['root' => 'array', 'document' => 'array']
        ]);
        $scopeById = [];
        foreach ($cursor as $row) {
            $aid = isset($row['_id']) ? (string)$row['_id'] : null;
            if ($aid) {
                $scopeById[$aid] = $row['visibility']['scope'] ?? '';
            }
        }
        /** @var AnnouncementDeliveries $deliveriesModel */
        $deliveriesModel = $this->di->getObjectManager()->get(AnnouncementDeliveries::class);
        return $deliveriesModel->countUnreadForBadge($context, $scopeById);
    }

    /**
     * Acknowledge an announcement. Allowed when category is critical_compliance, display_rules.requires_acknowledgement is true,
     * or announcement_type is form-eligible (survey_request, user_feedback).
     *
     * @param string|ObjectId $announcementId
     * @param array $context
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function acknowledgeAnnouncement($announcementId, array $context = []): array
    {
        $context = $this->getContext($context);
        /** @var Announcements $announcementsModel */
        $announcementsModel = $this->di->getObjectManager()->get(Announcements::class);
        $id = $announcementId instanceof ObjectId ? $announcementId : new ObjectId($announcementId);
        $announcement = $announcementsModel->getCollection()->findOne(
            ['_id' => $id],
            ['typeMap' => ['root' => 'array', 'document' => 'array']]
        );
        if (!$announcement) {
            return ['success' => false, 'error' => 'Announcement not found'];
        }
        $category = $announcement['category'] ?? '';
        $announcementType = $announcement['announcement_type'] ?? '';
        $displayRules = $announcement['display_rules'] ?? [];
        $requiresAck = !empty($displayRules['requires_acknowledgement']);
        $isFormEligible = in_array($announcementType, Announcements::getFormEligibleTypes(), true);
        $allowed = ($category === Announcements::CATEGORY_CRITICAL_COMPLIANCE) || $requiresAck || $isFormEligible;
        if (!$allowed) {
            return ['success' => false, 'error' => 'Acknowledge is only allowed for critical_compliance announcements, when requires_acknowledgement is set, or for form announcements (survey_request, user_feedback)'];
        }
        $now = new UTCDateTime();
        $scope = $announcement['visibility']['scope'] ?? null;
        /** @var AnnouncementDeliveries $deliveriesModel */
        $deliveriesModel = $this->di->getObjectManager()->get(AnnouncementDeliveries::class);
        $deliveriesModel->upsertDelivery($announcementId, $context, [
            'is_acknowledged' => true,
            'acknowledged_at' => $now
        ], $scope);
        /** @var AnnouncementEngagement $engagement */
        $engagement = $this->di->getObjectManager()->get(AnnouncementEngagement::class);
        $engagement->record($announcementId, 'acknowledged', $context, []);
        return ['success' => true, 'error' => null];
    }

    /**
     * Get form schema for an announcement. Only for published announcements with engagement type (survey_request, user_feedback).
     *
     * @param string|ObjectId $announcementId
     * @return array|null ['title' => string, 'description' => string, 'fields' => array] or null if not found / not eligible
     */
    public function getForm($announcementId): ?array
    {
        /** @var Announcements $announcementsModel */
        $announcementsModel = $this->di->getObjectManager()->get(Announcements::class);
        $id = $announcementId instanceof ObjectId ? $announcementId : new ObjectId($announcementId);
        $announcement = $announcementsModel->getCollection()->findOne(
            ['_id' => $id],
            ['typeMap' => ['root' => 'array', 'document' => 'array']]
        );
        if (!$announcement) {
            return null;
        }
        $status = $announcement['status'] ?? '';
        if ($status !== Announcements::STATUS_PUBLISHED) {
            return null;
        }
        $type = $announcement['announcement_type'] ?? '';
        if (!in_array($type, Announcements::getFormEligibleTypes(), true)) {
            return null;
        }
        /** @var AnnouncementForms $formsModel */
        $formsModel = $this->di->getObjectManager()->get(AnnouncementForms::class);
        $form = $formsModel->getByAnnouncementId($announcementId);
        if (!$form) {
            return null;
        }
        return [
            'id' => isset($form['_id']) ? (string)$form['_id'] : '',
            'announcement_id' => isset($form['announcement_id']) ? (string)$form['announcement_id'] : (string)$announcementId,
            'title' => $form['title'] ?? '',
            'description' => $form['description'] ?? '',
            'fields' => $form['fields'] ?? [],
        ];
    }

    /**
     * Validate form responses against form schema. Returns list of validation errors.
     *
     * @param array $fields Form fields from getForm
     * @param array $responses Key-value by field id
     * @return array List of error strings (empty if valid)
     */
    public function validateFormResponses(array $fields, array $responses): array
    {
        $errors = [];
        $maxTextLength = 500;
        $maxTextareaLength = 2000;
        foreach ($fields as $field) {
            $id = $field['id'] ?? null;
            if ($id === null) {
                continue;
            }
            $required = !empty($field['required']);
            $value = array_key_exists($id, $responses) ? $responses[$id] : null;
            if ($required && ($value === null || $value === '')) {
                $errors[] = "Field '{$id}' is required.";
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $type = $field['type'] ?? 'text';
            switch ($type) {
                case 'text':
                    if (!is_string($value) || strlen($value) > $maxTextLength) {
                        $errors[] = "Field '{$id}' must be text up to {$maxTextLength} characters.";
                    }
                    break;
                case 'textarea':
                    if (!is_string($value) || strlen($value) > $maxTextareaLength) {
                        $errors[] = "Field '{$id}' must be text up to {$maxTextareaLength} characters.";
                    }
                    break;
                case 'rating':
                    $scale = isset($field['scale']) ? (int)$field['scale'] : 5;
                    if (!is_numeric($value)) {
                        $errors[] = "Field '{$id}' must be a number.";
                    } else {
                        $v = (float)$value;
                        if ($v < 1 || $v > $scale) {
                            $errors[] = "Field '{$id}' must be between 1 and {$scale}.";
                        }
                    }
                    break;
                case 'radio':
                case 'select':
                    $options = $field['options'] ?? [];
                    if (!is_array($options) || !in_array($value, $options, true)) {
                        $errors[] = "Field '{$id}' must be one of the allowed options.";
                    }
                    break;
                case 'checkbox':
                    if (!is_bool($value) && !is_array($value)) {
                        $errors[] = "Field '{$id}' must be boolean or array.";
                    }
                    break;
                default:
                    if (!is_string($value)) {
                        $errors[] = "Field '{$id}' has invalid type.";
                    }
            }
        }
        return $errors;
    }

    /**
     * Submit form response. Validates, checks duplicate (409), inserts, records engagement, optionally marks read.
     *
     * @param string|ObjectId $announcementId
     * @param string|ObjectId $formId
     * @param array $responses Key-value by field id
     * @param array $context
     * @return array ['success' => bool, 'error' => string|null, 'code' => string|null] code 409 for duplicate
     */
    public function submitFormResponse($announcementId, $formId, array $responses, array $context = []): array
    {
        $context = $this->getContext($context);
        $form = $this->getForm($announcementId);
        if ($form === null) {
            return ['success' => false, 'error' => 'Announcement or form not found', 'code' => '404'];
        }
        /** @var AnnouncementForms $formsModel */
        $formsModel = $this->di->getObjectManager()->get(AnnouncementForms::class);
        $formDoc = $formsModel->getByAnnouncementId($announcementId);
        if (!$formDoc) {
            return ['success' => false, 'error' => 'Form not found', 'code' => '404'];
        }
        $fid = $formId instanceof ObjectId ? $formId : new ObjectId($formId);
        if ((string)($formDoc['_id'] ?? '') !== (string)$fid) {
            return ['success' => false, 'error' => 'Form does not belong to this announcement', 'code' => '400'];
        }
        /** @var AnnouncementFormResponses $responsesModel */
        $responsesModel = $this->di->getObjectManager()->get(AnnouncementFormResponses::class);
        if ($responsesModel->findResponse($announcementId, $formId, $context) !== null) {
            return ['success' => false, 'error' => 'Already submitted', 'code' => '409'];
        }
        $validationErrors = $this->validateFormResponses($form['fields'], $responses);
        if (!empty($validationErrors)) {
            return ['success' => false, 'error' => implode(' ', $validationErrors), 'code' => '400'];
        }
        $responsesModel->insertResponse($announcementId, $formId, $context, $responses);
        /** @var AnnouncementEngagement $engagement */
        $engagement = $this->di->getObjectManager()->get(AnnouncementEngagement::class);
        $engagement->record($announcementId, 'form_submitted', $context, ['form_id' => (string)$fid]);
        $this->markAnnouncementRead($announcementId, $context);
        return ['success' => true, 'error' => null, 'code' => null];
    }

    /**
     * Record an engagement event (append-only).
     *
     * @param string|ObjectId $announcementId
     * @param string $event e.g. viewed, clicked, cta_clicked, form_started, beta_join_clicked
     * @param array $context From getContext()
     * @param array $metadata Optional
     * @return bool
     */
    public function recordEngagement($announcementId, string $event, array $context = [], array $metadata = []): bool
    {
        $context = $this->getContext($context);
        /** @var AnnouncementEngagement $engagement */
        $engagement = $this->di->getObjectManager()->get(AnnouncementEngagement::class);
        return $engagement->record($announcementId, $event, $context, $metadata);
    }

    /**
     * Resolve user_ids that match announcement visibility (for WebSocket push). GLOBAL returns [] (defer to on-open).
     *
     * @param array $visibility visibility.scope, visibility.shop_ids, visibility.target_shop_ids, visibility.marketplace_ids
     * @param string|null $appTag Optional; used to scope by app
     * @return string[] List of user_id
     */
    public function resolveUserIdsByVisibility(array $visibility, ?string $appTag = null): array
    {
        $scope = $visibility['scope'] ?? '';
        if ($scope === Announcements::SCOPE_GLOBAL) {
            return [];
        }
        if ($scope === Announcements::SCOPE_USER) {
            $userIds = $visibility['user_ids'] ?? [];
            $userIds = array_values(array_filter(array_map('strval', (array) $userIds)));
            return $userIds;
        }
        $baseMongo = $this->di->getObjectManager()->create(\App\Core\Models\BaseMongo::class);
        $coll = $baseMongo->getCollectionForTable('user_details');
        $opts = ['typeMap' => ['root' => 'array', 'document' => 'array'], 'projection' => ['user_id' => 1]];
        $userIds = [];
        if ($scope === Announcements::SCOPE_SHOP) {
            $shopIds = $visibility['shop_ids'] ?? [];
            if (empty($shopIds)) {
                return [];
            }
            $cursor = $coll->find(
                ['shops._id' => ['$in' => array_map('strval', $shopIds)]],
                $opts
            );
            foreach ($cursor as $doc) {
                if (!empty($doc['user_id'])) {
                    $userIds[(string)$doc['user_id']] = true;
                }
            }
        } elseif ($scope === Announcements::SCOPE_TARGET) {
            $targetIds = $visibility['target_shop_ids'] ?? [];
            if (empty($targetIds)) {
                return [];
            }
            $cursor = $coll->find(
                ['shops.targets._id' => ['$in' => array_map('strval', $targetIds)]],
                $opts
            );
            foreach ($cursor as $doc) {
                if (!empty($doc['user_id'])) {
                    $userIds[(string)$doc['user_id']] = true;
                }
            }
        } elseif ($scope === Announcements::SCOPE_MARKETPLACE) {
            $marketplaceIds = $visibility['marketplace_ids'] ?? [];
            if (empty($marketplaceIds)) {
                return [];
            }
            $cursor = $coll->find(
                ['shops.marketplace' => ['$in' => (array)$marketplaceIds]],
                $opts
            );
            foreach ($cursor as $doc) {
                if (!empty($doc['user_id'])) {
                    $userIds[(string)$doc['user_id']] = true;
                }
            }
        }
        return array_keys($userIds);
    }

    /**
     * Push announcement to audience via WebSocket (on publish). Calls handleMessage for each resolved user_id.
     *
     * @param string|ObjectId $announcementId
     * @param string|null $appTag If null, uses getContext() appTag
     * @return int Number of users notified
     */
    public function pushAnnouncementToAudience($announcementId, ?string $appTag = null): int
    {
        /** @var Announcements $announcementsModel */
        $announcementsModel = $this->di->getObjectManager()->get(Announcements::class);
        $id = $announcementId instanceof ObjectId ? $announcementId : new ObjectId($announcementId);
        $announcement = $announcementsModel->getCollection()->findOne(
            ['_id' => $id],
            ['typeMap' => ['root' => 'array', 'document' => 'array']]
        );
        if (!$announcement) {
            return 0;
        }
        $visibility = $announcement['visibility'] ?? [];
        $userIds = $this->resolveUserIdsByVisibility($visibility, $appTag);
        if (empty($userIds)) {
            return 0;
        }
        $summary = [
            '_id' => (string)$announcement['_id'],
            'title' => $announcement['title'] ?? '',
            'category' => $announcement['category'] ?? '',
            'severity' => $announcement['severity'] ?? 'info',
            'display_rules' => $announcement['display_rules'] ?? [],
        ];
        $connectorHelper = $this->di->getObjectManager()->get(Helper::class);
        $notified = 0;
        foreach ($userIds as $userId) {
            try {
                $connectorHelper->handleMessage([
                    'user_id' => $userId,
                    'announcement' => $summary,
                ]);
                $notified++;
            } catch (\Throwable $e) {
                // log and continue
            }
        }
        return $notified;
    }
}
