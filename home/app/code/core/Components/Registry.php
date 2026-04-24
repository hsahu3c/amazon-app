<?php

namespace App\Core\Components;

use stdClass;

class Registry extends stdClass
{

    public function __call($property, $arguments)
    {
        if (strpos($property, 'get') === 0) {
            $output = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', substr($property, 3)));
            if (property_exists($this, $output)) {
                return $this->$output;
            }
            return false;
        } elseif (strpos($property, 'set') === 0) {
            $output = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', substr($property, 3)));
            $this->$output = $arguments[0];
            return $this;
        } else {
            throw new \RuntimeException($property . ' Method not found');
        }
    }

    public function getData()
    {
        return (array) $this;
    }
}
