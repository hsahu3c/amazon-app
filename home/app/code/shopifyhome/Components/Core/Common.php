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

use Exception;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Request;

class Common extends \App\Shopifyhome\Components\Authenticate\Common
{
    /**
     * The Shopify domain.
     *
     * @var stringstack
     */
    protected $_shop;

    protected $_response = [];

    /**
     * The Shopify domain.
     *
     * @var stringstack
     */
    protected $_callName;

    protected $_storedResponse = [];

    /**
     * The current API call limits from last request.
     *
     * @var array
     */
    protected $_apiCallLimits = [
        'rest'  => [
            'left'  => 0,
            'made'  => 0,
            'limit' => 40,
        ],
        'graph' => [
            'left'          => 0,
            'made'          => 0,
            'limit'         => 1000,
            'restoreRate'   => 50,
            'requestedCost' => 0,
            'actualCost'    => 0,
        ],
    ];

    /**
     * api endpoint
     *
     * @var string
     */
    protected $_apiEndURL = '';

    /**
     * If rate limiting is enabled.
     *
     * @var bool
     */
    protected $_rateLimitingEnabled = false;

    /**
     * The rate limiting cycle (in ms).
     *
     * @var int
     */
    protected $_rateLimitCycle = 0.5 * 1000;

    /**
     * The rate limiting cycle buffer (in ms).
     *
     * @var int
     */
    protected $_rateLimitCycleBuffer = 0.1 * 1000;

    /**
     * Request timestamp for every new call.
     * Used for rate limiting.
     *
     * @var int
     */
    protected $requestTimestamp;

    /**
     * Request timestamp for every new call.
     * Used for rate limiting.
     *
     * @var int
     */
    protected $_requestTimestamp;

    /**
     * The Shopify scope.
     *
     * @var stringstack
     */
    protected $_sellerToken;

    /**
     * Constructor.
     *
     * @param bool $private If this is a private or public app
     */
    public function _construct()
    {
        parent::_construct();
    }

    /**
     * Runs a request to the Shopify API.
     *
     * @param string     $type   The type of request... GET, POST, PUT, DELETE
     * @param string     $path   The Shopify API path... /admin/xxxx/xxxx.json
     * @param array|null $params Optional parameters to send with the request
     *
     * @return array An array of the Guzzle response, and JSON-decoded body
     */
    public function rest(string $type, string $path, array $params = null)
    {
        // Check the rate limit before firing the request
        if ($this->isRateLimitingEnabled() && $this->_requestTimestamp) {
            // Calculate in milliseconds the duration the API call took
            $duration = round(microtime(true) - $this->_requestTimestamp, 3) * 1000;
            $waitTime = ($this->_rateLimitCycle - $duration) + $this->_rateLimitCycleBuffer;

            if ($waitTime > 0) {
                // Do the sleep for X mircoseconds (convert from milliseconds)
                usleep($waitTime * 1000);
            }
        }

        // Update the timestamp of the request
        $tmpTimestamp = $this->_requestTimestamp;
        $this->_requestTimestamp = microtime(true);

        $errors = false;
        $response = null;
        $body = null;

        
        try {
            // Build URI and try the request
            $uri = $this->getBaseUri()->withPath($path);

            // Build the request parameters for Guzzle
            $guzzleParams = ['http_errors' => false];
            if ($params !== null) {
                $guzzleParams[strtoupper($type) === 'GET' ? 'query' : 'json'] = $params;
            }

            // Set the response
            $response = $this->_client->request($type, $uri, $guzzleParams);
            $body = $response->getBody();

        } catch (Exception $e) {
            if ($e instanceof ClientException || $e instanceof ServerException) {
                // 400 or 500 level error, set the response
                $response = $e->getResponse();
                $body = $response->getBody();

                // Build the error object
                $errors = (object) [
                    'status'    => $response->getStatusCode(),
                    'body'      => $body->getContents(),
                    'exception' => $e,
                ];
            } else {
                // Else, rethrow
                throw $e;
            }
        }

        // Grab the API call limit header returned from Shopify
        $callLimitHeader = $response->getHeader('http_x_shopify_shop_api_call_limit');
        if ($callLimitHeader) {
            $calls = explode('/', (string) $callLimitHeader[0]);
            $this->_apiCallLimits['rest'] = [
                'left'  => (int) $calls[1] - $calls[0],
                'made'  => (int) $calls[0],
                'limit' => (int) $calls[1],
            ];
        }

        // Return Guzzle response and JSON-decoded body
        return (object) [
            'response'   => $response,
            'errors'     => $errors,
            'body'       => $body,
            'timestamps' => [$tmpTimestamp, $this->_requestTimestamp],
        ];
    }

