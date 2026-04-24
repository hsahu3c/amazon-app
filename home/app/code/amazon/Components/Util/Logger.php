<?php

namespace App\Amazon\Components\Util;

use Phalcon\Logger\Adapter\File;
use Psr\Log\LoggerInterface;

class Logger extends File implements LoggerInterface
{
    /**
     * Phalcon\Logger\Adapter\File constructor
     *
     * @param string $name
     * @param array $options
     */
    public function __construct($name = "", $options = null)
    {
        $name = LOG_DIR . DS . "amazon.log";
    }

    public function log($type, $message = null, $context = null): void
    {
        $message .= " " . json_encode($context);
        parent::log($type, $message, $context);
    }
}