<?php

namespace App\Core\Components;

class Profile extends \App\Core\Components\Base
{
    public function getCategories($marketplace, $level, $parent)
    {
        $class = 'App\Core\\'.ucfirst($marketplace).'\Components\Profile';
        $result = [];
        if (class_exists($class)) {
            $result = $this->di->getObjectManager()->get($class)->getCategories($level, $parent);
        }
        return  $result;
    }

    public function getAttributes($marketplace, $category)
    {
        $class = 'App\Core\\'.ucfirst($marketplace).'\Components\Profile';
        $result = [];
        if (class_exists($class)) {
            $result = $this->di->getObjectManager()->get($class)->getAttributes($category);
        }
        return  $result;
    }
}