    /**
     * Runs a request to the Shopify API.
     *
     * @param string $query     The GraphQL query
     * @param array  $variables The optional variables for the query
     *
     * @throws Exception When missing api password is missing for private apps
     * @throws Exception When missing access key is missing for public apps
     *
     * @return array An array of the Guzzle response, and JSON-decoded body
     */
    public function graph(string $query, array $variables = [])
    {
        // Build the request

        $request = ['query' => $query];
        if ($variables !== []) {
            $request['variables'] = $variables;
        }

        // Update the timestamp of the request
        $tmpTimestamp = $this->requestTimestamp;
        $this->requestTimestamp = microtime(true);

        // Create the request, pass the access token and optional parameters
        // ability to make multiple call simultaneously
        $response = $this->_client->request(
            'POST',
            $this->getBaseUri()->withPath('/admin/api/2019-07/graphql.json'),
            ['body' => json_encode($request), 'http_errors' => false]
        );

        // Grab the data result and extensions
        $body = $this->jsonDecode($response->getBody());
        if(($response->getStatusCode() != 200) && ($response->getStatusCode() != 201) && ($response->getStatusCode() != 202) ){
            $errormsg = $this->getResponseErrorCode($response->getStatusCode());
            return [
                'response'   => $response,
                'body'       => $body['errors'] ?? $errormsg,
                'errors'     => $body['errors'] ?? $errormsg,
                'timestamps' => [$tmpTimestamp, $this->requestTimestamp],
            ];
        }

        if (array_key_exists('extensions', $body) && array_key_exists('cost', $body['extensions'])) {
            // Update the API call information
            $calls = $body['extensions']['cost'];
            $this->apiCallLimits['graph'] = [
                'left'          => (int) $calls['throttleStatus']['currentlyAvailable'],
                'made'          => (int) ($calls['throttleStatus']['maximumAvailable'] - $calls['throttleStatus']['currentlyAvailable']),
                'limit'         => (int) $calls['throttleStatus']['maximumAvailable'],
                'restoreRate'   => (int) $calls['throttleStatus']['restoreRate'],
                'requestedCost' => (int) $calls['requestedQueryCost'],
                'actualCost'    => (int) $calls['actualQueryCost'],
            ];
        }

        // Return Guzzle response and JSON-decoded body
        return [
            'response'   => $response,
            'body'       => array_key_exists('errors', $body) ? $body['errors'] : $body['data'],
            'errors'     => array_key_exists('errors', $body) ?? '',
            'timestamps' => [$tmpTimestamp, $this->requestTimestamp],
        ];
    }

    public function graphAsync(string $query, array $variables = []): void
    {
        $client = $this->_client;
        $req = new Request('POST', $this->getBaseUri()->withPath('/admin/api/graphql.json'),[], json_encode(['query' => $query]));
        $this->_response = $this->_client->sendAsync(
                    $req,
                    [
                        'http_errors' => false
                    ]
                );
                // Update status to completed
        $this->_response->then(
                    function ($res) use ($client) {
                        $returnRes = $res->getBody()->getContents();
                        $statusCode = $res->getStatusCode();
                        $this->_storedResponse['success'][] = [
                            'status_code' => $statusCode,
                            'message'     => $returnRes
                        ];
                    },
                    function (RequestException $e) use ($client) {
                        $returnRes = $e->getResponse()->getBody()->getContents();
                        $statusCode = $e->getStatusCode();
                        $this->_storedResponse['error'][] = [
                            'status_code' => $statusCode,
                            'message'     => $returnRes
                        ];
                    }
                );
    }

    protected function fire()
    {
        $this->_response->wait();
        return $this->_storedResponse;
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
     * Decodes the JSON body.
     *$this->di->getRequest()->get()
     * @param string $json The JSON body
     *
     * @return object The decoded JSON
     */
    protected function jsonDecode($json, $bool = true)
    {
        // From firebase/php-jwt
        if (!(defined('JSON_C_VERSION') && PHP_INT_SIZE > 4)) {
            /**
             * In PHP >=5.4.0, json_decode() accepts an options parameter, that allows you
             * to specify that large ints (like Steam Transaction IDs) should be treated as
             * strings, rather than the PHP default behaviour of converting them to floats.
             */
            $obj = json_decode($json, $bool, 512, JSON_BIGINT_AS_STRING);
        } else {
            // @codeCoverageIgnoreStart
            /**
             * Not all servers will support that, however, so for older versions we must
             * manually detect large ints in the JSON string and quote them (thus converting
             * them to strings) before decoding, hence the preg_replace() call.
             * Currently not sure how to test this so I ignored it for now.
             */
            $maxIntLength = strlen((string) PHP_INT_MAX) - 1;
            $jsonWithoutBigints = preg_replace('/:\s*(-?\d{'.$maxIntLength.',})/', ': "$1"', $json);
            $obj = json_decode((string) $jsonWithoutBigints, $bool);
            // @codeCoverageIgnoreEnd
        }

        return $obj;
    }

    protected function getResponseErrorCode($code)
    {
        $errorCodes = $this->di->getCache()->get('shopifyErrorCodes');
        if(!$errorCodes){
            $errorCodes = $this->di->getObjectManager()->get(Status::class)->errorCodes();
            $this->di->getCache()->set('shopifyErrorCodes', $errorCodes);
        }

        return $errorCodes[$code];
    }
}

 