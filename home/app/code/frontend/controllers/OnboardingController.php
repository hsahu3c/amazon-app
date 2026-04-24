<?php
namespace App\Frontend\Controllers;
use App\Core\Controllers\BaseController;

/**
 * Will handle onboarding requests.
 *
 * @since 1.0.0
 */
class OnboardingController extends BaseController {

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

                break;

            case 'DELETE' :
                $contentType = $this->request->getHeader('Content-Type');
                if ( str_contains( (string) $contentType, 'application/json' ) ) {
                    $data = $this->request->getJsonRawBody(true);
                } else {
                    $data = $this->request->getPost();
                }

                break;

            default:
                $data = $this->request->get();
                break;
        }

        return $data;
    }

    /**
     * Get installation form.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function installationFormAction() {
        $params = $this->getRequestData();
        if ( $redirect = $this->request->get( 'redirect_return_type' )){
            $this->session->set( "redirect_return_type",  $redirect );
            if ( $url = $this->request->get( 'custom_url' ) ) {
                $this->session->set( "custom_url", $url );
            }
        }

        if ( $params['code'] ) {
            if ( $model = $this->di->getConfig()->connectors->get( $params['code'] )->get( 'source_model' ) ) {
                $data = $this->di->getObjectManager()->get( $model )->getInstallationForm( $params );
                if ( $data['post_type'] === "redirect" ) {
                    $this->response->redirect( $data['action'] );
                } elseif ( $data['post_type'] === "view_template" ) {
                    $this->view->setViewsDir( CODE . $data['viewDir'] );
                    $this->view->setVars( $data['params'] );
                    $this->view->pick( $data['template'] );
                }
            }
        } else {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'code'    => 'missing_required_params',
                    'message' => 'Missing Code.'
                ]
            );
        }
    }
}