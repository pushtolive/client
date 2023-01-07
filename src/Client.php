<?php
namespace PushToLive\Client;

use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Bramus\Monolog\Formatter\ColorSchemes\TrafficLight;
use ChrisUllyott\FileSize;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\ZipArchive\FilesystemZipArchiveProvider;
use League\Flysystem\ZipArchive\ZipArchiveAdapter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use GuzzleHttp\Client as Guzzle;
use Symfony\Component\Yaml\Yaml;
use Env\Env;
use GuzzleHttp\Psr7;

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

    protected function zipPackPath(string $path) : string {
        // Create access to local filesystem
        $onDisk = new LocalFilesystemAdapter($path);
        $onDiskFs = new Filesystem($onDisk);

        // Create the zippack
        $zipPackFileName = tempnam("/tmp", "ptlz_") . ".zip";
        $archiveProvider = new FilesystemZipArchiveProvider($zipPackFileName);
        $zipPack = new ZipArchiveAdapter($archiveProvider);
        $zipPackFs = new Filesystem($zipPack);

        $ignoreListGlobs = [
            ".git/*",
            ".github/*",
            ".gitignore",
            ".gitmodules",
            "ptl.yml",
        ];

        // Iterate files locally and stuff them in the zippack
        foreach($onDiskFs->listContents("/", Filesystem::LIST_DEEP) as $file){
            /** @var $file FileAttributes */
            $ignore = false;
            // If the file matches our list of ignore globs, ignore it
            foreach($ignoreListGlobs as $glob){
                if(fnmatch($glob, $file->path())){
                    $ignore = true;
                }
            }
            // If the file is actually a directory, ignore it
            if($file->isDir()){
                $ignore = true;
            }

            // If we're ignoring it, jump out now.
            if($ignore){
                continue;
            }

            // Alls cool, ship it
            $this->logger->debug(sprintf(" > Found %s", $file->path()));
            $zipPackFs->writeStream($file->path(), $onDiskFs->readStream($file->path()));
        }

        // Force save to disk
        unset($zipPackFs, $zipPack);

        return $zipPackFileName;
    }
    protected function findZipPacks() : array
    {
        $zipPacks = [];
        foreach ($this->appYaml['services'] as $serviceName => $configuration) {
            if(isset($configuration['build'])){
                $this->logger->info(sprintf("Found path to zippack: %s", realpath($configuration['build'])));
                $path = $this->zipPackPath(realpath($configuration['build']));
                $size = new FileSize(filesize($path));
                $this->logger->debug(sprintf("  > Resulting Zippack is %s",$size->asAuto()));
                $zipPacks[$serviceName] = [
                    'name' => $configuration['build'],
                    //'contents' => file_get_contents($path),
                    'contents' => base64_encode(file_get_contents($path)),
                    //'filename' => basename($path),
                ];
            }
        }
        return $zipPacks;
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

        // Scan for paths to zip-pack.
        foreach($this->findZipPacks() as $service => $zipPack){
            $this->appYaml['services'][$service]['build'] = [
                'context' => $this->appYaml['services'][$service]['build'],
                'zippack' => $zipPack['contents'],
            ];
        }

        \Kint::dump($this->appYaml);

        // Send deploy to server
        $this->logger->info("Sending request to PushTolive...");
        try {
            $deployBody = Yaml::dump($this->appYaml);
            $deployResponse = $this->guzzle->put("v0/deploy", [
                'body' => $deployBody,
                'debug' => true
            ]);
        }catch(ServerException $serverException){
            $this->logger->critical($serverException->getResponse()->getBody());
            exit(1);
        }

        $deployResponseJson = $deployResponse->getBody()->getContents();

        $deploy = json_decode($deployResponseJson);
        if(!$deploy->Status == 'Okay'){
            $this->logger->critical(sprintf("Failed to deploy!"));
            if(isset($deploy->Reason)){
                $this->logger->critical($deploy->Reason);
            }
            \Kint::dump($deploy);
            exit(1);
        }

        $this->logger->info("Services deploying:");
        foreach($deploy->Services as $service){
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