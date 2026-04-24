<?php

namespace App\Core\Components;

class Services extends Base
{
    public function _construct(){

    }
    public function getInstantPrice($plan)
    {
        return 0;
    }

    public function getBillingPrice($plan, $input = [])
    {
        return 0;
    }

    public function getShortDescription($plan)
    {
        return '';
    }

    public function getDescription($plan)
    {
        return '';
    }

    public function getCreatePlanHtml()
    {
        return '';
    }

    public function preparePlanPaymentDetaills()
    {
        return '';
    }

    /**
     * @param $data
     * @return $this
     */
    public function setData($data)
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
        return $this;
    }
}
