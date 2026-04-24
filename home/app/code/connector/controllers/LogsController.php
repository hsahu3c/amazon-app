<?php

namespace App\Connector\Controllers;

use Phalcon\Mvc\Controller;
use App\Connector\Components\Order\Order;


class LogsController extends Controller
{
    public function getReportAction()
    {
        $process = $this->request->get('process') ?? false;
        $date = $this->request->get('date') ?? false;
        $userId = $this->request->get('user') ?? false;
        if ($userId == false) {
            $userId = "";
        } else {
        }

        $folder = '';
        echo '<div class="container bg-white">';
        if ($process) {
            switch ($process) {
                case 'create':
                    $folder = 'order-create';
                    if ($date == false) {
                        $date = date('Y-m-d');
                    } else {
                    }

                    echo '<h2 class="text-dark text-center">Order Create Report For <b>' . $date . '</b></h2>';
                    echo '<hr>';
                    $this->getBaseFilePath($process, $folder, $date, $userId);
                    break;

                case 'cancel':
                    if ($date == false) {
                        $date = date('d-m-y');
                    } else {
                    }

                    echo '<h2 class="text-dark text-center">Order Cancel Report For <b>' . $date . '</b></h2>';
                    echo '<hr>';
                    $folder = 'order-cancel';
                    $this->getBaseFilePath($process, $folder, $date, $userId);
                    break;

                case 'refund':
                    $folder = 'order-refund';
                    if ($date == false) {
                        $date = date('d-m-Y');
                    } else {
                    }

                    echo '<h2 class="text-dark text-center">Order Refund Report For <b>' . $date . '</b></h2>';
                    echo '<hr>';
                    break;

                case 'notifications':
                    if ($date == false) {
                        $date = date('d-m-y');
                    } else {
                    }

                    echo '<h2 class="text-dark text-center">Order Notifications Report For <b>' . $date . '</b></h2>';
                    echo '<hr>';
                    $folder = 'OrderNotifications';
                    $this->getBaseFilePath($process, $folder, $date, $userId);
                    break;

                default:
                    return 'Invalid process';
            }
        } else {
            echo '<h1 class="text-danger">Please provide process!</h1>';
        }

        echo '</div>';
    }

    public function getBaseFilePath($process, ?string $folder, $date, ?string $userId): void
    {
        $userCount = 0;
        if (!empty($folder)) {
            $path = BP . '/var/log/order/' . $folder;
            if (file_exists($path)) {
                if (!empty($userId)) {
                    $userFile = $path . '/' . $userId;
                    $userCount = 1;
                    echo '<h4 class="text-dark">Files found: <b>' . $userCount . '</b></h4>';
                    $response = $this->getProcessReportAsPerUser($userId, $userFile, $date, $process);
                    if (!empty($response) && isset($response['success']) && $response['success']) {
                        $this->getFinalResult($response);
                    }
                } else {
                    $users = scandir($path);
                    unset($users[0], $users[1]);
                    $users = array_values($users);
                    $userCount = count($users);
                    echo '<h4 class="text-dark">Files found: <b>' . $userCount . '</b></h4>';
                    $totalAutomatic = 0;
                    $successAutomatic = 0;
                    $failAutomatic = 0;
                    $untrackedAutomatic = 0;
                    $incompleteAutomatic = 0;
                    $totalManual = 0;
                    $successManual = 0;
                    $failManual = 0;
                    $untrackedManual = 0;
                    $incompleteManual = 0;
                    foreach ($users as $user) {
                        $userFile = $path . '/' . $user;
                        $response = $this->getProcessReportAsPerUser($user, $userFile, $date, $process);
                        if (!empty($response) && isset($response['success']) && $response['success']) {
                            if (isset($response['data']['automatic'])) {
                                $totalAutomatic += $response['data']['automatic']['total'];
                                $successAutomatic += $response['data']['automatic']['success'];
                                $failAutomatic += $response['data']['automatic']['fail'];
                                $untrackedAutomatic += $response['data']['automatic']['untracked'];
                                $incompleteAutomatic += $response['data']['automatic']['incomplete'];
                            }

                            if (isset($response['data']['manual'])) {
                                $totalManual += $response['data']['manual']['total'];
                                $successManual += $response['data']['manual']['success'];
                                $failManual += $response['data']['manual']['fail'];
                                $untrackedManual += $response['data']['manual']['untracked'];
                                $incompleteManual += $response['data']['manual']['incomplete'];
                            }
                        }
                    }

                    $result = [
                        'success' => true,
                        'data' => [
                            'automatic' => [
                                'total' => $totalAutomatic,
                                'success' => $successAutomatic,
                                'fail' => $failAutomatic,
                                'incomplete' => $incompleteAutomatic,
                                'untracked' => $untrackedAutomatic
                            ],
                            'manual' => [
                                'total' => $totalManual,
                                'success' => $successManual,
                                'fail' => $failManual,
                                'incomplete' => $incompleteManual,
                                'untracked' => $untrackedManual
                            ]
                        ]
                    ];
                    $this->getFinalResult($result);
                }
            } else {
                echo '<h2 class="text-danger">File path not found</h2>';
            }
        } else {
            echo '<h2 class="text-danger">Folder empty!</h2>';
        }
    }

