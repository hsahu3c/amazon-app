<?php
namespace App\Connector\Components\Order;
use App\Core\Components\Base;
use App\Connector\Components\Order\OrderReturn\Invoker;
use App\Connector\Components\Order\OrderReturn\AcceptCommand;
use App\Connector\Components\Order\OrderReturn\DeclineCommand;
use App\Connector\Components\Order\OrderReturn\RequestCommand;

class OrderReturn extends Base {
    public function return(string $operation, array $data): array
    {
        $invoker = new Invoker();
        switch($operation) {
            case 'accept':
                $invoker->setCommand($this->di->getObjectManager()->create(AcceptCommand::class, [$data, $data['marketplace']]));
                break;
            case 'reject':
                $invoker->setCommand($this->di->getObjectManager()->create(DeclineCommand::class, [$data, $data['marketplace']]));
                break;
            case 'request':
                $invoker->setCommand($this->di->getObjectManager()->create(RequestCommand::class, [$data, $data['marketplace']]));
                break;
            default:
                throw new \Exception('Invalid operation provided.');
        }

        return $invoker->executeCommand();
    }
}