<?php

namespace App\Core\Controllers;

class CaptchaController extends BaseController
{
    /**
     * google recaptcha version 2
     * accepts data at post request,validates the response then sends result as json.
     * @return array
     */
    public function v2Action()
    {
        if (!$this->request->isPost()) {
            return $this->prepareResponse([
                'success' => false,
                'msg' => 'only post method is allowed.'
            ]);
        }
        if (!isset($this->getRequestData()["response"])) {
            return $this->prepareResponse([
                'success' => false,
                'msg' => 'response token is missing.'
            ]);
        }
        $isDev = $this->di->get('isDev') ?? false;
        $config = $this->di->getConfig();
        $token = $this->getRequestData()["response"];
        $clientIp = $this->request->getClientAddress();
        $recaptcha = new \ReCaptcha\ReCaptcha(
            $config->path('recaptcha.v2.secret-key', null),
            new \ReCaptcha\RequestMethod\CurlPost()
        );
        // if settings are given
        if (is_null($config->path('recaptcha.v2.settings', null))) {
            $resp = $recaptcha->verify($token, $clientIp);
        } else {
            $resp = $recaptcha
                ->setExpectedHostname($config->path('recaptcha.v2.settings.host-name', null))
                ->verify($token, $clientIp);
        }
        if ($resp->isSuccess()) {
            return $this->prepareResponse([
                'success' => true,
                'msg' => 'recaptcha verification successfully.'
            ]);
        }
        // if error
        return $isDev ? $this->prepareResponse([
            'success' => false,
            'msg' => 'recaptcha verification failed.',
            'errors' => $resp->getErrorCodes()
        ]) : $this->prepareResponse([
            'success' => false,
            'msg' => 'recaptcha verification failed.'
        ]);
    }
    /**
     * google recaptcha version 3
     * accepts data at post request,validates the response then sends result as json.
     * @return array
     */
    public function v3Action()
    {
        if (!$this->request->isPost()) {
            return $this->prepareResponse([
                'success' => false,
                'msg' => 'only post method is allowed.'
            ]);
        }
        if (!isset($this->getRequestData()["response"])) {
            return $this->prepareResponse([
                'success' => false,
                'msg' => 'response token is missing.'
            ]);
        }
        $isDev = $this->di->get('isDev') ?? false;
        $config = $this->di->getConfig();
        $token = $this->getRequestData()["response"];
        $clientIp = $this->request->getClientAddress();
        $recaptcha = new \ReCaptcha\ReCaptcha(
            $config->path('recaptcha.v3.secret-key', null),
            new \ReCaptcha\RequestMethod\CurlPost()
        );
        if (is_null($config->path('recaptcha.v3.settings', null))) {
            $resp = $recaptcha
                ->setScoreThreshold($config->path('recaptcha.v3.threshold', null))
                ->verify($token, $clientIp);
        } else {
            $resp = $recaptcha
                ->setExpectedHostname($config->path('recaptcha.v3.settings.host-name', null))
                ->setScoreThreshold($config->path('recaptcha.v3.threshold', null))
                ->verify($token, $clientIp);
        }
        if ($resp->isSuccess()) {
            return $this->prepareResponse([
                'success' => true,
                'msg' => 'recaptcha verification successfull.'
            ]);
        }
        return $isDev ? $this->prepareResponse([
            'success' => false,
            'msg' => 'recaptcha verification failed.',
            'errors' => $resp->getErrorCodes()
        ]) : $this->prepareResponse(['success' => false, 'msg' => 'recaptcha verification failed.']);
    }
}
