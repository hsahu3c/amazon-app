<?php

namespace App\Connector\Controllers;

use \App\Core\Controllers\BaseController;
use App\Connector\Components\Emails\Email;

class EmailController extends BaseController
{
    public function checkSubscriptionAction()
    {
        $rawBody = $this->getRequestData();
        if (!isset($rawBody['token']) || empty($rawBody['token'])) {
            $result = [
                'success' => false,
                'message' => 'Token not found!'
            ];
        } else {
            $token = $rawBody['token'];
            $emailComp = $this->di->getObjectManager()->get(Email::class);
            $decodedData = $emailComp->validateandDecodeToken($token);
            if ($decodedData['success'] == false) {
                $result = $decodedData;
            } else {
                $tokenData = $decodedData['data'];
                $action = $tokenData['action'];
                $actionData = $emailComp->getActionData(['_id' => $tokenData['action_id'], 'type' => 'email_subscription']);
                if (empty($actionData)) {
                    $result = [
                        'success' => false,
                        'message' => 'Unable to process your request!'
                    ];
                } else {
                    $check = $emailComp->isSettingsAlreadyImplemented($actionData);
                    $message = "";
                    if ($check && $action == Email::EMAIL_UNSUBSCRIBE) {
                        $message = 'Already unsubscribed!';
                        $actionData['action'] = 'email_subscribe';
                        $actionData['only_token'] = true;
                        $response = $emailComp->generateTokenForEmailSubscription($actionData);
                    } elseif (!$check && $action == Email::EMAIL_UNSUBSCRIBE) {
                        $message = 'Not unsubscribed!';
                    } elseif ($check && $action == Email::EMAIL_SUBSCRIBE) {
                        $actionData['action'] = 'email_unsubscribe';
                        $actionData['only_token'] = true;
                        $message = 'Already Subscribed!';
                        $response = $emailComp->generateTokenForEmailSubscription($actionData);
                    } elseif (!$check && $action == Email::EMAIL_SUBSCRIBE) {
                        $message = 'Not Subscribed!';
                    } else {
                        $message = 'Invalid action!';
                    }

                    if (!empty($response) && isset($response['success']) && $response['success']) {
                        $token = $response['token'] ?? "";
                    }

                    $result = [
                        'success' => true,
                        'message' => $message,
                        'already_implemented' => $check
                    ];
                    if ($check) {
                        $result['new_token'] = $token;
                    }
                }
            }
        }

        return $this->prepareResponse($result);
    }

    public function processEmailSubscriptionAction()
    {
        $rawBody = $this->getRequestData();
        if (!isset($rawBody['token']) || empty($rawBody['token'])) {
            $result = [
                'success' => false,
                'message' => 'Token not found!'
            ];
        } else {
            $reasons = [];
            $reasons = $rawBody['reasons'] ? ['reasons' =>  $rawBody['reasons']] : [];
            $token = $rawBody['token'];
            $emailComp = $this->di->getObjectManager()->get(Email::class);
            $decodedData = $emailComp->validateandDecodeToken($token);
            if ($decodedData['success'] == false) {
                $result = $decodedData;
            } else {
                $tokenData = $decodedData['data'];
                $action = $tokenData['action'] ?? "";
                //not used for now
                // if (isset($rawBody['validate'])) {
                //     $check = $emailComp->isSettingsAlreadyImplemented($tokenData);
                //     $message = "";
                //     if($check && $action == Email::EMAIL_UNSUBSCRIBE) {
                //         $message = 'Already unsubscribed!';
                //     } elseif (!$check && $action == Email::EMAIL_UNSUBSCRIBE) {
                //         $message = 'Not unsubscribed!';
                //     } elseif ($check && $action == Email::EMAIL_SUBSCRIBE) {
                //         $message = 'Already Subscribed!';
                //     } elseif (!$check && $action == Email::EMAIL_SUBSCRIBE) {
                //         $message = 'Not Subscribed!';
                //     } else {
                //         $message = 'Invalid action!';
                //     }
                //     $result = [
                //         'success' => true,
                //         'message' => $message,
                //         'already_implemented' => $check
                //     ];
                // } else {
                if (isset($tokenData['action_id'])) {
                    $actionData = $emailComp->getActionData(['_id' => $tokenData['action_id'], 'type' => 'email_subscription']);
                    if(empty($actionData)) {
                        return $this->prepareResponse([
                            'success' => false,
                            'message' => 'Unable to process your request!'
                        ]);
                    }

                    $response = $emailComp->processEmailSubscriptionUsingToken($actionData, $reasons);
                } else {
                    $response = $emailComp->processEmailSubscriptionUsingToken($tokenData, $reasons);
                }

                //$response = $emailComp->processEmailSubscriptionUsingToken($tokenData);
                if ($response['success']) {
                    if ($action == Email::EMAIL_UNSUBSCRIBE) {
                        $actionData['action'] = 'email_subscribe';
                        $actionData['only_token'] = true;
                        $tokenResponse = $emailComp->generateTokenForEmailSubscription($actionData);
                        $message = 'Your mail has been unsubscribed successfully!';
                    } elseif ($action == Email::EMAIL_SUBSCRIBE) {
                        $actionData['action'] = 'email_unsubscribe';
                        $actionData['only_token'] = true;
                        $tokenResponse = $emailComp->generateTokenForEmailSubscription($actionData);
                        $message = 'Your mail has been subscribed successfully!';
                    }

                    $result = [
                        'success' => true,
                        'message' => $message
                    ];
                    if (!empty($tokenResponse) && isset($tokenResponse['success']) && $tokenResponse['success']) {
                        $result['new_token'] = $tokenResponse['token'] ?? "";
                    }
                } else {
                    $result = [
                        'success' => false,
                        'message' => 'Unable to unsubscribe mail!'
                    ];
                }
            }
        }

        return $this->prepareResponse($result);
    }

    public function updateUserEmailAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $emailComp = $this->di->getObjectManager()->get('\App\Connector\Components\Emails\Email');
        return $this->prepareResponse($emailComp->updateUserEmail($rawBody));
    }

    public function getUserEmailAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        $emailComp = $this->di->getObjectManager()->get('\App\Connector\Components\Emails\Email');
        return $this->prepareResponse($emailComp->getUserEmail($rawBody));
    }
}
