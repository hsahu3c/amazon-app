<?php

namespace App\Core\Components;

use Phalcon\Cache\Adapter\Redis;
use Phalcon\Storage\SerializerFactory;
use Phalcon\Cache\Cache as PhalconCache;

class Cache extends Base
{
    protected $cache = null;
    public $prefix;
    public $isUnit = false;

    public function __construct()
    {
        $args = func_get_args();
        if (count($args) && $args[0] == 'isUnit')
            $this->isUnit = true;
    }

    public function setDi(\Phalcon\Di\DiInterface $di): void
    {
        parent::setDi($di);
        if (!$this->cache) {
            // if unit test is true
            if ($this->isUnit) {
                $redisConfig =  BP . DS . 'app' . DS . 'etc' . DS . 'phpunit-redis.php';
                if (!is_file($redisConfig)) {
                    throw new \RuntimeException("file `phpunit-redis.php` is missing. file should be at $redisConfig");
                }
                $redisConfig = require $redisConfig;
            } else {
                $redisConfig = BP . DS . 'app' . DS . 'etc' . DS . 'redis.php';
                if (!is_file($redisConfig)) {
                    throw new \RuntimeException("file `redis.php` is missing. file should be at $redisConfig");
                }
                $redisConfigData = require $redisConfig;
                 if (php_sapi_name() !== 'cli' && ($currentApp = $this->di->getRequest()->getHeader('current-App'))) {
                    $currentApp = $this->di->getRequest()->getHeader('current-App');
                    $currentAppRedisConfigData = $redisConfigData[$currentApp];
                    $redisConfig = $currentAppRedisConfigData;
                }else{
                    $redisConfig = $redisConfigData;
                }
            }
            $serializerFactory = new SerializerFactory();

            $redisConfig['defaultSerializer'] = 'Php';
            $adapter = new Redis($serializerFactory, $redisConfig);
            // phalcon cache adapter provides necessary methods to provide PSR-16 methods
            $cache = new PhalconCache($adapter);
            $this->cache = $cache;
            $this->di->set('cache', $this);

            if (!isset($redisConfig["prefix"])) {
                throw new \RuntimeException("missing key 'prefix' in redis config. at $path ");
            }
            if ($redisConfig["prefix"][strlen($redisConfig["prefix"]) - 1] !== "_") {
                throw new \RuntimeException("missing underscore at last of prefix key in redis or phpunit-redis config.");
            }
            //setting the default global prefix
            $this->prefix = $redisConfig["prefix"];
        }
    }

    /**
     * Fetches a value from the cache.
     *
     * @param string $key The unique key of this item in the cache.
     * @param string $cacheType The Sub Prefix or a container, containing all the key values. 
     * @param mixed $default Default value to return if the key does not exist.
     * @return mixed The value of the item from the cache, or $default in case of cache miss.
     */
    public function get($key, $cacheType = "default", $default = null)
    {
        return $this->cache->get($cacheType . "_" . $key, $default);
    }

    /**
     * Persists data in the cache, uniquely referenced by a key with an optional expiration TTL time.
     *
     * @param string $key The unique key of this item in the cache.
     * @param mixed $value The value of the item to store. Must be serializable.
     * @param string $cacheType The Sub Prefix or a container, containing all the key values. 
     * @param null|int|\DateInterval $ttl Optional. The TTL value of this item. If no value is sent and
     *            the driver supports TTL then the library may set a default value
     *            for it or let the driver take care of that.
     * @return bool True on success and false on failure.
     */
    public function set($key, $value, $cacheType = "default", $ttl = null)
    {
        if ($this->has($cacheType, "blocked")) {
            return false;
        }
        return $this->cache->set($cacheType . "_" . $key, $value, $ttl);
    }

