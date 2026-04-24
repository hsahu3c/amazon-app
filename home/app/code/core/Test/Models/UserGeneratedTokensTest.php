<?php

namespace App\Core\Test\Models;

class UserGeneratedTokensTest extends \App\Core\Components\UnitTestApp
{

    /*
     * Get All token of users
     */
    public function testGetUserGeneratedTokens()
    {
        $helper = $this->di->getObjectManager()->get('\App\Core\Models\UserGeneratedTokens');
        $userId = 2;
        $limit = 5;
        $activePage = 1;
        $result = $helper->getUserGeneratedTokens($userId, $limit, $activePage);
        $this->assertTrue($result['success']);
    }

    /**
     * Generate a new token from the Refresh Token for user.
     */
    public function testCreateTokenByRefresh()
    {
        $helper = $this->di->getObjectManager()->get('\App\Core\Models\UserGeneratedTokens');
        $refreshToken = [];
        $refreshToken['title'] = 'Auto generated Token';
        $refreshToken['duration'] = 1440;
        $result = $helper->createTokenByRefresh($refreshToken);
        $this->assertTrue($result['success']);
    }
}
