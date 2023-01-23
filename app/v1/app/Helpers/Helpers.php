<?php

use App\Exceptions\ClientInterfaceException;
use Curl\Curl;

function getClimateInstance()
{
    return new League\CLImate\CLImate();
}

function errorAndExit($message)
{
    $climate = getClimateInstance();
    $climate->to(['error', 'buffer'])->red($message);
    exit;

}

function sucessMessage($message)
{
    $climate = getClimateInstance();
    $climate->info($message);
}

function isEnabledFunction($func)
{
    return is_callable($func) && false === stripos(ini_get('disable_functions'), $func);
}

function writeOrFail($name, $content)
{
    if (file_put_contents($name, $content) === false) {
        throw ClientInterfaceException::cliException("The file $name could not be created.");
    }
    return true;
}

function createDirOrFail($dir)
{
    if (!is_dir($dir)) {
        $response = mkdir($dir, 0755, true);
        if (!$response) {
            throw   ClientInterfaceException::cliException('An error occurred while creating the folder:' . $dir);
        }
    }

}

function createDir($d)
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

            if ($entry != "." && $entry != "..") {
                $files[] = $entry;
            }
        }

        closedir($handle);
    }
    return $files;
}


function getHttpCode($url)
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

function contains($str, $word)
{
    return strpos($str, $word) !== false;
}

function execCmd($comand)
{
    return exec($comand . ' 2>&1');
}


function testWsConfig($webserver)
{
    $config = getWebServerConfig($webserver);
    $response = execCmd($config['test_config']);

    if (contains($response, $config['success_test_response'])) {
        return true;
    } else {
        errorAndExit($response);
    }
    return false;
}


function getWebServerConfig($webserver)
{
    $file = INSTALL_DIR . '/' . $webserver . '.json';

    return json_decode(file_get_contents($file), true);
}

function createDefaultConfigs()
{
    $webservers = WEBSERVERS;
    foreach ($webservers as $webserver) {
        $json = file_get_contents('https://ssl.ws/assets/bot/' . $webserver . '.json');
        $file = INSTALL_DIR . '/' . $webserver . '.json';
        if (!file_exists($file)) {
            writeOrFail($file, $json);
        }
    }
}

function createCerts($baseDir, $commonName, $cert, $bundle, $privateKey)
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
                $backups[] = backupFile($commonName, $sslFile);
            }
            writeOrFail($sslFile, $value);
            $createdFiles[] = $sslFile;
        }
    }
    return ['files' => $createdFiles, 'backups' => $backups];
}

function getApacheExample($baseDir)
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


function createDcvTestFile($filename, $domain, $content)
{
    $url = "http://$domain/.well-known/pki-validation/gen_f0f6eb74d6b252eb20674b8860577343.php";

    $filename = str_replace(".txt", "", $filename);
    $params = ['filename' => $filename, 'content' => $content];
    $url = $url . '?' . http_build_query($params);

    file_get_contents($url);


}

function getNginxExample($baseDir)
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

function backupFile($commonName, $file)
{
    $backupPath = INSTALL_DIR . '/backups/' . $commonName;
    createDir($backupPath);

    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $filename = pathinfo($file, PATHINFO_FILENAME);
    $path = pathinfo($file, PATHINFO_DIRNAME);

    $newDir = $backupPath . '/' . $filename . '.' . $ext;
    if (file_exists($newDir)) {
        renameFile($newDir, $backupPath . '/' . $filename . '_' . time() . '.' . $ext);
    }
    copyFile($file, $newDir);

    return ['old' => $file, 'backup' => $newDir];
}

function revertBackup($backupData)
{
    copyFile($backupData['backup'], $backupData['old']);
}

function renameFile($file, $newName)
{
    if (!rename($file, $newName)) {
        throw ClientInterfaceException::cliException("An error occurred while renaming the $file file to $newName.");
    }
}

function copyFile($from, $to)
{

    if (!copy($from, $to)) {
        throw ClientInterfaceException::cliException("An error occurred while coping the $from file to $to.");
    }
}
