<?php
/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */

namespace App\Connector\Contracts\Currency;

/**
 * Interface CurrencyInterface
 * @api
 */
interface CurrencyInterface
{
    /**
     * @param array $data
     * @return string
     * @throws NoSuchEntityException
    */
    public function convert( $clientCurrency, $currency, $price);
}