    public function getFinalResult(array $result): void
    {
        echo '<hr class="border border-dark">';
        echo '<div class="row"><h3 class="col-12 text-center">FINAL RESULT</h3></div>';
        if (isset($result['data']['automatic'])) {
            echo '<div class="row"><h3 class="col-12">AUTOMATIC</h3></div>';
            echo '<div class="row">
        <table class="table">
            <tr>
                <th class="bg-primary">TOTAL count: </th>
                <td class="bg-dark text-warning"><b>' . $result['data']['automatic']['total'] . '</b></td>
                <th class="bg-success">Process SUCCESS count: </th>
                <td class="bg-dark text-warning"><b>' . $result['data']['automatic']['success'] . '</b></td>
                <th class="bg-danger">Process FAIL count: </th>
                <td class="bg-dark text-warning"><b>' . $result['data']['automatic']['fail'] . '</b></td>
                <th class="bg-warning">Process INCOMPLETE count: </th>
                <td class="bg-dark text-warning"><b>' . $result['data']['automatic']['incomplete'] . '</b></td>
                <th class="bg-secondary">UNTRACKED PROCESS count: </th>
                <td class="bg-dark text-warning"><b>' . $result['data']['automatic']['untracked'] . '</b></td>
            </tr>
            </table></div>';
        }

        if (isset($result['data']['manual'])) {
            echo '<div class="row"><h3 class="col-12">MANUAL</h3></div>';
            echo '<div class="row">
        <table class="table">
            <tr>
                <th class="bg-primary">TOTAL count: </th>
                <td class="bg-dark text-warning"><b>' . $result['data']['manual']['total'] . '</b></td>
                <th class="bg-success">Process SUCCESS count: </th>
                <td class="bg-dark text-warning"><b>' . $result['data']['manual']['success'] . '</b></td>
                <th class="bg-danger">Process FAIL count: </th>
                <td class="bg-dark text-warning"><b>' . $result['data']['manual']['fail'] . '</b></td>
                <th class="bg-warning">Process INCOMPLETE count: </th>
                <td class="bg-dark text-warning"><b>' . $result['data']['manual']['incomplete'] . '</b></td>
                <th class="bg-secondary">UNTRACKED PROCESS count: </th>
                <td class="bg-dark text-warning"><b>' . $result['data']['manual']['untracked'] . '</b></td>
            </tr>
            </table></div>';
        }
    }

    public function getProcessReportAsPerUser(string $user, string $userFile, string $date, $process)
    {
        $dateWiseInfoFile = $userFile . '/' . $date;
        $response = "";
        if (file_exists($dateWiseInfoFile)) {
            echo '<hr class="border border-dark">';
            echo '<div><h4 class="text-center">Processing Report for user : <b>' . $user . '</b></h4></div><br>' . PHP_EOL;
            echo '<div class="row">';
            switch ($process) {
                case 'create':
                    $response = $this->getOrderCreateProcessReport($dateWiseInfoFile . '/order-process.log', $dateWiseInfoFile);
                    break;
                case 'cancel':
                    $response = $this->getOrderCancelProcessReport($dateWiseInfoFile);
                    break;
                case 'notifications':
                    $response = $this->getNotificationsReport($dateWiseInfoFile . '/process.log');
                    break;
                default:
                    echo 'Undefined process!!';
                    break;
            }

            echo '</div>';
            return $response;
        }
        return $response;
    }

