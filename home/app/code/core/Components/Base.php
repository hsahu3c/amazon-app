<?php

namespace App\Core\Components;

use Phalcon\Di\InjectionAwareInterface;

class Base implements InjectionAwareInterface
{
    /**
     * @var DiInterface
     */
    protected $di;

    public function setDi(\Phalcon\Di\DiInterface $di): void
    {
        $this->di = $di;
        $this->_construct();
    }

    public function getDi(): \Phalcon\Di\DiInterface
    {
        return $this->di;
    }

    public function _construct()
    {
        // invoking via setDi
    }

    public function setUserCache($key, $value, $userId = false)
    {
        if (!$userId) $userId = $this->di->getUser()->id;
        $this->setCache('user_' . $key . '_' . $userId, $value);
    }

    public function setCache($key, $value)
    {
        $this->di->getCache()->set($key, $value);
    }

    public function getUserCache($key, $userId = false)
    {
        if (!$userId) $userId = $this->di->getUser()->id;
        return $this->getCache('user_' . $key . '_' . $userId);
    }
    public function getCache($key)
    {
        return $this->di->getCache()->get($key);
    }
}
