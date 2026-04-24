<?php

namespace App\Core\Components\Rest;

use App\Core\Components\Rest\Base;

class User extends Base
{
    public function getToken($user)
    {
        $user = \App\Core\Models\User::findFirst([["username" => $user]]);
        if ($user) {
            return $user->getToken('+365 days', false, false);
        } else {
            return false;
        }
    }
    public function create(string $user, string $email, $password, $role)
    {
        $userModel = $this->di->getObjectManager()->create('\App\Core\Models\User');
        return $userModel->createUser([
            'username' => $user,
            'email' => $email,
            'password' => $password,
        ], $role);
    }
    public function updatePassword($user, $password)
    {
        $user = \App\Core\Models\User::findFirst([["username" => $user]]);
        $hash = $user->getHash($password);
        $user->setPassword($hash);
        $user->save();
    }
}
