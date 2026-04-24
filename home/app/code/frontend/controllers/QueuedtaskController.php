<?php
namespace App\Frontend\Controllers;
use App\Connector\Models\Notifications;
use App\Connector\Models\QueuedTasks;
use App\Core\Controllers\BaseController;

/**
 * Will handle onboarding requests.
 *
 * @since 1.0.0
 */
class QueuedtaskController extends BaseController {

    /**
     * Get request data.
     *
     * @since 1.0.0
     * @return array
     */
    public function getRequestData() {
        switch ( $this->request->getMethod() ){
            case 'POST' :
                $contentType = $this->request->getHeader('Content-Type');
                if ( str_contains( (string) $contentType, 'application/json' ) ) {
                    $data = $this->request->getJsonRawBody(true);
                } else {
                    $data = $this->request->getPost();
                }

                break;
            case 'PUT' :
                $contentType = $this->request->getHeader('Content-Type');
                if ( str_contains( (string) $contentType, 'application/json' ) ) {
                    $data = $this->request->getJsonRawBody(true);
                } else {
                    $data = $this->request->getPut();
                }

                $data = array_merge( $data, $this->request->get() );
                break;

            case 'DELETE' :
                $contentType = $this->request->getHeader('Content-Type');
                if ( str_contains( (string) $contentType, 'application/json' ) ) {
                    $data = $this->request->getJsonRawBody(true);
                } else {
                    $data = $this->request->getPost();
                }

                $data = array_merge( $data, $this->request->get() );
                break;

            default:
                $data = $this->request->get();
                break;
        }

        return $data;
    }

    /**
     * Get notification.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function allNotificationsAction()
    {
        $rawBody = $this->getRequestData();
        $notifications = new Notifications;
        return $this->prepareResponse($notifications->getAllNotifications($rawBody));
    }

    /**
     * Clear all notification
     *
     * @since 1.0.0
     * @return mixed
     */
    public function clearNotificationsAction() {
        $notifications = new Notifications;
        return $this->prepareResponse(
            $notifications->clearAllNotifications(
                $this->di->getUser()->id ?? false,
                $this->di->getRequester()->getSourceId() ?? false,
                $this->di->getAppCode()->getAppTag() ?? 'default'
            )
        );
    }

    /**
     * Get all queuedtask
     *
     * @since 1.0.0
     * @return mixed
     */
    public function allQueuedTasksAction()
    {
        $rawBody = $this->getRequestData();
        $queuedTasks = new QueuedTasks;
        return $this->prepareResponse($queuedTasks->getAllQueuedTasks($rawBody));
    }
}