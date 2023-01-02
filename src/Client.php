<?php
namespace PushToLive\Client;

use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Bramus\Monolog\Formatter\ColorSchemes\TrafficLight;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use GuzzleHttp\Client as Guzzle;
use Symfony\Component\Yaml\Yaml;

class Client{

    protected Logger $logger;
    protected array $credentials;
    protected Guzzle $guzzle;
    protected array $defaultConfig = [
        'ENDPOINT' => 'http://pushto.live/',
        'CURL_DEBUG' => "no",
    ];
    protected array $config;
    private string $identityUsername;
    private string $identityEmail;
    private string $identityOrgName;

    private string $appYamlPath = "ptl.yml";
    private array $appYaml;

    public function __construct(){
        $this->logger = new Logger("PTL-Client");
        // Configure a pretty CLI Handler
        $cliHandler = new StreamHandler('php://stdout', Logger::DEBUG);
        $cliFormatter = new ColoredLineFormatter(
            new TrafficLight(),
            // the default output format is "[%datetime%] %channel%.%level_name%: %message% %context% %extra%"
            '[%datetime%] %level_name%: %message%'."\n",
            'g:i'
        );
        $cliHandler->setFormatter($cliFormatter);
        $this->logger->pushHandler($cliHandler);

        $this->logger->info("Running PushToLive!");

        if(file_exists("/config/config")) {
            $this->config = array_merge($this->defaultConfig, parse_ini_file("/config/config"));
            $this->logger->debug("Loaded overriding config file.");
        }else{
            $this->config = $this->defaultConfig;
        }

        $this->readCredentials();
        $this->guzzle = new Guzzle([
            "base_uri" => $this->config['ENDPOINT'],
            "headers" => [
                "Access-Key" => $this->credentials['ACCESS_KEY'],
                "Secret-Key" => $this->credentials['SECRET_KEY'],
            ],
            'curl' => [CURLOPT_RESOLVE => ['api.pushto.local:9090:172.17.0.1']],
            'debug' => $this->config['CURL_DEBUG'] == 'yes',
        ]);

        $this->validateCredentials();
    }

    public function readCredentials() : void {
        if(!file_exists("/config/credentials")){
            $this->logger->critical("Cannot find credentials file!");
            exit(1);
        }
        $this->credentials = parse_ini_file("/config/credentials");
    }

    public function validateCredentials() : void {
        $whoami = $this->guzzle->post("v0/whoami");
        $whoami = json_decode($whoami->getBody()->getContents());
        if($whoami->Status != 'Okay'){
            $this->logger->critical($whoami->Reason);
            exit(1);
        }
        $this->identityUsername = $whoami->Username;
        $this->identityEmail = $whoami->Email;
        $this->identityOrgName = $whoami->OrgName;
        $this->logger->info(sprintf("Hello, '%s' from '%s'!", $this->identityUsername, $this->identityOrgName));
    }

    public function readApp() : void {
        $fullFilePath = sprintf("/context/%s", $this->appYamlPath);
        if(!file_exists($fullFilePath)){
            $this->logger->critical(sprintf("Cant find %s", $this->appYamlPath));
        }
        $this->appYaml = Yaml::parseFile($fullFilePath);
    }

    public function deployApp() : void {
        $this->logger->info(sprintf("Submitting '%s' to deploy", $this->appYaml['name']));
        $deployResponse = $this->guzzle->put("v0/deploy", ['body' => Yaml::dump($this->appYaml)]);

        $deployResponse = $deployResponse->getBody()->getContents();
        \Kint::dump($deployResponse);

        $deployResponse = json_decode($deployResponse);

        if(!$deployResponse->Status == 'Okay'){
            $this->logger->critical(sprintf("Failed to deploy!"));
            if(isset($deployResponse->Reason)){
                $this->logger->critical($deployResponse->Reason);
            }
            \Kint::dump($deployResponse);
            exit(1);
        }
        \Kint::dump($deployResponse);
        $this->logger->info("Services deploying:");
        foreach($deployResponse->Services as $service){
            $this->logger->info(sprintf(" > %s", $service->Name));
        }
    }

    public function run() : void {
        $this->readApp();
        $this->deployApp();
    }
}