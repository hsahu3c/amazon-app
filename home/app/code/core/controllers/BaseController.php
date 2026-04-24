<?php

namespace App\Core\Controllers;

use App\Core\Components\Log;
use Phalcon\Mvc\Controller;

class BaseController extends Controller
{
    public function prepareResponse($data, $type = 'json')
    {
        $data['ip'] = $_SERVER['REMOTE_ADDR'];
        if (isset($data['message']) && is_string($data['message'])) {
            $data['message'] = $this->di->getLocale()->_($data['message']);
            $data['is_translated'] = true;
        }
        $app = $this->di->getRegistry()->getRequestingApp();
        try {
            if ($type == 'json') {
                $controller = ucfirst(
                    str_replace(
                        '-',
                        '',
                        ucwords($this->router->getControllerName(), '-')
                    )
                );
                $action = $this->router->getActionName();
                $modifierClass = 'App\\' . ucfirst($app) . '\Components\Modifiers\\' . $controller;
                $modifiedResponse = $data;
                if (class_exists($modifierClass)) {
                    $modifiedResponse = $this->di
                        ->get('App\\' . ucfirst($app) . '\Components\Modifiers\\' . $controller)
                        ->$action($data);
                }
                if ($this->di->getConfig()->path("requests.track_global_requests", false))
                    $data["time_taken"] = number_format(abs(microtime(true) - TRACK_INIT), 3);
                $URI = $this->request->get()["_url"] ?? "/";
                if ($this->di->getConfig()->path("log_user_activity.$URI", false)) {
                    $prepareData = [
                        'url' => $URI,
                        'time_taken' => round(abs(microtime(true) - TRACK_INIT), 3),
                        'playload' => array_merge(
                            $this->getRequestData(),
                            $this->request->get() ?? []
                        ),
                    ];
                    $this->di->getObjectManager()->get('\App\Frontend\Components\Helper')->logAPItime($prepareData);
                }
                $response = $this->response->setJsonContent($modifiedResponse);
            }
        } catch (\Exception $exception) {
            if ($this->di->getConfig()->path("requests.track_global_requests", false))
                $data["time_taken"] = number_format(abs(microtime(true) - TRACK_INIT), 3);
            $code = (int) ($data["status_code"] ?? ($data["statusCode"] ?? 200));
            if ($code)
                $this->response->setStatusCode($code);
            $response = $this->response->setJsonContent($data);
        }
        if (!isset($modifiedResponse)) {
            $modifiedResponse = $data;
        }
        $this->logResponse($response, $modifiedResponse);
        return $response;
    }
    public function getRequestData()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }
        return $rawBody;
    }
    private function logResponse($responseObj, $modifiedResponse)
    {
        $configData = $this->di->getRegistry()->getAppConfig();
        $logData = [
            '_id' => $this->di->getRegistry()->getRequestLogId(),
            'channel' => $configData['marketplace'] ?? $this->di->getAppCode()->get() ?? "default",
            'response_headers' => $responseObj->getHeaders()->toArray(),
            'url' => $this->request->get()["_url"] ?? "/",
            'method' => $this->request->getMethod(),
            'payload' => array_merge(
                $this->getRequestData(),
                $this->request->get() ?? []
            )
        ];
        $requestLogger = $this->di->getObjectManager()->get('\App\Core\Components\RequestLogger');

        if ($requestLogger->canLogResponse($modifiedResponse)) {
            $logData['response_body'] = $modifiedResponse;
            $logData['started'] = $modifiedResponse["started"] ?? microtime(true);
            $logData['ended'] = microtime(true);
            $logData['time_taken'] =
                $logData["ended"] - ($modifiedResponse["started"] ?? microtime(true));
            $requestLogger->logContent($logData);
        }
    }
}
