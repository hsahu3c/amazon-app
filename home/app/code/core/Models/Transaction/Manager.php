<?php
namespace App\Core\Models\Transaction;

class Manager extends \App\Core\Components\Base
{
    protected $managers = [];
    protected $transactions = [];
    protected $rollbackPendent = false;
    public function get($name = 'default', $db = 'db', $autoBigin = true)
    {
        if (isset($this->managers[$name])) {
            $transaction = $this->managers[$name]->get($autoBigin);
        } else {
            $this->managers[$name] = new \Phalcon\Mvc\Model\Transaction\Manager();
            $this->managers[$name]->setDbService($db);
            
            
            $this->managers[$name]->setRollbackPendent($this->rollbackPendent);
            $transaction = $this->managers[$name]->get($autoBigin);
        }
        $this->transactions[] = $transaction;
        return $transaction;
    }

    public function getTransactions()
    {
        return $this->transactions;
    }

    public function setRollbackPendent($flag = true)
    {
        $this->rollbackPendent = $flag;
    }
}
