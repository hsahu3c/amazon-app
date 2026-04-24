<?php

namespace App\Amazon\Controllers;

use App\Core\Controllers\BaseController;
use App\Amazon\Components\Template\BulkAttributesEdit;
use App\Amazon\Components\Template\BulkAttributesDelete;

/**
 * Class TemplateBulkEditController
 * @package App\Amazon\Controllers
 */
class TemplateBulkEditController extends BaseController
{
    public function exportProductsAttributesAction()
    {
        $requestData = $this->getRequestData();
        $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['source_shop_id'] ??= $this->di->getRequester()->getSourceId() ?? false;
        $requestData['target_shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;
        $getRatesResponse = $this->getTemplateBulkEditComponent()->export($requestData);
        return $this->prepareResponse($getRatesResponse);
    }

    public function importProductsAttributesAction()
    {
        $requestData = $this->getRequestData();
        $userId = $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['source_shop_id'] ??= $this->di->getRequester()->getSourceId() ?? false;
        $requestData['target_shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;

        $files = $this->request->getUploadedFiles();
        $templateId = $requestData['template_id'] ?? '';

        $templateName = $requestData['template_name'] ?? "New template";

        $allowedMimeTypes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        $bulkTemplateEditComponent = $this->getTemplateBulkEditComponent();
        $fileNamePrefix = $bulkTemplateEditComponent::FILE_NAME_PREFIX;
        $baseFilePath = $bulkTemplateEditComponent::FILE_BASE_PATH;

        $expectedFileName = $fileNamePrefix . "_{$templateName}.xlsx";

        foreach ($files as $file) {
            if (!in_array($file->getType(), $allowedMimeTypes)) {
                return $this->prepareResponse(['success' => false, 'message' => 'Please use XLSX file type only']);
            }

            $importedFileName = $file->getName();

            // Remove trailing " (number)" pattern before the file extension, e.g., "example (1).xlsx" becomes "example.xlsx"
            // $importedFileName = preg_replace('/\s*\(\d+\)(?=\.[^.]+$)/', '', $importedFileName);

            // Remove multiple trailing "(number)" pattern before the file extension, e.g., "example (1) (2).xlsx" becomes "example.xlsx"
            $importedFileName = preg_replace('/(\s*\(\d+\))+(?=\.[^.]+$)/', '', $importedFileName);

            //check the name of imported file, it should be same as what name was exported
            if ($importedFileName != $expectedFileName) {
                $response = ['success' => false, 'message' => 'Please import the same file that was exported.'];
                return $this->prepareResponse($response);
            }

            //checking if the there is an exported file or not
            $exportedFilePath = $baseFilePath . DS . $userId . DS . $templateId . DS . 'exports' . DS . $expectedFileName;
            if (!file_exists($exportedFilePath)) {
                $response = ['success' => false, 'message' => 'No export found for this template. Please export it first.'];
                return $this->prepareResponse($response);
            }

            $filePath = $baseFilePath . DS . $userId . DS . $templateId . DS . 'exports' . DS . $fileNamePrefix . "_{$templateName}" . '_imported.xlsx';

            if (file_exists($filePath)) {
                unlink(($filePath));
            }

            $file->moveTo(
                $filePath
            );
        }

        $requestData['file_path'] = $filePath;
        $requestData['exported_file_path'] = $exportedFilePath;

        $importResponse = $bulkTemplateEditComponent->import($requestData);
        return $this->prepareResponse($importResponse);
    }

    public function exportProductAttributesToDeleteAction()
    {
        $requestData = $this->getRequestData();
        $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['source_shop_id'] ??= $this->di->getRequester()->getSourceId() ?? false;
        $requestData['target_shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;
        $getRatesResponse = $this->getTemplateBulkDeleteComponent()->export($requestData);
        return $this->prepareResponse($getRatesResponse);
    }

    public function importProductAttributesToDeleteAction()
    {
        $requestData = $this->getRequestData();
        $userId = $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['source_shop_id'] ??= $this->di->getRequester()->getSourceId() ?? false;
        $requestData['target_shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;

        $files = $this->request->getUploadedFiles();
        $templateId = $requestData['template_id'] ?? '';

        $templateName = $requestData['template_name'] ?? "New template";

        $allowedMimeTypes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        $templateBulkDeleteComp = $this->getTemplateBulkDeleteComponent();
        $fileNamePrefix = $templateBulkDeleteComp::FILE_NAME_PREFIX;
        $baseFilePath = $templateBulkDeleteComp::FILE_BASE_PATH;

        $expectedFileName = $fileNamePrefix . "_{$templateName}.xlsx";

        foreach ($files as $file) {
            if (!in_array($file->getType(), $allowedMimeTypes)) {
                return $this->prepareResponse(['success' => false, 'message' => 'Please use XLSX file type only']);
            }

            $importedFileName = $file->getName();

            // Remove trailing " (number)" pattern before the file extension, e.g., "example (1).xlsx" becomes "example.xlsx"
            $importedFileName = preg_replace('/\s*\(\d+\)(?=\.[^.]+$)/', '', $importedFileName);


            //check the name of imported file, it should be same as what name was exported
            if ($importedFileName != $expectedFileName) {
                $response = ['success' => false, 'message' => 'Please import the same file that was exported.'];
                return $this->prepareResponse($response);
            }

            //checking if the there is an exported file or not
            $exportedFilePath = $baseFilePath . DS . $userId . DS . $templateId . DS . 'exports' . DS . $expectedFileName;
            if (!file_exists($exportedFilePath)) {
                $response = ['success' => false, 'message' => 'No export found for this template. Please export it first.'];
                return $this->prepareResponse($response);
            }

            $filePath = $baseFilePath . DS . $userId . DS . $templateId . DS . 'exports' . DS . $fileNamePrefix . "_{$templateName}" . '_imported.xlsx';

            if (file_exists($filePath)) {
                unlink(($filePath));
            }

            $file->moveTo(
                $filePath
            );
        }

        $requestData['file_path'] = $filePath;
        $requestData['exported_file_path'] = $exportedFilePath;

        $importResponse = $templateBulkDeleteComp->import($requestData);
        return $this->prepareResponse($importResponse);
    }

    /**
     * Cleanup bulk-edited attributes when products are removed from template,
     * template product type changes, or override is enabled.
     *
     * Expected request payload:
     * - source_shop_id: string
     * - target_shop_id: string
     * - template_id: string
     * - template_name: string
     * - reason: string (override_enabled|product_removed|product_type_changed|...)
     * - filter_type: string (manual|advanced)
     * - product_count: int
     * - container_ids: array (required if filter_type = manual)
     * - query: string (required if filter_type = advanced)
     * - global_and: string (optional, for advanced filter)
     *
     * @return mixed
     */
    public function cleanupProductsAttributesAction()
    {
        $requestData = $this->getRequestData();
        $requestData['user_id'] = $this->di->getUser()->id;
        $requestData['source_shop_id'] ??= $this->di->getRequester()->getSourceId() ?? false;
        $requestData['target_shop_id'] ??= $this->di->getRequester()->getTargetId() ?? false;

        $cleanupResponse = $this->getTemplateBulkEditComponent()->cleanup($requestData);
        return $this->prepareResponse($cleanupResponse);
    }

    public function getTemplateBulkEditComponent()
    {
        return $this->di->getObjectManager()->get(BulkAttributesEdit::class);
    }

    public function getTemplateBulkDeleteComponent()
    {
        return $this->di->getObjectManager()->get(BulkAttributesDelete::class);
    }
}
