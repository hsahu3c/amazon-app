<?php

namespace App\Core\Components\Rest;

/**
 * Calling the respective functions from this class's respective component
 */
class Phpunit extends Base
{
    public function generateConfig()
    {
        if ($this->component->generateConfig()) {
            return ['success' => true, 'message' => 'phpunit.xml created successfully'];
        }
    }
}
