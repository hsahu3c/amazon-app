<?php

namespace App\Connector\Api\Helper;

use App\Core\Models\BaseMongo;


class GetGCPAccessToken extends BaseMongo
{
    /**
     * Generate new access token
     * @return array
     */
    public function generateNewAccessToken()
    {
        $awsLambda = new \App\Connector\Api\Helper\AWSLambda;

        $cred = $this->di->getConfig()->google_auth_library_details->toArray();

        $awsLambdaUrl = 'https://dhwvvna24qofurmimz4akt6hbm0jzmmx.lambda-url.ap-northeast-3.on.aws/';

        return $awsLambda->invokeFunctionWithIAMCred($awsLambdaUrl, 'POST', [], $cred, ['region' => 'ap-northeast-3']);
    }
}
