<?php

namespace App\Core;

use \Phalcon\Config;
use \Phalcon\Di\DiInterface;

/**
 * To keep the console compatible with rest of the framework
 */
class PhalconConsole extends \Phalcon\Cli\Console
{
    use Traits\Application;

    /**
     * Constructor
     *
     * @param DiInterface $di
     */
    public function __construct(DiInterface $di)
    {
        global $argv;
        parent::__construct();
        parent::setDI($di);
        $this->di = $di;
        $di->set('app', $this);
        $this->di->get('\App\Core\Components\Log');
        $this->di->get('\App\Core\Components\Cache');
        $this->registerAllModules();
        $this->di->set('registry', new \App\Core\Components\Registry);
        $this->loadAllConfigs();
        $this->registerDi();
        $this->loadDatabase();
        $this->di->setShared('objectManager', '\App\Core\Components\ObjectManager');
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
     * Register keys into di from config
     *
     * @return void
     */
    public function registerDi()
    {
        foreach ($this->di->getConfig()->di as $key => $class) {
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
        $user_id = (string)$user->getId();
        $decodedToken = [
            'role' => 'admin',
            'user_id' => $user_id,
        ];
        $this->di->getRegistry()->setDecodedToken($decodedToken);
        $this->di->getRegistry()->setRequestingApp('console');
        if ($user) {
            $this->di->setUser($user);
        }
    }
    /**
     * get list of active modules
     *
     * @return void
     */
    public function getAllModules()
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
}
