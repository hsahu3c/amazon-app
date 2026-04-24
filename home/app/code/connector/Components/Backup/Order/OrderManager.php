<?php
namespace App\Connector\Components\Backup\Order;
use App\Connector\Components\Backup\Observer;
use App\Connector\Components\Backup\Subject;

class OrderManager implements Subject {
    private array $observers = [];

    private array $orders = [];

    public function attach(Observer $observer): void {
        $this->observers[] = $observer;
    }

    public function detach(Observer $observer): void {
        $key = array_search($observer, $this->observers);
        if ($key !== false) {
            unset($this->observers[$key]);
        }
    }

    public function addOrder(array $orders): void {
        $this->orders = $orders;
        $this->notify(); // Notify observers of the change
    }

    public function getOrders(): array {
        return $this->orders;
    }

    public function clearOrders(): void {
        $this->orders = [];
    }

    public function notify(): void {
        foreach ($this->observers as $observer) {
            $observer->update($this);
        }
    }
}