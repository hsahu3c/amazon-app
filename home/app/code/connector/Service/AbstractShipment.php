<?php
/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */
namespace App\Connector\Service;

abstract class AbstractShipment
{
   abstract public function isShippable();
}