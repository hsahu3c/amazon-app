<?php

namespace App\Core;

use \Phalcon\Di\DiInterface;
use Symfony\Component\Console\Output\ConsoleOutput;

class ConsoleApplication extends \Symfony\Component\Console\Application
{
    use Traits\Application;
    public $phalconConsole;
    public $di;
    public $locale;

    public function __call($name, $params)
    {
        if (!in_array($name, get_class_methods($this))) {
            if (!empty($params)) {
                return $this->phalconConsole->$name($params[0]);
            } else {
                return $this->phalconConsole->$name();
            }
        }
    }
    /**
     * Constructor
     *
     * @param DiInterface $di
     */
    public function __construct(DiInterface $di)
    {
        global $argv;
        parent::__construct();
        $this->setCatchExceptions(false);
        $this->di = $di;
        $di->set('app', $this);
        $this->di->get('\App\Core\Components\Log');
        $this->di->get('\App\Core\Components\Cache');
        $this->phalconConsole = new \App\Core\PhalconConsole($this->di);
        $this->registerAllModules();
        $this->di->set('registry', new \App\Core\Components\Registry);
        $this->loadAllConfigs();
        // setting loadAllConfigs method pointer in di
        $loadAllConfigPtr = [$this, "loadAllConfigs"];
        $cache = $this->di->getCache();
        $this->di->setShared('allConfigs', function () use ($loadAllConfigPtr, $cache) {
            $key = "config";
            if ($cache->has($key)) {
                return $cache->get($key);
            }
            $loadAllConfigPtr();
            return $cache->get($key);
        });
        $this->registerDi();
        $this->loadDatabase();
        $this->di->setShared('objectManager', '\App\Core\Components\ObjectManager');
        $this->di->setShared('objectManager', '\App\Core\Components\Translation');
        $this->di->set('coreConfig', new \App\Core\Models\Config);
        $this->di->setShared('transactionManager', '\App\Core\Models\Transaction\Manager');
        /* set rollback pendent for rollback in case of any exception or error */
        $this->di->getTransactionManager()->setRollbackPendent(true);
        $this->di->setTokenManager($this->di->getObjectManager()->get('App\Core\Components\TokenManager'));
        $this->di->setRequest($this->di->getObjectManager()->get('Phalcon\Http\Request'));
        if (!isset($argv[2]) || (isset($argv[2]) && $argv[2] != 'install')) {
            $this->setProxyUserAndToken();
        }
        $this->hookEvents();
    }

    /**
     * Register the DI from config
     *
     * @return void
     */
    public function registerDi()
    {
        foreach ($this->di->getConfig()->di as $key => $class) {
            if (method_exists($class, "setDi")) {
                (new $class)->setDi($this->di);
            }
            $this->di->set(
                $key,
                $class
            );
        }
    }
    /**
     * Set a proxy user
     *
     * @return void
     */
    public function setProxyUserAndToken()
    {
        $user = \App\Core\Models\User::findFirst([["username" => "admin"]]);
        $user->id = (string)$user->_id;
        $userId = (string)$user->getId();
        $decodedToken = [
            'role' => 'admin',
            'user_id' => $userId,
        ];
        $this->di->getRegistry()->setDecodedToken($decodedToken);
        $this->di->getRegistry()->setRequestingApp('console');
        if ($user) {
            $this->di->setUser($user);
        }
    }
    /**
     * Fetch all active modules
     *
     * @return array
     */
    public function getAllModules(): array
    {
        $modules = $this->getSortedModules();
        $activeModules = [];
        foreach ($modules as $mod) {
            foreach ($mod as $module) {
                $activeModules[$module['name']] = $module['active'];
            }
        }
        return $activeModules;
    }

    /**
     * Starting the Symphony Console Application
     *
     * @param array $args Command line arguments
     * @return void
     */
    public function handle($args)
    {
        $output = new ConsoleOutput();
        $output->writeln("<fg=blue>
       _  __      ____    ____   
   ___(_)/ _|    |___ \  |___ \  
  / __| | |_ _____ __) |   __) | 
 | (__| |  _|_____/ __/ _ / __/  
  \___|_|_|      |_____(_)_____| 
                                 
        </>");

        $enabledModules = array_filter($this->getAllModules(), fn($x) => $x);
        foreach (array_keys($enabledModules) as $i) {
            $path =  BP . "/app/code/{$i}/console";
            if (file_exists($path)) {
                $console = glob($path . "/*Command.php");
                foreach ($console as $file) {
                    require_once $file;
                    $pathArr = explode(DS, $file);
                    $name = $pathArr[count($pathArr) - 1];
                    $name = str_replace(".php", "", $name);
                    $name = "\\{$name}";
                    if (isset($this->phalconConsole->locale)) {
                        $this->locale = $this->phalconConsole->locale;
                    }
                    $instance = new $name($this->phalconConsole->di);
                    if (
                        get_parent_class($instance) == "BaseCommand" ||
                        get_parent_class($instance) == "Symfony\Component\Console\Command\Command"
                    ) {
                        $this->add(new $name($this->di));
                    }
                }
            }
        }
        $this->run();
    }
}
