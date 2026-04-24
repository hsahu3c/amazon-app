<?php

namespace App\Core;

use App\Core\Components\Config;
use Phalcon\Logger\Logger;
use Phalcon\Di\DiInterface;

class UnitApplication extends \Phalcon\Mvc\Application
{

    use \App\Core\Traits\Application;
    const FAILURE_MESSAGE = 'Something went wrong';

    /**
     * Application Constructor
     *
     * @param \Phalcon\Di\DiInterface $di
     */
    public function __construct(DiInterface $di)
    {
        /**
         * Sets the parent DI and register the app itself as a service,
         * necessary for redirecting HMVC requests
         */
        parent::setDI($di);
        $di->set('app', $this);
        $this->di->get('\App\Core\Components\Log');
        // passing isUnit if it's unitTest
        $this->di->get('\App\Core\Components\Cache', ["isUnit"]);
        $this->registerAllModules();
        $this->di->set('registry', new \App\Core\Components\Registry);
        $this->loadAllConfigs();
        $this->di->setShared('objectManager', '\App\Core\Components\ObjectManager');
        $this->registerDi();
        $this->loadDatabase();
        $this->di->setShared('transactionManager', '\App\Core\Models\Transaction\Manager');
        $this->di->getTransactionManager()->setRollbackPendent(true);
        $this->di->set('coreConfig', new \App\Core\Models\Config);
        $this->di->getUrl()->setBaseUri($this->di->getConfig()->backend_base_url);
        $this->di->setTokenManager($this->di->getObjectManager()->get('App\Core\Components\TokenManager'));
        $this->hookEvents();
        $this->setProxyUserAndToken();
    }
    public function setProxyUserAndToken()
    {
        // fetch admin's id from db
        $decodedToken = [
            'role' => 'admin',
            'user_id' => (string) \App\Core\Models\User::findFirst(['username' => 'admin'])->id,
        ];
        $this->di->getRegistry()->setDecodedToken($decodedToken);
        $user = \App\Core\Models\User::findFirst(['user_id' => $decodedToken['user_id']]);
        $this->di->getRegistry()->setRequestingApp('unittesting');
        if ($user) {
            $this->di->setUser($user);
        }
    }
    public function registerDi()
    {
        foreach ($this->di->getConfig()->di as $key => $class) {
            $this->di->getObjectManager()->get($key);
        }
    }
    /**
     * lists all currently active modules
     * writes all active modules in app/etc/modules.php
     * @return array
     */
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
    /**
     * loads and caches all config files
     * @internal used within UniApplication.php
     * @return void
     */
    public function loadAllConfigs()
    {
        if ($this->di->getCache()->get('config')) {
            $config = $this->di->getCache()->get('config');
        } else {
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
                }
            }
            //config for unit testing
            $filePath = BP . DS . 'app' . DS . 'etc' . DS . 'unit-config.php';
            if (file_exists($filePath)) {
                $array = new \Phalcon\Config\Adapter\Php($filePath);
                $config->merge($array);
            } else {
                throw new \Exception("\033[31m file `unit-config.php` is missing. file should be at \n $filePath \033[0m");
            }
            $this->di->getCache()->set('config', $config);
        }
        $this->di->set('config', $config);
    }
    public function logException($msg, $type, $file)
    {
        try {
            $this->di->getLog()->logContent($msg, $type, $file);
        } catch (\Phalcon\Mvc\Model\Transaction\Failed $e) {
            // echo $e->getMessage();
        }
    }
    public function run()
    {
        try {
            $response = $this->handle('');
            return $response->send();
        } catch (\Phalcon\Mvc\Router\Exception $e) {
            $msg = $e->getMessage() . PHP_EOL . $e->getTraceAsString();
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result = ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        } catch (\Phalcon\Mvc\Model\Exception $e) {
            $msg = ($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result =  ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        } catch (\Phalcon\Mvc\Application\Exception $e) {
            $msg = ($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result =  ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        } catch (\Phalcon\Logger\Exception $e) {
            $msg = ($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result =  ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        } catch (\Phalcon\Http\Response\Exception $e) {
            $msg = ($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result =  ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        } catch (\Phalcon\Http\Request\Exception $e) {
            $msg = ($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result =  ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        } catch (\Phalcon\Events\Exception $e) {
            $msg = ($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result =  ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        } catch (\Phalcon\Di\Exception $e) {
            $msg = ($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result =  ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        } catch (\Phalcon\Db\Exception $e) {
            $msg = ($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result =  ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        } catch (\Phalcon\Config\Exception $e) {
            $msg = ($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result =  ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        } catch (\Phalcon\Cache\Exception $e) {
            $msg = ($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result =  ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        } catch (\Phalcon\Application\Exception $e) {
            $msg = ($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result =  ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        } catch (\Phalcon\Acl\Exception $e) {
            $msg = ($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result =  ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        } catch (\Phalcon\Security\Exception $e) {
            $msg = ($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result =  ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        } catch (\Exception $e) {
            $msg = ($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            $this->logException($msg, Logger::CRITICAL, 'exception.log');
            $result =  ['success' => false, 'code' => 'something_went_wrong', 'message' => self::FAILURE_MESSAGE];
        }
        return (new \Phalcon\Http\Response)->setJsonContent($result)->send();
    }
}
