<?php

namespace App\Connector\Api;

use App\Core\Models\BaseMongo;

use GuzzleHttp\Client;

class OpenAI extends BaseMongo
{

    public $base_url = 'https://api.openai.com/v1/';

    public function callAPI($url, $headers, $body, $type)
    {
        // echo $url;
        $client = new Client(["verify" => false]);
        if ($type == 'POST') {
            $response =   $client->post($url, ['headers' => $headers, 'json' => $body, 'http_errors' => false]);
        } elseif ($type == 'GET') {
            $response = $client->get($url, ['headers' => $headers, 'query' => [], 'http_errors' => false]);
        } elseif ($type == 'PUT') {
            $response =  $client->put($url, ['headers' => $headers, 'json' => $body, 'http_errors' => false]);
        } elseif ($type == "DELETE") {
            $response = $client->delete($url, ['headers' => $headers, 'http_errors' => false]);
        } else {
            $response = $client->get($url, ['headers' => $headers, 'query' => [], 'http_errors' => false]);
        }

        $bodyContent = $response->getBody()->getContents();
        $headersContent = $response->getHeaders();

        $res = json_decode($bodyContent, true);

        $res['headers'] = $headersContent;
        return $res;
    }

    public function getHeaders()
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->di->getConfig()->getOpenAIKEY
        ];
    }

    public function chatCompletionWithoutStream($body)
    {
        $headers = $this->getHeaders();
        $url = $this->base_url . 'chat/completions';
        $response = $this->callAPI($url, $headers, $body, 'POST');

        $response['response_text'] =  $response['choices'][0]['message']['content'];
        return $response;
    }

    public function chatCompletion($body)
    {
        if (!isset($body['stream']) || (isset($body['stream']) && !$body['stream'])) {
            return $this->chatCompletionWithoutStream($body);
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        $url = 'https://api.openai.com/v1/chat/completions';
        $response_text = '';
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Cache-Control: no-cache', 'Content-Type: application/json', 'Accept: text/event-stream', 'Authorization: Bearer ' . $this->di->getConfig()->getOpenAIKEY));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 0);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($curl, CURLOPT_VERBOSE, false);
        curl_setopt($curl, CURLOPT_NOPROGRESS, true);
        curl_setopt($curl, CURLOPT_BUFFERSIZE, 128);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt(
            $curl,
            CURLOPT_WRITEFUNCTION,
            function ($ch, $data) use (&$response_text): int {
                $content = "";
                $deltas = explode("\n", $data);
                foreach ($deltas as $delta) {

                    if (strlen($delta) > 0 && str_contains($delta, '    "error": {')) {
                        $errorMsg = 'Throttle Error: Rate Limit Exceed';
                        $index = array_search($delta, $deltas);
                        $errorMessage = $deltas[$index + 1];

                        // send $errorData if you want dynamic message from openAI
                        $errorData = json_decode(substr($errorMessage, 19));
                        echo "event: error\n";
                        // Sleep for 0.5 seconds before sending the next word
                        //     usleep(500000);
                        echo "data: {$errorMsg}\n\n";
                    } elseif (strlen($delta) > 0) {
                        if (strpos($delta, '{') !== false) {
                            $msg = str_split($delta);
                            // extract the json message.
                            $json = '';
                            $in = 0;
                            foreach ($msg as $i => $char) {
                                if ($char == '{') {
                                    $in++;
                                }

                                if ($in) {
                                    $json .= $msg[$i];
                                }

                                if ($char == '}') {
                                    $in--;
                                }
                            }

                            if ($json) {
                                $json = json_decode($json, true);
                            }

                            if ((isset($json['choices']) && count($json['choices']) > 0 && isset($json['choices'][0]['delta'])) || (isset($json['delta']) && count($json['delta']))) {
                                $del = $json['choices'][0]['delta'] ??  $json['delta'];
                                if (isset($del['content'])) {
                                    $content .= str_contains($del['content'], "\n") ? str_replace("\n", "\\n", $del['content']) : $del['content'];
                                }
                            } elseif (isset($json['error']['message'])) {
                                $content = $json['error']['message'];
                            }
                        } elseif (trim($delta) == "data: [DONE]") {
                            $content = "";
                        } else {
                            $content = "";
                        }
                    }
                }

                echo "event: message\n";
                echo "data: {$content}\n\n";
                $response_text = $response_text . $content;
                flush();
                if (connection_aborted()) return 0;

                return strlen($data);
            }
        );
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Enable implicit flushing
        ob_implicit_flush(true);
        curl_exec($curl);
        curl_close($curl);
        echo "event: stop\n";
        echo "data: stopped\n\n";

        return ['response_text' => $response_text];
    }

    public function completion($body)
    {
        $headers = $this->getHeaders();
        $url = $this->base_url . 'completions';
        $response = $this->callAPI($url, $headers, $body, 'POST');
        return $response;
    }

    public function createEmbedding($body)
    {
        $headers = $this->getHeaders();
        $url = $this->base_url . 'embeddings';
        $response = $this->callAPI($url, $headers, $body, 'POST');
        return $response;
    }
}
