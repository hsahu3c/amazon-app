<?php

namespace App\Core\Controllers;


class UserController extends BaseController
{
    public function createAction()
    {

        $rawBody = $this->getRequestData();
        $autoConfirm = true;
        if (isset($rawBody['autoConfirm'])) {
            $autoConfirm = $rawBody['autoConfirm'];
            unset($rawBody['autoConfirm']);
        }
        $user = new \App\Core\Models\User;
        return $this->prepareResponse($user->createUser($rawBody, 'customer', $autoConfirm));
    }

    /*
    For Customer Api
    Reset password by using the old password
    */
    public function resetpasswordAction()
    {
        $user = $this->di->getUser();
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($user->resetUserPassword($rawBody));
    }

    /**
     * sends otp to the email provided
     */
    public function otpMailAction()
    {
        $userOtp = new \App\Core\Models\UserOtp;
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($userOtp->sendOTPMail($rawBody));
    }
    public function validateOtpAction()
    {
        $userOtp = new \App\Core\Models\UserOtp;
        $otp = $this->getRequestData();
        return $this->prepareResponse($userOtp->validateOtp($otp));
    }

    public function loginAction()
    {
        if (!$this->request->isPost())
            return $this->prepareResponse([
                "success" => false,
                "code" => "incorrect_method",
                "message" => $this->di->get('isDev') ? 'Send data at post request :-(' : 'Fill All Required Fields..'
            ]);
        $user = new \App\Core\Models\User;
        $rawBody = $this->getRequestData();
        // bypass account bans if isDev flag on
        if (!$this->di->get('isDev')) {
            $resp = $this->di->getObjectManager()
                ->get("\App\Core\Components\Account")
                ->handleIfBlocked($rawBody, false);
            if (isset($resp["success"]) && !$resp["success"]) {
                return $this->prepareResponse($resp);
            }
        }
        return $this->prepareResponse($user->login($rawBody));
    }
    public function shopifyLoginAction()
    {
        if ($this->request->isGet())
            return $this->prepareResponse([
                'success' => false,
                'code' => $this->di->get('isDev') ? 'incorrect_method' : 'data_missing',
                'message' => $this->di->get('isDev') ? "send data at post request :-(" : 'Fill All Required Fields..',
                'data' => []
            ]);
        $user = new \App\Shopify\Models\User;
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($user->login($rawBody));
    }

    public function loginAsUserAction()
    {
        $user = new \App\Core\Models\User;
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($user->loginAsUser($rawBody));
    }

    /*
    For Admin Api
    Reset password by using the old password
    */
    public function updateStatusAction()
    {
        $user = new \App\Core\Models\User;
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($user->updateStatus($rawBody));
    }

    public function deleteAction()
    {
        $user = new \App\Core\Models\User;
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($user->deleteUser($rawBody));
    }

    /*
    Verify (confirm) the user when clicked on the confirmation email link.
    */
    public function verifyuserAction()
    {
        $user = new \App\Core\Models\User;
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($user->confirmUser($rawBody['token']));
    }

    /**
     * @return mixed
     */
    public function updateConfirmationAction()
    {
        $user = new \App\Core\Models\User;
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($user->updateConfirmation($rawBody));
    }

    /*
    Forgot password action. It will send the email with reset password link
    */
    public function forgotAction()
    {
        $user = new \App\Core\Models\User;
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($user->forgotPassword($rawBody));
    }

    /*
    Forgot password action. It will send the email with reset password link
    */
    public function forgotresetAction()
    {
        $user = new \App\Core\Models\User;
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($user->forgotReset($rawBody));
    }

    /*
    For Customer Api
    Get the customer detail along with the user_data table
    */
    public function getDetailsAction()
    {
        $user = $this->di->getUser();
        $details = $user->getDetails();

        unset($details['data']['password'], $details['data']['last_used_passwords']);

        return $this->prepareResponse($details);
    }

    public function getAllAction()
    {
        $user = $this->di->getUser();
        $pageSettings = $this->getRequestData();

        /* Below commented code is written for advanced filter and full-text search */

        if (isset($pageSettings['filter']) || isset($pageSettings['search'])) {
            return $this->prepareResponse($user->getAll(
                $pageSettings['count'],
                ($pageSettings['count'] * $pageSettings['activePage']) - $pageSettings['count'],
                $pageSettings
            )
            );
        } else {
            return $this->prepareResponse($user->getAll(
                $pageSettings['count'],
                ($pageSettings['count'] * $pageSettings['activePage']) - $pageSettings['count']
            )
            );
        }
    }

    /*
    For Admin Api
    Update the customer accept the array in key value form
    */
    public function adminUpdateUserAction()
    {
        $user = new \App\Core\Models\User;
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($user->adminUpdateUser($rawBody));
    }

