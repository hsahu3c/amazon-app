<?php

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use GuzzleHttp\Client;

class MWServerCheckCommand extends Command
{

    protected static $defaultName = "mw-server-status";

    protected static $defaultDescription = "To check the status of MW (Mongo Webhook) server, is running or not";

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
        // $this->setHelp('Sync target order from Shopify.');
        // $this->addOption("user_id", "u", InputOption::VALUE_REQUIRED, "user_id of the user");
        // $this->addOption("shop_id", "s", InputOption::VALUE_REQUIRED, "Amazon Home Shop Id of the user");
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $url = "http://localhost:3000/test";
            $res = $this->callAPI($url);


            print_r($res);
        } catch (\Exception) {
            //error means server is not working we need to trigger fail safe process now
            $triggers =  new \App\Connector\Models\MongostreamV2\InitiateFailSafeCases;
            $triggers->initiate();
            // print_r($e->getMessage());
        }

        return true;
    }

    public function callAPI($url, $headers = [], $body = [], $type = 'GET')
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
        $response->getHeaders();

        $res = json_decode($bodyContent, true);

        // $res['headers'] = $headersContent;
        return $res;
    }
}
