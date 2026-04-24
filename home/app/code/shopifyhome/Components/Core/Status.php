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

namespace App\Shopifyhome\Components\Core;

class Status
{
    public function errorCodes()
    {
        return [
            303 => 'The response to the request can be found under a different URI.',
            400 => 'The request was not understood by the server, generally due to bad syntax or because the Content-Type header was not correctly set to application/json.',
            401 => 'The necessary authentication credentials are not present in the request or are incorrect.',
            402 => 'The requested shop is currently frozen.',
            403 => 'The server is refusing to respond to the request.',
            404 => 'The requested resource was not found but could be available again in the future.',
            406 => 'The requested resource is only capable of generating content not acceptable according to the Accept headers sent in the request.',
            422 => 'The request body was well-formed but contains semantical errors.',
            423 => 'The requested shop is currently locked.',
            429 => 'The request was not accepted because the application has exceeded the rate limit.',
            500 => 'An internal error occurred in Shopify. Please post to the API & Technology forum so that Shopify staff can investigate.',
            501 => 'The requested endpoint is not available on that particular shop',
            503 => 'The server is currently unavailable.',
            504 => 'The request could not complete in time. Try breaking it down in multiple smaller requests.'
        ];
    }
}