<?php

namespace App\Connector\Components;

class ImportServiceHelper extends \App\Connector\Models\User\Service
{
    protected $table = 'user_service';

    protected $implicit = false;

    public $code = 'product_import';

    public $type = 'importer';

    //importer/uploader\
    public $startedAt;

    public $expiringAt;

    public $availableCredits = 0;

    public $usedCredits = 0;

    public $totalUsedCredits = 0;

    public $serviceCharge ;

     /* fixed charge of service*/
    public $perUnitUsagePrice;

    public $chargeType;

     // prepaid/postpaid
    public $unpaidCredits = 0; /*use for postpaid*/

    /* available credit reset date
        creadit reset after days
        service credit
    */
    public function onConstruct(): void
    {
        $this->di = $this->getDi();
        $this->setSource($this->table);

        $this->initializeDb($this->getMultipleDbManager()->getDefaultDb());
    }

    public function useService(): void
    {
        if ($this->chargeType == 'prepaid') {
            $this->getColelction()->findOneAndUpdate(
                ['code' => $this->code,'marchant_id'=>$this->merchant_id],
                ['$inc' => ['total_used_credits' => 1], '$dec' => ['available_credits' => 1]]
            );
        } else {
            $this->getColelction()->findOneAndUpdate(
                ['code' => $this->code,'marchant_id'=>$this->merchant_id],
                ['$inc' => ['total_used_credits' => 1,'unpaid_credits'=>1]]
            );
        }

        $this->totalUsedCredits += 1;
        $this->availableCredits -= 1;
    }

    public function canUseService(): int
    {
        $serviceExpireDate = new \DateTime($this->getExpiringAt());
        $now = new \DateTime();

        if ($this->chargeType == 'prepaid') {
            if ($serviceExpireDate > $now && $this->getAvailableCredits() > 0) {
                return 1;
            }
        } else {
            return 1;
        }

        return 0;
    }

    public function getBillTillNow(): int|float
    {
        return $this->serviceCharge + ($this->unpaidCredits * $this->perUnitUsagePrice);
    }

    public function resetUnpaidCredits(): void
    {
        $this->unpaidCredits = 0;
        $this->getColelction()->findOneAndUpdate(
            ['code' => $this->code,'marchant_id'=>$this->merchant_id],
            ['$set' => ['unpaid_credits' => 0]]
        );
    }
}
