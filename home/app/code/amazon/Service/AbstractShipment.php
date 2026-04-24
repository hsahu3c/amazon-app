<?php
/**
 * Copyright © Cedcommerce, Inc. All rights reserved.
 * See LICENCE.txt for license details.
 */
namespace App\Amazon\Service;

abstract class AbstractShipment
{
   abstract public function isShippable();
}