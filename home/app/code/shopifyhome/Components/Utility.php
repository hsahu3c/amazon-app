<?php
namespace App\Shopifyhome\Components;

use App\Core\Components\Base;
class Utility extends Base
{

    public $_msg;

    public $_filePath;

    public function init($filePath = 'shopify/system.log', $msg = ''){
        $this->_filePath = $filePath;
        $this->_msg = $msg;
        return $this;
    }

    public function RAMConsumption(): void
    {
        /* Currently used memory */
        $mem_usage = memory_get_usage();

        /* Peak memory usage */
        $mem_peak = memory_get_peak_usage();

        $alloc_usage = memory_get_usage(true);

        $this->di->getLog()->logContent($this->_msg.'. Now Using ***'.round($mem_usage / (1024 * 1024), 4) . ' MB *** of RAM. Peak usage: *** '. round($mem_peak / (1024 * 1024), 4) . 'MB *** of memory.' ,'info',$this->_filePath);
        $this->di->getLog()->logContent($this->_msg.'. Allocated memory ***'.round($mem_usage / (1024 * 1024), 4) . ' MB *** RAM.','info',$this->_filePath);

    }

    public function secondsToTime($s)
    {
        $h = floor($s / 3600);
        $s -= $h * 3600;
        $m = floor($s / 60);
        $s -= $m * 60;
        return $h.':'.sprintf('%02d', $m).':'.sprintf('%02d', $s);
    }

    public function CPUConsumption(): void
    {
        $load = null;

        if (stristr(PHP_OS, "win"))
        {
            $cmd = "wmic cpu get loadpercentage /all";
            @exec($cmd, $output);

            if ($output)
            {
                foreach ($output as $line)
                {
                    if ($line && preg_match("/^[0-9]+\$/", $line))
                    {
                        $load = $line;
                        break;
                    }
                }
            }
        }
        else
        {
            if (is_readable("/proc/stat"))
            {
                // Collect 2 samples - each with 1 second period
                // See: https://de.wikipedia.org/wiki/Load#Der_Load_Average_auf_Unix-Systemen
                $statData1 = $this->_getServerLoadLinuxData();
                sleep(1);
                $statData2 = $this->_getServerLoadLinuxData();

                if
                (
                    (!is_null($statData1)) &&
                    (!is_null($statData2))
                )
                {
                    // Get difference
                    $statData2[0] -= $statData1[0];
                    $statData2[1] -= $statData1[1];
                    $statData2[2] -= $statData1[2];
                    $statData2[3] -= $statData1[3];

                    // Sum up the 4 values for User, Nice, System and Idle and calculate
                    // the percentage of idle time (which is part of the 4 values!)
                    $cpuTime = $statData2[0] + $statData2[1] + $statData2[2] + $statData2[3];

                    // Invert percentage to get CPU time, not idle time
                    $load = 100 - ($statData2[3] * 100 / $cpuTime);
                }
            }
        }

        if (is_null($load)) {
            $this->di->getLog()->logContent($this->_msg.' .CPU load not estimateable (maybe too old Windows or missing rights at Linux or Windows)','info',$this->_filePath);
        }
        else {
            $this->di->getLog()->logContent($this->_msg.' .Current CPU consumption : *** '.round($load, 2).' % ***.' ,'info',$this->_filePath);
        }
    }

    function exceptionHandler($msg){
        $e_array=["No suitable servers found",'socket error or timeout'];
        foreach ($e_array as $emsg){
            if(strstr((string) $msg,$emsg)){
                return true;
            }
        }

        return false;
    }

    function _getServerLoadLinuxData()
    {
        if (is_readable("/proc/stat"))
        {
            $stats = @file_get_contents("/proc/stat");

            if ($stats !== false)
            {
                // Remove double spaces to make it easier to extract values with explode()
                $stats = preg_replace("/[[:blank:]]+/", " ", $stats);

                // Separate lines
                $stats = str_replace(["\r\n", "\n\r", "\r"], "\n", $stats);
                $stats = explode("\n", $stats);

                // Separate values and find line for main CPU load
                foreach ($stats as $statLine)
                {
                    $statLineData = explode(" ", trim($statLine));

                    // Found!
                    if
                    (
                        (count($statLineData) >= 5) &&
                        ($statLineData[0] == "cpu")
                    )
                    {
                        return array(
                            $statLineData[1],
                            $statLineData[2],
                            $statLineData[3],
                            $statLineData[4],
                        );
                    }
                }
            }
        }

        return null;
    }
}