<?php

namespace App\Connector;

use Phalcon\Mvc\View;
use Phalcon\Di\DiInterface;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\ModuleDefinitionInterface;

class Register implements ModuleDefinitionInterface
{

    /**
     * Register a specific autoloader for the module
     */
    public function registerAutoloaders(DiInterface $di = null)
    {
    }

    /**
     * Register specific services for the module
     */
    public function registerServices(DiInterface $di): void
    {

        // Registering a dispatcher
        $di->set(
            'dispatcher',
            function (): \Phalcon\Mvc\Dispatcher {
                $dispatcher = new Dispatcher();

                $dispatcher->setDefaultNamespace('App\Connector\Controllers');
               
                return $dispatcher;
            }
        );


        // Registering the view component
        $di->set(
            'view',
            function (): \Phalcon\Mvc\View {
                $view = new View();

                $view->setViewsDir(CODE.'/connector/views/');

                return $view;
            }
        );
    }
}
