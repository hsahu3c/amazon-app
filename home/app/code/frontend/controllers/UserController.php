<?php
namespace App\Frontend\Controllers;
use App\Frontend\Components\User;
use App\Core\Controllers\BaseController;

/**
 * Will handle product requests.
 *
 * @since 1.0.0
 */
class UserController extends BaseController {

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
     * Will login user.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function loginAction() {
        if ( $this->request->isGet() && $this->di->get('isDev') ) {
            return $this->prepareResponse(["success" => false, "code" => "incorrect_method", "message" => "send data at post request :-("]);
        }

        if ( $this->request->isGet() ) {
            return $this->prepareResponse(['success' => false, 'code' => 'data_missing', 'message' => 'Fill All Required Fields..', 'data' => []]);
        }

        $user = $this->di->getObjectManager()->get( '\App\Core\Models\User' );
        return $this->prepareResponse($user->login($this->getRequestData()));
    }

     /**
     * To get basic details of user ( modified )
     *
     * @return array
     */
    public function getBasicUserDetailsAction() {
        $response = $this->di->getObjectManager()->get(User::class)->getUserDetails();
        return $this->prepareResponse( ['success' => true, 'data' => $response] );
    }

    /**
     * Will send the forget mail to the user
     *
     * @return string
     */
    public function forgotAction() {
        $user = new \App\Core\Models\User;
        return $this->prepareResponse($user->forgotPassword($this->getRequestData()));
    }

    /**
     * Used to reset the password
     *
     * @return string
     */
    public function forgotresetAction()
    {
        $user = new \App\Core\Models\User;
        return $this->prepareResponse($user->forgotReset($this->getRequestData()));
    }

    /**
     * User Logout
     *
     * @return string
     */
    public function logoutAction()
    {
        $user = $this->di->getUser();
        return $this->prepareResponse($user->logout());
    }

    /**
     * This function is used to change the password ( modified ).
     *
     * @return string
     */
    public function changePasswordAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        return $this->prepareResponse($this->di->getObjectManager()->get(User::class)->changePassword($rawBody));
    }

    /**
     * Will save user locale.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function saveLocaleAction() {
        return $this->prepareResponse(
            $this->di->getObjectManager()->get(User::class)
            ->saveUserLocale(
                $this->getRequestData(),
                $this->di->getRequest()->getHeaders()
            )
        );
    }

    /**
     * Will delete user account at arise.
     *
     * @since 1.0.0
     * @return mixed
     */
    public function deleteUserAriseAction() {
        if ( ! class_exists( '\App\Arisehome\Components\User\user' ) || ! method_exists( '\App\Arisehome\Components\User\user', 'deleteUserAccount' ) ) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'msg'     => 'Oops! method not found'
                ]
            );
        }

        return $this->prepareResponse(
            $this->di->getObjectManager()->get('\App\Arisehome\Components\User\user')
            ->deleteUserAccount(
                $this->getRequestData()
            )
        );
    }
}