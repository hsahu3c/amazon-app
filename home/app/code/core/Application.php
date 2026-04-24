<?php

namespace App\Core;

use Phalcon\Mvc\Router;
use Phalcon\Di\DiInterface;
use Phalcon\Logger\Logger;

class Application extends \Phalcon\Mvc\Application
{
    const FAILURE_MESSAGE = 'Something went wrong';
    use Traits\Application;
    /**
     * Application Constructor
     *
     * @param \Phalcon\Di\DiInterface $di
     */
    public function __construct(DiInterface $di = null)
    {
        /**
         * Sets the parent DI and register the app itself as a service,
         * necessary for redirecting HMVC requests
         */
        parent::setDI($di);
        $di->set('app', $this);

        // setting is dev-flag in di
        $this->di->set('isDev', function () {
            return $_SERVER['CED_IS_DEV'] ?? (file_exists(BP . '/var/is-dev.flag') ?
                (int) trim(file_get_contents(BP . '/var/is-dev.flag')) : 0);
        });
        $this->di->get('\App\Core\Components\AppCode');

        $this->di->get('\App\Core\Components\Cache');
        $this->registerAllModules();
        $this->di->set('registry', new \App\Core\Components\Registry);
        $this->loadAllConfigs();
        // setting loadAllConfigs method pointer in di




        $loadAllConfigPtr = [$this, "loadAllConfigs"];
        $cache = $this->di->getCache();
        $this->di->setShared('allConfigs', function () use ($loadAllConfigPtr, $cache) {
            $key = "config";
            if ($config = $cache->get($key)) {
                return $config;
            }
            $loadAllConfigPtr();
            return $cache->get($key);
        });
        $iniConfig = $this->di->getConfig()->ini ?? [];
        foreach ($iniConfig as $iniKey => $iniValue) {
            ini_set($iniKey, $iniValue);
        }

        $this->di->setShared('objectManager', '\App\Core\Components\ObjectManager');
        $this->registerDi();
        $this->loadDatabase();

        $this->di->setShared('transactionManager', '\App\Core\Models\Transaction\Manager');

        /* set rollback pendent for rollback in case of any exception or error */
        $this->di->getTransactionManager()->setRollbackPendent(true);

        $this->di->set('coreConfig', new \App\Core\Models\Config);
        $this->di->getUrl()->setBaseUri($this->di->getConfig()->backend_base_url);
        $this->di->setTokenManager($this->di->getObjectManager()->get('App\Core\Components\TokenManager'));
        $this->hookEvents();
        $this->registerRouters();
    }

    public function registerDi()
    {
        foreach ($this->di->getConfig()->di as $key => $class) {
            $this->di->getObjectManager()->get($key);
        }
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




    public function registerRouters()
    {
        // Specify routes for modules
        $di = $this->di;
        $this->di->set(
            'router',
            function () use ($di) {
                $router = new Router();
                $router->setDefaultModule('core');
                $router->add(
                    '/:module/:controller/:action/:params',
                    [
                        'module' => 1,
                        'controller' => 2,
                        'action' => 3,
                        'params' => 4,
                    ]
                );
                foreach ($di->getConfig()->path("routers", []) as $routes) {
                    $di->getObjectManager()->get($routes)->addRouter($router);
                }
                return $router;
            }
        );
        /* Register routes of other modules */
    }

    public function logException($msg, $type, $file)
    {
        try {
            $this->di->getLog()->logContent($msg, $type, $file);
        } catch (\Phalcon\Mvc\Model\Transaction\Failed $e) {
            echo $e->getMessage();
        }
    }

    public function run()
    {
        try {
            $started = microtime(true);
            $this->router->setDefaultAction('index');
            $this->router->setDefaultController('index');
            $str = str_replace($this->di->getConfig()->get('base_path_removal'), '', $_SERVER['REQUEST_URI']);
            $response = $this->handle($str);
            if (
                $this->di->getConfig()->get("requests")
                && $this->di->getConfig()->get("requests")->get("track_requests")
                && $this->di->get('isDev')
            ) {
                $prepareResp = $this->di->getObjectManager()->get("App\Core\Components\RequestLogger");
                $prepareResp->logContent(array_merge($this->request->get(), ["started" => $started]), true);
            }
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
        $response = (new \Phalcon\Http\Response)
        ->setStatusCode(500)
        ->setJsonContent($result);

        $this->eventsManager->fire('application:beforeSendResponse', $this, $response);
        return $response->send();
    }
}
