<?php
namespace App\Amazon\Components;

use Phalcon\Logger\Logger;
use App\Core\Components\Base;
use MongoDB\BSON\UTCDateTime;

/**
 * Class LoginHelper
 * @package App\Amazon\Components
 */
class LoginHelper extends Base
{
    public function loginAfter($event, $myComponent, $data = []): void
    {
        // $this->di->getLog()->logContent($data['user_id'], Logger::CRITICAL, 'last-login.log');
        try {
            $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
            $collection = $mongo->getCollectionForTable('user_details');
            $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];
            $seller = $collection->updateOne(['user_id' => $data['user_id']], ['$set' => ['last_login_at' => new UTCDateTime(strtotime(date('c')) * 1000)]]);
        } catch (\Exception $exception) {
            $this->di->getLog()->logContent($exception->getMessage().' || '.$exception->getTraceAsString(), Logger::CRITICAL, 'last-login-exception.log');
        }
    }
}