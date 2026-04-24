<?php

namespace App\Core\Components;

use Phalcon\Logger\Logger;

class Log extends Base
{
    protected $log;
    protected $adapters = [];
    public function setDi(\Phalcon\Di\DiInterface $di): void
    {
        parent::setDi($di);
        $adapter = fopen(BP . DS . 'var' . DS . 'log' . DS . 'system.log', 'a+');
        $this->adapters['system.log'] = $adapter;
        $this->di->set('log', $this);
    }

    public function createDirectory($dir)
    {
        $path = BP . DS . 'var' . DS . 'log' . DS . $dir;
        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }
    }

    public function logContent(
        $content,
        $type = Logger::DEBUG,
        $file = 'system.log',
        $closeConnectionNow = false,
        $messageUniqueCode = false
    ) {
        if ($this->di->getConfig()->get('log_level') <= $type) {
            if (isset($this->adapters[$file])) {
                $adapter = $this->adapters[$file];
            } else {
                $oldmask = umask(0);
                $this->createDirectory(dirname($file));
                $adapter = fopen(BP . DS . 'var' . DS . 'log' . DS . $file, 'a+');
                umask($oldmask);
                if (!$closeConnectionNow) {
                    $this->adapters[$file] = $adapter;
                }
            }

            switch ($type) {
                case Logger::CRITICAL:
                    $this->log($adapter, Logger::CRITICAL, $content);
                    break;
                case Logger::EMERGENCY:
                    $this->log($adapter, Logger::CRITICAL, $content);
                    $this->notifyThroughMail($messageUniqueCode, $content);
                    break;
                case Logger::DEBUG:
                    $this->log($adapter, Logger::DEBUG, $content);
                    break;
                case Logger::ERROR:
                    $this->log($adapter, Logger::ERROR, $content);
                    break;
                case Logger::INFO:
                    $this->log($adapter, Logger::INFO, $content);
                    break;
                case Logger::NOTICE:
                    $this->log($adapter, Logger::NOTICE, $content);
                    break;
                case Logger::WARNING:
                    $this->log($adapter, Logger::WARNING, $content);
                    break;
                case Logger::ALERT:
                    $this->log($adapter, Logger::ALERT, $content);
                    break;
                default:
                    $this->log($adapter, Logger::CUSTOM, $content);
                    break;
            }

            if ($closeConnectionNow) {
                $this->close($adapter);
                return false;
            } else {
                return $adapter;
            }
        }
    }

    private function notifyThroughMail($messageUniqueCode, $content)
    {
        if (empty($messageUniqueCode) || empty($content)) {
            return false;
        }
        $currentTimestamp = time();
        $config = $this->di->getConfig()->get('mailer');

        $email = $config->get('critical_mail_reciever') ?? '';
        $debug = 0;
        $isHtml = true;

        $subject = $config && $config->get('critical_mail_subject') ? $config->get('critical_mail_subject') : 'Critical issue';
        $appCode = $this->di->getConfig()->get('app_code');
        $subject = $subject . " - {$appCode}";

        $bccs = $config && $config->get('critical_mail_reciever_bcc') ? $config->get('critical_mail_reciever_bcc') : [];
        if(is_object($bccs)) {
            $bccs = $bccs->toArray();
        }

        $ccs = $config && $config->get('critical_mail_receiver_cc') ? $config->get('critical_mail_receiver_cc') : [];
        if(is_object($ccs)) {
            $ccs = $ccs->toArray();
        }

        $lastMessageTime = $this->di->getCache()->get('sendmail_' . $messageUniqueCode);
        $this->logCriticalExceptionWithUser($content);
        if (!$lastMessageTime || $lastMessageTime < $currentTimestamp - 3600) {
            $userId = $this->di->getUser()->id ?? null;
            $userName = $this->di->getUser()->username ?? null;
            if ($userId && $userName) {
                $content = "UserDetails: [" .
                    "user_id: " . $userId . "," .
                    "username: " . $userName . "" .
                    "]," .
                    "Exception: " . $content;
            }
            $this->di->getCache()->set('sendmail_' . $messageUniqueCode, $currentTimestamp);
            $this->di->getMailer()->send($email, $subject, $content, [
                'debug' => $debug,
                'isHtml' => $isHtml,
                'bccs' => $bccs,
                'ccs' => $ccs
            ]);
            return true;
        }

        return false;
    }
    public function log($adapter, $loglevel, $content)
    {
        $content = date('c') . " => " . $content;
        fwrite($adapter, $content . PHP_EOL);
    }

    public function close($adapter)
    {
        fclose($adapter);
    }

    /**
     * This function returns a stack trace with no truncated strings
     * @var \Exception $exception
     * 
     * @return String
     */
    public static function getExceptionTraceAsString($exception)
    {
        $rtn = "";
        $count = 0;

        $rtn .= sprintf(
            "#%s %s(%s)\n",
            $count,
            $exception->getFile(),
            $exception->getLine()
        );
        $count++;
        
        foreach ($exception->getTrace() as $frame) {
            $args = "";
            if (isset($frame['args'])) {
                $args = array();
                foreach ($frame['args'] as $arg) {
                    if (is_string($arg)) {
                        $args[] = "'" . $arg . "'";
                    } elseif (is_array($arg)) {
                        $args[] = "Array";
                    } elseif (is_null($arg)) {
                        $args[] = 'NULL';
                    } elseif (is_bool($arg)) {
                        $args[] = ($arg) ? "true" : "false";
                    } elseif (is_object($arg)) {
                        $args[] = get_class($arg);
                    } elseif (is_resource($arg)) {
                        $args[] = get_resource_type($arg);
                    } else {
                        $args[] = $arg;
                    }
                }
                $args = join(", ", $args);
            }
            $current_file = "[internal function]";
            if (isset($frame['file'])) {
                $current_file = $frame['file'];
            }
            $current_line = "";
            if (isset($frame['line'])) {
                $current_line = $frame['line'];
            }
            $rtn .= sprintf(
                "#%s %s(%s): %s%s%s(%s)\n",
                $count,
                $current_file,
                $current_line,
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'],
                $args
            );
            $count++;
        }
        return $rtn;
    }

    private function logCriticalExceptionWithUser($content)
    {
        $userId = $this->di->getUser()->id ?? null;
        $userName = $this->di->getUser()->username ?? null;

        if ($userId && $userName) {
            $content =
                "UserDetails: [" .
                "user_id: " . $userId . "," .
                "username: " . $userName . "" .
                "]," .
                "Exception: " . $content;
        }

        $this->logContent($content, 'critical', 'exception_with_user.log');
    }
}
