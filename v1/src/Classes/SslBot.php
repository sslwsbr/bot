<?php

namespace App\Classes;

use App\Exceptions\ClientInterfaceException;
use App\Helpers\Utils;
use League\CLImate\CLImate;

class SslBot
{
    private CLImate $cliMate;
    public array $params = [
        'w',
        'c',
        'p',
        'd'
    ];
    public array $helParams = [
        'help',
        'version'
    ];
    public string $code;

    private Utils $utils;

    public function __construct()
    {
        $this->cliMate = new CLImate();
        $this->utils = Utils::getInstance();
    }


    public function getParamsInfos(): array
    {
        $helpInfo = [];
        $helpInfo['w'] = "Your server name. Ex: nginx.";
        $helpInfo['c'] = "The download code provided by your SSL certificate provider.";
        $helpInfo['p'] = "This option defines where your site's public folder is located.";
        $helpInfo['v'] = "Displays the current core version.";
        return $helpInfo;
    }

    private function getFormatedParams(): array
    {
        $paramsList = [];
        foreach ($this->params as $param) {
            $paramsList[] = "$param:";
        }
        foreach ($this->helParams as $param) {
            $paramsList[] = "$param";
        }
        return $paramsList;
    }

    public function getParamsValue()
    {

        return getopt(null, $this->getFormatedParams());
    }

    private function createParams(): void
    {
        foreach ($this->getParamsValue() as $index => $param) {
            $this->{$index} = $param;
        }
    }

    private function getOnlyParam(string $param)
    {
        $opts = getopt(null, $this->getFormatedParams());
        return $opts[$param] ?? null;
    }

    private function validateParams(): void
    {
        $params = $this->getParamsValue();
        if (isset($params['help']) || count($params) === 0) {
            foreach ($this->getParamsInfos() as $index => $paramInfo) {
                $this->cliMate->info("--$index : $paramInfo");
            }
            exit;
        }
        if (isset($params['version'])) {
            $this->cliMate->info(APP_NAME);
            $this->cliMate->info("Version:" . APP_VERSION);
            $this->cliMate->info("Site: ssl.ws");
            exit;
        }
        $requiredParams = ['c', 'w', 'p'];
        foreach ($requiredParams as $p) {
            if (!isset($params[$p]) || empty($params[$p])) {
                $msg = "The --$p parameter is required.";
                throw ClientInterfaceException::cliException($msg);
            }
        }
        if (!in_array($params['w'], WEBSERVERS)) {
            $msg = "Invalid web server. Accepted servers are: apache and nginx.";
            throw ClientInterfaceException::cliException($msg);
        }
        if (!is_dir($params['p'])) {
            $msg = 'The directory "' . $params['p'] . '" does not exist.';
            throw ClientInterfaceException::cliException($msg);
        }
        if (!$this->utils->isEnabledFunction('exec')) {
            $msg = "The exec function must be active.";
            throw ClientInterfaceException::cliException($msg);
        }
        $this->utils->createDefaultConfigs();

    }

    public function run()
    {
        try {
            $this->utils->successMessage("SSL.WS - V1.1");
            $this->utils->createDirOrFail(INSTALL_DIR);
            $this->createParams();
            $this->validateParams();
            $this->code = $this->getOnlyParam('c');
            $sslApi = new SslApi();
            $orderData = $sslApi->getOrderInfo($this->code, 0);
            $sslOrder = new SslOrder($orderData, $this->getParamsValue());
            $sslOrder->process();
        } catch (\Exception $ex) {
            $this->utils->errorAndExit($ex->getMessage() . ':' . $ex->getFile() . ':' . $ex->getLine());
        }
    }

}

