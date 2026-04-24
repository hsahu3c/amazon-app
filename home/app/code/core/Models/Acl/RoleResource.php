<?php
namespace App\Core\Models\Acl;

class RoleResource extends \App\Core\Models\BaseMongo
{
    protected $table = 'acl_role_resource';

    protected $isGlobal = true;
}