    /*
    For Customer Api
    Update the customer accept the array in key value form
    */
    public function updateuserAction()
    {
        $user = $this->di->getUser();
        $rawBody = $this->getRequestData();
        return $this->prepareResponse($user->updateUser($rawBody));
    }

    /*
    For Customer Api
    Update the customer profile Picture
    */
    public function updateProfilePicAction()
    {
        $user = $this->di->getUser();
        $rawBody = $this->getRequestData();
        if ($this->request->hasFiles() == true) {
            foreach ($this->request->getUploadedFiles() as $file) {
                $allowedExtension = ['png', 'jpg', 'gif'];
                $extension = strtolower($file->getExtension());
                if (in_array($extension, $allowedExtension)) {
                    $path = BP . DS . 'public' . DS . 'media' . DS . 'user' . DS . $user->id . DS . 'profile-pic.' . $extension;
                    if (!file_exists(BP . DS . 'public' . DS . 'media' . DS . 'user' . DS . $user->id)) {
                        $oldmask = umask(0);
                        mkdir(BP . DS . 'public' . DS . 'media' . DS . 'user' . DS . $user->id, 0777, true);
                        umask($oldmask);
                    }
                    $file->moveTo($path);
                    $url = 'media' . DS . 'user' . DS . $user->id . DS . 'profile-pic.' . $extension;
                    return $this->prepareResponse($user->updateUser([
                        'profile_pic' => $url,
                        'username' => $user->username
                    ]));
                } else {
                    return $this->prepareResponse([
                        'success' => false,
                        'code' => 'inavlid_extension',
                        'message' => 'Invalid Extension Type',
                        'data' => []
                    ]);
                }
            }
        } else {
            if ($rawBody['profile_pic'] == null) {
                return $this->prepareResponse($user->updateUser([
                    'profile_pic' => null,
                    'username' => $user->username
                ]));
            }
        }
        return $this->prepareResponse([
            'success' => false,
            'code' => 'file_not_found',
            'message' => 'No File Found',
            'data' => []
        ]);
    }

    /*
    Prepare configuration form
    */
    public function configurationFormAction()
    {
        $rawBody = $this->getRequestData();
        if ($code = $rawBody['code']) {
            if (
                $model = $this->di->getConfig()
                    ->connectors->get($code)->get('source_model')
            ) {
                return $this->prepareResponse([
                    'success' => true,
                    'code' => '',
                    'message' => '',
                    'data' => $this->di->getObjectManager()
                        ->get($model)->getInstallationForm($code)
                ]);
            }
        } else {
            return $this->prepareResponse([
                'success' => false,
                'code' => 'missing_required_params',
                'message' => 'Missing Code.'
            ]);
        }
    }

    public function logoutAction()
    {
        $user = $this->di->getUser();
        return $this->prepareResponse($user->logout());
    }

    public function setTrialPeriodAction()
    {
        $rawBody = $this->getRequestData();
        $userModel = new \App\Core\Models\User;
        return $this->prepareResponse($userModel->setTrialPeriod($rawBody));
    }

    public function createWebhookAction()
    {
        $rawBody = $this->getRequestData();
        $userModel = new \App\Core\Models\User;
        return $this->prepareResponse($userModel->createWebhook($rawBody));
    }

    public function getExistingWebhooksAction()
    {
        $rawBody = $this->getRequestData();
        $userModel = new \App\Core\Models\User;
        return $this->prepareResponse($userModel->getExistingWebhooks($rawBody));
    }

    /**
     * It will resend the verification link to given user in case the current verification link token has expired.
     * Changes made byt utkarsh for twitter project
     */
    public function resendVerificationLinkAction()
    {
        $rawBody = $this->getRequestData();
        $userModel = $this->di->getObjectManager()->get('App\Core\Models\User');
        return $this->prepareResponse($userModel->sendConfirmationMail($rawBody));
    }

    /**
     * It will send the uninstall mail to the given user
     */
    public function sendUninstallMailAction()
    {
        $rawBody = $this->getRequestData();
        $userModel = $this->di->getObjectManager()->get('App\Core\Models\User');
        return $this->prepareResponse($userModel->sendUninstallMail($rawBody));
    }

    public function reLoginUserAction()
    {
        $rawBody = $this->getRequestData();
        $userModel = $this->di->getObjectManager()->get('App\Core\Models\User');
        return $this->prepareResponse($userModel->reLoginUserUsingToken($rawBody));
    }

    public function checkUserExistsAction()
    {
        $rawBody = $this->getRequestData();
        $userModel = $this->di->getObjectManager()->get('App\Core\Models\User');
        return $this->prepareResponse($userModel->checkUserExists($rawBody));
    }
}
