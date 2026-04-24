<?php
/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */
namespace App\Connector\Service;
use App\Connector\Contracts\Currency\CurrencyInterface;
/**
 * Interface 
 * @services
 */
class Currency implements CurrencyInterface
{
    protected $userCurrencySetting;

    const BASE_CURRENCY = 'USD';

    public function convert( $clientCurrency, $price, $baseCurrency = "USD")
    {
        return $price; 
        #TODO: Since this piece of code is unreachable
        // $userCurrencySetting = $this->getCurrencySetting();
        // $currencyValue = [];
        // foreach($userCurrencySetting as $key => $currency){
        //     if(isset($currency['currency']) && $currency['currency'] == $clientCurrency)
        //     {
        //         $currencyValue = $currency;
        //         break;
        //     }
        // }
        // if(empty($currencyValue)){
        //     return $price;
        // } else {
        //     return $price/$currencyValue['value'];
        // }

    }

    public function getCurrencySetting()
    {
        $currencySetting = [['currency'=>'USD',"value"=>1]];
        return $this->userCurrencySetting = $currencySetting;

        #TODO: Since this piece of code is unreachable
        // if(is_null($this->userCurrencySetting))
        // {
        //     $objectManager = $this->di->getObjectManager();
        //     $configObj = $objectManager->get(\App\Core\Models\Config\Config::class);
        //     $configObj->setGroupCode("currency");
        //     $configObj->setUserId($this->di->getUser()->id);
        //     $currencySetting = $configObj->getConfig();
        //     if(empty($currencySetting)){
        //         $currencySetting = [['currency'=>'USD',"value"=>2]];
        //     } else {

        //     }
        //     return $this->userCurrencySetting = $currencySetting;
        // }
        // return $this->userCurrencySetting;

    }
}