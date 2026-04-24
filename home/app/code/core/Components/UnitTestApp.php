<?php

namespace App\Core\Components;

use Phalcon\Autoload\Loader;
use \Phalcon\Di\DiInterface;
use \Phalcon\Di\FactoryDefault;
use PHPUnit\Framework\TestCase;

class UnitTestApp extends TestCase
{
    protected bool $_loaded = false;
    protected $di;
    
    public function setUp(): void
    {
        $di = new FactoryDefault();
        require_once BP . DS . 'vendor' . DS . 'autoload.php';
        /**Register loader for modules**/
        $di->set(
            'loader',
            function () {
                return new Loader();
            }
        );
        /** @var Loader */
        $loader = $di['loader'];
        $loader->setNamespaces(
            [
                'Phalcon' => BP . DS . 'vendor' . DS . 'phalcon' . DS . 'incubator' . DS . 'Phalcon' . DS,
                'App\Core'   => CODE . DS . 'core',
                'App\Core\Middlewares'   => CODE . DS . 'core' . DS . 'Middlewares' . DS,
            ]
        );
        $loader->register();
        // Create an application
        new \App\Core\UnitApplication($di);
        $this->setDi($di);
        $this->_loaded = true;
    }

    public function setDi(DiInterface $di)
    {
        $this->di = $di;
    }
}
