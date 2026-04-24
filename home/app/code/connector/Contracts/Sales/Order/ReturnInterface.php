<?php

namespace App\Connector\Contracts\Sales\Order;

interface ReturnInterface {

    public function request(array $data): array;

    public function accept(array $data): array;

    public function reject(array $data): array;

    public function getRequestReason(array $source, array $target): array;

    public function getRejectReason(array $source, array $target): array;
}