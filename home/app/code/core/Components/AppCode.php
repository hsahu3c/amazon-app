<?php

namespace App\Core\Components;

class AppCode extends Base
{

    private $app_code = [
        'shopify' => "default"
    ];

    private $app_tag = 'default';

    private $currentApp = null;

    public function setDi(\Phalcon\Di\DiInterface $di): void
    {
        parent::setDi($di);
        $this->di->set('appCode', $this);
    }

    public function get()
    {
        return $this->app_code;
    }

    public function set($app_code)
    {
        if (gettype($app_code) === "string") $app_code = [
            'shopify' => $app_code
        ];
        $this->app_code = array_merge($this->app_code, $app_code);
    }

    public function getAppTag()
    {
        return $this->app_tag;
    }

    public function setAppTag($app_tag)
    {
        $this->app_tag = $app_tag;
    }

    public function setCurrentApp($currentApp)
    {
        $this->currentApp = $currentApp;
    }

    public function getCurrentApp()
    {
        return $this->currentApp;
    }
}
