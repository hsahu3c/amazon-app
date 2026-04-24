<?php

namespace App\Core\Components\Rest;

use App\Core\Components\Base as BaseComponent;

class Base extends BaseComponent
{
    protected $component;
    /**
     * Constructor
     */
    public function __construct($di)
    {
        parent::_construct();
        $this->setDi($di);
        $class = $this->getClass();
        echo BP . "/app/code/core/Components/{$class}.php";
        if (file_exists(BP . "/app/code/core/Components/{$class}.php")) {
            echo true;
            $this->component = $this->di->getObjectManager()->get("App\Core\Components\\{$class}");
        }
    }
    /**
     * Get the name of the child class without the namespace
     *
     * @return void
     */
    public function getClass()
    {
        $className = get_class($this);
        return explode("\\", $className)[count(explode("\\", $className)) - 1];
    }
}
