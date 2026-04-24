<?php

namespace App\Core\Models\User;

class Data extends \App\Core\Models\Base
{
    protected $table = 'user_data';
    public $allowedKey = [
        'buisness_name',
        'full_name',
        'profile_pic',
        'mobile',
        'phone',
        'locale',
        'primary_time_zone',
        'best_time_to_contact',
        'term_and_conditon',
        'how_u_know_about_us',
        'firstname',
        'lastname',
        'preffered_time_of_calling',
        'time_zone',
        'website_url',
        'skype_id',
        'country',
        'state',
        'city',
        'zipcode',
        'shops',
        'user_id'
    ];
    public function initialize()
    {
        $this->setSource($this->table);
        $this->setConnectionService($this->getMultipleDbManager()->getDefaultDb());
    }
}
