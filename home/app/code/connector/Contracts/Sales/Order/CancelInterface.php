<?php
/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */
namespace App\Connector\Contracts\Sales\Order;

/**
 * Interface CancelInterface
 * @api
 */
interface CancelInterface
{
    /**
     * cancel the order
     */
    public function cancel(array $data): array;

    /**
     * prepare data
     */
    public function prepareForDb(array $data): array;

    /**
     * create order in db
     */
    public function create(array $data): array;

    /**
     * format order data according to required format
     */
    public function getFormattedData(array $data): array;

    /**
     * validate settings before cancellation
     */
    public function validateForCancellation(array $data): array;

}