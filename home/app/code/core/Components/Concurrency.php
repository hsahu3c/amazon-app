<?php

namespace App\Core\Components;

trait Concurrency
{
    public function createConcurrencyDirectory()
    {
        if (!file_exists(BP . DS . 'var' . DS . 'concurrency')) {
            mkdir(BP . DS . 'var' . DS . 'concurrency');
        }
    }
    public function handleLock($code, $fun, $limit = 2)
    {
        $count = 0;
        $maxTryLimit = 30 * 1000;
        $tryCount = 0;

        $timeout = false;
        $gotTheLock = false;
        $h = false;
        while (!$gotTheLock && !$timeout) {
            $this->createConcurrencyDirectory();
            while ($count < $limit) {
                $count++;
                $file = BP . DS . 'var' . DS . 'concurrency' . DS . $code . '-' . $count;
                $h = fopen($file, 'w+');
                if (flock($h, LOCK_EX | LOCK_NB, $wouldblock)) { //TODO
                    try {
                        //$this->di->getLog()->logContent('open lock for code: ' . $code . ' count ' . $count, 'info', 'locks.log');
                    } catch (\Exception $e) {
                        echo $e->getMessage();
                    }
                    $gotTheLock = true;
                    break;
                }
            }
            $count = 0;
            $tryCount++;
            usleep(1000);
            if ($tryCount > $maxTryLimit) {
                //echo  'max try exceeded'.PHP_EOL;
                $timeout = true;
            } else {
                //echo 'try again'.PHP_EOL;
            }
        }
        if ($gotTheLock) {
            try {
                $result = $fun();
                if ($h) {
                    flock($h, LOCK_UN);
                    fclose($h);
                }
                return $result;
            } catch (\Exception $e) {
                $this->di->getLog()->logContent(
                    'Error in concurrency handleSingleLock' . $e->getMessage() . ' code: ' . $code,
                    'info',
                    'system.log'
                );
                if ($h) {
                    flock($h, LOCK_UN);
                    fclose($h);
                }
            }
        } else {
            // get out of limit lock and execute  
            $count = 0;
            $this->createConcurrencyDirectory();
            while (!$gotTheLock) {
                $count++;
                $this->createConcurrencyDirectory();
                $file = BP . DS . 'var' . DS . 'concurrency' . DS . $code . '-' . $count;
                $h = fopen($file, 'w+');
                if (flock($h, LOCK_EX | LOCK_NB, $wouldblock)) {
                    $gotTheLock = true;
                    // $this->di->getLog()->logContent('open lock for code: ' . $code . ' count' . $count, 'info', 'locks.log');
                }
            }
            try {
                // $this->di->getLog()->logContent('got the out of limit lock at :' . $count . 'for code : ' . $code, 'info', 'system.log');
                $result = $fun();
                if ($h) {
                    flock($h, LOCK_UN);
                    fclose($h);
                }
                return $result;
            } catch (\Exception $e) {
                $this->di->getLog()->logContent(
                    'Error in concurrency handleSingleLock' . $e->getMessage() . ' code: ' . $code,
                    'info',
                    'system.log'
                );
                if ($h) {
                    flock($h, LOCK_UN);
                    fclose($h);
                }
            }
        }
    }


    public function handleSingleLock($code, $fun, $limit = 1)
    {
        $this->createConcurrencyDirectory();

        $file = BP . DS . 'var' . DS . 'concurrency' . DS . $code;
        $h = fopen($file, 'w+');
        flock($h, LOCK_EX);
        try {
            $result = $fun();
            flock($h, LOCK_UN);
            return $result;
        } catch (\Exception $e) {
            $this->di->getLog()->logContent('Error in concurrency handleSingleLock' . $e->getMessage() . ' code: ' . $code, 'info', 'system.log');
            if ($h) {

                flock($h, LOCK_UN);
            }
        }
        fclose($h);
    }
}
