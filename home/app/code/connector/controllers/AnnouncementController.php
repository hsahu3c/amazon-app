<?php

namespace App\Connector\Controllers;

use App\Connector\Components\AnnouncementHelper;

/**
 * Merchant-facing announcement APIs: list, mark read, acknowledge, form, engagement.
 * GET /connector/announcement/getList
 * POST /connector/announcement/markRead, markAllRead, acknowledge, submitForm, engagement
 * GET /connector/announcement/getForm
 */
class AnnouncementController extends \App\Core\Controllers\BaseController
{
    private function getHelper(): AnnouncementHelper
    {
        return $this->di->getObjectManager()->get(AnnouncementHelper::class);
    }

    /**
     * GET list for bell – announcements with read/ack state for current context.
     */
    public function getListAction()
    {
        try {
            $helper = $this->getHelper();
            $result = $helper->getAnnouncementsForBell();
            return $this->prepareResponse([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST mark one as read. Body or query: id (announcement_id).
     */
    public function markReadAction()
    {
        try {
            $data = array_merge($this->request->get() ?? [], $this->getRequestData());
            $id = $data['id'] ?? $data['announcement_id'] ?? null;
            if (!$id) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'id or announcement_id required',
                    'status_code' => 400
                ]);
            }
            $helper = $this->getHelper();
            $helper->markAnnouncementRead($id);
            return $this->prepareResponse([
                'success' => true,
                'message' => 'Marked as read'
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST mark all announcements as read for current context.
     */
    public function markAllReadAction()
    {
        try {
            $helper = $this->getHelper();
            $result = $helper->markAllAnnouncementsRead();
            return $this->prepareResponse([
                'success' => true,
                'data' => $result,
                'message' => 'All marked as read'
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST acknowledge (critical_compliance only). Body or query: id.
     */
    public function acknowledgeAction()
    {
        try {
            $data = array_merge($this->request->get() ?? [], $this->getRequestData());
            $id = $data['id'] ?? $data['announcement_id'] ?? null;
            if (!$id) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'id or announcement_id required',
                    'status_code' => 400
                ]);
            }
            $helper = $this->getHelper();
            $result = $helper->acknowledgeAnnouncement($id);
            if (!$result['success']) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => $result['error'],
                    'status_code' => 400
                ]);
            }
            return $this->prepareResponse([
                'success' => true,
                'message' => 'Announcement acknowledged successfully'
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * GET form schema. Query: id (announcement_id).
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
            $helper = $this->getHelper();
            $form = $helper->getForm($id);
            if ($form === null) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Form not found',
                    'status_code' => 404
                ]);
            }
            return $this->prepareResponse([
                'success' => true,
                'data' => $form
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST submit form. Body: announcement_id, form_id, responses.
     */
    public function submitFormAction()
    {
        try {
            $data = $this->getRequestData();
            $announcementId = $data['announcement_id'] ?? null;
            $formId = $data['form_id'] ?? null;
            $responses = $data['responses'] ?? [];
            if (!$announcementId || !$formId) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'announcement_id and form_id required',
                    'status_code' => 400
                ]);
            }
            if (!is_array($responses)) {
                $responses = [];
            }
            $helper = $this->getHelper();
            $result = $helper->submitFormResponse($announcementId, $formId, $responses);
            if (!$result['success']) {
                $code = (int)($result['code'] === '409' ? 409 : ($result['code'] === '404' ? 404 : 400));
                return $this->prepareResponse([
                    'success' => false,
                    'message' => $result['error'],
                    'status_code' => $code
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
     * POST record engagement event. Body: announcement_id, event, metadata (optional).
     */
    public function engagementAction()
    {
        try {
            $data = $this->getRequestData();
            $announcementId = $data['announcement_id'] ?? null;
            $event = $data['event'] ?? null;
            $metadata = $data['metadata'] ?? [];
            if (!$announcementId || !$event) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'announcement_id and event required',
                    'status_code' => 400
                ]);
            }
            if (!is_array($metadata)) {
                $metadata = [];
            }
            $helper = $this->getHelper();
            $helper->recordEngagement($announcementId, $event, [], $metadata);
            if ($event === 'beta_join_clicked') {
                $helper->markAnnouncementRead($announcementId);
            }
            return $this->prepareResponse([
                'success' => true,
                'message' => 'Engagement recorded successfully'
            ]);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
