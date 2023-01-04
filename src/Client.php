<?php
namespace PushToLive\Client;

use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Bramus\Monolog\Formatter\ColorSchemes\TrafficLight;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use GuzzleHttp\Client as Guzzle;
use Symfony\Component\Yaml\Yaml;
use Env\Env;

class Client{

    protected Logger $logger;
    protected array $credentials;
    protected Guzzle $guzzle;
    protected array $defaultConfig = [];
    protected array $config;
    private string $identityUsername;
    private string $identityEmail;
    private string $identityOrgName;

    private array $appYamlPaths;
    private array $appYaml;

    public function __construct(){
        $this->defaultConfig = [
            'ENDPOINT' => Env::get("ENDPOINT") ?? 'http://api.pushto.live/',
            'CURL_DEBUG' => "no",
        ];
        $this->appYamlPaths = [
            "/app/ptl.yml",
            "/context/ptl.yml",
            Env::get("GITHUB_WORKSPACE") . "/ptl.yml" ?? "/github/workspace/ptl.yml",
        ];
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

        if(file_exists("/config/config")) {
            $this->config = array_merge($this->defaultConfig, parse_ini_file("/config/config"));
            $this->logger->debug("Loaded overriding config file.");
        }else{
            $this->config = $this->defaultConfig;
        }

        $this->logger->info("Running PushToLive!");
        $this->logger->debug(sprintf("Endpoint is %s", $this->config['ENDPOINT']));

        $this->readCredentials();
        $this->guzzle = new Guzzle([
            "base_uri" => $this->config['ENDPOINT'],
            "headers" => [
                "Access-Key" => $this->credentials['ACCESS_KEY'],
                "Secret-Key" => $this->credentials['SECRET_KEY'],
            ],
            'curl' => [
                #CURLOPT_RESOLVE => ['api.pushto.local:80:172.17.0.1']
            ],
            'debug' => $this->config['CURL_DEBUG'] == 'yes',
        ]);

        $this->validateCredentials();
    }

    public function readCredentials() : void {

        if(file_exists("/config/credentials")){
            $this->credentials = parse_ini_file("/config/credentials");
            return;
        }

        if(Env::get('PTL_ACCESS_KEY') && Env::get('PTL_SECRET_KEY')){
            $this->credentials['ACCESS_KEY'] = Env::get('PTL_ACCESS_KEY');
            $this->credentials['SECRET_KEY'] = Env::get('PTL_SECRET_KEY');
            return;
        }

        $this->logger->critical("Cannot find credentials file or ACCESS_KEY and SECRET_KEY!");

        exit(1);
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
        foreach($this->appYamlPaths as $appYamlPath){
            if(file_exists($appYamlPath)){
                $this->logger->debug(sprintf("Found config: %s", $appYamlPath));
                $this->appYaml = Yaml::parseFile($appYamlPath);
                return;
            }
        }
        $this->logger->critical(sprintf("Cant find config in any path: %s", implode(", ", $this->appYamlPaths)));
        exit(1);
    }

    public function doesInstanceExist() : bool{
        try {
            $instanceInfo = $this->guzzle->get(sprintf("v0/projects/%s/%s/%s", $this->appYaml['name'], $this->repoContextType, $this->repoContextName));
        }catch(ServerException $serverException){
            $this->logger->critical($serverException->getResponse()->getBody());
            exit(1);
        }catch(ClientException $clientException){
            if($clientException->getResponse()->getStatusCode() ==404){
                return false;
            }
            throw $clientException;
        }
        //$instanceInfo = $instanceInfo->getBody()->getContents();

        return true;
    }
    public function undeployApp() : void {

        if(!$this->hasRepoContext()){
            $this->logger->critical("Cannot undeploy an app that doesn't have a repo context");
            exit(1);
        }

        $this->logger->info(sprintf("Terminating '%s' (%s %s).", $this->appYaml['name'], $this->repoContextType, $this->repoContextName));

        if(!$this->doesInstanceExist()){
            $this->logger->warning(sprintf("The service we were supposed to terminate (%s %s %s) does not exist.", $this->appYaml['name'], $this->repoContextType, $this->repoContextName));
            return;
        }

        try {
            $undeployResponse = $this->guzzle->delete(sprintf("v0/projects/%s/%s/%s", $this->appYaml['name'], $this->repoContextType, $this->repoContextName));
        }catch(ServerException $serverException){
            $this->logger->critical($serverException->getResponse()->getBody());
            exit(1);
        }
        $undeployResponse = json_decode($undeployResponse->getBody()->getContents());
        if(isset($this->Deleted)) {
            $this->logger->info("Services terminating:");
            foreach ($undeployResponse->Deleted->Service as $service) {
                $this->logger->info(sprintf(" > %s", $service->Name));
            }
        }else{
            $this->logger->debug("Nothing to terminate.");
        }

    }
    public function deployApp() : void {
        $this->logger->info(sprintf("Submitting '%s' to deploy", $this->appYaml['name']));
        if($this->hasRepoContext()){
            $this->logger->info(sprintf(" > Context is %s/%s", $this->repoContextType, $this->repoContextName));
            $this->appYaml['context'] = [
                'type' => $this->repoContextType,
                'name' => $this->repoContextName,
            ];
        }
        try {
            $deployBody = Yaml::dump($this->appYaml);
            $deployResponse = $this->guzzle->put("v0/deploy", ['body' => $deployBody]);
        }catch(ServerException $serverException){
            $this->logger->critical($serverException->getResponse()->getBody());
            exit(1);
        }

        $deployResponse = $deployResponse->getBody()->getContents();

        $deployResponse = json_decode($deployResponse);

        if(!$deployResponse->Status == 'Okay'){
            $this->logger->critical(sprintf("Failed to deploy!"));
            if(isset($deployResponse->Reason)){
                $this->logger->critical($deployResponse->Reason);
            }
            \Kint::dump($deployResponse);
            exit(1);
        }

        $this->logger->info("Services deploying:");
        foreach($deployResponse->Services as $service){
            $this->logger->info(sprintf(" > %s", $service->Name));
        }
    }

    protected ?string $repoContextType = null;
    protected ?string $repoContextName = null;

    protected function hasRepoContext() : bool {
        if($this->repoContextType !== null && $this->repoContextName !== null){
            return true;
        }
        return false;
    }
    private function determineRepositoryContext() : void {
       $this->repoContextType = Env::get("GITHUB_REF_TYPE");
       $this->repoContextName = Env::get("GITHUB_REF_NAME");
    }

    public function run() : void {
        $this->determineRepositoryContext();
        $this->readApp();
        $event = Env::get('GITHUB_EVENT_NAME') ?? 'push';
        switch($event){
            case 'delete':
                $this->undeployApp();
                break;
            case 'push':
                $this->deployApp();
                break;
        }

    }
}