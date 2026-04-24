<?php
/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */
namespace App\Connector\Contracts\Sales\Order;

/**
 * Interface CatalogRuleRepositoryInterface
 * @api
 */
interface ShipInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function ship(array $order): array;

    public function mold(array $order): array;
}