<?php

namespace App\Core\Middlewares;

use App\Core\Components\Staff\StaffRole;
use Phalcon\Events\Event;
use MongoDB\BSON\ObjectId;

/**
 * FirewallMiddleware
 *
 * Checks the whitelist and allows clients or not
 */
class Firewall implements MiddlewareInterface
{
    /**
     * @var int
     */
    public $timeLimit;

    /**
     * @var int
     */
    public $throttleLimit;
    protected $di;
    /**
     * Before anything happens
     * @param Event $event
     * @param \Phalcon\Mvc\Application $application
     * @return bool
     */
    public function beforeHandleRequest(Event $event, \Phalcon\Mvc\Application $application)
    {
        if (!$this->isIpValid()) {
            header("HTTP/1.1 403 Forbidden");
            header('Content-Type: application/json');
            echo json_encode(
                [
                    'success' => false,
                    'code' => 'access_forbidden',
                    'message' => 'Access Forbidden'
                ]
            );
            exit;
        }
        $this->di = $application->di;
        $throttleConfig = $this->di->getConfig();
        $this->timeLimit = $throttleConfig->get("throttle")->get("time_limit");
        $this->throttleLimit = $throttleConfig->get("throttle")->get("throttle_limit");
        $isThrottleEnabled = $throttleConfig->get("throttle")->get("is_throttle_enabled") ?? false;

        $allowedEndpoints = $this->di->getConfig()->path('maintenance.allowed_endpoints') ?? [];
        if ($this->isMaintenanceModeEnabled() && !$this->isEndpointAllowed($application->request->getURI(), $allowedEndpoints)) {
            $application->response->setStatusCode(503, 'Service Unavailable');
            $application->response->setJsonContent([
                'success' => false,
                'code' => 'under_maintenance',
                'message' => 'Maintenance mode is enabled. Please try again later.'
            ]);
            $application->response->send();
            exit();
        }

        if ($isThrottleEnabled) {
            $throttle = $this->handleThrottle($application);
            if (!$throttle) {
                header('Content-Type: application/json');
                echo json_encode(
                    [
                        'success' => false,
                        'code' => 'too_much_request',
                        'message' => 'Limit exceeded.Try after sometime.'
                    ]
                );
                exit;
            }
        }

        $headers = $this->get_nginx_headers();

        $isDev = $this->di->get('isDev') ?? false;

        if ($isDev && $application->request->getMethod() == 'OPTIONS') {
            header("Access-Control-Allow-Headers: " . $headers['Access-Control-Request-Headers'] . "");
            exit;
        }

        if (isset($headers['Authorization'])) {
            $bearer = $headers['Authorization'];
        } elseif (isset($headers['authorization'])) {
            $bearer = $headers['authorization'];
        } else {
            $bearer = '';
        }

        $authType = '';
        $bearer = preg_replace('/\s+/', ' ', $bearer);
        if (preg_match('/Bearer\s(\S+)/', $bearer, $matches)) {
            $bearer = $matches[1];
            $authType = 'bearer';
        } elseif (preg_match('/Basic\s(\S+)/', $bearer, $matches)) {
            $authType = 'basic';
        } else {
            $session = $application->di->getObjectManager()->get('session');
            $session->start();
        }
        if ($bearer == '' && $application->request->get('bearer')) {
            $bearer = $application->request->get('bearer');
            $session = $application->di->getObjectManager()->get('session');
            $session->start();
        }
        $this->di->getRegistry()->setIsSubUser(false);
        if ($bearer != '' && $authType != 'basic') {
            try {
                // setting current-App to db config
                $currentApp = null;
                if ($application->di->getRequest()->getHeader('current-App') != null) {
                    $currentApp = $application->di->getRequest()->getHeader('current-App');
                    $this->di->getCache()->set("db", $this->di->get('config')->databases->$currentApp);
                }
                $application->di->getAppCode()->setCurrentApp($currentApp);
                $decoded = $application->di->getObjectManager()
                    ->get('App\Core\Components\Helper')->decodeToken($bearer);
                if ($decoded['success']) {
                    $decoded = $decoded['data'];
                    $application->di->getRegistry()->setToken($bearer);
                    $this->validateRequest($application, $decoded);
                } else {
                    header('Content-Type: application/json');
                    echo json_encode(
                        [
                            'success' => false,
                            'code' => $decoded['code'],
                            'message' => $decoded['message']
                        ]
                    );
                    exit;
                }
            } catch (\Exception $e) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
        } else {
            if ($session->has("userId")) {
                $userId = $session->get("userId");
            } elseif ($application->request->get('user_id')) {
                $userId = $application->request->get('user_id');
            } else {
                $user = \App\Core\Models\User::findFirst([["username" => "anonymous"]]);
                $userId = (string) $user->getId();
            }

            $data = [
                'role' => 'anonymous',
                'user_id' => $userId,
            ];
            $this->validateRequest($application, $data);
        }
        return true;
    }

