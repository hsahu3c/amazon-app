<?php

use App\Plan\Components\Transaction;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use App\Plan\Models\Plan as PlanModel;

class DeactivatePlanCommand extends Command
{

    protected static $defaultName = "plan:deactivate";

    protected static $defaultDescription = "Deactivate Plans";

    protected $di;
    /**
     * Constructor
     * Calls parent constructor and sets the di
     *
     * @param $di
     */
    public function __construct($di)
    {
        parent::__construct();
        $this->di = $di;
    }

    /**
     * Configuration for the command
     * Used to set help text and add options and arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setHelp('To deactivate expired plans');
        $this->addOption(
            "function",
            "f",
            InputOption::VALUE_REQUIRED,
            "run function",
            ""
        );
        $this->addOption(
            "user",
            "u",
            InputOption::VALUE_OPTIONAL,
            "user id",
            ""
        );
    }

    /**
     * The main logic to execute when the command is run
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $f = $input->getOption("function") ?? "";
        if(empty($f)) {
            echo 'Provide a valid function!';
            return false;
        }

        print_r("Executing command to {$f} \n");
        $this->$f($input);
        return true;
    }

    public function deactivateUsers($input): void
    {
        $logFile = 'plan/cron/'.date('Y-m-d').'.log';
        $currentDate = date('Y-m-d');
        $thresoldDate = date('Y-m-d', strtotime('-30days'));
        $conditionFilterData = [
            'type' => PlanModel::PAYMENT_TYPE_USER_SERVICE,
            'service_type' => PlanModel::SERVICE_TYPE_ORDER_SYNC,
            'deactivate_on' => ['$lt' => $currentDate, '$gt' => $thresoldDate]
        ];
        $planModel = $this->di->getObjectManager()->get(PlanModel::class);
        $bkpPaymentCollection = $planModel->baseMongo->getCollectionForTable('payment_details_uninstalled_users');

        $userServices = $planModel->paymentCollection->find($conditionFilterData, PlanModel::TYPEMAP_OPTIONS)->toArray();
        $planModel->commonObj->addLog('command: DeactivatePlanCommand | function: deactivateUsers', $logFile);
        if (!empty($userServices)) {
            $planModel->commonObj->addLog('Total services found: '.count($userServices), $logFile);
            foreach($userServices as $userService) {
                $userId = $userService['user_id'] ?? "";
                $planModel->commonObj->addLog('Processing for user: '.$userId, $logFile);
                $res = $planModel->commonObj->setDiForUser($userId);
                if ($res['success']) {
                    $planModel->commonObj->addLog('Processing deactivation! '.$userId, $logFile);
                    $activePlanData = $planModel->getActivePlanForCurrentUser($userId);
                    $pendingSettlement = $planModel->getPendingSettlementInvoice($userId);
                    if (!empty($activePlanData) && empty($pendingSettlement)) {
                        if ($planModel->isFreePlan($activePlanData['plan_details'] ?? []) || $planModel->isTrialPlan($activePlanData['plan_details'] ?? [])) {
                            $planModel->deactivatePlan('free', $userId);
                        } elseif ($planModel->isCustomPlanOnetime($activePlanData['plan_details'] ?? [])) {
                            $planModel->deactivatePlan('onetime', $userId);
                        } else {
                            $planModel->deactivatePlan('paid', $userId, "", true);
                        }
                    }
                } else {
                    $planModel->commonObj->addLog('User not set in di. Moving in uninstall collection of payments!', $logFile);
                    $allPaymentData = $planModel->paymentCollection->find([
                        'user_id' => $userId
                    ], PlanModel::TYPEMAP_OPTIONS)->toArray();
                    if (!empty($allPaymentData)) {
                        $bkpResponse = $bkpPaymentCollection->insertMany($allPaymentData);
                        if ($bkpResponse->getInsertedCount() == count($allPaymentData)) {
                            $planModel->commonObj->addLog('Insertion in other collection successfull, now removing data and disabling sync', $logFile);
                            $planModel->paymentCollection->deleteMany([
                                'user_id' => $userId
                            ]);
                            $planModel->updateTargetEntriesInDynamo($userId);
                            $this->di->getObjectManager()->get(Transaction::class)->syncDeactivated($userId);
                            $this->di->getObjectManager()->get(Transaction::class)->userRemovedFromPayment($userId);
                        }
                    }
                }
            }
        } else {
            $planModel->commonObj->addLog('No services to deactivate plan found!', $logFile);
        }

        $planModel->commonObj->addLog('   ---------------------------------   ', $logFile);
        echo 'Process completed!';
    }

    /**
     * onetime use to diable users bfcm offers
     */
    public function disableBFCMPlansAndConvertOnetime($input): void
    {
        $logFile = 'plan/cron/'.date('Y-m-d').'.log';
        $conditionFilterData = [
            'type' => PlanModel::PAYMENT_TYPE_ACTIVE_PLAN,
            'status' => PlanModel::USER_PLAN_STATUS_ACTIVE,
            'plan_details.discounts' => ['$size' => 2],
            'plan_details.discounts.name' => "Festive Offer",
            'plan_details.billed_type' => PlanModel::BILLED_TYPE_YEARLY,
            'plan_details.custom_plan' => ['$exists' => false]
        ];
        $planModel = $this->di->getObjectManager()->get(PlanModel::class);
        $bfcmActivePlans = $planModel->paymentCollection->find($conditionFilterData, PlanModel::TYPEMAP_OPTIONS)->toArray();
        $planModel->commonObj->addLog('command: DeactivatePlanCommand | function: disableBFCMPlansAndConvertOnetime', $logFile);
        echo 'Total active BFCM plans found: '.count($bfcmActivePlans);
        if (!empty($bfcmActivePlans)) {
            $planModel->commonObj->addLog('Total active bfcm plans found are: '.count($bfcmActivePlans), $logFile);
            foreach($bfcmActivePlans as $activePlan) {
                if(isset($activePlan['user_id'])) {
                    $userId = $activePlan['user_id'];
                    $planModel->commonObj->addLog('Processing for user: '.$userId, $logFile);
                    $diRes = $planModel->commonObj->setDiForUser($userId);
                    if (!$diRes['success']) {
                        $planModel->commonObj->addLog('Unable to set di', $logFile);
                        continue;
                    }

                    $deactivateDate = date('Y-m-d', strtotime($activePlan['created_at'].'+1year'));
                    $planModel->paymentCollection->updateOne(
                        [
                            '_id' => $activePlan['_id']
                        ],
                        ['$set' => [
                            'plan_details.payment_type'=> "onetime",
                            'plan_details.custom_plan'=> true,
                        ]]
                    );
                    $planModel->paymentCollection->updateOne(
                        [
                            'user_id' => $userId,
                            'type' => PlanModel::PAYMENT_TYPE_USER_SERVICE,
                            'service_type' => PlanModel::SERVICE_TYPE_ORDER_SYNC
                        ],
                        ['$set' => [
                            'deactivate_on'=> $deactivateDate
                        ]]
                    );
                    $planModel->commonObj->addLog('Plan converted onetime now cancelling recurring!', $logFile);
                    if ($planModel->cancelRecurryingForCurrentUser($userId)) {
                        $planModel->commonObj->addLog('Successfully recurring cancelled!', $logFile);
                    } else {
                        $planModel->commonObj->addLog('Failure while cancelling recurring need to check for user: '.$userId, $logFile);
                    }
                }
            }
        } else {
            $planModel->commonObj->addLog('No Plans found!', $logFile);
        }

        $planModel->commonObj->addLog('   ---------------------------------   ', $logFile);
        echo 'Command excuted successfully!';
    }

