<?php

namespace App\Core\Traits;

use App\Core\Components\Config;
use Phalcon\Events\Manager;
use Phalcon\Db\Adapter\Pdo\Mysql as DbAdapter;

trait Application
{

    public function registerAllModules()
    {
        // Register the installed modules
        $modules = [];
        $namespaces = [];
        /** @var \Phalcon\Autoload\Loader */
        $loader = $this->getDi()->getLoader();

        foreach ($this->getAllModules() as $moduleName => $active) {
            if ($active) {
                $namespace = [
                    'App\\' . ucfirst($moduleName) . '\Controllers' => CODE . DS . $moduleName . DS . 'controllers' . DS,
                    'App\\' . ucfirst($moduleName) . '\Models' => CODE . DS . $moduleName . DS . 'Models' . DS,
                    'App\\' . ucfirst($moduleName) . '\Components' => CODE . DS . $moduleName . DS . 'Components' . DS,
                    'App\\' . ucfirst($moduleName) . '\Setup' => CODE . DS . $moduleName . DS . 'Setup' . DS,
                    'App\\' . ucfirst($moduleName) . '\Handlers' => CODE . DS . $moduleName . DS . 'Handlers' . DS,
                    'App\\' . ucfirst($moduleName) . '\Api' => CODE . DS . $moduleName . DS . 'Api' . DS,
                    'App\\' . ucfirst($moduleName) . '\Traits' => CODE . DS . $moduleName . DS . 'Traits' . DS,
                    'App\\' . ucfirst($moduleName) . '\Service' => CODE . DS . $moduleName . DS . 'Service' . DS,
                    'App\\' . ucfirst($moduleName) . '\Contracts' => CODE . DS . $moduleName . DS . 'Contracts' . DS,
                    'App\\' . ucfirst($moduleName) . '\Test' => CODE . DS . $moduleName . DS . 'Test' . DS,
                    'App\\' . ucfirst($moduleName) . '\Repositories' => CODE . DS . $moduleName . DS . 'Repositories' . DS
                ];

                $namespaces = array_merge($namespaces, $namespace);

                $modules[$moduleName] = [
                    'className' => 'App\\' . ucwords($moduleName) . '\Register',
                    'path' => CODE . DS . $moduleName . DS . 'Register.php',
                ];
            }
            $directories[] = CODE . DS . $moduleName . DS . 'console';
        }
        $loader->setDirectories($directories);
        $loader->setNamespaces($namespaces);
        $loader->register();
        $this->registerModules($modules);
    }

