<?php

namespace App\Core\Components\Staff;

use App\Core\Components\Base;
use \App\Core\Models\User;

class EmailHelper extends Base
{

    public function sendStaffRequestMail($data)
    {
        /**gets template path */
        $templateData = [];
        if (isset($data['templateSource'])) {
            $templateData['marketplace'] = $data['templateSource'];
        }
        $templateData['templateName'] = 'staff_request';
        $templatePath = $this->di->getObjectManager()->get(User::class)->getTemplatePath($templateData);
        if (empty($templatePath['success'])) {
            return $templatePath;
        }

        $emailData = [
            'email' => $data['email'], //Seller email address
            'name' => $data['name'],
            'staff_name' => $data['staff_name'],
            'staff_email' => $data['staff_email'],
            'subject' => 'Staff Account Request',
            'path' => $templatePath['path'],
            "base_url" => $_SERVER['HTTP_HOST'],
            'appPath' => $data['appPath']
        ];
        $send = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($emailData);
        if ($send) {
            return ['success' => true, 'message' => 'Staff request notification email sent successfully.'];
        } else {
            return ['success' => false, 'message' => 'Error sending staff request notification email.'];
        }
    }

    public function sendStaffApprovalMail($data)
    {

        $templateData = [];
        if (isset($data['templateSource'])) {
            $templateData['marketplace'] = $data['templateSource'];
        }
        $templateData['templateName'] = 'staff_approve';
        $templatePath = $this->di->getObjectManager()->get(User::class)->getTemplatePath($templateData);
        if (empty($templatePath['success'])) {
            return $templatePath;
        }

        $emailData = [
            'email' => $data['email'], // Staff user's email address
            'name' => $data['name'],  // Staff user's name
            'seller_name' => $data['seller_name'],  // Seller's name
            'subject' => 'Staff Account Approved',
            'path' => $templatePath['path'],
            "base_url" => $_SERVER['HTTP_HOST']
        ];

        $send = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($emailData);
        if ($send) {
            return ['success' => true, 'message' => 'Staff approval notification email sent successfully.'];
        } else {
            return ['success' => false, 'message' => 'Error sending staff approval notification email.'];
        }
    }
    public function sendStaffRejectionMail($data)
    {
        $templateData = [];
        if (isset($data['templateSource'])) {
            $templateData['marketplace'] = $data['templateSource'];
        }
        $templateData['templateName'] = 'staff_reject';
        $templatePath = $this->di->getObjectManager()->get(User::class)->getTemplatePath($templateData);
        if (empty($templatePath['success'])) {
            return $templatePath;
        }

        $emailData = [
            'email' => $data['email'], // Staff user's email address
            'name' => $data['name'],  // Staff user's name
            'seller_name' => $data['seller_name'],  // Seller's name
            'subject' => 'Staff Account Rejected',
            'path' => $templatePath['path'],
            "base_url" => $_SERVER['HTTP_HOST']
        ];

        $send = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($emailData);
        if ($send) {
            return ['success' => true, 'message' => 'Staff rejection notification email sent successfully.'];
        } else {
            return ['success' => false, 'message' => 'Error sending staff rejection notification email.'];
        }
    }

    public function sendStaffDeletionMail($data)
    {
        $templateData = [];
        if (isset($data['templateSource'])) {
            $templateData['marketplace'] = $data['templateSource'];
        }
        $templateData['templateName'] = 'staff_delete';
        $templatePath = $this->di->getObjectManager()->get(User::class)->getTemplatePath($templateData);
        if (empty($templatePath['success'])) {
            return $templatePath;
        }

        $emailData = [
            'email' => $data['email'], // Staff user's email address
            'name' => $data['name'],  // Staff user's name
            'seller_name' => $data['seller_name'],  // Seller's name
            'subject' => 'Staff Account Deleted',
            'path' => $templatePath['path'],
            "base_url" => $_SERVER['HTTP_HOST']
        ];

        $send = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($emailData);
        if ($send) {
            return ['success' => true, 'message' => 'Staff deletion notification email sent successfully.'];
        } else {
            return ['success' => false, 'message' => 'Error sending staff deletion notification email.'];
        }
    }
}