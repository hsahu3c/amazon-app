<?php

namespace App\Connector\Components;

class ApiClient extends \App\Core\Components\Base
{
    public $_refreshToken = '';

    public $_token;

    public $_errorMsg = '';

    public $_tokenType = '';

    public $_appCode = 'shopify';

    public function init($tokenType, $getFromCache = true, $appCode = false)
    {

        $apiConnector =  $this->di->getConfig()->get('apiconnector');
        if (!$appCode && isset($this->di->getAppCode()->get()[$tokenType])) {
            $appCode = $this->di->getAppCode()->get()[$tokenType];
        } elseif (!$appCode) {
            $appCode = "default";
        }

        $generateNew = $getFromCache === false ? true : false;
        $this->_tokenType = $tokenType;
        $this->_appCode = $appCode;

        $appCodeExist = $apiConnector
            ->get($this->_tokenType)
            ->get($this->_appCode);
        if ($this->_appCode != "default" && empty($appCodeExist)) {
            $this->_appCode = "default";
        }

        $this->getTokenFromRefresh($generateNew);
        return $this;
    }

    /**
     * call the internal api with this function
     * @param string $endPoint
     * @param array $headers
     * @param array $data
     * @param string $type
     * @return array/false
     */
    public function call($endPoint, $headers = [], $data = [], $type = 'GET', $dataType = null)
    {

        if (!empty($this->_errorMsg)) {
            return [
                'success' => false,
                'message' => $this->_errorMsg
            ];
        }

        if (!$token = $this->_token) {
            return ['success' => false, 'message' => isset($this->_errorMsg) ?? $this->di->getLocale()->_("Error in fetching token")];
        }

        $apiConnector =  $this->di->getConfig()->get('apiconnector');
        $base_uri = $apiConnector->get('base_url');
        $marketplaceBackendBaseUrl = $apiConnector->get($this->_tokenType)
            ->get($this->_appCode)
            ->get('api_base_url');
        if ($marketplaceBackendBaseUrl) {
            $base_uri = $marketplaceBackendBaseUrl;
        }

        $headers['Authorization'] = $token;
        $headers['User-Agent'] = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/94.0.4606.61 Safari/537.36";

        $url = $base_uri . 'webapi/rest/v1/' . $endPoint;

        $response = $this->di->getObjectManager()->get('App\Core\Components\Guzzle')
            ->call($url, $headers, $data, $type, $dataType);


        if (
            !empty($response) &&
            $response['success'] == false &&
            (isset($response['code'])) &&
            ($response['code'] == 'token_expired')
        ) {
            if (!$token = $this->getTokenFromRefresh(true)) {
                return ['success' => false, 'message' => 'error in refershing token'];
            }
            $headers['Authorization'] = $token->_token;
            $response = $this->di->getObjectManager()->get('App\Core\Components\Guzzle')
                ->call($url, $headers, $data, $type, $dataType);
        }

        return $response;
    }

    /**
     * Get a token from refresh token.
     * @param boolean $genrateNew pass ture if old token was invalid.
     * @return string/boolean $token
     */
    public function getTokenFromRefresh($genrateNew = false, $retry = 0)
    {
        if ((!$this->_token = $this->di->getCache()->get('api_token_' . $this->_tokenType . '_' . $this->_appCode)) || $genrateNew) {
            $apiConnector = $this->di->getConfig()->get('apiconnector');
            $base_uri = $apiConnector->get('base_url');
            $marketplaceBackendBaseUrl = $apiConnector->get($this->_tokenType)
                ->get($this->_appCode)
                ->get('api_base_url');
            if ($marketplaceBackendBaseUrl) {
                $base_uri = $marketplaceBackendBaseUrl;
            }
            $refreshToken = $apiConnector
                ->get($this->_tokenType)
                ->get($this->_appCode)
                ->get('refresh_token');
            $tokenData = $this->di->getObjectManager()->get('App\Core\Components\Guzzle')
                ->call($base_uri . 'core/token/getTokenByRefresh', ['Authorization' => $refreshToken, "User-Agent" => "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/94.0.4606.61 Safari/537.36"]);
            if (isset($tokenData['success']) && isset($tokenData['data']['token'])) {
                $this->_token = $tokenData['data']['token'];
                $this->_errorMsg = '';
                $this->di->getcache()->set('api_token_' . $this->_tokenType . '_' . $this->_appCode, $this->_token);
                return $this;
            }
            $genericErr = 'Error Obtaining token from Remote Server. Kindly contact Remote Host for more details.';
            $this->_errorMsg = isset($tokenData['message']) ? $tokenData['message'] : $genericErr;
            if ((($this->_errorMsg === $genericErr) || !is_array($tokenData)) && $retry === 0) {
                $this->di->getCache()->delete('api_token_' . $this->_tokenType . '_' . $this->_appCode);
                return $this->getTokenFromRefresh($genrateNew, 1);
            } else {
                $logFile = 'api_refresh_token_error.log';
                $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                $this->di->getLog()->logContent(
                    'Unable to generate token from remote: ' . json_encode([
                        'user_id' => $this->di->getUser()->id ?? 'N/A',
                        'token_data' => $tokenData ?? 'N/A',
                        'generate_new' => $genrateNew ?? 'N/A',
                        'base_uri' => $base_uri ?? 'N/A',
                        'debug_backtrace' => $trace ?? 'N/A',
                    ]),
                    'info',
                    $logFile
                );
            }
            return $this;
        }
        $this->_token = $this->di->getCache()->get('api_token_' . $this->_tokenType . '_' . $this->_appCode);
        $this->_errorMsg = '';
        return $this;
    }
}
