<?php

namespace App\Core\Models;

use \MongoDB\BSON\UTCDateTime as ISOTime;
use  App\Core\Components\Email\Helper;

class UserOtp extends BaseMongo
{
    protected $table = 'user_otp';
    /**
     * generate OTP
     */
    public function generateOTP($email): array
    {
        if (!is_null($email)) {
            $collection = $this->getCollection();
            $collection->createIndex(["created_at" => 1], ['expireAfterSeconds' => 1800]);
            $userId = $this->di->getUser()->user_id;
            $otpData = [];
            $otpData['user_id'] = $userId;
            $otpData['email'] = $email;
            $otpData['created_at'] = $date = new ISOTime();
            $otp = $collection->findOne(['email' => $email]);
            $maxOtpLimit = $this->di->getConfig()->get('user_otp')->max_otp_limit;
            if (!empty($otp['created_at'])) {
                $createdDate = $otp->created_at->toDateTime();
                $interval = (int) ($createdDate->diff($date->toDateTime())->format('%R%i min'));
                $timeLeft = (31 - ceil($interval));
                if ($interval < 1) {
                    return ['success' => false, 'message' => 'Please try again after one minute.'];
                }
            }
            $otpData['otp'] = rand(10000, 99999);
            if (empty($otp) || (isset($otp['no_of_times_send']) && $otp['no_of_times_send'] < $maxOtpLimit)) {
                $collection->updateOne(
                    ['email' => $email,],
                    ['$set' => $otpData, '$inc' => ['no_of_times_send' => 1]],
                    ['upsert' => true]
                );
                $response = [
                    'success' => true, 'otp' => $otpData['otp'],
                    'no_of_attempts_left' => ($maxOtpLimit - (($otp['no_of_times_send'] ?? 0) + 1))
                ];
            } else {
                $response = [
                    'success' => false,
                    'message' => 'Number of times exceeded! Please try again after ' .
                        ($timeLeft . ' minute(s)') ?? 'sometime.'
                ];
            }
        } else {
            $response = [
                'success' => false,
                'message' => 'Required e-mail address missing'
            ];
        }
        return $response;
    }
    /**
     * this method is called to send the OTP for user confirmation
     *
     * @param array $data
     *
     */
    public function sendOTPMail($data): array
    {
        if (!empty(trim($data['email']))) {
            $templateData = [];
            if (isset($data['templateSource'])) {
                $templateData['marketplace'] = $data['templateSource'];
                $emailTemplate = $this->di->getObjectManager()->get(Helper::class);
                $marketplaceEmailData = $emailTemplate->getOtpMailDataFromMarketplace($data);
                $data = array_merge($data, $marketplaceEmailData);
            }
            $templateData['templateName'] = 'send_otp';
            $templatePath = $this->di->getObjectManager()->get('\App\Core\Models\User')->getTemplatePath($templateData);
            if (empty($templatePath['success'])) {
                $response = $templatePath;
            } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $response = [
                    'success' => false, 'message' => 'Invalid e-mail Format!'
                ];
            } else {
                $user = $this->di->getUser();
                $data['path'] = $templatePath['path'];
                $data['name'] = $user->name ?? $this->getNameFromEmail($data['email']);
                $otpData = $this->generateOTP($data['email']);
                if (empty($otpData['success'])) {
                    return $otpData;
                }
                $data['otp'] = $otpData['otp'];
                $data['otp_array'] = str_split($otpData['otp']);
                $send = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($data);
                if ($send) {
                    $response = [
                        'success' => true,
                        'message' => 'One-time Passcode sent successfully!',
                        'no_of_attempts_left' => $otpData['no_of_attempts_left']
                    ];
                } else {
                    $response = [
                        'success' => false,
                        'message' => 'Error in sending OTP'
                    ];
                }
            }
        } else {
            $response = [
                'success' => false,
                'message' => 'Required e-mail address missing'
            ];
        }
        return $response;
    }
    /**
     * this function will validate otp
     *
     * @param [array] $data
     * @return array
     */
    public function validateOtp($data): array
    {
        if (empty(trim($data['email']))) {
            return [
                'success' => false,
                'message' => 'Required data email is missing.'
            ];
        }
        $collection = $this->getCollection();
        if (empty(trim($data['otp']))) {
            return [
                'success' => false,
                'message' => 'One-time Passcode Required.'
            ];
        }
        $otp = $data['otp'];
        $email = trim($data['email']);
        $isExpDeleted = $collection->deleteOne([
            'email' => $email,
            'created_at' => [
                '$lte' => new ISOTime(strtotime(date(DATE_ATOM)) * 1000 - (1000 * 60 * 15))
            ]
        ]);
        if ($isExpDeleted->getDeletedCount() == 1) {
            $response = [
                'success' => false,
                'message' => 'One-time passcode expired!'
            ];
        } else {
            $deleteOtp = $collection->deleteOne(['email' => $email, 'otp' => $otp]);
            if ($deleteOtp->getDeletedCount() == 1) {
                $response = [
                    'success' => true,
                    'message' => 'Valid One-time passcode.'
                ];
            } else {
                $response = [
                    'success' => false,
                    'message' => 'Entered One-time passcode is incorrect!'
                ];
            }
        }
        return $response;
    }

    /**
     * function used to will return initials from email address
     *
     * @param string $email
     * @return string
     */
    public function getNameFromEmail($email): string
    {
        return (!empty(explode("@", $email)[0]) ? explode("@", $email)[0] : 'Seller');
    }
}