    public function getOrderIdFileResult($path): string
    {
        if (file_exists($path)) {
            $file = fopen($path, "r");
            $filesize = filesize($path);
            $filetext = fread($file, $filesize);
            $content = explode(PHP_EOL, $filetext);
            foreach ($content as $k => $line) {
                if (str_contains($line, 'Order Create Process End')) {
                    return $content[$k - 3];
                }
            }

            return 'No end result!!';
        }
        return 'File not found!';
    }

    public function getNotificationsReport($path)
    {
        if (file_exists($path)) {
            $file = fopen($path, "r");
            $filesize = filesize($path);
            $filetext = fread($file, $filesize);
            $content = explode('Notifications Process Start', $filetext);
            if (!empty($content)) {
                unset($content[0]);
                $content = array_values($content);
                $totalNotifications = count($content);
                $total = 0;
                $foundCount = 0;
                $notfoundCount = 0;
                $cancelOrders = 0;
                $successCount = 0;
                $failCount = 0;
                $incompleteCount = 0;
                $processEnd = 0;
                foreach ($content as  $processData) {
                    $processData = explode(PHP_EOL, $processData);
                    $result = "";
                    $output = "";
                    foreach ($processData as $line) {
                        if (str_contains($line, 'Order Status: canceled') || str_contains($line, 'Order Status: cancelled')) {
                            $cancelOrders += 1;
                        }

                        if (str_contains($line, 'Source Order Found for order_id')) {
                            $foundCount += 1;
                            $result .= '<div class="col-4 border-top border-dark ">' . $line . '</div>';
                        } else if (str_contains($line, 'Source order not found in db')) {
                            $notfoundCount += 1;
                        }

                        if (str_contains($line, 'Remote success:')) {
                            $remoteResponse = explode('Remote success: ', $line);
                            $data = json_decode($remoteResponse[1], true);
                            if (is_array($data) && isset($data['success']) && $data['success']) {
                                $output .= '<li class="list-group-item-success p-2">Remote success for order id ' . $data['data']['amazon_order_id'] . '</li>';
                            } else {
                                $output .= '<li class="list-group-item-danger p-2">Remote failure: ' . $line . '</li>';
                            }
                        }

                        if (str_contains($line, 'Received order cancel data = ')) {
                            $receivedData = explode('Received order cancel data = ', $line);
                            $data = json_decode($receivedData[1], true);
                            if(!empty($data)) {
                                if (count($data) > 1) {
                                    $output .= '<li class="list-group-item-warning p-2">Multiple data for cancellation</li>';
                                }

                                foreach($data as $cancelData) {
                                    if (is_array($cancelData) && isset($cancelData['success']) && $cancelData['success']) {
                                        $output .= '<li class="list-group-item-success p-2">Received order cancel data successfully!</li>';
                                    } else {
                                        $output .= '<li class="list-group-item-danger p-2">Failure in receiving order cancel data!!</li>';
                                    }
                                }
                            }  else {
                                $output .= '<li class="list-group-item-danger p-2">Failure in receiving order cancel data!!</li>';
                            }
                        }

                        if (str_contains($line, 'No updated order for cancellation')) {
                            $output .= '<li class="list-group-item-danger p-2">' . $line . '</li>';
                        }

                        if (str_contains($line, 'cancellation response =')) {
                            $cancelResponse = explode('cancellation response = ', $line);
                            $data = json_decode($cancelResponse[1], true);
                            if (is_array($data) && isset($data['success']) && $data['success']) {
                                $successCount += 1;
                                $output .= '<li class="list-group-item-success p-2">' . $line . '</li>';
                            } else {
                                $failCount += 1;
                                $output .= '<li class="list-group-item-danger p-2">' . $line . '</li>';
                            }
                        }

                        if (str_contains($line, 'No orders Found for cancellation')) {
                            $failCount += 1;
                            $output .= '<li class="list-group-item-danger p-2">' . $line . '</li>';
                        }
                    }

                    if ($result != "") {
                        if ($output != "") {
                            $result .= '<div class="col-8 border-dark border-top"><ul class="list-group list-unstyled">' . $output . '</ul></div>';
                        } else {
                            $incompleteCount += 1;
                            $result .= '<div class="col-8 border-dark border-top bg-warning">Something went wrong</div>';
                        }

                        echo $result;
                    }
                }
            }

            echo '<table class="table">
                <tr>
                <th class="bg-info">TOTAL ORDERS NOTIFICATIONS RECEIVED: </th>
                <td class="bg-dark text-warning"><b>' . $totalNotifications . '</b></td>
                <th class="bg-success">ORDERS FOUND: </th>
                <td class="bg-dark text-warning"><b>' . $foundCount . '</b></td>
                <th class="bg-danger">ORDERS NOT FOUND: </th>
                <td class="bg-dark text-warning"><b>' . $notfoundCount . '</b></td>
            </tr>
            </tr>
            </table>';
            echo '<table class="table">
                <tr>
                <th class="bg-primary">TOTAL ORDERS FOR CANCELLATION: </th>
                <td class="bg-dark text-warning"><b>' . $cancelOrders . '</b></td>
                <th class="bg-success">SUCCESS: </th>
                <td class="bg-dark text-warning"><b>' . $successCount . '</b></td>
                <th class="bg-danger">FAIL: </th>
                <td class="bg-dark text-warning"><b>' . $failCount . '</b></td>
                <th class="bg-warning">INCOMPLETE: </th>
                <td class="bg-dark text-warning"><b>' . $incompleteCount . '</b></td>
                <th class="bg-secondary">UNTRACKED: </th>
                <td class="bg-dark text-warning"><b>' . ($cancelOrders - ($successCount + $failCount + $incompleteCount)) . '</b></td>
            </tr>
            </tr>
            </table>';
            return [
                'success' => true,
                'data' => [
                    'automatic' => [
                        'total' => $cancelOrders,
                        'success' => $successCount,
                        'fail' => $failCount,
                        'incomplete' => $incompleteCount,
                        'untracked' => ($cancelOrders - ($successCount + $failCount + $incompleteCount))
                    ]
                ]
            ];
        }
        echo '<h4 class="text-center">No reports found!!</h4>';
    }

