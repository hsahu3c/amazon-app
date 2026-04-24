<?php
namespace App\Frontend\Controllers;

use App\Core\Controllers\BaseController;
class FreshChatController extends BaseController
{
    public function saveRestoreIdAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $userId = $this->di->getUser()->id ?? null;
        if(!empty($userId)) {
            $collection = $this->di->getObjectManager()->get("\App\Core\Models\BaseMongo")->getCollectionForTable("user_details");
            $updateUser = $collection->updateOne(['user_id' => $userId],['$set'=>['freshchat_restore_id' => $rawBody['freshchat_restore_id']]]);
            return $this->prepareResponse(['success' => true, 'message' => 'Freshchat restore_id updated successfully','restore_id' => $rawBody['freshchat_restore_id']]);
        }

        return $this->prepareResponse(['success' => true, 'message' =>"User ID missing"]);
    }

    public function getRestoreIdAction()
    {
        $userId = $this->di->getUser()->id ?? null;
        if(!empty($userId)) {
            $user = $this->di->getUser();
            $userDetails = $user->getDetails();
            $paymentDetails = $this->getPaymentDetails($this->di->getUser()->id);
            $userDetails['data']['paymentDetails']= $paymentDetails;
            return $this->prepareResponse(['success' => true, 'data' => $userDetails]);
        }

        return $this->prepareResponse(['success' => true, 'message' =>"User ID missing"]);
    }

    public function getPaymentDetails($userId){
        $collection = $this->di->getObjectManager()->get("\App\Core\Models\BaseMongo")->getCollectionForTable("payment_details");
        $paymentDetails = $collection->aggregate([['$match'=>['user_id' => $userId,'type'=>'active_plan','status'=>'active']]])->toArray();
        return $paymentDetails[0];
    }
}