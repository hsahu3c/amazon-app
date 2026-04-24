<?php

namespace App\Amazon\Components\Template;

use App\Amazon\Components\Template\BulkAttributesEdit\Export\Export as ExportHelper;
use App\Amazon\Components\Template\BulkAttributesEdit\Import\Import as ImportHelper;
use App\Amazon\Components\Template\BulkAttributesEdit\Cleanup\Cleanup as CleanupHelper;

class BulkAttributesEdit
{
    const FILE_BASE_PATH = BP . DS . 'var' . DS . 'file' . DS . 'template_bulk_edit';
    const FILE_NAME_PREFIX = "ced_amz";

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

        return $this->di->getObjectManager()->get(ExportHelper::class)->export($params);
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

        return $this->di->getObjectManager()->get(ImportHelper::class)->import($params);
    }

    /**
     * Cleanup bulk-edited attributes when products are removed from template
     * or template product type changes.
     *
     * @param array $params Required keys:
     *   - user_id, source_shop_id, target_shop_id, template_id, template_name
     *   - reason (override_enabled|product_removed|product_type_changed|...)
     *   - filter_type (manual|advanced)
     *   - product_count
     *   - container_ids (if manual) OR query/global_and (if advanced)
     * @return array
     */
    public function cleanup(array $params): array
    {
        $requiredParams = [
            'user_id',
            'source_shop_id',
            'target_shop_id',
            'template_id',
            'template_name',
            'reason',
            'filter_type',
            'product_count'
        ];

        foreach ($requiredParams as $param) {
            if (!isset($params[$param])) {
                return [
                    'success' => false,
                    'message' => "Required parameter '{$param}' missing for cleanup."
                ];
            }
        }

        return $this->di->getObjectManager()->get(CleanupHelper::class)->cleanup($params);
    }
}
