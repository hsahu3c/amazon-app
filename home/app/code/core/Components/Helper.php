<?php

namespace App\Core\Components;

use \Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Helper extends Base
{
    private $dir = BP . DS . 'app' . DS . 'etc' . DS . 'security' . DS;

    public function getJwtToken(
        $token,
        $algo = 'RS256',
        $save = true,
        $privateKey = false,
        $restrictForSameUser = true
    ) {

        $date = new \DateTime();
        $token["iss"] = HOST;
        if ($restrictForSameUser && $this->di->getConfig()->get("token")->get("set_audience")) {
            $token["aud"] = $token['aud'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        }

        $token["token_id"] = $date->getTimestamp();
        if (!$privateKey) {
            $currentApp = $this->di->getAppCode()->getCurrentApp();
            if ($currentApp != null && $this->di->getConfig()->get("securityFiles") && $this->di->getConfig()->get("securityFiles")[$currentApp]) {
                $privateKey = $this->di->getConfig()->get("securityFiles")[$currentApp]['pemFile'];
            }else{
                $privateKey = file_get_contents($this->dir . 'connector.pem');
            }
        }

        if ($save) {
            $this->di->getTokenManager()->addToken($token);
        }

        return JWT::encode($token, $privateKey, $algo);
    }

    public function decodeToken($token, $check = true, $key = false)
    {
        try {
            if (count(explode('.', $token)) > 2) {
                list($header, $payload, $signature) = explode('.', $token);
                $header = json_decode(base64_decode($header), true);
                if (!$key) {
                    $key = '';
                    $currentApp = $this->di->getAppCode()->getCurrentApp();
                    if ($header['alg'] == 'RS256') {
                        if ($currentApp != null && $this->di->getConfig()->get("securityFiles") && $this->di->getConfig()->get("securityFiles")[$currentApp]) {
                            $key = $this->di->getConfig()->get("securityFiles")[$currentApp]['pubFile'];
                        } else {
                            $key = file_get_contents($this->dir . 'connector.pub');
                        }
                    }

                    if ($header['alg'] == 'HS256') {
                        if ($currentApp != null && $this->di->getConfig()->get("securityFiles") && $this->di->getConfig()->get("securityFiles")[$currentApp]) {
                            $key = $this->di->getConfig()->get("securityFiles")[$currentApp]['pemFile'];
                        } else {
                            $key = file_get_contents($this->dir . 'connector.pem');
                        }
                    }
                }

                $jwt = (array) JWT::decode($token, new Key($key, $header['alg'] ?? 'RS256'));
                $ip = $_SERVER['REMOTE_ADDR'] ?? false;
                if ($ip && $check) {
                    $cachedTime = $this->di->getCache()->get('token_' . $ip);
                    if (time() - $cachedTime > 1800) {
                        $check = true;
                    } else {
                        $check = false;
                    }
                }


                if (!$check || $this->di->getTokenManager()->checkToken($jwt) || $jwt['role'] == 'app') {
                    if ($ip && $check) {
                        $this->di->getCache()->set('token_' . $ip, time());
                    }
                    $result = ['success' => true, 'data' => $jwt];
                } else {
                    $result = [
                        'success' => false,
                        'code' => 'invalid_token',
                        'message' => 'Token is not valid anymore'
                    ];
                }
            } else {
                $result = [
                    'success' => false,
                    'code' => 'invalid_token',
                    'message' => 'Token is not valid anymore'
                ];
            }
        } catch (\Firebase\JWT\ExpiredException $e) {
            $tks = explode('.', $token);
            list($headb64, $bodyb64, $cryptob64) = $tks;
            $payload = json_decode(JWT::urlsafeB64Decode($bodyb64), true, 512, JSON_BIGINT_AS_STRING);
            $this->di->getTokenManager()->removeToken($payload);
            $result = ['success' => false, 'code' => 'token_expired', 'message' => $e->getMessage()];
        } catch (\Firebase\JWT\BeforeValidException $e) {
            $result = ['success' => false, 'code' => 'future_token', 'message' => $e->getMessage()];
        } catch (\Exception $e) {
            $result = ['success' => false, 'code' => 'token_decode_error', 'message' => $e->getMessage() /*.'- '.$key*/];
        }
        return $result;
    }

    public function curlRequest($url, $data, $json = true, $returnTransfer = true, $method = 'POST', $sslVerify = 2)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        if ($method == 'POST') {
            if ($json) {
                $dataString = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($dataString)
                )
                );
            } else {
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, $returnTransfer);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            return ['responseCode' => $httpCode, 'errorMsg' => curl_error($ch)];
        }
        return ['responseCode' => $httpCode, 'message' => $response];
    }

    public function addHandler($handler, $path, $overwrite = false)
    {
        $data = [
            'handler' => $handler,
            'content' => base64_encode(file_get_contents($path)),
            'bearer' => $this->di->getConfig()->rabbitmq_token,
            'overwrite' => $overwrite
        ];
        return $this->curlRequest($this->di->getConfig()->rabbitmq_url . '/rmq/queue/addHandler', $data, false);
    }

    public function getAllCountry()
    {

        $filePath = BP . DS . 'app' . DS . 'code' . DS . 'core' . DS . 'etc' . DS . 'country.php';
        if (file_exists($filePath)) {
            return require_once $filePath;
        }
        return [];
    }

    public function saveFile($directory, $file, $data, $mode = 'a')
    {
        if (!file_exists($directory)) {
            $oldmask = umask(0);
            mkdir($directory, 0777, true);
            umask($oldmask);
        }
        $handle = fopen($file, $mode);
        fwrite($handle, $data);
        fclose($handle);
    }

    public function setUserToken($userId)
    {
        $user = \App\Core\Models\User::findFirst([["_id" => $userId]]);
        $user->id = (string) $user->_id;
        $this->di->setUser($user);
        $token = $this->di->getRegistry()->getDecodedToken();
        $roleId = $user->role_id;
        $role = \App\Core\Models\Acl\Role::findFirst([["_id" => $roleId]]);
        $token['user_id'] = (string) $user->_id;
        $token['role'] = $role->code;
        $this->di->getRegistry()->setDecodedToken($token);
    }

    public function sendOtp($contactDetails)
    {
        $phone = $contactDetails['phone'];
        $userId = $this->di->getUser()->id;
        $client = new \Twilio\Rest\Client(
            $this->di->getConfig()->otp_details->account_number,
            $this->di->getConfig()->otp_details->access_token
        );
        $otp = rand(100000, 999999);
        try {
            $client->messages->create(
                $phone,
                array(
                    'from' => $this->di->getConfig()->otp_details->sender_phone,
                    'body' => 'Your OTP for  ' . $this->di->getConfig()->app_name . ' app is ' . $otp
                )
            );
            $this->di->getCache()->set($otp . '_' . $userId, time());
            return ['success' => true, 'message' => 'Get OTP from user'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Invalid mobile number.'];
        }
    }

    public function matchOtp($otpDetails)
    {
        $otp = $otpDetails['otp'];
        $userId = $this->di->getUser()->id;
        $currentTimestamp = time();
        if ($this->di->getCache()->get($otp . '_' . $userId)) {
            $otpTimestamp = $this->di->getCache()->get($otp . '_' . $userId);
            $this->di->getCache()->delete($otp . '_' . $userId);
            if (($currentTimestamp - $otpTimestamp) < 200) {
                return ['success' => true, 'message' => 'OTP matched'];
            }
            return ['success' => false, 'message' => 'OTP expired'];
        }
        return ['success' => false, 'message' => 'OTP did not match'];
    }

    public function testingThrottle($data)
    {
        echo ' | Message => ' . $data['data'] . ' | Timestamp => ' . time();
        return true;
    }

    public function getAllModules()
    {
        $filePath = BP . DS . 'app' . DS . 'etc' . DS . 'modules.php';
        if (file_exists($filePath)) {
            return require $filePath;
        } else {
            $modules = $this->getSortedModules();
            $activeModules = [];
            foreach ($modules as $mod) {
                foreach ($mod as $module) {
                    $activeModules[$module['name']] = $module['active'];
                }
            }
            $handle = fopen($filePath, 'w+');
            fwrite($handle, '<?php return ' . var_export($activeModules, true) . ';');
            fclose($handle);
            return $activeModules;
        }
    }

    public function getSortedModules()
    {
        $modules = [];
        foreach (new \DirectoryIterator(CODE) as $fileInfo) {
            if (!$fileInfo->isDot() && $fileInfo->isDir()) {
                $filePath = CODE . DS . $fileInfo->getFilename() . DS . 'module.php';
                if (file_exists($filePath)) {
                    $module = require_once $filePath;
                    if (isset($module['sort_order'])) {
                        $modules[$module['sort_order']][] = $module;
                    } else {
                        $modules[9999][] = $module;
                    }
                }
            }
        }
        ksort($modules, 1);
        return $modules;
    }

    public function getKeyPair()
    {
        $config = array(
            "digest_alg" => "sha512",
            "private_key_bits" => 4096,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        );

        // Create the private and public key
        $res = openssl_pkey_new($config);

        // Extract the private key from $res to $privKey
        openssl_pkey_export($res, $privKey);

        // Extract the public key from $res to $pubKey
        $pubKey = openssl_pkey_get_details($res);
        $pubKey = $pubKey["key"];

        return ['public' => $pubKey, 'private' => $privKey];
    }

    public function generateChildAcl($subuser)
    {
        $acl = new \Phalcon\Acl\Adapter\Memory();
        $acl->setDefaultAction(\Phalcon\Acl\Enum::DENY);
        $acl->addRole('child_' . $subuser['_id']);
        $resources = \App\Core\Models\Resource::find()->toArray();
        $components = [];
        $ids = [];
        foreach ($resources as $resource) {
            $ids[] = $resource['_id'];
            $components[$resource['module'] . '_' . $resource['controller']][] = $resource['action'];
        }
        foreach ($components as $componentCode => $componentResources) {
            $acl->addComponent($componentCode, $componentResources);
        }
        if (
            isset($subuser['resources']) &&
            ($subuser['resources'] instanceof \Phalcon\Db\Adapter\MongoDB\Model\BSONArray
                || is_array($subuser['resources'])
            )
        ) {
            foreach ($subuser['resources'] as $roleResource) {
                $_ = array_search($roleResource, $ids);
                if ($_ !== false) {
                    $acl->allow(
                        'child_' . $subuser['_id'],
                        $resources[$_]['module'] . '_' . $resources[$_]['controller'],
                        $resources[$_]['action']
                    );
                }
            }
        }
        $this->di->getCache()->set('child_' . $subuser['_id'] . "_acl", $acl, 'setup');
    }
}
