<?php

namespace App\Plan\Components;

use App\Core\Components\Base;
use App\Plan\Models\Plan;

/**
 * class PlanEvent for plan events
 */
class PlanEvent extends Base
{
    /**
     * app purchase event
     */
    public function sendAppPurchaseEvent($planDetails): void
    {
        // $shopJson = $this->di->getUser()->shops;
        // $shopJson = $shopJson[0] ?? [];
        $this->di->setPlan($this->di->getObjectManager()->create('App\Plan\Models\PlanDetails')->getPlanInfo($this->di->getUser()->id));
        $eventData = $this->getActivePlanDataForCRM($this->di->getUser()->id);
        $eventData['next_billing_date'] = $planDetails['next_billing_date'] ?? date('c');
        $this->di->getEventsManager()->fire('application:planCreated', $this, $eventData);
    }

    /**
     * plan renew event
     */
    public function sendPlanRenewEvent($activePlan, $nextBillingDate = null): void
    {
        $shopJson = $this->di->getUser()->shops;
        $shopJson = $shopJson[0] ?? [];
        $this->di->setPlan($this->di->getObjectManager()->create('App\Plan\Models\PlanDetails')->getPlanInfo($this->di->getUser()->id));
        $eventData = $this->getActivePlanDataForCRM($this->di->getUser()->id);
        $eventData['next_billing_date'] = $nextBillingDate ?? date('c');
        $this->di->getEventsManager()->fire('application:planRenewed', $this, $eventData);
        // $this->di->getEventsManager()->fire('application:planRenewed', $this, [
        //     'username' => $this->di->getUser()->username ?? "",
        //     'name' => $this->di->getUser()->name ?? "",
        //     'email' => $this->di->getUser()->email ?? "",
        //     'shops' => json_decode(json_encode($this->di->getUser()->shops), true),
        //     // 'shopJson' => json_encode($shopJson),
        //     // 'data' => [
        //     //     // 'expiry_date' => date('Y-m-d H:i:s'),
        //     //     'next_billing_date' => $nextBillingDate,
        //     //     'active_plan_details' => $activePlan
        //     // ],
        //     'plan' => $activePlan,
        //     'type' => 'plan',
        //     'next_billing_date' => $nextBillingDate
        // ]);
    }

    public function sendPlanDeactivateEvent($planDetails, $chargeId = null): void
    {
        // $shopJson = $this->di->getUser()->shops;
        // $shopJson = $shopJson[0] ?? [];
        $this->di->setPlan($this->di->getObjectManager()->create('App\Plan\Models\PlanDetails')->getPlanInfo($this->di->getUser()->id));
        if (!empty($planDetails['plan_details']) && !isset($planDetails['plan_details']['payment_type'])) {
            $planDetails['plan_details']['payment_type'] = Plan::BILLING_TYPE_RECURRING;
        }
        $planDetails['payment_details'] = [
            'charge_id' => $chargeId,
            'subscription_status' => 'cancelled',
        ];
        $planDetails['payment_method'] = 'shopify';
        $this->di->getEventsManager()->fire('application:planDeactivated', $this, [
            'username' => $this->di->getUser()->username ?? "",
            'name' => $this->di->getUser()->name ?? "",
            'email' => $this->di->getUser()->email ?? "",
            'shops' => json_decode(json_encode($this->di->getUser()->shops), true),
            // 'shopJson' => json_encode($shopJson),
            // 'data' => [
            //     'date' => date('Y-m-d H:i:s'),
            //     'plan_details' => $planDetails
            // ],
            'plan' => $planDetails,
            'type' => 'plan',
            'next_billing_date' => date('Y-m-d H:i:s'),
        ]);
    }

    public function sendServiceUpdateEvent($updatedServices): void
    {
        $shopJson = $this->di->getUser()->shops;
        $shopJson = $shopJson[0] ?? [];
        $this->di->setPlan($this->di->getObjectManager()->create('App\Plan\Models\PlanDetails')->getPlanInfo($this->di->getUser()->id));
        $this->di->getEventsManager()->fire('application:planServiceUpdate', $this, [
            'username' => $this->di->getUser()->username ?? "",
            'name' => $this->di->getUser()->name ?? "",
            'email' => $this->di->getUser()->email ?? "",
            'shops' => json_decode(json_encode($this->di->getUser()->shops), true),
            'shopJson' => json_encode($shopJson),
            'data' => [
                $updatedServices
            ]
        ]);
        foreach ($this->di->getPlan()->user_services as $userService) {
            if (($userService['service_type']) === 'order_sync') {
                $this->di->getEventsManager()->fire('application:serviceRenewed', $this, [
                    'username' => $this->di->getUser()->username ?? "",
                    'name' => $this->di->getUser()->name ?? "",
                    'email' => $this->di->getUser()->email ?? "",
                    'shops' => json_decode(json_encode($this->di->getUser()->shops), true),
                    // 'shopJson' => json_encode($shopJson),
                    'user_service' => $userService,
                    'type' => 'plan',
                    'next_billing_date' => $userService['subscription_billing_date'] ?? ($userService['expired_at'] ?? date('Y-m-d', strtotime('+1month')))
                ]);
            }
        }
    }

    public function sendServiceExhaustedEvent($serviceData): void
    {
        $shopJson = $this->di->getUser()->shops;
        $shopJson = $shopJson[0] ?? [];
        $this->di->getEventsManager()->fire('application:serviceExhausted', $this, [
            'username' => $this->di->getUser()->username ?? "",
            'user_id' => $this->di->getUser()->id ?? "",
            'name' => $this->di->getUser()->name ?? "",
            'email' => $this->di->getUser()->email ?? "",
            'shops' => json_decode(json_encode($this->di->getUser()->shops), true),
            'shopJson' => json_encode($shopJson),
            'user_service' => $serviceData,
            'type' => 'plan',
            'next_billing_date' => $serviceData['subscription_billing_date'] ?? $serviceData['expired_at'] ?? null
        ]);
    }

    public function getActivePlanDataForCRM($userId = false)
    {
        $activePlan = $this->di->getPlan()->active_plan ?? [];
        $paymentDetails = $this->di->getPlan()->payment_info ?? [];
        $activePlan['payment_details'] = [];
        if (!empty($paymentDetails)) {
            $activePlan['payment_details'] = [
                'charge_id' => $paymentDetails['marketplace_data']['id'],
                'subscription_status' => $paymentDetails['marketplace_data']['status'],
            ];
            $activePlan['payment_method'] = 'shopify';
            if (!empty($activePlan['plan_details']) && !isset($activePlan['plan_details']['payment_type'])) {
                $activePlan['plan_details']['payment_type'] = Plan::BILLING_TYPE_RECURRING;
            }
        }
        $activePlan['payment_method'] = 'shopify';
        $data = [
            'username' => $this->di->getUser()->username ?? "",
            'name' => $this->di->getUser()->name ?? "",
            'email' => $this->di->getUser()->email ?? "",
            'shops' => json_decode(json_encode($this->di->getUser()->shops), true),
            'plan' => $activePlan,
            'type' => 'plan',
        ];
        return $data;
    }
}