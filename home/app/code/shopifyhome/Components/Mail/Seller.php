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
 * @package     Ced_Shopifyhome
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright CEDCOMMERCE (http://cedcommerce.com/)
 * @license     http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Components\Mail;

use App\Shopifyhome\Components\Core\Common;
use Exception;

class Seller extends Common
{

    public const WELCOME_EMAIL_KEY = 'welcome';

    public const UNINSTALL_EMAIL_KEY = 'uninstall';

    public function welcome($data): void
    {
        // $path = 'shopifyhome' . DS . 'view' . DS . 'email' . DS . 'review.volt';
        // $mailData['email'] = $data['email'];
        // $mailData['path'] = $path;
        // $mailData['name'] = !empty($data['name']) ? $data['name'] : 'Seller';
        // $mailData['subject'] = 'Account Pending for Facebook Approval';
        // $mailData['aksb'] = $this->di->getConfig()->backend_base_url .'front/shopify/email/aksb.js';
        // $mailData['logo'] = $this->di->getConfig()->backend_base_url .'front/shopify/email/logo.png';
        // $mailData['fb_banner'] = $this->di->getConfig()->backend_base_url .'front/shopify/email/fb_banner.jpg';
        // $response = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($mailData);
    }

    public function onboard($data): void
    {
        // $path = 'shopifyhome' . DS . 'view' . DS . 'email' . DS . 'onboard.volt';
        // $mailData['email'] = $data['email'];
        // $mailData['path'] = $path;
        // $mailData['name'] = !empty($data['name']) ? $data['name'] : 'Seller';
        // $mailData['subject'] = 'Welcome Onboard with Facebook Marketplace Integration';
        // $this->di->getLog()->logContent('PROCESS 00010 | App\Shopifyhome\Components\Mail\Seller | Send Mail | email id : '.$data['email'],'info','shopify'.DS.'mail.log');
        // $response = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($mailData);
    }

    public function onboardNew($data, $configData = [])
    {
        if(isset($data['email']) && !empty($data['email'])) {
            $sendMail = true;
            $path = 'shopifyhome' . DS . 'view' . DS . 'email' . DS . 'onboarding.volt';
            $mailData['path'] = $path;
            $mailData['app_name'] = $this->di->getConfig()->app_name ?? null;
            $mailData['subject'] = 'Welcome Onboard';
            $mailData['target_marketplace'] = 'Amazon';
            $mailData['source_marketplace'] = 'Shopify';
            if (!is_null($mailData['app_name'])) {
                $mailData['subject'] = 'Welcome to '. ucfirst((string) $mailData['app_name']);
            } else {
                $mailData['subject'] = 'Welcome Onboard';
            }
            
            $mailData['basehomeurl'] = $this->di->getConfig()->app_url;
            $adminPanelUrl = $this->di->getConfig()->admin_panel_base_url;
            $mailData['support_page'] = $adminPanelUrl . 'support';
            $mailData = array_merge($mailData, $data);
            $mailData['name'] = !empty($mailData['name']) ? $mailData['name'] : 'Seller';
            $this->configureMailDataFromConfig($configData, self::WELCOME_EMAIL_KEY, $sendMail, $mailData);
            try {
                $response = false;
                if ($sendMail && !empty($mailData['path'])) {
                    $response = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($mailData);
                    if(!$response) {
                        $this->di->getLog()->logContent('PROCESS 00010 | App\Shopifyhome\Components\Mail\Seller | Send Mail | Unable to send mail','info','shopify'.DS.'mail.log');
                    }
                }
                
                return $response;
            } catch (Exception $e) {
                $this->di->getLog()->logContent('PROCESS 00010 | App\Shopifyhome\Components\Mail\Seller | Send Mail | Failure occured => reason:  '.json_encode($e->getMessage()),'info','shopify'.DS.'mail.log');
            }
        }
        else {
            return false;
        }
    }

    /**
     * function to configureMailDataFromConfig
     *
     * @param array $configData
     * @param string $mailType
     * @param boolean $sendMail
     * @param array $mailData
     */
    public function configureMailDataFromConfig($configData, $mailType, &$sendMail, &$mailData): void
    {
        if (!empty($configData['email'][$mailType])) {
            $configMailData = $configData['email'][$mailType];
            if (
                !empty($configMailData['class']) &&
                !empty($configMailData['method'])
            ) {
                $extraMailData = $this->getExtraMailData($configMailData['class'], $configMailData['method']);
                $mailData = array_merge($mailData, $extraMailData);
            }

            if (isset($configMailData['send_email']) && !$configMailData['send_email']) {
                $sendMail = false;
            }

        }
    }

    /**
     * function to get the extraMailData
     *
     * @param string $className
     * @param string $method
     * @return array
     */
    public function getExtraMailData($className, $method)
    {
        $data = [];
        if (class_exists($className) && method_exists($className, $method)) {
            $extraMailData = $this->di->getObjectManager()->get($className)->$method();
            if (is_array($extraMailData)) {
                $data = $extraMailData;
            }
        }
        
        return $data;
    }

    public function uninstall($data): void
    {
        // $path = 'shopifyhome' . DS . 'view' . DS . 'email' . DS . 'uninstall.volt';
        // $mailData['email'] = $data['email'];
        // $mailData['path'] = $path;
        // $mailData['subject'] = "What went wrong?";
        // $response = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($mailData);
    }

    public function uninstallNew($data, $configData = [])
    {
        $response = false;
        if(isset($data['email'])) {
            $sendMail = true;
            $path = '';//'shopifyhome' . DS . 'view' . DS . 'email' . DS . 'uninstall.volt';
            $mailData['path'] = $path;
            $mailData['subject'] = "What went wrong?";
            // $mailData['basehomeurl'] = 'https://home.connector.sellernext.com';
            $mailData['basehomeurl'] = $this->di->getConfig()->app_url;

            $mailData = array_merge($mailData, $data);
            $this->configureMailDataFromConfig($configData, self::UNINSTALL_EMAIL_KEY, $sendMail, $mailData);
            if ($sendMail && !empty($mailData['path'])) {
                $response = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($mailData);
            }
        }
        else {
            return false;
        }
        
        return $response;
    }

    public function approved($data): void
    {
        // $path = 'shopifyhome' . DS . 'view' . DS . 'email' . DS . 'approved.volt';
        // $mailData['email'] = $data['email'];
        // $mailData['path'] = $path;
        // $mailData['subject'] = "Congratulations, start selling on Facebook";
        // $response = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($mailData);
        // if(!$response){
        //     $this->di->getLog()->logContent('MAIL | Seller.php | approved fun |  **ERROR** Email not send to email id : '.$mailData['email'],'info','error_mail.log');
        // }
        // return $response;
    }

    public function catalog_sync($data){
        return false;
    }

    public function imported($data): void
    {
        // $path = 'shopifyhome' . DS . 'view' . DS . 'email' . DS . 'products_imported.volt';
        // $mailData['email'] = $data['email'];
        // $mailData['path'] = $path;
        // $mailData['subject'] = "You are Simply a Click away from Selling your Products";
        // $response = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($mailData);
    }

    public function failedOrder($data)
    {
        if(isset($data['email'], $data['orders'])) {
            $path = 'shopifyhome' . DS . 'view' . DS . 'email' . DS . 'failed-order.volt';
            $mailData['path'] = $path;
            $mailData['subject'] = "Amazon order(s) failed to sync";
            $mailData['basehomeurl'] = $this->di->getConfig()->app_url;
            
            $mailData = array_merge($mailData, $data);

            $mailData['name'] = !empty($mailData['name']) ? $mailData['name'] : 'Seller';
            
            $response = $this->di->getObjectManager()->get('App\Core\Components\SendMail')->send($mailData);
        }
        else {
            return false;
        }
    }
}