<?php

namespace App\Connector\Components\Order\OrderReturn;

interface Command {
    public function execute(): array;   
}