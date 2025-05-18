<?php

namespace App\Helpers;

use App\Exceptions\ClientInterfaceException;
use League\CLImate\CLImate;

class Utils
{
    private static Utils $instance;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getClimateInstance(): CLImate
    {
        return new CLImate();
    }

    public function errorAndExit($message): void
    {
        $climate = $this->getClimateInstance();
        $climate->to(['error', 'buffer'])->red($message);
        exit;

    }

    public function successMessage($message): void
    {
        $climate = $this->getClimateInstance();
        $climate->info($message);
    }

    public function isEnabledFunction($func): bool
    {
        return is_callable($func) && false === stripos(ini_get('disable_functions'), $func);
    }

    /**
     * @throws ClientInterfaceException
     */
    public function writeOrFail($name, $content): bool
    {
        if (file_put_contents($name, $content) === false) {
            throw ClientInterfaceException::cliException("The file $name could not be created.");
        }
        return true;
    }

    /**
     * @throws ClientInterfaceException
     */
    public function createDirOrFail($dir)
    {
        if (!is_dir($dir)) {
            $response = mkdir($dir, 0755, true);
            if (!$response) {
                throw   ClientInterfaceException::cliException('An error occurred while creating the folder:' . $dir);
            }
        }

    }

    /**
     * @throws ClientInterfaceException
     */
    public function createDir(string $d): void
    {
        $dirs = [$d, $d . '/crt', $d . '/pem'];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                $response = mkdir($dir, 0755, true);
                if (!$response) {
                    throw   ClientInterfaceException::cliException('An error occurred while creating the folder:' . $dir);
                }
            }

        }

    }

    function getAllFiles($dir)
    {
        $files = [];
        if ($handle = opendir($dir)) {

            while (false !== ($entry = readdir($handle))) {

                if ($entry !== "." && $entry !== "..") {
                    $files[] = $entry;
                }
            }

            closedir($handle);
        }
        return $files;
    }


    public function getHttpCode($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // Return data inplace of echoing on screen
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); // Skip SSL Verification
        $apiResponse = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpcode;

    }

    public function contains($str, $word): bool
    {
        return strpos($str, $word) !== false;
    }

    public function execCmd(string $command)
    {
        return exec($command . ' 2>&1');
    }


    /**
     * @throws \JsonException
     */
    public function testWsConfig(string $webserver): bool
    {
        $config = $this->getWebServerConfig($webserver);
        $response = $this->execCmd($config['test_config']);

        if ($this->contains($response, $config['success_test_response'])) {
            return true;
        }

        $this->errorAndExit($response);
        return false;
    }

    /**
     * @throws \JsonException
     */
    public function jsonToArray(?string $json): array
    {
        if (is_null($json)) {
            return [];
        }
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws \JsonException
     */
    public function getWebServerConfig($webserver): array
    {
        $file = INSTALL_DIR . '/' . $webserver . '.json';

        return $this->jsonToArray(file_get_contents($file));
    }

    /**
     * @throws ClientInterfaceException
     */
    public function createDefaultConfigs(): void
    {
        $webservers = WEBSERVERS;
        foreach ($webservers as $webserver) {
            $json = file_get_contents('https://ssl.ws/assets/bot/' . $webserver . '.json');
            $file = INSTALL_DIR . '/' . $webserver . '.json';
            if (!file_exists($file)) {
                $this->writeOrFail($file, $json);
            }
        }
    }

    /**
     * @throws ClientInterfaceException
     */
    public function createCerts($baseDir, $commonName, $cert, $bundle, $privateKey): array
    {
        $backups = [];
        $createdFiles = [];

        $sslDir = $baseDir;
        $certsData = [];
        $certsData['pem'] = [
            'fullchain.pem' => $cert . $bundle,
            'private.pem' => $privateKey
        ];
        $certsData['crt'] = [
            'certificate.crt' => $cert,
            'ca-bundle.crt' => $bundle,
            'private.key' => $privateKey,
        ];

        foreach ($certsData as $type => $data) {
            foreach ($data as $fileName => $value) {
                $sslFile = $sslDir . '/' . $type . '/' . $fileName;
                if (file_exists($sslFile)) {
                    $backups[] = $this->backupFile($commonName, $sslFile);
                }
                $this->writeOrFail($sslFile, $value);
                $createdFiles[] = $sslFile;
            }
        }
        return ['files' => $createdFiles, 'backups' => $backups];
    }

    public function getApacheExample($baseDir): string
    {
        $sslDir = $baseDir;
        return "\n
<VirtualHost *:443>
    #Insert above this line your document root, server name and etc.
    SSLEngine on
    SSLCertificateFile      $sslDir/crt/certificate.crt
    SSLCertificateKeyFile       $sslDir/crt/private.key
    SSLCertificateChainFile    $sslDir/crt/ca-bundle.crt
</VirtualHost> \n";
    }


    public function createDcvTestFile($filename, $domain, $content): void
    {
        $url = "http://$domain/.well-known/pki-validation/gen_f0f6eb74d6b252eb20674b8860577343.php";

        $filename = str_replace(".txt", "", $filename);
        $params = ['filename' => $filename, 'content' => $content];
        $url .= '?' . http_build_query($params);
        file_get_contents($url);
    }

    public function getNginxExample($baseDir): string
    {
        $sslDir = $baseDir;
        return "\n
server {
    listen 443;
    #Insert above this line your document root, server name and etc.
    ssl on;
    ssl_certificate $sslDir/pem/fullchain.pem;
    ssl_certificate_key $sslDir/pem/private.pem;
    #Enter your code here
} \n";
    }

    /**
     * @throws ClientInterfaceException
     */
    public function backupFile($commonName, $file): array
    {
        $backupPath = INSTALL_DIR . '/backups/' . $commonName;
        $this->createDir($backupPath);

        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $filename = pathinfo($file, PATHINFO_FILENAME);
        $newDir = $backupPath . '/' . $filename . '.' . $ext;
        if (file_exists($newDir)) {
            $this->renameFile($newDir, $backupPath . '/' . $filename . '_' . time() . '.' . $ext);
        }
        $this->copyFile($file, $newDir);

        return ['old' => $file, 'backup' => $newDir];
    }

    /**
     * @throws ClientInterfaceException
     */
    public function revertBackup($backupData): void
    {
        $this->copyFile($backupData['backup'], $backupData['old']);
    }

    /**
     * @throws ClientInterfaceException
     */
    public function renameFile($file, $newName): void
    {
        if (!rename($file, $newName)) {
            throw ClientInterfaceException::cliException("An error occurred while renaming the $file file to $newName.");
        }
    }

    /**
     * @throws ClientInterfaceException
     */
    public function copyFile($from, $to): void
    {
        if (!copy($from, $to)) {
            throw ClientInterfaceException::cliException("An error occurred while coping the $from file to $to.");
        }
    }

}