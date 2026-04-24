<?php

/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_Amazon
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Amazon\Components\CredRotation;

use App\Amazon\Components\Common\Helper;
use App\Core\Components\Base as Base;
use Aws\Exception\AwsException;
use Exception;
use Aws\Ssm\SsmClient; 

/**
 * the class Rotation is used for rotating app credentials like client secret
 */
class Rotation extends Base
{
    public function initiateSecretRotation($sqsData)
    {
        $logFile = 'important/clientSecretRotation/'.date('Y-m-d').'.log';
        $success = false;
        $this->di->getLog()->logContent('Data received at initiateSecretRotation: '.json_encode($sqsData, true), 'info', $logFile);
        if (isset($sqsData['applicationOAuthClientSecretExpiry']['clientSecretExpiryReason']) && ($sqsData['applicationOAuthClientSecretExpiry']['clientSecretExpiryReason'] == 'PERIODIC_ROTATION')) {
            $applicationId = $sqsData['applicationId'];
            $expiryTime = $sqsData['applicationOAuthClientSecretExpiry']['clientSecretExpiryTime'];
            $message = 'Some error occurred in rotating secret!';
            $subject = 'Important - Client Secret Rotation Failed';
            $content = 'This is to notify you that the process of <b>client secret rotation at Amazon</b> initiation faced some error due to which the rotation process is not initiated properly please make sure to reinitiate this process as the credentials will expiring within a few days. Please keep this in note that your current secret will expire on <b>'.$expiryTime.'</b> so make this sure that the process is done properly and as soon as possible. Thankyou!';
            try {
                $response = $this->di->getObjectManager()->get(Helper::class)->sendRequestToAmazon('rotate-secret', ['expire_on' => $expiryTime, 'application_id' => $applicationId], 'POST');
                $this->di->getLog()->logContent('response: '.json_encode($response, true), 'info', $logFile);
                if (isset($response['success']) && $response['success']) {
                    $success = true;
                    $subject = 'Important - Client Secret Rotation Initiated';
                    $content = 'This is to notify you that the process of <b>client secret rotation at Amazon</b> is initiated successfully and the response of the successful response will be send to the provided sqs within a few days. Please keep this in note that your current secret will expire on <b>'.$expiryTime.'</b> so make this sure that the process is done properly and as soon as possible. Thankyou!';
                    $message = 'Rotation started successfully!';
                }
            } catch (Exception $e) {
                $message = 'Some error occured: ' . json_encode($e->getMessage());
            }

            $this->notifyDevs($subject, $content);
            return [
                'success' => $success,
                'message' => $message
            ];
        }
        $this->notifyDevs('Important - Client Secret Rotation Failed', 'We have received the data at initiateSecretRotation function but the data is not in the required format due to which the rotation is failed to process. Kindly look into this on priority in logs. Thankyou!');
        return [
            'success' => false,
            'message' => 'Data is not in the required format!'
        ];
    }

    public function rotateSecret($sqsData): void
    {
        $logFile = 'important/clientSecretRotation/'.date('Y-m-d').'.log';
        $this->di->getLog()->logContent('Data received at rotateSecret: '.json_encode($sqsData, true), 'info', $logFile);
        if (isset($sqsData['applicationOAuthClientNewSecret']['newClientSecret'])) {
            $applicationId = $sqsData['applicationId'];
            $newSecret = $sqsData['applicationOAuthClientNewSecret']['newClientSecret'];
            $clientId = $sqsData['applicationOAuthClientNewSecret']['clientId'];
            $newClientSecretExpiryTime = $sqsData['applicationOAuthClientNewSecret']['newClientSecretExpiryTime'];
            $oldClientSecretExpiryTime = $sqsData['applicationOAuthClientNewSecret']['oldClientSecretExpiryTime'];
            try {
                $db = $this->di->get('remote_db');
                if ($db) {
                    $appsCollections = $db->selectCollection('apps');
                    $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
                    $appInfo = $appsCollections->find(['marketplace' => 'amazon'], $options)->toArray();
                    $this->di->getLog()->logContent('existing entry in db: '.json_encode($appInfo, true), 'info', $logFile);
                    if (!empty($appInfo)) {
                        foreach($appInfo as $app) {
                            foreach (['EU', 'FE', 'NA'] as $region) {
                            if (!empty($app[$region]) && isset($app[$region]['sp_application_id_production']) && ($app[$region]['sp_application_id_production'] == $applicationId)) {
                                    $this->updateClientSecret($app, $newSecret);
                                    $app['last_secret_expired_at'] = $oldClientSecretExpiryTime;
                                    $app['new_secret_expiring_on'] = $newClientSecretExpiryTime;
                                    $this->di->getLog()->logContent('latest after update: '.json_encode($app, true), 'info', $logFile);
                                    $appsCollections->replaceOne(['_id' => $app['_id'], 'marketplace' => 'amazon'], $app, ['upsert' => true]);
                                    if (isset($app['rotation_in_progress'])) {
                                        $unsetKeys['rotation_in_progress'] = true;
                                    }

                                    if (isset($app['current_secret_expire_on'])) {
                                        $unsetKeys['current_secret_expire_on'] = true;
                                    }

                                    if (!empty($unsetKeys)) {
                                        $this->di->getLog()->logContent('keys to unset: '.json_encode($unsetKeys, true), 'info', $logFile);
                                        $appsCollections->updateOne(['_id' => $app['_id'], 'marketplace' => 'amazon'], ['$unset' => $unsetKeys]);
                                    }
                                }

                                break;
                            }
                        }
                    }

                    $response = $this->updateCredentialsAtAws($newSecret);
                    if (!$response['success']) {
                        $subject = 'Important - Client Secret Rotation At SSM Failed';
                        $content = 'This is to notify you that the process of client secret rotation at <b>SSM</b> is failed to update after receiving the successful credentials so please check for the possible reasons at your end and update them as soon as possible as the client secret is expiring on <b>'.$oldClientSecretExpiryTime.'</b>. Thankyou!';
                        $this->notifyDevs($subject, $content);
                    } else {
                        $subject = 'Important - Client Secret Rotation Done At SSM';
                        $content = 'This is to notify you that the process of client secret rotation at Amazon  is successfully done and your client secret at <b>SSM</b> is updated with new credentials that will expire on <b>'.$newClientSecretExpiryTime.'</b>. Please note that to update the credentials for your app you need to connect to the Project Owner of Amazon. Thankyou!';
                        $img = "https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/planactivated.png";
                        $this->notifyDevs($subject, $content, $img);
                    }

                    $this->di->getLog()->logContent('updateCredentialsAtAws: '.json_encode($response, true), 'info', $logFile);
                    $subject = 'Important - Client Secret Rotation Done';
                    $content = 'This is to notify you that the process of client secret rotation at Amazon is successfully done and your client secret at <b>apps</b> collection is updated with new credentials that will expire on <b>'.$newClientSecretExpiryTime.'</b>. Please note that to update the credentials for your app you need to connect to the Project Owner of Amazon. Thankyou!';
                    $img = "https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/planactivated.png";
                }
            } catch (Exception) {
                $subject = 'Important - Client Secret Rotation Fail To Update';
                $content = 'This is to notify you that the process of client secret rotation at Amazon is failed to update after receiving the successful credentials so please check for the possible reasons at your end and update them as soon as possible as the client secret is expiring on <b>'.$oldClientSecretExpiryTime.'</b>. Thankyou!';
                $img = "https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/warning.png";
            }

            $this->notifyDevs($subject, $content, $img);
        }
    }

