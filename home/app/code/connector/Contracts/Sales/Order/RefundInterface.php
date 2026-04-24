<?php

/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */

namespace App\Connector\Contracts\Sales\Order;

/**
 * Interface RefundInterface
 */
interface RefundInterface
{
    /**
     * @throws CouldNotRefundException
     */
    public function refund(array $order): array;

    public function prepareForDb(array $order): array;

    public function create(array $order): array;

    public function getSettings(array $order): array;

    public function isFeedBased(): bool;

    public function hasResponseContent(): bool;
}
