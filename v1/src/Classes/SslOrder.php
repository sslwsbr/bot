<?php

namespace App\Classes;

use App\Exceptions\ClientInterfaceException;
use App\Helpers\Utils;
use League\CLImate\CLImate;

class SslOrder
{
    private $order;
    private $api;
    private $cliParams;
    private $cliMate;
    private Utils $utils;


    public function __construct($order, $cliParams)
    {
        $this->order = $order;
        $this->api = new SslApi();
        $this->cliParams = $cliParams;
        $this->cliMate = new CLImate();
        $this->utils = Utils::getInstance();
    }

    public function refreshOrderData($update = 0): void
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

    public function isWaitingSetup(): bool
    {
        return $this->order['status'] === 'Waiting for Setup' || is_null($this->order['csr']);
    }

    public function getDir(): string
    {
        $commonName = $this->getCommonName();
        $webserverConfig = $this->getWebServerData();
        return $webserverConfig['base_dir'] . '/ssl/bot/' . $commonName . '_' . $this->getShortDownloadCode();
    }

    public function getCsrDir(): string
    {
        return $this->getDir() . '/crt/csr.csr';
    }

    public function getPkDir(): string
    {
        return $this->getDir() . '/crt/private.key';
    }

    public function getCertificateDir(): string
    {
        return $this->getDir() . '/crt/certificate.crt';
    }

    public function getCaBundleDir(): string
    {
        return $this->getDir() . '/crt/ca-bundle.crt';
    }

    public function canGenerateNewCsr(): bool
    {
        $csrDir = $this->getCsrDir();
        $pkDir = $this->getPkDir();
        return !(file_exists($csrDir) && file_exists($pkDir));
    }

    /**
     * @throws ClientInterfaceException
     */
    public function generateNewCsr(): void
    {
        $code = $this->getDownloadCode();
        $response = $this->api->generateCsr($code);
        if (!isset($response['csr'], $response['private_key']) || is_null($response)) {
            throw ClientInterfaceException::errorGeneratingCsr();
        }
        $this->utils->createDir($this->getDir());
        $this->utils->writeOrFail($this->getPkDir(), $response['private_key']);
        $this->utils->writeOrFail($this->getCsrDir(), $response['csr']);
    }

    /**
     * @throws ClientInterfaceException
     */
    public function getCsr()
    {
        if ($this->canGenerateNewCsr()) {
            $this->generateNewCsr();
        }
        return file_get_contents($this->getCsrDir());

    }

    /**
     * @throws ClientInterfaceException
     */
    public function process(): void
    {
        $this->getCommonNameOrFail();
        $csr = $this->getCsr();
        $this->api->getOrderInfo($this->getDownloadCode(), 1);

        $data = $this->api->issueOrReissue($this->getDownloadCode(), $csr);
        if (is_null($data)) {
            throw new ClientInterfaceException("An error occurred while issuing/remitting the SSL certificate.");
        }
        $this->processDcvData($data);

    }

    private function waitingDcvValidation(): bool
    {
        $status = ['Sending To Vendor', 'Awaiting Validation'];
        if (in_array($this->order['status'], $status, true)) {
            return true;
        }
        return false;
    }

