<?php

namespace App\Connector\Models\MongostreamV2;

use Aws\Ec2\Ec2Client;

use App\Connector\Models\MongostreamV2\Helper;

//this is not used as we can leverage cloudwatch functionallity to achive auto restart at the time of health fail
class InitiateFailSafeCases extends Helper
{

    public $_client;

    public function initiate(): void
    {
        $this->initializeClient();
        //check the instance status, if instance status is stopped then try to restart 
        //check how many time it was restarted
        //if less than 3 check the server running
        //re-start server and send a mail that server restart try has been done

        $res = $this->checkInstanceStatus();

        if ($res['Code'] == 16) {
            $this->checkManually();
        } else {
        }
    }

    private function initializeClient(): void
    {
        $this->_client = new Ec2Client(include BP . '/app/etc/aws.php');
    }

    private function checkInstanceStatus()
    {

        $res =  $this->_client->describeInstanceStatus();

        return $res['InstanceStatuses'][0]['InstanceState'];
    }

    private function checkManually(): void
    {
        //send a mail that pm2 failed to restart we need to debug manually
    }
}