    private function isMaintenanceModeEnabled()
    {
        $maintenanceFile = BP . '/var/maintenance.lock';

        return file_exists($maintenanceFile);
    }

    private function isEndpointAllowed($uri, $allowedEndpoints)
    {
        foreach ($allowedEndpoints as $allowedEndpoint) {
            if (fnmatch($allowedEndpoint, $uri)) {
                return true;
            }
        }

        return false;
    }

    private function prepareLogData($application)
    {
        $request = $application->di->getRequest();
        $queryParam = $request->getQuery();
        return [
            '_id' => $application->di->getRegistry()->getRequestLogId(),
            'user_id' => $application->di->getUser()->id,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $request->getUserAgent(),
            'endpoint' => $queryParam['_url'] ?? '',
            'query_params' => $queryParam,
            'request_headers' => $request->getHeaders(),
            'request_body' => $request->getRawBody(),
            'method' => $request->getMethod()
        ];
    }

    /**
     * @param $application
     * @param $decodedToken
     * Headers
     * Ced-Source-Id
     * Ced-Source-Name
     * Ced-Target-Id
     * Ced-Target-Name
     * @return bool
     */
    public function validateRequest($application, $decodedToken)
    {
        if (isset($decodedToken['aud']) && $decodedToken['aud'] != $_SERVER['REMOTE_ADDR']) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(
                [
                    'success' => false,
                    'code' => 'wrong_aud',
                    'message' => 'Access forbidden'
                ]
            );
            exit;
        }
        $application->di->getRegistry()->setDecodedToken($decodedToken);

        $user = $application->di->getObjectManager()->create('\App\Core\Models\User')->findFirst([
            [
                '_id' => new \MongoDB\BSON\ObjectId($decodedToken['user_id'])
            ]
        ]);

        $application->di->getRegistry()->setRequestingApp('angular');

        $appCode = [
            'shopify' => "default"
        ];
        $appTag = "default";

        if ($application->di->getRequest()->getHeader('appCode') != null) {
            $appCode = json_decode(
                base64_decode($application->di->getRequest()->getHeader('appCode')),
                true
            );
        } elseif (isset($application->di->getRequest()->get()['app_code'])) {
            $appCode = $application->di->getRequest()->get()['app_code'];
        } elseif (
            $application->di->getRequest()->getHeader('Content-Type') != null &&
            strpos($application->di->getRequest()->getHeader('Content-Type'), 'application/json') !== false
            && isset($application->di->getRequest()->getJsonRawBody(true)['app_code'])
        ) {
            $appCode = $application->di->getRequest()->getJsonRawBody(true)['app_code'];
        }

        if ($application->di->getRequest()->getHeader('appTag') != null) {
            $appTag = $application->di->getRequest()->getHeader('appTag');
        }

        $application->di->getRequester()
            ->setHeaders($application->di->getRequest()->getHeaders());

