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

namespace App\Shopifyhome\Components\Authenticate;

use App\Shopifyhome\Components\Authenticate\Common;

#[\AllowDynamicProperties]
class Sellerauth extends Common
{
    /**
     * Constructor.
     *
     * @param bool $private If this is a private or public app
     */
    public function _construct()
    {
        $this->_callName = 'authURL';
        parent::_construct();
    }

    /*
     * Call if the app is yet to be published on Shopify app store
     *
     * @param Uri $uri
     *
     * @return string
     */
    public function fetchAuthenticationUrl($shopUrl)
    {
        $this->_shop = $shopUrl;
        $redirectUrl = $this->getAuthUrl($this->_scope, $this->_redirectUrl.'nextgen/public/index2.php', $this->_state);
        if(isset($redirectUrl['errorFlag'])) return $redirectUrl;
        return [
            'success' => true,
            'authUrl' => $redirectUrl
        ];
    }

    /**
     * Gets the authentication URL for Shopify to allow the user to accept the app (for public apps).
     *
     * @param string|array $scopes      The API scopes as a comma seperated string or array
     * @param string       $redirectUri The valid redirect URI for after acceptance of the permissions.
     *                                  It must match the redirect_uri in your app settings.
     *
     * @return string Formatted URL or array for error
     */
    public function getAuthUrl($scopes, string $redirectUri, $state)
    {
        if ($this->_apiKey === null) {
            return [
                'errorFlag' => true,
                'msg' => 'API key is missing'
            ];
        }

        if (is_array($scopes)) {
            $scopes = implode(',', $scopes);
        }

        return (string) $this->getBaseUri()
            ->withPath('/admin/oauth/authorize')
            ->withQuery(http_build_query([
                'client_id'    => $this->_apiKey,
                'scope'        => $scopes,
                'redirect_uri' => $redirectUri,
                'state' => $state
            ]));
    }

    /**
     * Verify the incoming request from Shopify and fetch Token
     *
     * @param string|array $scopes      The API scopes as a comma seperated string or array
     * @param string       $redirectUri The valid redirect URI for after acceptance of the permissions.
     *                                  It must match the redirect_uri in your app settings.
     *
     * @return string Formatted URL
     */
    public function verifyRequestandFetchToken($postData)
    {
        if($this->verifyRequest($postData)){
            $this->_shop = $postData['shop'];
            $this->_callName = 'fetchToken';
            $tokenInfo = $this->requestAccessToken($postData['code']);
            if(isset($tokenInfo['errorFlag'])) return $tokenInfo;

            return ['success' => true , 'token' => $tokenInfo];
        } else {
            return [
                'errorFlag' => true,
                'msg' => 'Sorry , user cannot be validated !! Please try again. If problem persists, contact our 24*7 friendly support.'
            ];
        }
    }

    /**
     *
     */
    public function getUserInfo()
    {
        // pull info using user-id
        $params['shop'] = 'anshuman-ced.myshopify.com';
        $params['token']  = '731dbc7fa373bd9049607b3b5d73a68d';
        return $params;
    }

    
}