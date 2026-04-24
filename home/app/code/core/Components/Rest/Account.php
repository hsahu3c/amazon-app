<?php

namespace App\Core\Components\Rest;

use App\Core\Components\Rest\Base;

/**
 * Calling the respective functions from this class's respective component
 */
class Account extends Base
{
    public function changeStatus(string $usernames, bool $isSub, string $action)
    {
        return $this->component->changeStatus($usernames, $isSub, $action);
    }
}
