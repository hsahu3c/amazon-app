<?php
namespace App\Core\Models;

class Resource extends BaseMongo
{
    protected $table = 'acl_resource';
    
    

    public $module;

    public $controller;

    public $action;

    protected $isGlobal = true;
}
