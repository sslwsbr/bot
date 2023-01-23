<?php

namespace App\Classes;

use App\Exceptions\ClientInterfaceException;
use League\CLImate\CLImate;

class SslOrder
{
    private $order;
    private $api;
    private $cliParams;
    private $cliMate;

    public function __construct($order, $cliParams)
    {
        $this->order = $order;
        $this->api = new SslApi();
        $this->cliParams = $cliParams;
        $this->cliMate = new CLImate();
    }

    public function refreshOrderData($update = 0)
    {
        $this->order = $this->api->getOrderInfo($this->order['download_code'], $update);

    }

    public function getDownloadCode()
    {
        return $this->order['download_code'];
    }

    public function getShortDownloadCode()
    {
        return substr($this->order['download_code'], 1, 6);
    }

    public function getCommonNameOrFail()
    {
        if (is_null($this->order)) {
            throw new ClientInterfaceException("Order not found.");
        }

        if (!isset($this->order['common_name']) || empty($this->order['common_name'])) {
            throw new ClientInterfaceException("There is no domain linked to this order.");
        }
        return $this->order['common_name'];
    }

    public function getCommonName()
    {
        return $this->order['common_name'];
    }

    public function isWaitingSetup()
    {
        if ($this->order['status'] == 'Waiting for Setup' || is_null($this->order['csr'])) {
            return true;
        }
        return false;
    }

    public function getDir()
    {
        $commonName = $this->getCommonName();
        $webserverConfig = $this->getWebServerData();
        return $webserverConfig['base_dir'] . '/ssl/bot/' . $commonName . '_' . $this->getShortDownloadCode();
    }

    public function getCsrDir()
    {
        return $this->getDir() . '/crt/csr.csr';
    }

    public function getPkDir()
    {
        return $this->getDir() . '/crt/private.key';
    }

    public function getCertificateDir()
    {
        return $this->getDir() . '/crt/certificate.crt';
    }

    public function getCaBundleDir()
    {
        return $this->getDir() . '/crt/ca-bundle.crt';
    }

    public function canGenerateNewCsr()
    {
        $csrDir = $this->getCsrDir();
        $pkDir = $this->getPkDir();
        if (file_exists($csrDir) && file_exists($pkDir)) {
            return false;
        }
        return true;
    }

    public function generateNewCsr()
    {
        $code = $this->getDownloadCode();
        $response = $this->api->generateCsr($code);
        if (is_null($response) || !isset($response['csr']) || !isset($response['private_key'])) {
            throw ClientInterfaceException::errorGeneratingCsr();
        }
        createDir($this->getDir());
        writeOrFail($this->getPkDir(), $response['private_key']);
        writeOrFail($this->getCsrDir(), $response['csr']);
    }

    public function getCsr()
    {
        if ($this->canGenerateNewCsr()) {
            $this->generateNewCsr();
        }
        return file_get_contents($this->getCsrDir());

    }

    public function proccess()
    {
        $this->getCommonNameOrFail();
        $csr = $this->getCsr();
        $data = $this->api->getOrderInfo($this->getDownloadCode(), 1);

        $data = $this->api->issueOrReissue($this->getDownloadCode(), $csr);
        if (is_null($data)) {
            throw new ClientInterfaceException("An error occurred while issuing/remitting the SSL certificate.");
        }
        $this->processDcvData($data);

    }

    private function waitingDcvValidation()
    {
        $status = ['Sending To Vendor', 'Awaiting Validation'];
        if (in_array($this->order['status'], $status)) {
            return true;
        }
        return false;
    }

    private function processDcvData($data)
    {
        $this->refreshOrderData();
        $publicFolder = $this->cliParams['p'];
        if ($this->waitingDcvValidation()) {
            $commonName = $this->getCommonName();
            if (!isset($data['dcv_data'][$commonName])) {
                throw new ClientInterfaceException("DCV data not found.");
            }
            if (!isset($data['dcv_data'][$commonName]['validations'])) {
                throw new ClientInterfaceException("DCV data not found.");

            }
            $dcvData = $data['dcv_data'][$commonName]['validations'];
            $allowedParams = ['http', 'https'];
            $directory = $publicFolder . '/.well-known/pki-validation/';
            $dcv = $data['dcv_data'][$commonName]['dcv_method'];
            createDir($directory);
            if (in_array($dcv, $allowedParams)) {
                $filename = str_replace(".txt", "", $dcvData[$dcv]['filename']) . ".txt";
                $content = $dcvData[$dcv]['content'];
                $dcvFile = $directory . '/' . $filename;
                $link = $dcvData[$dcv]['link'];
                createDcvTestFile($dcvData[$dcv]['filename'], $commonName, $content);
                if (!file_exists($dcvFile)) {
                    writeOrFail($dcvFile, $content);
                }
                $httpCode = intval(getHttpCode($link));
                if ($httpCode >= 400) {
                    throw new ClientInterfaceException("The validation file has been created but is not publicly accessible. Check that the public folder is correct and that the domain is online and pointing to this server.");
                }
            }
        }
        $this->updateCertificate();
    }

