<?php

namespace App\Classes;

use App\Exceptions\ClientInterfaceException;
use Curl\Curl;

class SslApi
{

    private $baseUrl = "https://ssl.ws/api/v1/bot/";

    private function callAPI($url, $method = 'GET', $data = [])
    {
        $curl = new Curl();
        if ($method === 'POST') {
            $response = $curl->post($url, $data);
        } else {
            $response = $curl->get($url, $data);
        }
        $response = json_decode(json_encode($response), true, 512, JSON_THROW_ON_ERROR);
        $apiResponse = $response;
        if (is_null($apiResponse)) {
            throw ClientInterfaceException::cliException('Api Request: An unknown error has occurred .');
        }
        if (isset($apiResponse['success']) && !$apiResponse['success']) {
            throw ClientInterfaceException::cliException($apiResponse['message']);
        }
        return $apiResponse;
    }

    /**
     * @throws ClientInterfaceException
     */
    public function getOrderInfo($code, $update)
    {
        $url = $this->baseUrl . 'order/' . $code . '/' . $update . '/info';
        return $this->getResponseData($this->callAPI($url));
    }

    /**
     * @throws ClientInterfaceException
     */
    public function generateCsr($code)
    {
        $url = $this->baseUrl . 'order/' . $code . '/csr';
        return $this->getResponseData($this->callAPI($url));

    }

    public function csrDecode($code, $csr)
    {
        $url = $this->baseUrl . 'order/' . $code . '/csr_decode';
        $postData = [
            'csr' => $csr
        ];
        return $this->getResponseData($this->callAPI($url, 'POST', $postData));
    }

    public function crtDecode($code, $crt)
    {
        $url = $this->baseUrl . 'order/' . $code . '/crt_decode';
        $postData = [
            'certificate' => $crt
        ];
        return $this->getResponseData($this->callAPI($url, 'POST', $postData));
    }

    /**
     * @throws ClientInterfaceException
     */
    public function issueOrReissue($code, $csr)
    {
        $url = $this->baseUrl . 'order/' . $code . '/issue_reissue';

        $postData = [
            'csr' => $csr
        ];
        return $this->getResponseData($this->callAPI($url, 'POST', $postData));
    }

    private function getResponseData($response)
    {
        return $response['data'] ?? null;
    }


}