    public function getOrderCreateProcessReport(string $path, string $folder): array
    {
        if (file_exists($path)) {
            $file = fopen($path, "r");
            $filesize = filesize($path);
            $filetext = fread($file, $filesize);
            $content = explode('--------------------- Order Create Process Start ------------------------', $filetext);
            if (!empty($content)) {
                unset($content[0]);
                $content = array_values($content);
                $total = count($content);
                $idFiles = [];
                $successCount = 0;
                $failCount = 0;
                $disableCount = 0;
                $incompleteCount = 0;
                foreach ($content as $processData) {
                    $processData = explode(PHP_EOL, $processData);
                    foreach ($processData as $k => $line) {
                        $idFile = "";
                        $result = "";
                        if (str_contains($line, 'Order ID received:')) {
                            $orderIdData = explode(":  ", $line);
                            $idFiles[] = $orderIdData[1];
                            $idFile = $folder . '/' . $orderIdData[1] . '.log';

                            if (!empty($idFile) && file_exists($idFile) && isset($processData[$k + 5]) && !str_contains($processData[$k + 5], 'Order Create Process End')) {
                                $response = $this->getOrderIdFileResult($idFile);
                                if (str_contains($response, 'successfully')) {
                                    $success = 'success';
                                    $successCount += 1;
                                } elseif(str_contains($response, 'order sync settings disabled or user uninstalled')) {
                                    $success = 'info';
                                    $disableCount += 1;
                                } else {
                                    $success = 'danger';
                                    $failCount += 1;
                                }

                                $result = "";
                                $result .= '<div class="col-4 border">Order Id found: ' . $orderIdData[1] . '</div>';
                                $result .= '<div class="col-8 border bg-' . $success . ' justify-content-center overflow-hidden">' . $response . '</div>';
                            }

                            if (isset($processData[$k + 5]) && str_contains($processData[$k + 5], 'Order Create Process End')) {
                                $endResults[] = $processData[$k + 2];
                                $incompleteCount += 1;
                                $result = "";
                                $result .= '<div class="col-4 border">Order Id found: ' . $orderIdData[1] . '</div>';
                                $result .= '<div class="col-8 bg-warning border">Order process failed: ' . $processData[$k + 2] . '</div>';
                            }
                        }

                        if ($result != "") {
                            echo $result;
                        }
                    }
                }

                echo '<table class="table">
                <tr>
                <th class="bg-primary">TOTAL count: </th>
                <td class="bg-dark text-warning"><b>' . $total . '</b></td>
                <th class="bg-success">Process SUCCESS count: </th>
                <td class="bg-dark text-warning"><b>' . $successCount . '</b></td>
                <th class="bg-info">Settings disabled count: </th>
                <td class="bg-dark text-info"><b>' . $disableCount . '</b></td>
                <th class="bg-danger">Process FAIL count: </th>
                <td class="bg-dark text-warning"><b>' . $failCount . '</b></td>
                <th class="bg-warning">Process INCOMPLETE count: </th>
                <td class="bg-dark text-warning"><b>' . $incompleteCount . '</b></td>
                <th class="bg-secondary">UNTRACKED PROCESS count: </th>
                <td class="bg-dark text-warning"><b>' . ($total - ($successCount + $failCount + $incompleteCount + $disableCount)) . '</b></td>
            </tr>
            </table>';
                return [
                    'success' => true,
                    'data' => [
                        'automatic' => [
                            'total' => $total,
                            'success' => $successCount,
                            'fail' => $failCount,
                            'incomplete' => $incompleteCount,
                            'untracked' => ($total - ($successCount + $failCount + $incompleteCount + $disableCount))
                        ]
                    ]
                ];
            }
            return [
                'success' => false,
                'message' => $path . ': No data in file!'
            ];
        }
        return [
            'success' => false,
            'message' => $path . ': File not found!'
        ];
    }