    public function getSortedModules()
    {
        $modules = [];
        foreach (new \DirectoryIterator(CODE) as $fileInfo) {
            if (!$fileInfo->isDot() && $fileInfo->isDir()) {
                $filePath = CODE . DS . $fileInfo->getFilename() . DS . 'module.php';
                if (file_exists($filePath)) {
                    $module = require $filePath;
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

    public function loadAllConfigs()
    {

        if (!$config = $this->di->getCache()->get('config')) {
            $config = new Config([]);
            foreach ($this->getAllModules() as $module => $active) {
                if ($active) {
                    $filePath = CODE . DS . $module . DS . 'etc' . DS . 'config.php';
                    if (file_exists($filePath)) {
                        $array = new \Phalcon\Config\Adapter\Php($filePath);
                        $config->merge($array);
                    }
                    $systemConfigFilePath = CODE . DS . $module . DS . 'etc' . DS . 'system.php';
                    if (file_exists($systemConfigFilePath)) {
                        $array = new \Phalcon\Config\Adapter\Php($systemConfigFilePath);
                        $config->merge($array);
                    }
                    $templatePath = CODE . DS . $module . DS . 'etc' . DS . 'template.php';
                    if (file_exists($templatePath)) {
                        $array = new \Phalcon\Config\Adapter\Php($templatePath);
                        $config->merge($array);
                    }
                }
            }
            $filePath = BP . DS . 'app' . DS . 'etc' . DS . 'config.php';
            if (file_exists($filePath)) {
                $array = new \Phalcon\Config\Adapter\Php($filePath);
                $config->merge($array);
            }
            // aws config
            $awsConfig = [];
            foreach (["aws", "dynamo", "sqs", "cloudwatch"] as $file) {
                $path = BP . DS . 'app' . DS . 'etc' . DS;
                if (file_exists($path . $file . ".php")) {
                    if ($file === 'aws') {
                        $awsConfig["aws"]["default"] = new \Phalcon\Config\Adapter\Php($path . $file . ".php");
                    } else {
                        $awsConfig["aws"][$file] = new \Phalcon\Config\Adapter\Php($path . $file . ".php");
                    }
                } else {
                    if ($file === 'aws') {
                        $awsConfig["aws"]["default"] = [];
                    } else {
                        $awsConfig["aws"][$file] = [];
                    }
                }
            }
            $config->merge($awsConfig);
            // redis config
            $redisConfig = [];
            $redisConfigData = require BP . DS . 'app' . DS . 'etc' . DS . "redis.php";
            if (!empty($redisConfigData)) {
                if (php_sapi_name() !== 'cli' && ($currentApp = $this->di->getRequest()->getHeader('current-App'))) {
                    $currentAppRedisConfigData = $redisConfigData[$currentApp];
                    $redisConfig["redis"] = new Config($currentAppRedisConfigData);
                } else {
                    $redisConfig["redis"] = new Config($redisConfigData);
                }
            }
            $config->merge($redisConfig);

            $prefix = $config->get('xiferp_retemarap_swa');
            if ($prefix != null) {
                $this->fetchParameterConfig($prefix, $config);
            }
            $this->di->getCache()->set('config', $config);
        }
        $this->di->set('config', $config);
    }

    public function fetchParameterConfig($prefix, $config)
    {
        $parameterName = "/" . $prefix;
        $ssmClient = new \Aws\Ssm\SsmClient(include BP . '/app/etc/aws.php');

        $result = [];
        $parameters = [];
        do {
            $result = $ssmClient->getParametersByPath([
                'Path' => $parameterName,
                'WithDecryption' => true,
                'Recursive' => true,
                'NextToken' => isset($result['NextToken']) ? $result['NextToken'] : null
            ]);
            foreach ($result['Parameters'] as $param) {
                $parameters = array_merge_recursive(
                    $parameters,
                    $this->getArrayFromPath($param['Name'], $param["Value"])
                );
            }
            // iterating pagination
        } while (isset($result['NextToken']));
        $config->merge(new Config($parameters));
    }
    /**
     * Get aws parameter path as nested array
     *
     * @param string $path
     * @param mixed $value
     * @param array $baseArray
     * @return array
     */
    public function getArrayFromPath(string $path, $value, array $baseArray = []): array
    {
        $pathArray = explode('/', $path);
        array_shift($pathArray);
        array_shift($pathArray);
        $temp = &$baseArray;
        foreach ($pathArray as $i) {
            $temp = &$temp[$i];
        }
        $temp = $value;
        unset($temp);
        return $baseArray;
    }

    public function loadDatabase()
    {
        if ($this->di->has('request')) {
            // for frontend requests
            $isReadPreferred = $this->di->getRequest()->getHeader('Connection-IsReadPreferred') ? true : false;
        } else {
            // for console requests
            $isReadPreferred = false;
        }
        $isMongo = false;

        foreach ($this->di->getConfig()->databases as $key => $database) {
            if ($database['adapter'] == 'Mongo') {
                $isMongo = true;
                $this->di->set(
                    $key,
                    function () use ($database, $isReadPreferred) {
                        if (!isset($database['username'])) {
                            $dsn =  $database['host'];
                            $mongo = new \MongoDB\Client($dsn);
                        } else {

                            $dsn =  $database['host'];
                            if ($isReadPreferred) {

                                $dsn =  $database['read-host'] ?? $database['host'];
                            }

                            $mongo = new \MongoDB\Client(
                                $dsn,
                                array(
                                    "username" => $database['username'],
                                    "password" => $database['password']
                                )
                            );
                        }

                        return $mongo->selectDatabase($database['dbname']);
                    },
                    true
                );
            } else {
                $this->di->set(
                    $key,
                    function () use ($database) {
                        return new DbAdapter((array)$database);
                    }
                );
            }
        }
        if ($isMongo) {
            $this->di->set(
                'collectionManager',
                function () {
                    $eventsManager = new Manager();
                    // Setting a default EventsManager
                    $modelsManager = new \Phalcon\Mvc\Collection\Manager();
                    $modelsManager->setEventsManager($eventsManager);
                    return $modelsManager;
                },
                true
            );
        }
    }
    public function hookEvents()
    {
        /**
         * Create a new Events Manager.
         */
        $eventsManager = new Manager();
        /**
         * Attach the middleware both to the events manager and the application
         */
        foreach ($this->di->getConfig()->events as $key => $events) {

            if ((php_sapi_name() === 'cli' && $key != 'application:beforeHandleRequest' && is_object($events)) ||
                (php_sapi_name() !== 'cli' && is_object($events))
            ) {
                foreach ($events as $event) {
                    $eventsManager->attach($key, $this->di->getObjectManager()->get($event));
                }
            }
        }
        $this->di->setEventsManager($eventsManager);
        $this->setEventsManager($eventsManager);
    }
}
