<?php
namespace App\Connector\Components\Webhook;

interface SubscriptionInterface
{
    public function subscribe(array $request): array;
    public function unsubscribe(array $request): array;
    public function update(array $request): array; // Handles subscription updates
    public function get(array $request): array; // Handles retrieving subscriptions
}