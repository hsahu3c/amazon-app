<?php


/**
 * CedCommerce
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the End User License Agreement (EULA)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://cedcommerce.com/license-agreement.txt
 *
 * @category    Ced
 * @package     Ced_amazon
 * @author      CedCommerce Core Team <connect@cedcommerce.com>
 * @copyright   Copyright © 2018 CedCommerce. All rights reserved.
 * @license     EULA http://cedcommerce.com/license-agreement.txt
 */

namespace App\Shopifyhome\Controllers;

use App\Core\Controllers\BaseController;
use App\Shopifyhome\Models\SourceModel;
class WebhookController extends BaseController
{
    public function trigerAction(): void{
        $this->di->getObjectManager()->get(SourceModel::class)->registerWebhooks();
    }
}