    /**
     * @throws ClientInterfaceException
     */
    private function processDcvData($data): void
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
            $this->utils->createDir($directory);
            if (in_array($dcv, $allowedParams, true)) {
                $filename = str_replace(".txt", "", $dcvData[$dcv]['filename']) . ".txt";
                $content = $dcvData[$dcv]['content'];
                $dcvFile = $directory . '/' . $filename;
                $link = $dcvData[$dcv]['link'];
                if (!file_exists($dcvFile)) {
                    $this->utils->writeOrFail($dcvFile, $content);
                }
                sleep(5);
                $httpCode = (int)$this->utils->getHttpCode($link);
                if ($httpCode >= 400) {
                    throw new ClientInterfaceException("The validation file has been created but is not publicly accessible. Check that the public folder is correct and that the domain is online and pointing to this server.(" . $httpCode . ')');
                }
            }
        }
        $this->updateCertificate();
    }

    public function updateCertificate(): void
    {

        $this->refreshOrderData();
        if ($this->waitingDcvValidation()) {
            $orderInfo = $this->downloadCertificate($this->getDownloadCode());
            if (!$orderInfo || empty($orderInfo['crt_code'])) {
                throw ClientInterfaceException::cliException("An error occurred while downloading. Try again later.");
            }
        }
        $localCertificate = @file_get_contents($this->getCertificateDir());
        $localCsr = file_get_contents($this->getCsrDir());
        if (!$localCsr) {
            $this->utils->errorAndExit("CSR not found.");
        }
        $orderInfo = $this->api->getOrderInfo($this->getDownloadCode(), 0);
        $remoteCertificate = $orderInfo['crt_code'];
        $remoteCsr = $orderInfo['csr'];
        $remoteCaBundle = $orderInfo['ca_code'];
        $webserverConfig = $this->getWebServerData();
        $commonName = $this->getCommonName();
        $privateKey = file_get_contents($this->getPkDir());
        if (!$privateKey) {
            $this->utils->errorAndExit("Private key not found.");
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
                        $this->utils->errorAndExit("Invalid local certificate serial.");
                    }
                    $certificateSerials[$type] = $crtResponse['serial'];
                }
            }


            $localCsrData = $this->api->csrDecode($this->getDownloadCode(), $localCsr);
            if (!isset($localCsrData['md5_hash'])) {
                $this->utils->errorAndExit("Invalid local csr.");
            }
            $localCsrId = $localCsrData['md5_hash'];
            $remoteCsrId = $orderInfo['csr_code'];
            if (empty($localCertificate) && $localCsrId == $remoteCsrId) {
                $fileData = $this->utils->createCerts($this->getDir(), $commonName, $remoteCertificate, $remoteCaBundle, $privateKey);
                if (isset($fileData['files'])) {
                    $this->completeProcess($this->getCommonName());
                }
            } else if ($certificateSerials['local'] != $certificateSerials['remote'] && $remoteCsrId == $localCsrId) {

                $fileData = $this->utils->createCerts($this->getDir(), $commonName, $remoteCertificate, $remoteCaBundle, $privateKey);
                if (isset($fileData['files'])) {
                    if ($this->utils->testWsConfig($this->cliParams['w'])) {
                        $this->utils->execCmd($webserverConfig['reload_command']);
                        $this->completeProcess($this->getCommonName());
                    } else {
                        foreach ($fileData['backups'] as $backup) {
                            $this->utils->revertBackup($backup);
                        }
                    }
                }
            }
        } else {
            $this->utils->errorAndExit("Certificate not found.");
        }


    }

    public function downloadCertificate($code)
    {
        $attempts = 1;
        $successValidation = false;
        while ($attempts <= 10 && $successValidation === false) {
            $this->cliMate->info('Awaiting certification authority verification. Attemp: ' . $attempts);
            $progress = $this->cliMate->progress()->total(120);
            for ($i = 0; $i <= 120; $i++) {
                $progress->current($i);
                if ($i % 10 == 0) {
                    $sslApi = new SslApi();
                    $orderInfo = $sslApi->getOrderInfo($code, 1);
                    $this->order = $orderInfo;
                    if (isset($orderInfo['crt_code']) && !$this->waitingDcvValidation()) {
                        $successValidation = true;
                        break;
                    }
                } else {
                    sleep(1);
                }
            }
            $attempts++;
        }
        if ($successValidation) {
            return $orderInfo;
        }
        return false;
    }

    /**
     * @throws \JsonException
     */
    public function getWebServerData()
    {
        return $this->utils->getWebServerConfig($this->cliParams['w']);
    }

    public function completeProcess($commonName): void
    {
        $webserverConfig = $this->getWebServerData();
        $sslDir = $this->getDir();
        $this->utils->successMessage("Your certificate was successfully issued.");
        $this->utils->successMessage("It was saved in $sslDir");
        $this->utils->successMessage("Follow the example block below to change your site's configuration file.");
        if ($this->cliParams['w'] === 'nginx') {
            echo $this->utils->getNginxExample($sslDir);
        } else {
            echo $this->utils->getApacheExample($sslDir);
        }
    }
}