    /**
     * Determines whether an item is present in the cache.
     *
     * NOTE: It is recommended that has() is only to be used for cache warming type purposes
     * and not to be used within your live applications operations for get/set, as this method
     * is subject to a race condition where your has() will return true and immediately after,
     * another script can remove it, making the state of your app out of date.
     *
     * @param string $key The unique key of this item in the cache.
     * @param string $cacheType The Sub Prefix or a container, containing all the key values. 
     * @return boolean
     */
    public function has($key, $cacheType = "default")
    {
        return $this->cache->has($cacheType . "_" . $key);
    }
    /**
     * Determines whether an item is present in the cache.
     *
     * NOTE: It is recommended that has() is only to be used for cache warming type purposes
     * and not to be used within your live applications operations for get/set, as this method
     * is subject to a race condition where your has() will return true and immediately after,
     * another script can remove it, making the state of your app out of date.
     *
     * @param string $key The unique key of this item in the cache.
     * @param string $cacheType The Sub Prefix or a container, containing all the key values. 
     * @return boolean
     */
    public function exists($key, $cacheType = "default")
    {
        return $this->has($key, $cacheType);
    }
    /**
     * Delete an item from the cache by its unique key.
     * 
     * @param string $key The unique key of this item in the cache.
     * @param string $cacheType The Sub Prefix or a container, containing all the key values. 
     * @return bool
     */
    public function delete($key, $cacheType = "default")
    {
        return $this->cache->delete($cacheType . "_" . $key);
    }
    /**
     * Persists a set of key => value pairs in the cache, with an optional TTL.
     *
     * @param list $values A list of key => value pairs for a multiple-set operation.
     * @param string $cacheType The Sub Prefix or a container, containing all the key values. 
     * @param null|int|\DateInterval $ttl    Optional. The TTL value of this item. If no value is sent and
     *                                       the driver supports TTL then the library may set a default value
     *                                       for it or let the driver take care of that.
     * @return bool True on success and false on failure.
     */
    public function setMultiple($values, $cacheType = "default", $ttl = null)
    {
        if ($this->has($cacheType, "blocked")) {
            return false;
        }
        $mapper = function ($values) use ($cacheType) {
            $tmp = [];
            foreach ($values as $k => $v) {
                $tmp[$cacheType . "_" . $k] =  $v;
            }
            return $tmp;
        };
        $containerArray = $mapper($values);
        return $this->cache->setMultiple($containerArray, $ttl);
    }

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param string $key The unique key of this item in the cache.
     * @param string $cacheType The Sub Prefix or a container, containing all the key values. 
     * @param mixed $default Default value to return if the key does not exist.
     * @return mixed
     */
    public function getMultiple($keys, $cacheType = "default", $default = null)
    {
        $containerArray = array_map(function ($v) use ($cacheType) {
            return $cacheType . "_" . $v;
        }, $keys);
        return $this->cache->getMultiple($containerArray, $default);
    }
    /**
     *  Deletes multiple cache items in a single operation.
     *
     * @param string $key The unique key of this item in the cache.
     * @param string $cacheType The Sub Prefix or a container, containing all the key values. 
     * @return bool
     */
    public function deleteMultiple($keys, $cacheType = "default")
    {
        $keys = array_map(function ($v) use ($cacheType) {
            return $cacheType . "_" . $v;
        }, $keys);
        return $this->cache->deleteMultiple($keys);
    }

    /**
     * Wipes clean the entire cache's keys of the given cacheType's keys.
     *
     * @return bool True on success and false on failure.
     */
    public function clear($cacheType = "default")
    {
        $containerArray = array_keys($this->getAll($cacheType));
        $containerArray = array_map(function ($k) {
            return explode($this->prefix, $k)[1];
        }, $containerArray);

        return $this->cache->deleteMultiple($containerArray);
    }
    /**
     * Wipes clean the entire keys of default cache.
     *
     * @return bool True on success and false on failure.
     */
    public function flush()
    {
        $containerArray = array_keys($this->getAll("default") ?? []);
        return $this->deleteMultiple($containerArray);
    }
    /**
     * Wipes clean the entire keys of given cacheType's cache.
     *
     * @return bool True on success and false on failure.
     */
    public function flushByType($cacheType)
    {
        $x = $this->getAll($cacheType);
        if (is_null($x) || !$x) return false;
        $containerArray = array_keys($x);
        return $this->deleteMultiple($containerArray, $cacheType);
    }
    /**
     * Wipes clean the entire cache's keys.
     *
     * @return bool True on success and false on failure.
     */
    public function flushAll()
    {
        return $this->cache->clear();
    }
    /**
     * Retrieves all keys saved in cache
     *
     * @param string $cacheType
     * @return array
     */
    public function getAll($cacheType = null)
    {
        $container = [];
        $tmp = [];
        $container = array_map(function ($x) {
            return explode("_", $x, 2)[1];
        }, $this->cache->getAdapter()->getKeys($cacheType ?? ""));
        foreach ($container as $key) {
            [$parent, $child] = explode("_", $key, 2);
            $tmp[$parent][$child] = $this->get($child, $parent);
        }
        if (!is_null($cacheType) && !key_exists($cacheType, $tmp)) return [];
        return is_null($cacheType) ? ($tmp ?? []) : $tmp[$cacheType];
    }
    /**
     *  blocks the given cacheType
     *
     * @param string $cacheType
     * @param integer $ttl
     * @return bool
     */
    public function blockCache($cacheType, $ttl = 1800)
    {
        $this->flushByType($cacheType);
        return $this->cache->set("blocked_" . $cacheType, 1, $ttl);
    }
}