        if ($user) {
            $user->id = (string) $user->_id;
            $application->di->setUser($user);

            if ($application->di->getConfig()->has('plan_di')) {
                $planDi = $application->di->getConfig()->get('plan_di')->toArray();
                if (!empty($planDi) && isset($planDi['enabled'], $planDi['class'], $planDi['method']) && $planDi['enabled'] && !empty($planDi['class']) && !empty($planDi['method'])) {
                    if (class_exists($planDi['class'])) {
                        $planObj = $this->di->getObjectManager()->create($planDi['class']);
                        if (method_exists($planObj, $planDi['method'])) {
                            $method = $planDi['method'];
                            $application->di->setPlan($planObj->$method($user->id));
                        }
                    }
                }
            }

            $application->di->getAppCode()->set($appCode);
            $application->di->getAppCode()->setAppTag($appTag);
            $acl = null;
            // if acl not already in cache, build it
            if (!$this->di->getCache()->has("acl", "setup")) {
                $this->di->getObjectManager()
                    ->get('App\Core\Components\Setup')
                    ->buildAclAction(false);
            }

            $acl = $this->di->getCache()->get("acl", "setup");

            $module = $application->router->getModuleName();
            $action = $application->router->getActionName();
            $controller = $application->router->getControllerName();


            if ($acl->isAllowed($decodedToken['role'], $module . '_' . $controller, $action)) {
                $this->checkStaffAccess($application, $user, $module . '_' . $controller, $action);
                $application->di->getUser()->setSubUserId($decodedToken['child_id'] ?? 0);
                return $this->checkChildAcl($decodedToken, $module . '_' . $controller, $action);
            } else {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(
                    [
                        'success' => false,
                        'code' => 'access_forbidden',
                        'message' => $this->di->getLocale()->_(
                            'access forbidden',
                            [
                                'msg' => $decodedToken['role'] . ' ' . $module . '_' . $controller . '_' . $action
                            ]
                        )
                    ]
                );
                exit;
            }
        } else {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(
                [
                    'success' => false,
                    'code' => 'token_user_not_found',
                    'message' => 'Access forbidden'
                ]
            );
            exit;
        }
    }


    public function checkStaffAccess($application, $user, $path, $action)
    {
        if ($application->di->getRequest()->getHeader('Business') != null) {
            $businessName = $application->di->getRequest()->getHeader('Business');
            if ($user->username != $businessName) {
                // User is not the main seller, check if they are staff
                $pipeline = [
                    [
                        '$match' => [
                            'username' => $businessName,
                            'staff.staff_user_id' => new ObjectId($user->id)
                        ]
                    ],
                    [
                        '$unwind' => '$staff'
                    ],
                    [
                        '$match' => [
                            'staff.staff_user_id' => new ObjectId($user->id),
                            'staff.status' => "approved"
                        ]
                    ],
                    [
                        '$limit' => 1
                    ]
                ];

                $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
                $collection = $mongo->getCollection('user_details');
                $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

                $staffMember = $collection->aggregate($pipeline, $options)->toArray();

                if ($staffMember) {
                    // User is a staff member, check ACL based on staff_role_id
                    $staffRole = $application->di->getObjectManager()->create('\App\Core\Models\Staff\StaffRole')->findFirst([
                        [
                            '_id' => $staffMember[0]['staff']['staff_role_id']
                        ]
                    ]);
                    if ($staffRole) {
                        if (!$this->di->getCache()->has('staff_acl', "setup")) {
                            $this->di->getObjectManager()->get(StaffRole::class)->generateStaffAcl();
                        }
                        $staffAcl = $this->di->getCache()->get("staff_acl", "setup");
                        if ($staffRole->resources == 'all' || $staffAcl->isAllowed($staffRole->code, $path, $action)) {
                            // Staff has permission
                            $mainSeller = $application->di->getObjectManager()->create('\App\Core\Models\User')->findFirst([
                                [
                                    'username' => $businessName
                                ]
                            ]);

                            if ($mainSeller) {
                                $mainSeller->id = (string) $mainSeller->_id;
                                $application->di->setUser($mainSeller);
                            }
                        } else {

                            header('Content-Type: application/json');
                            http_response_code(403);
                            echo json_encode(
                                [
                                    'success' => false,
                                    'code' => 'access_forbidden',
                                    'message' => 'Access forbidden'
                                ]
                            );
                            exit;
                        }
                    } else {
                        header('Content-Type: application/json');
                        http_response_code(403);
                        echo json_encode(
                            [
                                'success' => false,
                                'code' => 'invalid_staff_role',
                                'message' => 'Invalid staff role'
                            ]
                        );
                        exit;
                    }
                } else {
                    header('Content-Type: application/json');
                    http_response_code(403);
                    echo json_encode(
                        [
                            'success' => false,
                            'code' => 'not_staff',
                            'message' => 'User is not a staff member of the given business'
                        ]
                    );
                    exit;
                }
            }
        }
    }


    public function get_nginx_headers($functionName = 'getallheaders')
    {
        $allHeaders = array();
        if (function_exists($functionName)) {
            $allHeaders = $functionName();
        } else {
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) == 'HTTP_') {
                    $name = substr($name, 5);
                    $name = str_replace('_', ' ', $name);
                    $name = strtolower($name);
                    $name = ucwords($name);
                    $name = str_replace(' ', '-', $name);

                    $allHeaders[$name] = $value;
                } elseif ($functionName == 'apache_request_headers') {
                    $allHeaders[$name] = $value;
                }
            }
        }
        $this->di->getRegistry()->setHeaders($allHeaders);
        return $allHeaders;
    }

    public function handleThrottle($application)
    {
        $currentTimestamp = time();
        $clientIp = $_SERVER['REMOTE_ADDR'];
        $ip = $application->di->getCache()->get($clientIp);
        if ($ip) {
            $data = explode('_', $ip);
            $oldTimestamp = $data[0];
            $oldCount = $data[1];
            if ($oldTimestamp < $currentTimestamp - $this->timeLimit) {
                $application->di->getCache()->set($clientIp, $currentTimestamp . '_' . '1');
                return true;
            } elseif ($oldCount < $this->throttleLimit) {
                $oldCount++;
                $application->di->getCache()->set($clientIp, $oldTimestamp . '_' . $oldCount);
                return true;
            } else {
                $oldCount++;
                $application->di->getCache()->set($clientIp, $oldTimestamp . '_' . $oldCount);
                return false;
            }
        } else {
            $application->di->getCache()->set($clientIp, $currentTimestamp . '_' . '1');
            return true;
        }
    }

    public function checkChildAcl($decoded, $path, $action)
    {
        if (isset($decoded['child_id']) && $decoded['child_id']) {
            $mongo = new \App\Core\Models\BaseMongo;
            $collection = $mongo->getCollectionForTable("sub_user");
            $subuser = $collection->findOne(['_id' => $decoded['child_id']]);
            $this->di->getRegistry()->setIsSubUser(true);
            $this->di->setSubUser($subuser);
            if ($subuser['_id']) {
                if ($subuser['resources'] == 'all')
                    return true;

                $childAcl = null;
                $cacheKeyName = "child_" . $decoded['child_id'] . "_acl";

                // if child acl not in cache, build it
                if (!$this->di->getCache()->has($cacheKeyName, "setup")) {
                    $this->di->getObjectManager()
                        ->get('App\Core\Components\Setup')
                        ->buildChildAclById($subuser['_id']);
                }
                $childAcl = $this->di->getCache()->get(
                    $cacheKeyName,
                    "setup"
                );

                if ($childAcl->isAllowed('child_' . $decoded['child_id'], $path, $action)) {
                    return true;
                } else {
                    header('Content-Type: application/json');
                    http_response_code(403);
                    echo json_encode(
                        [
                            'success' => false,
                            'code' => 'child_access_forbidden',
                            'message' => 'Access forbidden'
                        ]
                    );
                    exit;
                }
            } else {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(
                    [
                        'success' => false,
                        'code' => 'child_token_user_not_found',
                        'message' => 'Access forbidden'
                    ]
                );
                exit;
            }
        } else {
            return true;
        }
    }
    public function isIpValid(): bool
    {
        $ip = !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : $_SERVER['SERVER_ADDR'];
        // $pattern = '/^((25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(25[0-5]|2[0-4]\d|[01]?\d\d?)$/';
        // return preg_match($pattern, $ip);
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Calls the middleware
     *
     * @param Micro $application
     *
     * @returns bool
     */
    public function call(\Phalcon\Mvc\Application $application)
    {
        return true;
    }
}
