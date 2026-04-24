<?php

use Phalcon\Cli\Task;


class OrderTask extends Task
{   
    protected $_args = false;

    public function initiateSyncAction(): void
    {
        $objectManager = $this->di->getObjectManager();
        $helper = $objectManager->get('\App\Connector\Components\OrderHelper');
        $helper->queueAllShopsToSyncOrder();
    }

    // This one is for Ebay
    public function initiateOrderSyncAction(): void
    {
        $objectManager = $this->di->getObjectManager();
        $helper = $objectManager->get('\App\Connector\Components\OrderHelper');
        $helper->queueShopsToSyncOrder();
    }

    public function shopEraserAction(): void
    {
        $helper = $this->di->getObjectManager()->get('\App\Connector\Components\Hook');
        $helper->shopErarser();
    }

}