    public function updateClientSecret(&$doc, $newSecret): void {
        foreach ($doc as $key => &$value) {
            if ($key === 'clientSecret' || $key === 'emb_client_secret') {
                $value = $newSecret;
            } elseif (is_array($value)) {
                $this->updateClientSecret($value, $newSecret);
            }
        }
    }

    public function notifyDevs($subject, $content, $img = "https://multiamazon-mail-images.s3.us-east-2.amazonaws.com/Images/warning.png"): void
    {
        $mailData['subject'] = $subject;
        $mailData['content'] = $content;
        $mailData['sender'] = $this->di->getConfig()->get('mailer')->get('sender_name') ?? "";
        $mailData['email'] = $this->di->getConfig()->get('mailer')->get('credentials_update_mail_reciever') ?? "";
        $mailData['path'] = 'amazon' . DS . 'view' . DS . 'email' . DS . 'NotifyOnSecretRotation.volt';
        $mailData['image_url'] = $img;
        $bccs = $this->di->getConfig()->get('mailer')->get('credentials_update_receiver_bccs') ?? [];
        if (!empty($bccs) && !is_array($bccs)) {
            $mailData['bccs'] = $bccs->toArray();
        }
        $this->di->getObjectManager()->get('\App\Core\Components\SendMail')->send($mailData);
    }

    public function updateCredentialsAtAws($newClientSecret)
    {
        $logFile = 'important/clientSecretRotation/'.date('Y-m-d').'.log';
        $ssmClient = new SsmClient(include BP.'/app/etc/aws.php');
        $parameterName = 'amazon-creds';
        try {
            $result = $ssmClient->getParameter([
                'Name' => $parameterName,
                'WithDecryption' => true,
            ]);
            $this->di->getLog()->logContent('SSM response on get entry: '.json_encode($result, true), 'info', $logFile);
            $existingParams = json_decode((string) $result['Parameter']['Value'], true);
            $this->di->getLog()->logContent('SSM params before update: '.json_encode($existingParams, true), 'info', $logFile);
            if (!empty($existingParams['SELLING_PARTNER_APP_CLIENT_SECRET'])) {
                $existingParams['SELLING_PARTNER_APP_CLIENT_SECRET'] = $newClientSecret;
                $params = [
                    'Name'      => $parameterName,
                    'Value'     => json_encode($existingParams),
                    'Overwrite' => true,
                    'Type'      => 'SecureString',
                ];
                $this->di->getLog()->logContent('SSM params sent to update: '.json_encode($params, true), 'info', $logFile);
                $ssmResult = $ssmClient->putParameter($params);
                $this->di->getLog()->logContent('ssmResult: '.json_encode($ssmResult, true), 'info', $logFile);
                return [
                    'success' => true,
                    'message' => 'SSM parameters updated!'
                ];
            }
            return [
                'success' => false,
                'message' => 'key not exist in ssm parameter'
            ];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'message' => json_encode($e->getMessage())
            ];
        }
    }
}