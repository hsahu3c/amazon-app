<?php
namespace App\Plan\Models;

use App\Core\Models\BaseMongo;

class ImporterPlan extends BaseMongo
{
    public const defaultPerProductCost = 0.1;

    public function choosePlan($planDetails) {
        $userId = $this->di->getUser()->id;
        $connector = $planDetails['connectors'];
        $totalCredits = $planDetails['total_credits'];
        $description = 'Product Import Charge';
        $services = $planDetails['services'];
        $amount = $totalCredits * self::defaultPerProductCost;
        if ($this->di->getConfig()->get('per_product_cost')) {
            $amount = $totalCredits * $this->di->getConfig()->per_product_cost;
        }

        $serviceHelper = $this->di->getObjectManager()->get('\App\Connector\Components\Services');
        $service = $serviceHelper->getServiceModel($serviceHelper->getByCode('product_import'), $userId);
        $alreadyAvailableCredits = 0;
        if ($service) {
            $alreadyAvailableCredits = (int)$service->getAvailableCredits();
        }

        foreach ($services as $key => $service) {
            $services[$key]['prepaid'] = [
                'service_credits' => $totalCredits + $alreadyAvailableCredits
            ];
        }

        $type = 'onetime';
        $allPaymentMethods = $this->di->getConfig()->payment_methods;
        $allPaymentMethods = $allPaymentMethods->toArray();

        $toReturnData = [];
        if (isset($allPaymentMethods[$connector])) {
            $connectorPaymentMethod = $allPaymentMethods[$connector];
            if (count($connectorPaymentMethod) > 1) {
                $toReturnData['show_payment_methods'] = true;
                $toReturnData['payment_methods'] = $connectorPaymentMethod;
                return ['success' => true, 'data' => $toReturnData];
            }

            $paymentMethod = array_values($connectorPaymentMethod)[0];
            $schema = $this->di->getObjectManager()->get($paymentMethod['source_model'])->getSchema($userId);
            if (!$schema) {
                return $this->processPayment($userId, $description, $amount, $services, $type, $paymentMethod);
            }

            $toReturnData['schema'] = $schema;
            return ['success' => true, 'data' => $toReturnData];
        }

        return ['success' => false, 'message' => 'No payment method found', 'code' => 'no_payment_method_found'];
    }

    public function submitSchema($planDetails) {
//        $planId = $planDetails['plan']['id'];
        $userId = $this->di->getUser()->id;
        $connector = $planDetails['plan']['connectors'];
        $amount = $planDetails['plan']['main_price'];
        $description = $planDetails['plan']['title'];
        $services = $planDetails['plan']['services'];
        $type = 'recurrying';
        if ($planDetails['plan']['validity'] == 365) {
            $type = 'onetime';
        }

        if (isset($planDetails['payment_method'])) {
            $paymentMethod = $planDetails['payment_method'];
            if (isset($planDetails['schema'])) {
                $schema = $planDetails['schema'];
                return $this->processPayment($userId, $description, $amount, $services, $type, $paymentMethod, $schema);
            }

            $schema = $this->di->getObjectManager()->get($paymentMethod['source_model'])->getSchema($userId);
            if (!$schema) {
                return $this->processPayment($userId, $description, $amount, $services, $type, $paymentMethod);
            }

            $toReturnData['schema'] = $schema;
            return ['success' => true, 'data' => $toReturnData];
        }

        if (isset($planDetails['schema'])) {
            $allPaymentMethods = $this->di->getConfig()->get('payment_methods');
            $allPaymentMethods = $allPaymentMethods->toArray();
            $paymentMethod = array_values($allPaymentMethods[$connector])[0];
            $schema = $planDetails['schema'];
            return $this->processPayment($userId, $description, $amount, $services, $type, $paymentMethod, $schema);
        }
    }

    public function createTransactionLog($description, $amount, $paymentStatus = 'pending', $services = [], $userId = false) {
        if (!$userId) {
            $userId = $this->di->getUser()->id;
        }

        $baseMongo = $this->di->getObjectManager()->get('\App\Core\Models\BaseMongo');
        $collection = $baseMongo->getCollectionForTable("transaction_log");
        $transactionData = [
            '_id' => (string)$baseMongo->getCounter('transaction_id'),
            'amount' => $amount,
            'created_at' => date("Y-m-d H:i:s"),
            'services' => $services,
            'description' => $description,
            'user_id' => $userId,
            'payment' => $paymentStatus
        ];
        $status = $collection->insertMany([
            $transactionData
        ]);
        if ($status) {
            return $transactionData['_id'];
        }

        return false;
    }

    public function processPayment($userId, $description, $amount, $services, $type, $paymentMethod, $schema = []) {
        $toReturnData = [];
        $transactionId = $this->createTransactionLog($description, $amount, 'pending', $services, $userId);
        if ($transactionId) {
            if ($paymentMethod['type'] == 'redirect') {
                $confirmationUrl = $this->di->getObjectManager()->get($paymentMethod['source_model'])->getConfirmationUrlForImporterPayment($amount, $type, $description, $transactionId, $userId, $schema);
                if ($confirmationUrl) {
                    $toReturnData['confirmation_url'] = $confirmationUrl;
                    return ['success' => true, 'data' => $toReturnData];
                }

                return ['success' => false, 'message' => 'Failed to process payment', 'code' => 'failed_to_process_payment'];
            }

            $paymentStatus = $this->di->getObjectManager()->get($paymentMethod['source_model'])->makePaymentForImporter($amount, $type, $description, $transactionId, $userId, $schema);
            if ($paymentStatus) {
                $toReturnData['payment_done'] = true;
                return ['success' => true, 'data' => $toReturnData];
            }

            return ['success' => false, 'message' => 'Failed to process payment', 'code' => 'failed_to_process_payment'];
        }

        return ['success' => false, 'message' => 'Transaction failed', 'code' => 'transaction_failed'];
    }
}