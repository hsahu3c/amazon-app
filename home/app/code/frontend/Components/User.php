<?php
namespace App\Frontend\Components;

use App\Core\Components\Base;
use DateTime;
use DateTimeZone;
class User extends Base {

     /**
     * Will change password.
     *
     * @param array $data
     * @return string
     */
    public function changePassword( $data ) {

        if( ! empty( $data['email'] ) ) {
            $coreUser =  new \App\Core\Models\User;
            $user = $coreUser::findFirst(["email" => "{$data['email']}" ])->toArray();
            if( $user['password'] !== $coreUser->getHash( $data['oldpassword'] ) ) {
                return [
                    'success' => false,
                    'msg'     => 'Password doesn\'t match.. Please try again'
                ];
            }

            $user['password'] = $coreUser->getHash($data['newpassword']);                    
            $coreUser->setData($user)->save();
            return ['success' => true, 'message' => 'Password has been Changed successfully.'];

        }
    }

    /**
     * function to get basic user details
     *
     * @param array $data contains params.
     * @return void
     */
    public function getUserDetails() {
        $created_at = $this->di->getUser()->created_at ?? '';
        if( is_object( $created_at) ) {
            $created_at = (string)$created_at / 1000;
            $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
            $userDetails  = $user_details->getConfigByKey('date_timezone_wrapper', $this->di->getUser()->id);
            $timeZone     = $userDetails !== false && ! empty( $userDetails['timezone'] ) ? $userDetails['timezone'] : 'GMT';

            $date_format  = $user_details->getConfigByKey('date_timezone_wrapper', $this->di->getUser()->id);
            $dateFormat  = $date_format !== false && ! empty( $date_format['date_format'] ) ? $date_format['date_format'] : 'Y-m-d H:i:s';


            $dateTime = gmdate( 'Y-m-d H:i:s',  $created_at );
            $date     = new \DateTime( $dateTime );
            $date->setTimezone(new \DateTimeZone($timeZone));
            $created_at = $date->format( $dateFormat );
        }
        elseif( empty( $created_at ) ) {
            $shops = $this->di->getUser()->shops ?? array();
            $created_at = $shops[0]['created_at'] ?? 'NA';
            if( is_object( $created_at) ) {
                $created_at = (string)$created_at / 1000;
                $user_details = $this->di->getObjectManager()->get('\App\Core\Models\User\Details');
                $userDetails  = $user_details->getConfigByKey('date_timezone_wrapper', $this->di->getUser()->id);
                $timeZone     = $userDetails !== false && ! empty( $userDetails['timezone'] ) ? $userDetails['timezone'] : 'GMT';

                $date_format  = $user_details->getConfigByKey('date_timezone_wrapper', $this->di->getUser()->id);
                $dateFormat  = $date_format !== false && ! empty( $date_format['date_format'] ) ? $date_format['date_format'] : 'Y-m-d H:i:s';


                $dateTime = gmdate( 'Y-m-d H:i:s',  $created_at );
                $date     = new \DateTime( $dateTime );
                $date->setTimezone(new \DateTimeZone($timeZone));
                $created_at = $date->format( $dateFormat );
            }
        }
        return [
            'email' => $this->di->getUser()->email ?? '',
            'store_url' => $this->getStoreUrl() ?? '',
            'created_at' => $created_at ?? '',
            'user_name' => $this->di->getUser()->username ?? ''
        ];
    }


    /**
     * Wil get the store url
     *
     * @return void
     */
    public function getStoreUrl() {

        $shops = $this->di->getUser()->shops ?? [];
        foreach ( $shops as $value ) {
            if (isset($value['targets']) && (! empty($value['domain']) || !empty($value['storeurl']))) {
                return $value['domain'] ?? ($value['storeurl'] ?? false);
            }
        }

        return false;

    }

    /**
     * Will save user locale.
     *
     * @param array $params array containing the params recieved in payload to save user locale.
     * @param array $headers array containing the headers of the request.
     * @since 1.0.0
     * @return array
     */
    public function saveUserLocale( $params = [], $headers = null ) {
        $collection = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo')->getCollectionForTable('user_details');
        $userId     = $this->di->getUser()->id ?? false;
        $localeCode = strtolower( (string) ($headers['Applocale'] ?? ( $headers['AppLocale'] ?? 'en' )) );
        $appTag     = $this->di->getAppCode()->getAppTag() ?? 'default';
        $collection->updateOne(
            [ 'user_id' => $userId ],
            [ '$set' => [ "{$appTag}.locale" => $localeCode ] ]
        );

        return [ 'success' => true, 'msg' => 'Locale Saved Successfully' ];
    }
}