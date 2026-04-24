<?php

namespace App\Amazon\Components\Template;

use App\Amazon\Components\Template\BulkAttributesEdit\Delete\Export as DeleteExportHelper;
use App\Amazon\Components\Template\BulkAttributesEdit\Delete\Import as DeleteImportHelper;

class BulkAttributesDelete
{
    const FILE_BASE_PATH = BP . DS . 'var' . DS . 'file' . DS . 'template_bulk_delete_attribute';
    const FILE_NAME_PREFIX = "ced_amz_delete_attr";

    public function export(array $params): array
    {
        if (!isset(
            $params['user_id'],
            $params['source_shop_id'],
            $params['target_shop_id'],
            $params['template_id'],
            $params['product_type'],
            $params['attributes_selected']
        )) {
            return [
                'success' => false,
                'message' => 'Required parameters missing for export'
            ];
        }

        return $this->di->getObjectManager()->get(DeleteExportHelper::class)->export($params);
    }

    public function import(array $params): array
    {
        if (!isset(
            $params['user_id'],
            $params['source_shop_id'],
            $params['target_shop_id'],
            $params['file_path'],
            $params['exported_file_path'],
            $params['template_id'],
            $params['product_type']
        )) {
            return [
                'success' => false,
                'message' => 'Required parameters missing for import.'
            ];
        }

        return $this->di->getObjectManager()->get(DeleteImportHelper::class)->import($params);
    }
}
