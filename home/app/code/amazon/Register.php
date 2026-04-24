<?php

namespace App\Amazon;

use Phalcon\Mvc\View;
use Phalcon\Di\DiInterface;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\ModuleDefinitionInterface;

class Register implements ModuleDefinitionInterface
{

/**
 * Register a specific autoloader for the module
 */
public function registerAutoloaders($di = null){

}

/**
 * Register specific services for the module
 */
public function registerServices($di): void{
    // Registering a dispatcher
    $di->set(
        'dispatcher',
        function (): Dispatcher {
            $dispatcher = new Dispatcher();
            $dispatcher->setDefaultNamespace('App\Amazon\Controllers');
            return $dispatcher;
        }
    );

    // Registering the view component
    $di->set(
        'view',
        function (): View {
            $view = new View();
            $view->setViewsDir(CODE.'/amazon/views/');
            return $view;
        }
    );
  }
}