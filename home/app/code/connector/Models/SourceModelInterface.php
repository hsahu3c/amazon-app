<?php
namespace App\Connector\Models;
/**
 * Interface SourceModelInterface
 */

interface SourceModelInterface
{

    public function prepareProductImportQueueData($handlerData,$shop);

    public function getMarketplaceProducts($sqsData);

}