<?php

namespace App\Amazon\Controllers;

use App\Core\Controllers\BaseController;
use App\Amazon\Models\OrderErrorSolution as OrderErrorSolutionModel;

class OrderErrorSolutionController extends BaseController
{
    public function getAction()
    {
        $params = $this->getRequestData();
        try {
            $orderErrorSolutionModel = $this->di->getObjectManager()->get(OrderErrorSolutionModel::class);
            $result = $orderErrorSolutionModel->get($params);

            $isSingleResult = count($result['data']) === 1 && !isset($params['activePage']);

            if ($isSingleResult) {
                return $this->prepareResponse([
                    'success' => true,
                    'data' => $result['data'][0],
                ]);
            } else {
                return $this->prepareResponse([
                    'success' => true,
                    'data' => $result['data'],
                    'total_count' => $result['total_count']
                ]);
            }
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => "Failed to fetch error solutions: " . $e->getMessage(),
            ]);
        }
    }

    public function saveAction()
    {
        $params = $this->getRequestData();
        try {
            // Required fields validation
            if (empty($params['error_msg']) || empty($params['category']) || empty($params['solution_steps'])) {
                return $this->prepareResponse([
                    'success' => false,
                    'message' => 'Missing required fields (error_msg, category, solution_steps)',
                ]);
            }

            $orderErrorSolutionModel = $this->di->getObjectManager()->get(OrderErrorSolutionModel::class);
            $result = $orderErrorSolutionModel->saveErrorSolution($params);
            return $this->prepareResponse($result);
        } catch (\Exception $e) {
            return $this->prepareResponse([
                'success' => false,
                'message' => "Failed to save error solution: " . $e->getMessage(),
            ]);
        }
    }
}
