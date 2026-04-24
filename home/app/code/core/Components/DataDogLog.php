<?php

namespace App\Core\Components;

// use Monolog\Logger;
// use Monolog\Formatter\LineFormatter;
// use Monolog\Handler\StreamHandler;
// use Monolog\Handler\SyslogHandler;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Maxbanton\Cwh\Handler\CloudWatch;
// use Monolog\Formatter\JsonFormatter;

class DataDogLog extends Base
{
    public function logContent($content, $type = 'debug',  $fileName = 'system.log', $addattr = true)
    {
        // create a log channel
//            $log = new Logger('Remote Log');

//            // create a Json formatter
//            $formatter = new JsonFormatter();

//            // create a handler
//            $file = BP.DS.'var'.DS.'log'.DS.'application-json.log';
//            $stream = new StreamHandler($file, Logger::DEBUG);
//            $stream->setFormatter($formatter);

//            // bind
//            $log->pushHandler($stream);
//         try {
//             $user_id = $this->di->getUser()->id;
//         } catch (\Exception $e) {
//             $user_id = 1;
//         }
//            $context = [
//                'type' => $type,
//                'user_id'=> $user_id,
//                'file' => $fileName,
//                'ip' => $_SERVER['REMOTE_ADDR']
//            ];

// // Add records to the log

//         if(!is_numeric($type)) {
//            switch ($type) {
//                case 'info':
//                    $type = Logger::INFO;
//                    break;
//                case 'critical':
//                    $type = Logger::CRITICAL;
//                    break;
//                default:
//                    $type = Logger::INFO;
//                    break;
//            }
//         }

//         if($addattr) {
//             $log->pushProcessor(function ($record) {
//                 $record['http']['method']=	$_SERVER['REQUEST_METHOD'];
//                 $record['http']['url']=	$_SERVER['REQUEST_URI'];
//                 $record['http']['url_details']['path']=	$_SERVER['REQUEST_URI'];
// //                $record['http']['status_code']=	http_response_code();
//                 $record['http_host']=	$_SERVER['HTTP_HOST'];
//                 return $record;
//             });
//         }

//         switch ($type) {
//             case Logger::CRITICAL:
//                 $log->critical($content,$context);
//                 break;
//             case Logger::EMERGENCY:
//                 $log->emergency($content,$context);
//                 break;
//             case Logger::DEBUG:
//                 $log->debug($content,$context);
//                 break;
//             case Logger::ERROR:
//                 $log->error($content,$context);
//                 break;
//             case Logger::INFO:
//                 $log->info($content,$context);
//                 break;
//             case Logger::NOTICE:
//                 $log->notice($content,$context);
//                 break;
//             case Logger::WARNING:
//                 $log->warning($content,$context);
//                 break;
//             case Logger::ALERT:
//                 $log->alert($content,$context);
//                 break;
//             default:
//                 $log->info($content,$context);
//                 break;
//         }




    }

}
