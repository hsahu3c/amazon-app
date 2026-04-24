<?php

namespace App\Core\Components\Rest;

/**
 * Calling the respective functions from this class's respective component
 */
class Token extends Base
{
    public function cleanExpired()
    {
        $manager = $this->di->getObjectManager()->get('\App\Core\Components\TokenManager');
        $manager->cleanExpiredTokens();
        return ['success' => true, 'message' => 'Expired tokens cleaned'];
    }
}
