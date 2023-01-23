<?php

namespace App\Classes;

use League\CLImate\CLImate;
use App\Exceptions\ClientInterfaceException;

class SslBot
{
    private $cliMate;
    public $params = [
        'w',
        'c',
        'p',
        'd'
    ];
    public $helParams = [
        'help',
        'version'
    ];
    public $ws;
    public $code;

    public $help;
    public $version = '1.0';

    public function __construct()
    {
        $this->cliMate = new CLImate();
    }


    public function getParamsInfos()
    {
        $helpInfo = [];
        $helpInfo['w'] = "Your server name. Ex: nginx.";
        $helpInfo['c'] = "The download code provided by your SSL certificate provider.";
        $helpInfo['p'] = "This option defines where your site's public folder is located.";
        $helpInfo['v'] = "Displays the current core version.";
        return $helpInfo;
    }

    private function getFormatedParams()
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

    private function createParams()
    {
        foreach ($this->getParamsValue() as $index => $param) {
            $this->{$index} = $param;
        }
    }

    private function getOnlyParam($param)
    {
        $opts = getopt(null, $this->getFormatedParams());
        if (isset($opts[$param])) {
            return $opts[$param];
        }
        return null;
    }

##sudo sslws_bot --w=apache2 --c=59b043fb1684046387a862bb408e426de204bcebb2bbe406f03f6a19f9659163 --p=/var/www/html

    private function validateParams()
    {
        $params = $this->getParamsValue();
        if (isset($params['help']) || count($params) == 0) {
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
        if (isset($params['c']) && !isset($params['w']) && $this->checkIfConfigured($params['c'])) {
            return null;
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
        if (!isEnabledFunction('exec')) {
            $msg = "The exec function must be active.";
            throw ClientInterfaceException::cliException($msg);
        }
        createDefaultConfigs();

    }

    public function run()
    {
        try {
            sucessMessage("SSL.WS - V1.1");
            createDirOrFail(INSTALL_DIR);
            $this->createParams();
            $this->validateParams();
            $this->code = $this->getOnlyParam('c');
            $sslApi = new SslApi();
            $orderData = $sslApi->getOrderInfo($this->code, 0);
            $sslOrder = new SslOrder($orderData, $this->getParamsValue());
            $sslOrder->proccess();
        } catch (\Exception $ex) {
            errorAndExit($ex->getMessage());
        }
    }

}

