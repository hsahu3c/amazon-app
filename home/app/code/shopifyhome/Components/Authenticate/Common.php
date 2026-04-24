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

use App\Core\Components\Base;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Request;

#[\AllowDynamicProperties]
class Common extends Base
{
    /**stack
     * If API calls are from a public or private app.
     *
     * @var string
     */
    protected $_private;

    /**
     * The Shopify access token.
     *
     * @var string
     */
    protected $_accessToken;

    /**
     * The Shopify API key.
     *
     * @var string
     */
    protected $_apiKey;

    /**
     * The Shopify API secret.
     *
     * @var string
     */
    protected $_apiSecret;

    /**
     * The Shopify domain.
     *
     * @var stringstack
     */
    protected $_shop;

    /**
     * The Shopify scope.
     * @var stringstack
     */
    protected $_scope;

    /**
     * Constructor.
     *
     * @param bool $private If this is a private or public app
     *
     * @return self
     */
    public function _construct()
    {
    }

    /**
     * Initialize seller related credentials.
     *
     * @param bool $private If this is a private or public app
     * @param string $callName initialize eseller environment whether call is being made for authURL fetch or Seller Token
     *
     * @return self
     */
    public function init(bool $private = false, $options = [])
    {
        $this->_private = $private;
        $this->_callName ??= '';
        $this->setSellerCredentials($this->_callName, $options);
        // Create the stack and assign the middleware which attempts to fix redirects
        $stack = HandlerStack::create();
        $stack->push(Middleware::mapRequest([$this, 'authRequest']));

        // Create a default Guzzle client with our stack
        $this->_client = new Client([
            'handler'  => $stack,
            'headers'  => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json'
            ],
        ]);

