<?php
namespace App\Core\Middlewares;

interface MiddlewareInterface
{

    /**
     * Calls the middleware
     */
    public function call(\Phalcon\Mvc\Application $application);
}
