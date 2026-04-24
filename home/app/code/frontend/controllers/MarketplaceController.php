<?php
namespace App\Frontend\Controllers;
use App\Core\Controllers\BaseController;

/**
 * Will handle Marketplace requests.
 *
 * @since 1.0.0
 */
class MarketplaceController extends BaseController {

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
     * Get marketplace details.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function getDetailsAction() {
        $params = $this->getRequestData();
        if ( isset( $params['search'] ) ) {
            $connectors = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')
                        ->getConnectorsWithFilter(
                            [
                                'type' => 'real',
                                'code' => $params['search']
                            ],
                            $this->di->getUser()->id ?? false
                        );
            return $this->prepareResponse(
                [
                    'success' => true,
                    'code'    => '',
                    'message' => '',
                    'data'    => $connectors
                ]
            );
        }
        $connectors = $this->di->getObjectManager()
                    ->get('App\Connector\Components\Connectors')
                    ->getConnectorsWithFilter(
                        [
                            'type' => 'real',
                        ],
                        $this->di->getUser()->getId()
                    );
        return $this->prepareResponse(
            [
                'success' => true,
                'code' => '',
                'message' => '',
                'data' => $connectors,
            ]
        );
    }
}