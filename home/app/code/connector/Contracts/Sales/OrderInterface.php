<?php
/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */

namespace App\Connector\Contracts\Sales;

/**
 * Interface OrderInterface
 * @api
 */
interface OrderInterface
{
    const SCHEMA_VERSION = "2.0";

    const TABLE = "order_container";

    const STATUS_CREATED = "Created";

    const STATUS_SHIPPED = "Shipped";

    const STATUS_FAILED = "Failed";

    const ITEM_REAl = "real";

    const ITEM_VIRTUAL = "virtual";

    const CURRENCY = "USD";

    const OBJECT_TYPE_SOURCE_ORDER = "source_order";

    const OBJECT_TYPE_SOURCE_ORDER_ITEMS = "source_order_items";

    const OBJECT_TYPE_TARGET_ORDER = "target_order";

    const OBJECT_TYPE_TARGET_ORDER_ITEMS = "target_order_items";

    /**
     * @return OrderInterface
     * @throws NoSuchEntityException
     */
    public function create(array $data): array;

    /**
     * @return OrderInterface
     * @throws NoSuchEntityException
     */
    public function update(array $filter, array $data): array;

    /**
     * @param string $orderId
     * @return OrderInterface
     * @throws NoSuchEntityException
     * @since 100.1.0
     */
    public function get(array $data): array;

    /**
     * @throws NoSuchEntityException
     * @since 100.1.0
     */
    public function getAll(array $data): array;

    /**
     * @throws NoSuchEntityException
     * @since 100.1.0
     */
    public function archiveOrder(array $data):array;

    /**
     * @param string $params;
     * @return OrderInterface
     * @throws NoSuchEntityException
     * @since 100.1.0
     */

    public function getByField(array $params): array;

    /**
     * @param string $data;
     * @return OrderInterface
     * @throws NoSuchEntityException
     * @since 100.1.0
     */
    public function prepareForDb(array $data): array;

     /**
     * @param string $data;
     * @return OrderInterface
     * @throws NoSuchEntityException
     * @since 100.1.0
     */
    public function validateForCreate(array $data):array;
}