    public function deactivateTrialPlan()
    {
        $logFile = 'plan/cron/deactivatetrial.log';
        $conditionFilterData = [
            'type' => PlanModel::PAYMENT_TYPE_USER_SERVICE,
            'service_type' => PlanModel::SERVICE_TYPE_ORDER_SYNC,
            'trial_service' => ['$exists' => true],
            'trial_service' => true
        ];
        $planModel = $this->di->getObjectManager()->get(PlanModel::class);
        $trialServices = $planModel->paymentCollection->find($conditionFilterData, PlanModel::TYPEMAP_OPTIONS)->toArray();
        if (!empty($trialServices)) {
            $planModel->commonObj->addLog('Total services found: '.count($trialServices), $logFile);
            foreach($trialServices as $trialService) {
                if (isset($trialService['user_id'])) {
                    $userId = $trialService['user_id'];
                    $planModel->commonObj->addLog(' -------------------- ', $logFile);
                    $planModel->commonObj->addLog('Processing for user: '.$userId, $logFile);
                    $diRes = $planModel->commonObj->setDiForUser($userId);
                    if (!$diRes['success']) {
                        $planModel->commonObj->addLog('Unable to set di', $logFile);
                        continue;
                    }
                    $activePlan = $planModel->getActivePlanForCurrentUser($userId);
                    if (!empty($activePlan)) {
                        if (isset($activePlan['plan_details']['code']) && $planModel->isTrialPlan($activePlan['plan_details'])) {
                            $planModel->deactivatePlan('free', $userId);
                            $planModel->commonObj->addLog('Trial plan deactivated!', $logFile);
                        } else {
                            $planModel->commonObj->addLog('Active plan is not trial need to check the service!', $logFile);
                        }
                    } else {
                        $planModel->commonObj->addLog('No active plan exist!', $logFile);
                    }
                } else {
                    $planModel->commonObj->addLog('User id not found!', $logFile);
                }
            }
        } else {
            $planModel->commonObj->addLog('No Trial services found!', $logFile);
        }
    }
}