    /**
     * @return mixed[]
     */
    public function getOrderCancelProcessReport(string $path): array
    {
        $result = [];
        if (file_exists($path)) {
            $cancelLogs = scandir($path);
            unset($cancelLogs[0]);
            unset($cancelLogs[1]);
            $cancelLogs = array_values($cancelLogs);
            foreach ($cancelLogs as $process) {
                switch ($process) {
                    case 'automatic-cancel.log':
                        echo '<div class="col-12 bg-info"><h5 class="text-center"> Providing automatic cancel logs report</h5></div>' . PHP_EOL . '<br><hr>';
                        $automaticFile = $path . '/' . $process;
                        if (file_exists($automaticFile)) {
                            $response = $this->getAutomaticCancelReport($automaticFile, $path);
                            if ($response['success']) {
                                $result['automatic'] = $response['data'];
                            }
                        }

                        break;
                    case 'manual-cancel.log':
                        echo '<div class="col-12 bg-info"><h5 class="text-center"> Providing Manual cancel logs report</h5></div>' . PHP_EOL . '<br><hr>';
                        $manualFile = $path . '/' . $process;
                        if (file_exists($manualFile)) {
                            $response = $this->getManualCancelReport($manualFile, $path);
                            if ($response['success']) {
                                $result['manual'] = $response['data'];
                            }
                        }

                        break;
                    default:
                        echo 'unknown file found ' . $process . ' found!';
                        break;
                }
            }

            if (!empty($result)) {
                return [
                    'success' => true,
                    'data' => $result
                ];
            }
            return $result;
        }
        return $result;
    }

