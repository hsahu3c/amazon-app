<?php

namespace App\Frontend\Controllers;

use App\Core\Controllers\BaseController;

/**
 * Will handle Config requests.
 *
 * @since 1.0.0
 */
class ConfigController extends BaseController
{

    /**
     * Get request data.
     *
     * @since 1.0.0
     * @return array
     */
    public function getRequestData()
    {
        switch ($this->request->getMethod()) {
            case 'POST':
                $contentType = $this->request->getHeader('Content-Type');
                if (str_contains((string) $contentType, 'application/json')) {
                    $data = $this->request->getJsonRawBody(true);
                } else {
                    $data = $this->request->getPost();
                }

                break;
            case 'PUT':
                $contentType = $this->request->getHeader('Content-Type');
                if (str_contains((string) $contentType, 'application/json')) {
                    $data = $this->request->getJsonRawBody(true);
                } else {
                    $data = $this->request->getPut();
                }

                $data = array_merge($data, $this->request->get());
                break;

            case 'DELETE':
                $contentType = $this->request->getHeader('Content-Type');
                if (str_contains((string) $contentType, 'application/json')) {
                    $data = $this->request->getJsonRawBody(true);
                } else {
                    $data = $this->request->getPost();
                }

                $data = array_merge($data, $this->request->get());
                break;

            default:
                $data = $this->request->get();
                break;
        }

        return $data;
    }

    /**
     * Get config data.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function getAction()
    {

        $params            = $this->getRequestData();
        $sourceMarketplace = $this->di->getRequester()->getSourceName() ?? false;
        $targetMarketplace = $this->di->getRequester()->getTargetName() ?? false;
        if (empty($targetMarketplace) || empty($sourceMarketplace)) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Marketplace is required'
                ]
            );
        }

        $connectorHelper = $this->di->getObjectManager()
            ->get('App\Connector\Components\Connectors')
            ->getConnectorModelByCode($targetMarketplace);
        if (!method_exists($connectorHelper, 'getConfigData')) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Method not found'
                ]
            );
        }

        return $this->prepareResponse(
            [
                'success' => true,
                'data'    => $connectorHelper->getConfigData($params)
            ]
        );
    }

    /**
     * Save config data.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function saveConfigAction()
    {
        $params                = $this->getRequestData();
        $params['marketplace'] = $this->di->getRequester()->getTargetName() ?? false;
        if (isset($params['marketplace'])) {
            $userId          = $this->di->getUser()->id;
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($params['marketplace']);
            if (!method_exists($connectorHelper, 'saveConfigData')) {
                return $this->prepareResponse(
                    [
                        'success' => false,
                        'msg'     => 'Method not found'
                    ]
                );
            }
            // if ( method_exists( $connectorHelper, 'initiateProductValidation' ) ) { // Commented code for Stopping product validation on config save
            //     $connectorHelper->initiateProductValidation( $params );
            // }
            return $this->prepareResponse(
                $connectorHelper->saveConfigData(
                    $params,
                    $userId
                )
            );
        }

        return $this->prepareResponse(
            [
                'success' => false,
                'code'    => 'invalid_request',
                'message' => 'Marketplace is required'
            ]
        );
    }

    /**
     * This function is used to get advanced shiping map data.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function getAdvancedMappingAction()
    {
        $params                = $this->getRequestData();
        $params['marketplace'] = $this->di->getRequester()->getTargetName() ?? false;
        if (isset($params['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($params['marketplace']);
            if (!method_exists($connectorHelper, 'getAdvancedConfigData')) {
                return $this->prepareResponse(
                    [
                        'success' => false,
                        'msg'     => 'Method not found'
                    ]
                );
            }

            return $this->prepareResponse(
                $connectorHelper->getAdvancedConfigData()
            );
        }

        return $this->prepareResponse(
            [
                'success' => false,
                'code'    => 'invalid_request',
                'message' => 'Marketplace is required'
            ]
        );
    }

    /**
     * This function is used to get shipping map data.
     *
     * @return string json string will be returned.
     */
    public function getShippingMapAction()
    {
        $params                       = $this->getRequestData();
        $params['marketplace']        = $this->di->getRequester()->getTargetName() ?? false;
        $params['source_marketplace'] = $this->di->getRequester()->getSourceName() ?? false;
        if (isset($params['marketplace'])) {
            $connectorHelper = $this->di->getObjectManager()
                ->get('App\Connector\Components\Connectors')
                ->getConnectorModelByCode($params['marketplace']);
            if (!method_exists($connectorHelper, 'getShippingCarriers')) {
                return $this->prepareResponse(
                    [
                        'success' => false,
                        'msg'     => 'Method not found'
                    ]
                );
            }

            return $this->prepareResponse(
                $connectorHelper->getShippingCarriers(
                    $params
                )
            );
        }

        return $this->prepareResponse(
            [
                'success' => false,
                'code'    => 'invalid_request',
                'message' => 'Marketplace is required'
            ]
        );
    }

    /**
     * This function is used to get the updated date of latest document edited group_code wise.
     *
     * @return string json string will be returned.
     */
    public function getLastSyncInfoAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $userId = $this->di->getUser()->id ?? null;
        // print_r($rawBody);die;
        if (!empty($userId)) {
            $collection = $this->di->getObjectManager()->get("\App\Core\Models\BaseMongo")->getCollectionForTable("config");
            $info = $collection->aggregate([['$match' => ['group_code' => $rawBody['group_code'], 'user_id' => $userId]], ['$sort' => ['updated_at' => -1]], ['$limit' => 1]])->toArray();
            // $updateUser = $collection->updateOne(['user_id' => $userId],['$set'=>['freshchat_restore_id' => $rawBody['freshchat_restore_id']]]);
            return $this->prepareResponse(['success' => true, 'message' => 'Data fetched successfully', 'data' => $info]);
        }

        return $this->prepareResponse(['success' => true, 'message' => "User ID missing"]);
    }

    public function fireOrderSyncEventAction()
    {
        $rawBody = $this->getRequestData();
        $rawBody['shopJson'] = json_encode($this->di->getUser()?->shops[0] ?? []);
        $this->di->getEventsManager()->fire('application:orderSyncUpdated', $this, $rawBody);
        return $this->prepareResponse(['success' => true, 'message' => 'event triggered']);
    }
}