    public function updateCertificate()
    {

        $this->refreshOrderData();
        if ($this->waitingDcvValidation()) {
            $orderInfo = $this->downloadCertificate($this->getDownloadCode());
            if (!$orderInfo || !isset($orderInfo['crt_code']) || empty($orderInfo['crt_code'])) {
                throw ClientInterfaceException::cliException("An error occurred while downloading. Try again later.");
            }
        }
        $localCertificate = @file_get_contents($this->getCertificateDir());
        $localCsr = file_get_contents($this->getCsrDir());
        if (!$localCsr) {
            errorAndExit("CSR not found.");
        }
        $orderInfo = $this->api->getOrderInfo($this->getDownloadCode(), 0);
        $remoteCertificate = $orderInfo['crt_code'];
        $remoteCsr = $orderInfo['csr'];
        $remoteCaBundle = $orderInfo['ca_code'];
        $webserverConfig = $this->getWebServerData();
        $commonName = $this->getCommonName();
        $privateKey = file_get_contents($this->getPkDir());
        if (!$privateKey) {
            errorAndExit("Private key not found.");
        }
        if (!empty($remoteCertificate)) {

            $certificateSerials = [
                'local' => null,
                'remote' => null
            ];

            $certificates = [
                'local' => $localCertificate,
                'remote' => $remoteCertificate
            ];
            if (!empty($localCertificate)) {
                foreach ($certificates as $type => $crt) {
                    $crtResponse = $this->api->crtDecode($this->getDownloadCode(), $crt);
                    if (!isset($crtResponse['serial']) || is_null($crtResponse['serial'])) {
                        errorAndExit("Invalid local certificate serial.");
                    }
                    $certificateSerials[$type] = $crtResponse['serial'];
                }
            }


            $localCsrData = $this->api->csrDecode($this->getDownloadCode(), $localCsr);
            if (!isset($localCsrData['md5_hash'])) {
                errorAndExit("Invalid local csr.");
            }
            $localCsrId = $localCsrData['md5_hash'];
            $remoteCsrId = $orderInfo['csr_code'];
            if (empty($localCertificate) && $localCsrId == $remoteCsrId) {
                $fileData = createCerts($this->getDir(), $commonName, $remoteCertificate, $remoteCaBundle, $privateKey);
                if (isset($fileData['files'])) {
                    $this->completeProcess($this->getCommonName());
                }
            } else if ($certificateSerials['local'] != $certificateSerials['remote'] && $remoteCsrId == $localCsrId) {

                $fileData = createCerts($this->getDir(), $commonName, $remoteCertificate, $remoteCaBundle, $privateKey);
                if (isset($fileData['files'])) {
                    if (testWsConfig($this->cliParams['w'])) {
                        execCmd($webserverConfig['reload_command']);
                        $this->completeProcess($this->getCommonName());
                    } else {
                        foreach ($fileData['backups'] as $backup) {
                            revertBackup($backup);
                        }
                    }
                }
            }

        } else {
            errorAndExit("Certificate not found.");
        }


    }

    public function downloadCertificate($code)
    {
        $attemps = 1;
        $sucessValidation = false;
        while ($attemps <= 10 && $sucessValidation === false) {
            $this->cliMate->info('Awaiting certification authority verification. Attemp: ' . $attemps);
            $progress = $this->cliMate->progress()->total(120);
            for ($i = 0; $i <= 120; $i++) {
                $progress->current($i);
                if ($i % 10 == 0) {
                    $sslApi = new SslApi();
                    $orderInfo = $sslApi->getOrderInfo($code, 1);
                    $this->order = $orderInfo;
                    if (isset($orderInfo['crt_code']) && !$this->waitingDcvValidation()) {
                        $sucessValidation = true;
                        break;
                    }
                } else {
                    sleep(1);
                }
            }
            $attemps++;
        }
        if ($sucessValidation) {
            return $orderInfo;
        }
        return false;
    }

    public function getWebServerData()
    {
        return getWebServerConfig($this->cliParams['w']);
    }

    public function completeProcess($commonName)
    {
        $webserverConfig = $this->getWebServerData();
        $sslDir = $this->getDir();
        sucessMessage("Your certificate was successfully issued.");
        sucessMessage("It was saved in $sslDir");
        sucessMessage("Follow the example block below to change your site's configuration file.");
        if ($this->cliParams['w'] == 'nginx') {
            echo getNginxExample($sslDir);
        } else {
            echo getApacheExample($sslDir);
        }
        //

    }
}
