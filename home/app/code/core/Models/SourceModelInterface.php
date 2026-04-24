<?php

namespace App\Core\Models;

/**
 * Interface SourceModelInterface
 */

interface SourceModelInterface
{
    /**
     * @param bool $userId
     * @param bool $getDetails
     * @return array
     */
    public function getShops($userId = false, $getDetails = false);
}
