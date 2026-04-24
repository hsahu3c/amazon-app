<?php

namespace App\Core\Components;

class RequestLogger extends Base
{
    public function logContent($data)
    {
        $requestLogger = $this->di->getObjectManager()->get("App\Core\Models\RequestLog");
        $ref = $requestLogger->findFirst(["url" => isset($data["_url"]) ? $data["_url"] : "/"]);
        // if doc found in db, just incr the hitCount
        if ($ref) {
            $ref->hitCount = $ref->hitCount + 1;
            $ref->time_taken = microtime(true) - $data["started"];
            $ref->avg = $ref->greedyAvg($ref->avg, $ref->hitCount - 1, $ref->time_taken);
            $ref->method = $this->di->getRequest()->getMethod();
            // $ref->payload = $this->di->getRequest()->getJsonRawBody() ?? $this->di->getRequest()->get();
            $headers = $this->di->getRequest()?->getHeaders();
            if (!empty($headers['Content-Type']) && $headers['Content-Type'] == 'application/json') {
                $ref->payload = $this->di->getRequest()->getJsonRawBody();
            } else {
                $ref->payload = $this->di->getRequest()->get();
            }
            $ref->intervals = array_merge($ref->intervals, [$ref->time_taken]);
            $ref->min_so_far = min($ref->intervals);
            $ref->max_so_far = max($ref->intervals);
            // if requests are more than stack size.
            if (count($ref->intervals) > $this->di->getConfig()->get("requests")->get("max_requests_stack")) {
                $maxStack = $this->di->getConfig()->get("requests")->get("max_requests_stack");
                $ref->intervals = array_slice($ref->intervals, $maxStack, count($ref->intervals));
                // recalculate the values
                $ref->avg = array_sum($ref->intervals) / count($ref->intervals);
                $ref->hitCount = count($ref->intervals);
                $ref->min_so_far = min($ref->intervals);
                $ref->max_so_far = max($ref->intervals);
            }
            $ref->save();
            // check if avg is above defined threshold
            if ($ref->avg > $this->di->getConfig()->get("requests")->get("threshold")) {
                $this->di->getLog()->logContent(
                    print_r("request $ref->url took longer than the defined threshold.\nrequest took $ref->time_taken\nBest is $ref->min_so_far\nAverage is $ref->avg\nWorst is $ref->max_so_far", true),
                    'info',
                    'request_tracks.log'
                );
            }
        } else {
            // insert into db
            $tmp = [];
            $tmp["url"] = $data["_url"] ?? "/";
            $tmp["started"] = $data["started"];
            $tmp["ended"] = microtime(true);
            $tmp["time_taken"] = $tmp["ended"] - $data["started"];
            unset($data["started"], $tmp["started"], $tmp["ended"]);
            $tmp["method"] = $this->di->getRequest()->getMethod();
            $tmp["payload"] = $this->di->getRequest()->getJsonRawBody() ??
                $this->di->getRequest()->get();
            $tmp["hitCount"] = 1;
            $tmp["avg"] = $tmp["time_taken"];
            $tmp["intervals"] = [$tmp["time_taken"]];
            //inserting the data
            return $requestLogger->insert($tmp);
        }
        return null;
    }

    public function canLogResponse($response)
    {
        $responseLogsEnabled = $this->di->getConfig()->get('response_logs_enabled');
        if (is_bool($responseLogsEnabled) && $responseLogsEnabled === false) {
            return false;
        }
        if (isset($response['success']) && $response['success'] === false) {
            return true;
        } else {
            $queryParam = $this->di->getRequest()->getQuery();
            if (isset($queryParam['_url'])) {
                $allowedEndpoints = [];
                if ($endpointStr = $this->di->getConfig()->get('response_log_endpoints')) {
                    $allowedEndpoints = explode(',', $endpointStr);
                }

                if (
                    in_array(
                        str_replace(
                            '/webapi/rest/v1/',
                            '',
                            $queryParam['_url']
                        ), $allowedEndpoints)
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}
