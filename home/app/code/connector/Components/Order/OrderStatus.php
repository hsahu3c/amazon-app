<?php

namespace App\Connector\Components\Order;

class OrderStatus extends \App\Core\Components\Base
{
    const pending = "Pending";

    const created = "Created";

    const partially_created = "Partially Created";

    const failed = "failed";

    const shipped = "Shipped";

    const partially_shipped = "Partially Shipped";

    const partially_cancelled = "Partially Cancelled";

    const cancelled = "Cancelled";

    const refund = "Refund";

    const partially_refund = "Partially Refund";

    const DISABLED = "Disabled";

    const FULFILLED = "Fulfilled";

    const order_priority_statuses = [
        [
            'priority' =>1,
            'status'=>self::pending
        ],
        [
            'priority' =>2,
            'status'=>self::partially_created
        ],
        [
            'priority' =>3,
            'status'=>self::created
        ],
        [
            'priority' => 4,
            'status'=>self::failed
        ],
        [
            'priority' => 5,
            'status'=>self::partially_shipped
        ],
        [
            'priority' =>6,
            'status'=>self::shipped
        ],
        [
            'priority' =>7,
            'status'=>self::partially_cancelled
        ],
        [
            'priority' =>8,
            'status'=>self::cancelled
        ],
        [
            'priority' =>9,
            'status'=>self::partially_refund
        ],
        [
            'priority' =>10,
            'status'=>self::refund
        ]

    ];



    public function validateStatus($savedStatus ,$status)
    {
        $orderStatuses = self::order_priority_statuses;
        $savedStatusPriority = 0;
        $statusPriority = 0;
        foreach($orderStatuses as $orderStatus)
        {
            if($orderStatus['status'] == $savedStatus){
                $savedStatusPriority = $orderStatus['priority'];
            } elseif($orderStatus['status'] == $status){
                $statusPriority = $orderStatus['priority'];
            }
        }

        if($savedStatusPriority && $statusPriority && $savedStatusPriority < $statusPriority){
            return true;
        }

        return false;
    }

}