        return $this;
    }

    public function setSellerCredentials($callName, $credentials = []): void
    {
        // pull data from db
        $callName = $credentials['call_name'] ?? $callName;
        $configData = $this->di->getConfig()->security->shopify_nxtgen->toArray();
        $this->_apiKey = $configData['auth_key'];
        switch ($callName)
        {
            case 'authURL' :
                $this->_scope = $configData['scope'];
                $this->_redirectUrl = $configData['redirect_url'];
                $this->_state = json_encode(["appId" => $this->di->getConfig()->get('shopify-app-id')]);
                /*$this->_state = $this->di->getRegistry()->getToken();*/
                /*$this->_apiSecret = $configData['secret_key'];*/
                break;

            case 'fetchToken' :
                $this->_apiSecret = $configData['secret_key'];
                break;

            case 'shopify_core' :
                // set data while being redirected frmo shopify
                if(empty($credentials)) $credentials = $this->di->getObjectManager()->get(Sellerauth::class)->getUserInfo();

                $this->_shop = $credentials['shop'];
                $this->_accessToken = $credentials['token'];
                break;

            default :
                $this->_apiSecret = $configData['secret_key'];
                $this->_scope = $configData['scope'];
                $this->_redirectUrl = $configData['redirect_url'];
                $this->_state = $this->di->getRegistry()->getToken();
                break;
        }
    }

    /**
     * Returns the base URI to use.setSellerCredentials
     *
     * @return \Guzzle\Psr7\Uri
     */
    public function getBaseUri()
    {
        if ($this->_shop === null) {
            // Shop is required
            throw new Exception('Shopify domain missing for API calls');
        }

        return new Uri("https://{$this->_shop}");
    }

    /**
     * Ensures we have the proper request for private and public calls.
     * Also modifies issues with redirects.
     *
     *
     * @return void
     */
    public function authRequest(Request $request)
    {
        // Get the request URI
        $uri = $request->getUri();

        if ($this->isAuthableRequest($uri)) {
            if ($this->isRestRequest($uri)) {
                // Checks for REST
                if ($this->_private && ($this->_apiKey === null || $this->_apiPassword === null)) {
                    // Key and password are required for private API calls
                    throw new Exception('API key and password required for private Shopify REST calls');
                }

                // Private: Add auth for REST calls
                if ($this->_private) {
                    // Add the basic auth header
                    return $request->withHeader(
                        'Authorization',
                        'Basic '.base64_encode("{$this->apiKey}:{$this->apiPassword}")
                    );
                }

                // Public: Add the token header
                return $request->withHeader(
                    'X-Shopify-Access-Token',
                    $this->_accessToken
                );
            }
            // Checks for Graph
            if ($this->_private && ($this->_apiPassword === null && $this->_accessToken === null)) {
                // Private apps need password for use as access token
                throw new Exception('API password/access token required for private Shopify GraphQL calls');
            }
            if (!$this->_private && $this->_accessToken === null) {
                // Need access token for public calls
                throw new Exception('Access token required for public Shopify GraphQL calls');
            }
            // Public/Private: Add the token header
            return $request->withHeader(
                'X-Shopify-Access-Token',
                $this->apiPassword ?? $this->_accessToken
            );
        }

        return $request;
    }

    /**
     * Determines if the request is to REST API.
     *
     *
     * @return bool
     */
    protected function isRestRequest(Uri $uri)
    {
        return $this->isGraphRequest($uri) === false;
    }

    /**
     * Determines if the request requires auth headers.
     *
     *
     * @return bool
     */
    protected function isAuthableRequest(Uri $uri)
    {
        return !str_contains((string) $uri, '/admin/oauth');
    }

    /**
     * Gets the access token from a "code" supplied by Shopify request after successfull authentication (for public apps).
     *
     * @param string $code The code from Shopify
     *
     * @throws Exception When API secret is missing
     *
     * @return string The access token
     */
    public function requestAccessToken(string $code)
    {
        if ($this->_apiSecret === null || $this->_apiKey === null) {
            // Key and secret required
            throw new Exception('API key or secret is missing');
        }

        // Do a JSON POST request to grab the access token
        $request = $this->_client->request(
            'POST',
            $this->getBaseUri()->withPath('/admin/oauth/access_token'),
            [
                'json' => [
                    'client_id'     => $this->_apiKey,
                    'client_secret' => $this->_apiSecret,
                    'code'          => $code,
                ],
                'http_errors' => false
            ]
        );

        $response = json_decode((string) $request->getBody()->getContents(), true);
        if(isset($response['error'])) return ['errorFlag' => true, 'msg' => $response['error_description']];

        // Decode the response body as an array and return access token string
        return json_decode((string) $request->getBody(), true)['access_token'];
    }

    /**
     * Verify the request is from Shopify using the HMAC signature (for public apps).
     *
     * @param array $params The request parameters (ex. $_GET)
     *
     * @return bool If the HMAC is validated
     */
    public function verifyRequest(array $params)
    {
        if ($this->_apiSecret === null) {
            // Secret is required
            throw new Exception('API secret is missing');
        }

        // Ensure shop, timestamp, and HMAC are in the params
        if (array_key_exists('shop', $params)
            && array_key_exists('timestamp', $params)
            && array_key_exists('hmac', $params)
        ) {
            // Grab the HMAC, remove it from the params, then sort the params for hashing
            $hmac = $params['hmac'];
            unset($params['hmac']);
            ksort($params);
            // Encode and hash the params (without HMAC), add the API secret, and compare to the HMAC from params
            return $hmac === hash_hmac('sha256', urldecode(http_build_query($params)), $this->_apiSecret);
        }

        // Not valid
        return false;
    }

    /**
     * Determines if rate limiting is enabled.
     *
     * @return bool
     */
    public function isRateLimitingEnabled()
    {
        return $this->_rateLimitingEnabled === true;
    }

    /**
     * Determines if the request is to Graph API.
     *
     *
     * @return bool
     */
    protected function isGraphRequest(Uri $uri)
    {
        return str_contains((string) $uri, 'graphql.json');
    }
}