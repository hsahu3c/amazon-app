<?php

namespace App\Core\Components;

class Session extends Base
{
    protected $session;
    /**
     * starts a session
     * @return void
     */
    public function start()
    {
        $this->session = new \Phalcon\Session\Manager();
        $redisConfig = require BP . DS . 'app' . DS . 'etc' . DS . 'redis.php';
        $serializerFactory = new \Phalcon\Storage\SerializerFactory();
        $factory = new \Phalcon\Storage\AdapterFactory($serializerFactory);
        $redisConfig['defaultSerializer'] = 'Php';
        $redisAdapter = new \Phalcon\Session\Adapter\Redis($factory, $redisConfig);
        $this->session->setAdapter($redisAdapter);
        $this->session->start();
    }

    /**
     * tells weather key is present or not in the session.
     *
     * @param mixed $key
     * @return boolean
     */
    public function has($key)
    {
        if (!$this->session->has($key)) {
            return false;
        }
        return true;
    }
    /**
     * sets a key value pair in session
     *
     * @param mixed $key
     * @param mixed $value
     * @return mixed
     */
    public function set($key, $value)
    {
        return $this->session->set($key, $value);
    }
    /**
     * returns a value associated with it's key
     *
     * @param mixed $key
     * @return mixed
     */
    public function get($key)
    {
        return $this->session->get($key);
    }
    /**
     * deletes a particular key from session
     *
     * @param mixed $key
     * @return bool
     */
    public function delete($key)
    {
        return $this->session->remove($key);
    }
    /**
     * destroys the session
     *
     * @param mixed $key
     * @return bool
     */
    public function destroy()
    {
        return $this->session->destroy();
    }
    /**
     * tells weather the session is started or not
     *
     * @return boolean
     */
    public function isStarted()
    {
        if ($this->session) return true;
        return false;
    }
}
