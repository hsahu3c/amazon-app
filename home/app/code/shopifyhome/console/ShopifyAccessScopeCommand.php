<?php

use App\Core\Models\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

#[\AllowDynamicProperties]
class ShopifyAccessScopeCommand extends Command
{

    protected static $defaultName = "shopify:access-scope";

    protected static $defaultDescription = "Verify Shopify Access Scope";

    const MARKETPLACE = 'shopify';

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
        $this->setHelp('Verify Shopify Access Scope');
    }

    /**
     * The main logic to execute when the command is run
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<options=bold;bg=cyan> ➤ </><options=bold;fg=cyan> Initiating Process..</>');
        $output->writeln("<options=bold;bg=bright-white> ➤ </> .....................");
        
        $inputFilePath = BP . DS . "var/file/home_user_details.csv";
        if (!file_exists($inputFilePath)) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> File does not exist: ' . $inputFilePath . '</>');
            return 1;
        }

        try {
            $results = $this->processCsvFile($inputFilePath, $output);
            
            $output->writeln('<options=bold;bg=green> ➤ </><options=bold;fg=green> Process Completed Successfully</>');
            return 0;
            
        } catch (Exception $e) {
            $output->writeln('<options=bold;bg=red> ➤ </><options=bold;fg=red> Error: ' . $e->getMessage() . '</>');
            return 1;
        }
    }

    /**
     * Process CSV file and check token for each shop URL
     *
     * @param string $csvFile
     * @param OutputInterface $output
     * @return array
     */
    private function processCsvFile(string $csvFile, OutputInterface $output): array
    {
        $results = [];
        $row = 0;
        $shopUrlIndex = null;
        
        if (($handle = fopen($csvFile, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $row++;
                
                // Process header row
                if ($row === 1) {
                    // Find shop_url column index
                    $shopUrlIndex = array_search('shop_url', $data);
                    if ($shopUrlIndex === false) {
                        throw new Exception('CSV file must contain "shop_url" column header');
                    }
                    $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Found shop_url column at index: ' . $shopUrlIndex . '</>');
                    continue;
                }
                
                // Process data rows
                if (isset($data[$shopUrlIndex]) && !empty(trim($data[$shopUrlIndex]))) {
                    $shopUrl = trim($data[$shopUrlIndex]);
                    
                    $output->writeln('<options=bold;bg=blue> ➤ </><options=bold;fg=blue> Processing shop: ' . $shopUrl . '</>');
                    $this->di->getLog()->logContent('Processing for shop_url now is ' . json_encode($shopUrl),'info', 'shopify_access_scope.log');

                    
                    
                    // Get token for this shop
                    $tokenData = $this->getToken($shopUrl);
                    
                    // Check access scopes if token data exists
                    $accessScopeResult = null;
                    if ($tokenData && isset($tokenData['apps'][0]['token']) && isset($tokenData['shop_url'])) {
                        $token = $tokenData['apps'][0]['token'];
                        $shopUrlFromToken = $tokenData['shop_url'];
                        $accessScopeResult = $this->checkAccessScopes($shopUrlFromToken, $token);
                        
                        // Check if access_scopes is empty and log
                        if ($accessScopeResult['success'] && 
                            isset($accessScopeResult['response']['access_scopes']) && 
                            empty($accessScopeResult['response']['access_scopes'])) {
                                $this->di->getLog()->logContent('Empty Access Scopes for Shop: ' . json_encode($shopUrlFromToken),'info', 'shopify_access_scope.log');

                        }
                        
                    }else{
                        $this->di->getLog()->logContent('No valid token data found for shop: ' . $shopUrl, 'info','shopify_access_scope.log');

                    }
                }
            }
            fclose($handle);
        } else {
            throw new Exception('Could not open CSV file for reading');
        }
        
        return $results;
    }

    /**
     * Get token data for a specific shop
     *
     * @param string $shop
     * @return array|false
     */
    public function getToken($shop)
    {
        $db = $this->di->get('remote_db');
        if ($db) {
            $appsCollections = $db->selectCollection('apps_shop');
            $options = ['typeMap' => ['root' => 'array', 'document' => 'array']];
            $appInfo = $appsCollections->findOne(['shop_url' => $shop], $options);
            return $appInfo;
        }
        return false;
    }

    /**
     * Check access scopes using Shopify API
     *
     * @param string $shopUrl
     * @param string $token
     * @return array
     */
    private function checkAccessScopes(string $shopUrl, string $token): array
    {
        try {
            // Build the URL dynamically
            $url = 'https://' . $shopUrl . '/admin/oauth/access_scopes.json';
            
            // Set up cURL
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'X-Shopify-Access-Token: ' . $token,
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                return [
                    'success' => false,
                    'error' => 'cURL Error: ' . $curlError,
                    'response' => null
                ];
            }
            
            if ($httpCode !== 200) {
                return [
                    'success' => false,
                    'error' => 'HTTP Error ' . $httpCode,
                    'response' => $response
                ];
            }
            
            $decodedResponse = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'error' => 'JSON Decode Error: ' . json_last_error_msg(),
                    'response' => $response
                ];
            }
            
            return [
                'success' => true,
                'error' => null,
                'response' => $decodedResponse,
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'response' => null
            ];
        }
    }
}
