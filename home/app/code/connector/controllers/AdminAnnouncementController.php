<?php

namespace App\Connector\Controllers;

use App\Connector\Components\AnnouncementHelper;
use App\Connector\Models\AnnouncementDeliveries;
use App\Connector\Models\AnnouncementEngagement;
use App\Connector\Models\AnnouncementFormResponses;
use App\Connector\Models\AnnouncementForms;
use App\Connector\Models\Announcements;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Admin announcement APIs: create, update, approve, publish, disable, resolve, list, get.
 * All under connector module with admin auth (caller must be admin/BDA).
 */
class AdminAnnouncementController extends \App\Core\Controllers\BaseController
{
    private function getHelper(): AnnouncementHelper
    {
        return $this->di->getObjectManager()->get(AnnouncementHelper::class);
    }

    private function getAnnouncementsCollection(): \MongoDB\Collection
    {
        return $this->di->getObjectManager()->get(Announcements::class)->getCollection();
    }

    /**
     * Validate visibility: exactly one scope; ids match scope.
     */
    private function validateVisibility(array $visibility): ?string
    {
        $scope = $visibility['scope'] ?? '';
        $validScopes = [Announcements::SCOPE_GLOBAL, Announcements::SCOPE_SHOP, Announcements::SCOPE_TARGET, Announcements::SCOPE_MARKETPLACE, Announcements::SCOPE_USER];
        if (!in_array($scope, $validScopes, true)) {
            return 'visibility.scope must be one of: ' . implode(', ', $validScopes);
        }
        $hasShopIds = !empty($visibility['shop_ids']);
        $hasTargetIds = !empty($visibility['target_shop_ids']);
        $hasMarketplaceIds = !empty($visibility['marketplace_ids']);
        $hasUserIds = !empty($visibility['user_ids']);
        $count = ($scope === Announcements::SCOPE_SHOP ? 1 : 0) + ($hasShopIds ? 1 : 0)
            + ($scope === Announcements::SCOPE_TARGET ? 1 : 0) + ($hasTargetIds ? 1 : 0)
            + ($scope === Announcements::SCOPE_MARKETPLACE ? 1 : 0) + ($hasMarketplaceIds ? 1 : 0);
        if ($scope === Announcements::SCOPE_GLOBAL && ($hasShopIds || $hasTargetIds || $hasMarketplaceIds || $hasUserIds)) {
            return 'visibility.scope GLOBAL must not set shop_ids, target_shop_ids, marketplace_ids, or user_ids';
        }
        if ($scope === Announcements::SCOPE_SHOP && !$hasShopIds) {
            return 'visibility.shop_ids required when scope is shop';
        }
        if ($scope === Announcements::SCOPE_TARGET && !$hasTargetIds) {
            return 'visibility.target_shop_ids required when scope is target';
        }
        if ($scope === Announcements::SCOPE_MARKETPLACE && !$hasMarketplaceIds) {
            return 'visibility.marketplace_ids required when scope is marketplace';
        }
        if ($scope === Announcements::SCOPE_USER && !$hasUserIds) {
            return 'visibility.user_ids required when scope is user';
        }
        if ($scope === Announcements::SCOPE_USER) {
            $userIds = $visibility['user_ids'];
            if (!is_array($userIds)) {
                $userIds = $userIds === null || $userIds === '' ? [] : (array) $userIds;
            }
            $userIds = array_values(array_filter(array_map('strval', $userIds)));
            if (empty($userIds)) {
                return 'visibility.user_ids must be a non-empty array when scope is user';
            }
        }
        return null;
    }

    /**
     * Normalize display_rules for storage: convert expiry_at from ISO string to BSON Date when present.
     *
     * @param array|object $displayRules
     * @return array
     */
    private function normalizeDisplayRules($displayRules): array
    {
        $rules = is_array($displayRules) ? $displayRules : (array)$displayRules;
        if (isset($rules['expiry_at']) && $rules['expiry_at'] !== null && $rules['expiry_at'] !== '') {
            $exp = $rules['expiry_at'];
            if (is_string($exp)) {
                $ts = strtotime($exp);
                $rules['expiry_at'] = $ts !== false ? new UTCDateTime($ts * 1000) : $exp;
            }
        }
        return $rules;
    }