    public function getAutomaticCancelReport($path, $folder): array
    {
        $file = fopen($path, "r");
        $filesize = filesize($path);
        $filetext = fread($file, $filesize);
        $content = explode(' --------------------------------- Automatic Cancel Start ---------------------------------', $filetext);
        $total = 0;
        $successCount = 0;
        $failCount = 0;
        $incompleteCount = 0;
        if (!empty($content)) {
            unset($content[0]);
            $content = array_values($content);
            $total = count($content);
            foreach ($content as $cancelProcess) {
                $processData = explode(PHP_EOL, $cancelProcess);
                $endResult = false;
                $result = '';
                foreach ($processData as $k => $line) {
                    if (str_contains($line, 'marketplace_reference_id for cancellation')) {
                        $reference_id = explode('marketplace_reference_id for cancellation: ', $line);
                        $result .= '<div class="col-4">Cancel marketplace_reference_id: ' . $reference_id[1] . '</div>';
                    }

                    if (str_contains($line, 'prepareSourceCancel') && isset($processData[$k + 1]) && !str_contains($processData[$k + 1], 'Success!!')) {
                        $result .= '<div class="col-4 bg-warning">Cancel marketplace_reference_id: Not found</div>';
                    }

                    if (str_contains($line, 'Automatic Cancel Done')) {
                        $endResult = true;
                        $success = 'success';
                        if (str_contains($processData[$k - 2], 'Failed!')) {
                            $success = 'danger';
                            $failCount += 1;
                        } else {
                            $successCount += 1;
                        }

                        $result .=  '<div class="col-8 bg-' . $success . '">Cancellation result:  <ul><li>' . $processData[$k - 3] . '</li><li> ' . $processData[$k - 2] . '</li></ul></div>';
                    }
                }

                if ($endResult == false && isset($processData[count($processData) - 3])) {
                    $incompleteCount += 1;
                    $result .=  '<div class="col-8 bg-warning">In complete cancellation process!! => ' . $processData[count($processData) - 3] . '</div>';
                }

                echo $result;
            }
        }

        echo '<table class="table">
                <tr>
                <th class="bg-primary">Total processes: </th>
                <td class="bg-dark text-warning"><b>' . $total . '</b></td>
                <th class="bg-success">Process Success count: </th>
                <td class="bg-dark text-warning"><b>' . $successCount . '</b></td>
                <th class="bg-danger">Process Fail count: </th>
                <td class="bg-dark text-warning"><b>' . $failCount . '</b></td>
                <th class="bg-warning">Process Incomplete count: </th>
                <td class="bg-dark text-warning"><b>' . $incompleteCount . '</b></td>
                <th class="bg-secondary">Untracked Process: </th>
                <td class="bg-dark text-warning"><b>' . ($total - ($successCount + $failCount + $incompleteCount)) . '</b></td>
            </tr>
            </table>';
        return [
            'success' => true,
            'data' => [
                'total' => $total,
                'success' => $successCount,
                'fail' => $failCount,
                'incomplete' => $incompleteCount,
                'untracked' => ($total - ($successCount + $failCount + $incompleteCount))
            ]
        ];
    }


    public function getManualCancelReport($path, $folder): array
    {
        $file = fopen($path, "r");
        $filesize = filesize($path);
        $filetext = fread($file, $filesize);
        $content = explode(' **************** Manual Cancel Start *****************', $filetext);
        $total = 0;
        $successCount = 0;
        $failCount = 0;
        $incompleteCount = 0;
        if (!empty($content)) {
            unset($content[0]);
            $content = array_values($content);
            $total = count($content);
            foreach ($content as $cancelProcess) {
                $processData = explode(PHP_EOL, $cancelProcess);
                $result = '';
                foreach ($processData as $k => $line) {
                    if (str_contains($line, 'Manual data received: ')) {
                        $reference_id = explode('Manual data received: ', $line);
                        $cancelData = json_decode($reference_id[1], true);
                        if (isset($cancelData['marketplace_reference_id'])) {
                            $result .= '<div class="col-4">Cancel marketplace_reference_id: ' . $cancelData['marketplace_reference_id'] . '</div>';
                        } else {
                            $result .= '<div class="col-4">There is some issue with data!</div>';
                        }
                    }

                    if (str_contains($line, 'Status of cancellation:')) {
                        $success = 'success';
                        if (str_contains($processData[$k + 1], 'failed')) {
                            $success = 'danger';
                            $failCount += 1;
                        } else {
                            $successCount += 1;
                        }

                        if (isset($processData[$k + 1])) {
                            $result .=  '<div class="col-8 bg-' . $success . '">Cancellation result: ' . $processData[$k + 1] . '</div>';
                        }
                    }
                }

                echo $result;
            }
        }

        echo '<table class="table">
                <tr>
                <th class="bg-primary">Total processes: </th>
                <td class="bg-dark text-warning"><b>' . $total . '</b></td>
                <th class="bg-success">Process Success count: </th>
                <td class="bg-dark text-warning"><b>' . $successCount . '</b></td>
                <th class="bg-danger">Process Fail count: </th>
                <td class="bg-dark text-warning"><b>' . $failCount . '</b></td>
                <th class="bg-warning">Process Incomplete count: </th>
                <td class="bg-dark text-warning"><b>' . $incompleteCount . '</b></td>
                <th class="bg-secondary">Untracked Process: </th>
                <td class="bg-dark text-warning"><b>' . ($total - ($successCount + $failCount + $incompleteCount)) . '</b></td>
            </tr>
            </table>';
        return [
            'success' => true,
            'data' => [
                'total' => $total,
                'success' => $successCount,
                'fail' => $failCount,
                'incomplete' => $incompleteCount,
                'untracked' => ($total - ($successCount + $failCount + $incompleteCount))
            ]
        ];
    }

