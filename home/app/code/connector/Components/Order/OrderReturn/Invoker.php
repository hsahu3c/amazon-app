<?php
namespace App\Connector\Components\Order\OrderReturn;

class Invoker {
    private ?\App\Connector\Components\Order\OrderReturn\Command $command = null;

    public function setCommand(Command $command): void
    {
        $this->command = $command;
    }

    public function executeCommand(): array
    {
        return $this->command->execute();
    }
}