    /**
     * POST create. Body: category, announcement_type, title, summary, content, severity, cta, display_rules, visibility.
     */
    public function createAction()
    {
        try {
            $data = $this->getRequestData();
            $category = $data['category'] ?? '';
            $announcementType = $data['announcement_type'] ?? $data['type'] ?? '';
            $title = $data['title'] ?? '';
            $visibility = $data['visibility'] ?? [];

            if (!in_array($category, Announcements::getCategories(), true)) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Invalid category',
                    'status_code' => 400
                ]);
            }
            if (strlen($title) < 1) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'title is required',
                    'status_code' => 400
                ]);
            }
            $visibilityErr = $this->validateVisibility($visibility);
            if ($visibilityErr !== null) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => $visibilityErr,
                    'status_code' => 400
                ]);
            }
            if ($announcementType !== '' && !in_array($announcementType, Announcements::getAnnouncementTypes(), true)) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Invalid announcement_type',
                    'status_code' => 400
                ]);
            }

            $now = new UTCDateTime();
            $userId = $this->di->getUser()->id ?? null;
            $displayRules = $this->normalizeDisplayRules($data['display_rules'] ?? []);
            $doc = [
                'category' => $category,
                'announcement_type' => $announcementType,
                'title' => $title,
                'summary' => $data['summary'] ?? '',
                'content' => $data['content'] ?? '',
                'summary_render_modal'=> $data['summary_render_modal']?? false,
                'severity' => $data['severity'] ?? 'info',
                'cta' => $data['cta'] ?? (object)[],
                'display_rules' => $displayRules,
                'visibility' => $visibility,
                'status' => Announcements::STATUS_DRAFT,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
                'version' => 1,
            ];
            $result = $this->getAnnouncementsCollection()->insertOne($doc);
            $id = (string)$result->getInsertedId();
            return $this->prepareResponse([
                'success' => true,
                'data' => ['id' => $id, '_id' => $id]
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * PUT/PATCH update. Only when status is draft or approved.
     */
    public function updateAction()
    {
        try {
            $data = $this->getRequestData();
            $id = $data['id'] ?? $data['_id'] ?? $this->request->get('id');
            if (!$id) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'id required',
                    'status_code' => 400
                ]);
            }
            $oid = new ObjectId($id);
            $coll = $this->getAnnouncementsCollection();
            $existing = $coll->findOne(['_id' => $oid], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
            if (!$existing) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Announcement not found',
                    'status_code' => 404
                ]);
            }
            $status = $existing['status'] ?? '';
            if (!in_array($status, [Announcements::STATUS_DRAFT, Announcements::STATUS_APPROVED], true)) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Can only update draft or approved announcements',
                    'status_code' => 400
                ]);
            }

            $visibility = $data['visibility'] ?? $existing['visibility'] ?? [];
            $visibilityErr = $this->validateVisibility($visibility);
            if ($visibilityErr !== null) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => $visibilityErr,
                    'status_code' => 400
                ]);
            }

            $category = $data['category'] ?? $existing['category'];
            if (!in_array($category, Announcements::getCategories(), true)) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Invalid category',
                    'status_code' => 400
                ]);
            }
            $announcementType = $data['announcement_type'] ?? $data['type'] ?? $existing['announcement_type'] ?? '';
            if ($announcementType !== '' && !in_array($announcementType, Announcements::getAnnouncementTypes(), true)) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Invalid announcement_type',
                    'status_code' => 400
                ]);
            }

            $now = new UTCDateTime();
            $displayRules = $this->normalizeDisplayRules(
                $data['display_rules'] ?? $existing['display_rules'] ?? []
            );
            $set = [
                'updated_at' => $now,
                'category' => $data['category'] ?? $existing['category'] ?? '',
                'announcement_type' => $announcementType,
                'title' => $data['title'] ?? $existing['title'] ?? '',
                'summary' => $data['summary'] ?? $existing['summary'] ?? '',
                'content' => $data['content'] ?? $existing['content'] ?? '',
                'severity' => $data['severity'] ?? $existing['severity'] ?? 'info',
                'cta' => $data['cta'] ?? $existing['cta'] ?? (object)[],
                'display_rules' => $displayRules,
                'visibility' => $visibility,
            ];
            $coll->updateOne(['_id' => $oid], ['$set' => $set]);
            return $this->prepareResponse(['success' => true, 'data' => ['id' => $id]]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST approve. id required; status must be draft.
     */
    public function approveAction()
    {
        try {
            $data = array_merge($this->request->get() ?? [], $this->getRequestData());
            $id = $data['id'] ?? $data['_id'] ?? null;
            if (!$id) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'id required',
                    'status_code' => 400
                ]);
            }
            $oid = new ObjectId($id);
            $coll = $this->getAnnouncementsCollection();
            $existing = $coll->findOne(['_id' => $oid], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
            if (!$existing) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Announcement not found',
                    'status_code' => 404
                ]);
            }
            if (($existing['status'] ?? '') !== Announcements::STATUS_DRAFT) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Only draft can be approved',
                    'status_code' => 400
                ]);
            }
            $userId = $this->di->getUser()->id ?? null;
            $now = new UTCDateTime();
            $coll->updateOne(
                ['_id' => $oid],
                ['$set' => [
                    'approved_by' => $userId,
                    'approved_at' => $now,
                    'status' => Announcements::STATUS_APPROVED,
                    'updated_at' => $now,
                ]]
            );
            return $this->prepareResponse(['success' => true]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST publish. id required; status must be approved (or draft if skip-approval). Calls pushAnnouncementToAudience.
     */
    public function publishAction()
    {
        try {
            $data = array_merge($this->request->get() ?? [], $this->getRequestData());
            $id = $data['id'] ?? $data['_id'] ?? null;
            if (!$id) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'id required',
                    'status_code' => 400
                ]);
            }
            $oid = new ObjectId($id);
            $coll = $this->getAnnouncementsCollection();
            $existing = $coll->findOne(['_id' => $oid], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
            if (!$existing) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Announcement not found',
                    'status_code' => 404
                ]);
            }
            $status = $existing['status'] ?? '';
            if (!in_array($status, [Announcements::STATUS_APPROVED, Announcements::STATUS_DRAFT], true)) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Only approved or draft can be published',
                    'status_code' => 400
                ]);
            }
            $now = new UTCDateTime();
            $coll->updateOne(
                ['_id' => $oid],
                ['$set' => ['status' => Announcements::STATUS_PUBLISHED, 'updated_at' => $now]]
            );
            $helper = $this->getHelper();
            $notified = $helper->pushAnnouncementToAudience($id);
            return $this->prepareResponse([
                'success' => true,
                'data' => ['notified_count' => $notified]
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST disable. id required.
     */
    public function disableAction()
    {
        try {
            $data = array_merge($this->request->get() ?? [], $this->getRequestData());
            $id = $data['id'] ?? $data['_id'] ?? null;
            if (!$id) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'id required',
                    'status_code' => 400
                ]);
            }
            $oid = new ObjectId($id);
            $now = new UTCDateTime();
            $result = $this->getAnnouncementsCollection()->updateOne(
                ['_id' => $oid],
                ['$set' => ['status' => Announcements::STATUS_DISABLED, 'updated_at' => $now]]
            );
            if ($result->getModifiedCount() === 0) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Announcement not found',
                    'status_code' => 404
                ]);
            }
            return $this->prepareResponse(['success' => true]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST resolve. id required; category must be critical_compliance.
     */
    public function resolveAction()
    {
        try {
            $data = array_merge($this->request->get() ?? [], $this->getRequestData());
            $id = $data['id'] ?? $data['_id'] ?? null;
            if (!$id) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'id required',
                    'status_code' => 400
                ]);
            }
            $oid = new ObjectId($id);
            $coll = $this->getAnnouncementsCollection();
            $existing = $coll->findOne(['_id' => $oid], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
            if (!$existing) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Announcement not found',
                    'status_code' => 404
                ]);
            }
            if (($existing['category'] ?? '') !== Announcements::CATEGORY_CRITICAL_COMPLIANCE) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Only critical_compliance can be resolved',
                    'status_code' => 400
                ]);
            }
            $now = new UTCDateTime();
            $coll->updateOne(
                ['_id' => $oid],
                ['$set' => ['status' => Announcements::STATUS_RESOLVED, 'updated_at' => $now]]
            );
            return $this->prepareResponse(['success' => true]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST delete. id required. Deletes the announcement and all related data:
     * linked form, deliveries, engagement events, form responses.
     */
    public function deleteAction()
    {
        try {
            $data = array_merge($this->request->get() ?? [], $this->getRequestData());
            $id = $data['id'] ?? $data['_id'] ?? null;
            if (!$id) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'id required',
                    'status_code' => 400
                ]);
            }
            $oid = new ObjectId($id);
            $coll = $this->getAnnouncementsCollection();
            $existing = $coll->findOne(['_id' => $oid], ['projection' => ['_id' => 1]]);
            if (!$existing) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Announcement not found',
                    'status_code' => 404
                ]);
            }
            /** @var AnnouncementForms $formsModel */
            $formsModel = $this->di->getObjectManager()->get(AnnouncementForms::class);
            $formsModel->getCollection()->deleteOne(['announcement_id' => $oid]);
            $this->di->getObjectManager()->get(AnnouncementDeliveries::class)->getCollection()->deleteMany(['announcement_id' => $oid]);
            $this->di->getObjectManager()->get(AnnouncementEngagement::class)->getCollection()->deleteMany(['announcement_id' => $oid]);
            $this->di->getObjectManager()->get(AnnouncementFormResponses::class)->getCollection()->deleteMany(['announcement_id' => $oid]);
            $coll->deleteOne(['_id' => $oid]);
            return $this->prepareResponse([
                'success' => true,
                'data' => ['id' => $id],
                'message' => 'Announcement and all related data (form, deliveries, engagement, form responses) deleted'
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * GET list with pagination. Query: status?, category?, page (default 1), limit (default 20, max 100).
     * Response: rows, total, total_pages, page, limit.
     */
    public function listAction()
    {
        try {
            $status = $this->request->get('status');
            $category = $this->request->get('category');
            $page = max(1, (int)$this->request->get('page', 1));
            $limit = min(100, max(1, (int)$this->request->get('limit', 20)));
            $skip = ($page - 1) * $limit;

            $filter = [];
            if ($status !== null && $status !== '') {
                $filter['status'] = $status;
            }
            if ($category !== null && $category !== '') {
                $filter['category'] = $category;
            }

            $coll = $this->getAnnouncementsCollection();
            $total = $coll->countDocuments($filter);
            $cursor = $coll->find($filter, [
                'sort' => ['created_at' => -1],
                'skip' => $skip,
                'limit' => $limit,
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ]);
            $rows = iterator_to_array($cursor);
            foreach ($rows as $i => $row) {
                if (isset($row['_id'])) {
                    $rows[$i]['id'] = (string)$row['_id'];
                }
            }
            $totalPages = $total > 0 ? (int)ceil($total / $limit) : 1;
            return $this->prepareResponse([
                'success' => true,
                'data' => [
                    'rows' => $rows,
                    'total' => $total,
                    'total_pages' => $totalPages,
                    'page' => $page,
                    'limit' => $limit,
                ]
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * GET count. Query: status?, category?. Returns total announcement count (same filters as list).
     * Use for pagination or header display without loading list.
     */
    public function countAction()
    {
        try {
            $status = $this->request->get('status');
            $category = $this->request->get('category');
            $filter = [];
            if ($status !== null && $status !== '') {
                $filter['status'] = $status;
            }
            if ($category !== null && $category !== '') {
                $filter['category'] = $category;
            }
            $total = $this->getAnnouncementsCollection()->countDocuments($filter);
            return $this->prepareResponse([
                'success' => true,
                'data' => ['total' => $total]
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * GET form schema for an announcement (admin: any status). Query: id or announcement_id.
     */
    public function getFormAction()
    {
        try {
            $id = $this->request->get('id') ?? $this->request->get('announcement_id');
            if (!$id) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'id or announcement_id required',
                    'status_code' => 400
                ]);
            }
            /** @var AnnouncementForms $formsModel */
            $formsModel = $this->di->getObjectManager()->get(AnnouncementForms::class);
            $form = $formsModel->getByAnnouncementId($id);
            if (!$form) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Form not found',
                    'status_code' => 404
                ]);
            }
            $data = [
                'id' => (string)($form['_id'] ?? ''),
                'announcement_id' => isset($form['announcement_id']) ? (string)$form['announcement_id'] : $id,
                'title' => $form['title'] ?? '',
                'description' => $form['description'] ?? '',
                'fields' => $form['fields'] ?? [],
            ];
            return $this->prepareResponse([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST save form for an announcement (create or update). Body: announcement_id, title?, description?, fields?.
     */
    public function saveFormAction()
    {
        try {
            $data = $this->getRequestData();
            $announcementId = $data['announcement_id'] ?? $data['id'] ?? $this->request->get('announcement_id');
            if (!$announcementId) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'announcement_id required',
                    'status_code' => 400
                ]);
            }
            $oid = new ObjectId($announcementId);
            $coll = $this->getAnnouncementsCollection();
            $existing = $coll->findOne(['_id' => $oid], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
            if (!$existing) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Announcement not found',
                    'status_code' => 404
                ]);
            }

            $formsModel = $this->di->getObjectManager()->get(AnnouncementForms::class);
            $formsColl = $formsModel->getCollection();
            $title = $data['title'] ?? '';
            $description = $data['description'] ?? '';
            $fields = $data['fields'] ?? [];
            if (!is_array($fields)) {
                $fields = [];
            }

            $now = new UTCDateTime();
            $userId = $this->di->getUser()->id ?? null;
            $existingForm = $formsModel->getByAnnouncementId($announcementId);

            if ($existingForm) {
                $formsColl->updateOne(
                    ['announcement_id' => $oid],
                    ['$set' => [
                        'title' => $title,
                        'description' => $description,
                        'fields' => $fields,
                        'updated_at' => $now,
                    ]]
                );
                $formId = (string)$existingForm['_id'];
            } else {
                $doc = [
                    'announcement_id' => $oid,
                    'title' => $title,
                    'description' => $description,
                    'fields' => $fields,
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $formsColl->insertOne($doc);
                $formId = (string)$doc['_id'];
            }
            return $this->prepareResponse([
                'success' => true,
                'data' => ['id' => $formId, 'announcement_id' => $announcementId]
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * GET single announcement by id.
     */
    public function getAction()
    {
        try {
            $id = $this->request->get('id') ?? $this->request->get('_id');
            if (!$id) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'id required',
                    'status_code' => 400
                ]);
            }
            $oid = new ObjectId($id);
            $doc = $this->getAnnouncementsCollection()->findOne(
                ['_id' => $oid],
                ['typeMap' => ['root' => 'array', 'document' => 'array']]
            );
            if (!$doc) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Announcement not found',
                    'status_code' => 404
                ]);
            }
            $doc['id'] = (string)$doc['_id'];
            return $this->prepareResponse([
                'success' => true,
                'data' => $doc
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * GET stats. Query: announcement_id?, category?, date_from?, date_to?, group_by (announcement|category|day).
     */
    public function statsAction()
    {
        try {
            $announcementId = $this->request->get('announcement_id');
            $category = $this->request->get('category');
            $dateFrom = $this->request->get('date_from');
            $dateTo = $this->request->get('date_to');
            $groupBy = $this->request->get('group_by', 'announcement');

            $engColl = $this->di->getObjectManager()->get(AnnouncementEngagement::class)->getCollection();
            $delColl = $this->di->getObjectManager()->get(AnnouncementDeliveries::class)->getCollection();
            $annColl = $this->di->getObjectManager()->get(Announcements::class)->getCollection();
            $opts = ['typeMap' => ['root' => 'array', 'document' => 'array']];

            $matchEng = [];
            if ($announcementId) {
                $matchEng['announcement_id'] = new ObjectId($announcementId);
            }
            if ($dateFrom || $dateTo) {
                $matchEng['created_at'] = [];
                if ($dateFrom) {
                    $matchEng['created_at']['$gte'] = new UTCDateTime(strtotime($dateFrom) * 1000);
                }
                if ($dateTo) {
                    $matchEng['created_at']['$lte'] = new UTCDateTime(strtotime($dateTo) * 1000);
                }
            }
            $matchEngFilter = $matchEng === [] ? (object)[] : $matchEng;

            $eventCounts = [];
            $cursor = $engColl->aggregate([
                ['$match' => $matchEngFilter],
                ['$group' => [
                    '_id' => [
                        'announcement_id' => '$announcement_id',
                        'event' => '$event'
                    ],
                    'count' => ['$sum' => 1]
                ]]
            ], $opts);
            foreach ($cursor as $row) {
                $aid = isset($row['_id']['announcement_id']) ? (string)$row['_id']['announcement_id'] : '';
                $event = $row['_id']['event'] ?? '';
                if (!isset($eventCounts[$aid])) {
                    $eventCounts[$aid] = [
                        'delivered_count' => 0,
                        'read_count' => 0,
                        'acknowledged_count' => 0,
                        'click_count' => 0,
                        'cta_click_count' => 0,
                        'form_started_count' => 0,
                        'form_submitted_count' => 0,
                    ];
                }
                if ($event === 'viewed' || $event === 'clicked') {
                    $eventCounts[$aid]['click_count'] += $row['count'];
                }
                if ($event === 'cta_clicked') {
                    $eventCounts[$aid]['cta_click_count'] = $row['count'];
                }
                if ($event === 'form_started') {
                    $eventCounts[$aid]['form_started_count'] = $row['count'];
                }
                if ($event === 'form_submitted') {
                    $eventCounts[$aid]['form_submitted_count'] = $row['count'];
                }
                if ($event === 'acknowledged') {
                    $eventCounts[$aid]['acknowledged_count'] = $row['count'];
                }
            }

            $delMatch = [];
            if ($announcementId) {
                $delMatch['announcement_id'] = new ObjectId($announcementId);
            }
            $delMatchFilter = $delMatch === [] ? (object)[] : $delMatch;
            $delivered = $delColl->aggregate([
                ['$match' => $delMatchFilter],
                ['$group' => [
                    '_id' => '$announcement_id',
                    'delivered' => ['$sum' => 1],
                    'read' => ['$sum' => ['$cond' => [['$eq' => ['$is_read', true]], 1, 0]]],
                    'ack' => ['$sum' => ['$cond' => [['$eq' => ['$is_acknowledged', true]], 1, 0]]]
                ]]
            ], $opts);
            foreach ($delivered as $row) {
                $aid = isset($row['_id']) ? (string)$row['_id'] : '';
                if (!isset($eventCounts[$aid])) {
                    $eventCounts[$aid] = [
                        'delivered_count' => 0, 'read_count' => 0, 'acknowledged_count' => 0,
                        'click_count' => 0, 'cta_click_count' => 0, 'form_started_count' => 0, 'form_submitted_count' => 0,
                    ];
                }
                $eventCounts[$aid]['delivered_count'] = $row['delivered'] ?? 0;
                $eventCounts[$aid]['read_count'] = $row['read'] ?? 0;
                $eventCounts[$aid]['acknowledged_count'] = $row['ack'] ?? 0;
            }

            $annFilter = [];
            if ($announcementId) {
                $annFilter['_id'] = new ObjectId($announcementId);
            }
            if ($category) {
                $annFilter['category'] = $category;
            }
            $annList = $annColl->find($annFilter, $opts);
            $rows = [];
            foreach ($annList as $ann) {
                $aid = (string)$ann['_id'];
                $rows[] = array_merge([
                    'announcement_id' => $aid,
                    'category' => $ann['category'] ?? '',
                    'announcement_type' => $ann['announcement_type'] ?? '',
                    'title' => $ann['title'] ?? '',
                    'created_at' => isset($ann['created_at']) ? $ann['created_at']->toDateTime()->format('c') : null,
                ], $eventCounts[$aid] ?? [
                    'delivered_count' => 0, 'read_count' => 0, 'acknowledged_count' => 0,
                    'click_count' => 0, 'cta_click_count' => 0, 'form_started_count' => 0, 'form_submitted_count' => 0,
                ]);
            }
            return $this->prepareResponse([
                'success' => true,
                'data' => ['rows' => $rows]
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * GET engagement report. Query: announcement_id?, event?, date_from?, date_to?, limit?, skip?.
     */
    public function engagementReportAction()
    {
        try {
            $announcementId = $this->request->get('announcement_id');
            $event = $this->request->get('event');
            $dateFrom = $this->request->get('date_from');
            $dateTo = $this->request->get('date_to');
            $limit = min(500, max(1, (int)$this->request->get('limit', 50)));
            $skip = max(0, (int)$this->request->get('skip', 0));

            $filter = [];
            if ($announcementId) {
                $filter['announcement_id'] = new ObjectId($announcementId);
            }
            if ($event !== null && $event !== '') {
                $filter['event'] = $event;
            }
            if ($dateFrom || $dateTo) {
                $filter['created_at'] = [];
                if ($dateFrom) {
                    $filter['created_at']['$gte'] = new UTCDateTime(strtotime($dateFrom) * 1000);
                }
                if ($dateTo) {
                    $filter['created_at']['$lte'] = new UTCDateTime(strtotime($dateTo) * 1000);
                }
            }

            $engColl = $this->di->getObjectManager()->get(AnnouncementEngagement::class)->getCollection();
            $total = $engColl->countDocuments($filter);
            $cursor = $engColl->find($filter, [
                'sort' => ['created_at' => -1],
                'skip' => $skip,
                'limit' => $limit,
                'typeMap' => ['root' => 'array', 'document' => 'array']
            ]);
            $rows = [];
            foreach ($cursor as $doc) {
                $doc['announcement_id'] = isset($doc['announcement_id']) ? (string)$doc['announcement_id'] : '';
                $doc['created_at'] = isset($doc['created_at']) ? $doc['created_at']->toDateTime()->format('c') : null;
                $rows[] = $doc;
            }
            return $this->prepareResponse([
                'success' => true,
                'data' => ['rows' => $rows, 'total' => $total, 'limit' => $limit, 'skip' => $skip]
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * GET form responses list (paginated) for admin. Query: announcement_id or form_id, page (default 1), limit (default 20, max 100).
     */
    public function formResponsesListAction()
    {
        try {
            $announcementId = $this->request->get('announcement_id');
            $formId = $this->request->get('form_id');
            $page = max(1, (int)$this->request->get('page', 1));
            $limit = min(100, max(1, (int)$this->request->get('limit', 20)));
            $skip = ($page - 1) * $limit;

            if (!$announcementId && !$formId) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'announcement_id or form_id required',
                    'status_code' => 400
                ]);
            }

            $filter = [];
            if ($announcementId) {
                $filter['announcement_id'] = new ObjectId($announcementId);
            }
            if ($formId) {
                $filter['form_id'] = new ObjectId($formId);
            }

            $responsesColl = $this->di->getObjectManager()->get(AnnouncementFormResponses::class)->getCollection();
            $opts = ['typeMap' => ['root' => 'array', 'document' => 'array']];

            $total = $responsesColl->countDocuments($filter);
            $cursor = $responsesColl->find($filter, [
                'sort' => ['submitted_at' => -1],
                'skip' => $skip,
                'limit' => $limit,
            ] + $opts);
            $rowsRaw = iterator_to_array($cursor);

            $fieldIds = [];
            foreach ($rowsRaw as $row) {
                foreach (array_keys($row['responses'] ?? []) as $fid) {
                    $fieldIds[$fid] = true;
                }
            }
            $fieldIds = array_keys($fieldIds);

            $rows = [];
            foreach ($rowsRaw as $row) {
                $r = [
                    'id' => isset($row['_id']) ? (string)$row['_id'] : '',
                    'announcement_id' => isset($row['announcement_id']) ? (string)$row['announcement_id'] : '',
                    'form_id' => isset($row['form_id']) ? (string)$row['form_id'] : '',
                    'user_id' => $row['user_id'] ?? '',
                    'shop_id' => $row['shop_id'] ?? '',
                    'target_shop_id' => $row['target_shop_id'] ?? '',
                    'submitted_at' => isset($row['submitted_at']) ? $row['submitted_at']->toDateTime()->format('c') : null,
                ];
                foreach ($fieldIds as $fid) {
                    $r['responses'][$fid] = $row['responses'][$fid] ?? null;
                }
                $rows[] = $r;
            }

            $totalPages = $total > 0 ? (int)ceil($total / $limit) : 1;
            return $this->prepareResponse([
                'success' => true,
                'data' => [
                    'rows' => $rows,
                    'total' => $total,
                    'total_pages' => $totalPages,
                    'page' => $page,
                    'limit' => $limit,
                ]
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * GET form responses export. Query: announcement_id or form_id, format=csv (default JSON).
     */
    public function formResponsesExportAction()
    {
        try {
            $announcementId = $this->request->get('announcement_id');
            $formId = $this->request->get('form_id');
            $format = $this->request->get('format', 'json');

            if (!$announcementId && !$formId) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'announcement_id or form_id required',
                    'status_code' => 400
                ]);
            }

            $filter = [];
            if ($announcementId) {
                $filter['announcement_id'] = new ObjectId($announcementId);
            }
            if ($formId) {
                $filter['form_id'] = new ObjectId($formId);
            }

            $responsesColl = $this->di->getObjectManager()->get(AnnouncementFormResponses::class)->getCollection();
            $formsColl = $this->di->getObjectManager()->get(AnnouncementForms::class)->getCollection();
            $opts = ['typeMap' => ['root' => 'array', 'document' => 'array']];

            $cursor = $responsesColl->find($filter, $opts);
            $rows = iterator_to_array($cursor);

            $fieldIds = [];
            foreach ($rows as $row) {
                foreach (array_keys($row['responses'] ?? []) as $fid) {
                    $fieldIds[$fid] = true;
                }
            }
            $fieldIds = array_keys($fieldIds);

            if ($format === 'csv') {
                $header = array_merge(['user_id', 'shop_id', 'target_shop_id', 'submitted_at'], $fieldIds);
                $lines = [implode(',', array_map(function ($h) {
                    return '"' . str_replace('"', '""', $h) . '"';
                }, $header))];
                foreach ($rows as $row) {
                    $submittedAt = isset($row['submitted_at']) ? $row['submitted_at']->toDateTime()->format('c') : '';
                    $arr = [
                        '"' . str_replace('"', '""', (string)($row['user_id'] ?? '')) . '"',
                        '"' . str_replace('"', '""', (string)($row['shop_id'] ?? '')) . '"',
                        '"' . str_replace('"', '""', (string)($row['target_shop_id'] ?? '')) . '"',
                        '"' . str_replace('"', '""', $submittedAt) . '"',
                    ];
                    foreach ($fieldIds as $fid) {
                        $v = $row['responses'][$fid] ?? '';
                        if (is_array($v)) {
                            $v = json_encode($v);
                        }
                        $arr[] = '"' . str_replace('"', '""', (string)$v) . '"';
                    }
                    $lines[] = implode(',', $arr);
                }
                $csv = implode("\n", $lines);
                $this->response->setHeader('Content-Type', 'text/csv; charset=UTF-8');
                $this->response->setHeader('Content-Disposition', 'attachment; filename="announcement-form-responses.csv"');
                $this->response->setContent($csv);
                return $this->response;
            }

            $out = [];
            foreach ($rows as $row) {
                $r = [
                    'user_id' => $row['user_id'] ?? '',
                    'shop_id' => $row['shop_id'] ?? '',
                    'target_shop_id' => $row['target_shop_id'] ?? '',
                    'submitted_at' => isset($row['submitted_at']) ? $row['submitted_at']->toDateTime()->format('c') : null,
                ];
                foreach ($fieldIds as $fid) {
                    $r[$fid] = $row['responses'][$fid] ?? null;
                }
                $out[] = $r;
            }
            return $this->prepareResponse([
                'success' => true,
                'data' => ['rows' => $out]
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