    public function getLogsAction()
    {
        $basePath = realpath(BP . '/var/log/');
        $folder = $this->request->get('folder') ?? false;

        // Check if the provided folder and file paths are within the var/log directory
        if ($folder && strpos(realpath($basePath . '/' . $folder), $basePath) !== 0) {
            return json_encode([
                'success' => false,
                'message' => 'Invalid folder path'
            ]);
        }

        $path = $folder ? $basePath . '/' . $folder : $basePath;

        if (is_dir($path)) {
            $data = scandir($path);
        } elseif (is_file($path)) {
            $this->readFileChunked($path);
            die;
        } else {
            return json_encode([
                'success' => false,
                'message' => 'No such file or directory found'
            ]);
        }

        echo '<pre>';
        print_r($data);
        die();
    }

    //work on below function to prevent memory exhaustion
    //also consider adding checks for CPU and Memory usage
    public function readFileChunked($filename): bool
    {
        $chunkSize = 512; // bytes per chunk
        $handle = fopen($filename, "rb");

        if ($handle === false) {
            return false;
        }

        echo '<pre>';
        while (!feof($handle)) {
            print fread($handle, $chunkSize);
            ob_flush();
            flush();
        }

        return  fclose($handle);
    }

    public function getBulletinReportAction()
    {
        $date = $this->request->get('date') ?? false;
        $folder = '';
        $msg = '';
        if ($date == false) {
            $date = date('Y-m-d');
        } else {
            $date = $date;
        }
        $path = BP . '/var/log/bulletin/urlTrack/' . $date.'.log';
        // echo '<pre>'; print_r($path); die();
        if (file_exists($path)) {
            $file = fopen($path, "r");
            $filesize = filesize($path);
            $content = fread($file, $filesize);
            $lines = explode(PHP_EOL, $content);
            $extractedData = [];
            $pattern = '/"User - (?P<user>[^|]+) \| Item Type - (?P<item_type>[^|]+) \| url - (?P<url>[^"]+)"/';
            $totalClicks = 0;
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                if (preg_match($pattern, $line, $matches)) {
                    $user = trim($matches['user']);
                    $itemType = trim($matches['item_type']);
                    $url = trim($matches['url']);
                    // $extractedData[$user][$itemType] = $url;
                    if (isset($extractedData[$user])) {
                        $extractedData[$user]['count'] += 1;
                    } else {
                        $extractedData[$user]['count'] = 1;
                    }
                    if (isset($extractedData[$user]['types'][$itemType])) {
                        $extractedData[$user]['types'][$itemType] += 1;
                    } else {
                        $extractedData[$user]['types'][$itemType] = 1;
                    }
                    // echo '<tr><td>'.$user.'</td><td>'.$itemType.'</td><td>'.$url.'</td></tr>';
                    $totalClicks += 1;
                }
            }
            if (!empty($extractedData)) {
                echo '<div class="container bg-white border">';
                echo '<div class="row">';
                echo '<div class="col-12 bg-info"><h5 class="text-center"> Providing Url Track History</h5></div>';
                foreach($extractedData as $user => $info) {
                        echo '<div class="col-3 border-bottom border-dark">'.$user.'</div>';
                        if (isset($info['types'])) {
                            $td = '<div class="col-6 border-bottom border-dark">';
                            foreach($info['types'] as $type => $count) {
                                $td .= $type.' >>> <b>'. $count.'</b>';
                                $td .= '<br>';
                            }
                            $td .= '</div>';
                            echo $td;
                        }
                        if (isset($info['count'])) {
                            echo '<div class="col-3 border-bottom border-dark"> Total time user visits: '.$count.'</div>';
                        }
                }
                echo '<div class="col-12 bg-info"><h5 class="text-center"> Total clicks: '.$totalClicks.'</h5></div>';
                echo '</div>';
                echo '</div>';
            }
        } else {
            echo '<h4 class="text-danger text-center">File not found!<b>' . $date . '</b></h4>';
        }
    }
}
