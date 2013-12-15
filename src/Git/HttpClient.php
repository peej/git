<?php

namespace Git;

/**
 * HTTP client to send/recieve JSON over HTTP requests
 */
class HttpClient
{
    private $cacheDir;
    private $token;

    private $requestHeaders = array();
    private $responseCode;
    private $responseHeaders = array();
    private $responseBody;

    function __construct($token = null)
    {
        $this->cacheDir = sys_get_temp_dir();
        $this->token = $token;
    }

    public function getCode()
    {
        return $this->responseCode;
    }

    public function getHeaders()
    {
        return $this->responseHeaders;
    }

    public function getHeader($name)
    {
        return isset($this->responseHeaders[$name]) ? $this->getHeaders()[$name] : null;
    }

    public function getBody()
    {
        return $this->responseBody;
    }

    public function send($url, $method = 'GET', $body = null)
    {
        $this->requestHeaders = array();

        if ($this->token) {
            if (strpos($this->token, ':') !== false) {
                $this->requestHeaders['Authorization'] = 'Basic '.base64_encode(trim($this->token));
            } else {
                $this->requestHeaders['Authorization'] = 'Token '.trim($this->token);
            }
        }

        $this->responseCode = 500;
        $this->responseHeaders = array();
        $this->responseBody = null;
        
        $options = array(
            'http' => array(
                'ignore_errors' => true,
                'method' => $method,
                'header' => ""
            )
        );
        $this->requestHeaders['User-Agent'] = 'peej/git';
        if ($body) {
            $options['http']['content'] = json_encode($body);
            $this->requestHeaders['Content-Type'] = 'application/json';
            $cacheFilename = $this->cacheDir.'/peej-git-'.md5($url.$method.$options['http']['content']);
        } else {
            $cacheFilename = $this->cacheDir.'/peej-git-'.md5($url.$method);
        }

        if ($method == 'GET' && file_exists($cacheFilename)) {
            $cache = json_decode(file_get_contents($cacheFilename));
            $this->requestHeaders['If-None-Match'] = $cache->headers->ETag;
            $this->responseBody = $cache->body;
        }

        foreach ($this->requestHeaders as $name => $value) {
            $options['http']['header'] .= $name.': '.$value."\n";
        }

        #var_dump($url, $method);
        #var_dump($body);
        #var_dump($options);
        #var_dump($cacheFilename);

        /*
        $curl = 'curl --data \''.json_encode($body).'\' -X '.$method.' ';
        foreach ($this->requestHeaders as $name => $value) {
            $curl .= '-H "'.$name.': '.trim($value).'" ';
        }
        $curl .= ' '.$url;
        echo $curl."\n";
        //*/
        
        $context = stream_context_create($options);
        $stream = fopen($url, 'r', false, $context);

        foreach (stream_get_meta_data($stream)['wrapper_data'] as $header) {
            $parts = explode(':', $header);
            if (!isset($parts[1])) {
                $this->responseCode = (int)substr($parts[0], 9, 3);
            } else {
                $this->responseHeaders[trim($parts[0])] = trim($parts[1]);
            }
        }

        #var_dump($this->responseCode);

        if ($this->responseCode != 304 || $this->responseBody == null) {
        
            $this->responseBody = json_decode(stream_get_contents($stream));

            if ($this->responseCode >= 400) {
                throw new Exception($url.' returned error '.$this->responseCode.' ('.(isset($this->responseBody->message) ? $this->responseBody->message : '').')', $this->responseCode);
            }

            if (isset($this->responseHeaders['ETag'])) { // write cache file
                file_put_contents($cacheFilename, json_encode(array(
                    'headers' => $this->responseHeaders,
                    'body' => $this->responseBody
                ), JSON_PRETTY_PRINT));
            }

            #var_dump($this->responseHeaders);

            fclose($stream);
        }

/*
        $api = json_decode(@file_get_contents('github-api.json'));
        if (!$api) {
            $api = new \stdClass;
        }
        $sBody = serialize($body);
        $sResponse = serialize($this->responseBody);
        $api->{md5($url.$method.$sBody.$sResponse)} = array(
            'url' => $url,
            'method' => $method,
            'body' => $sBody,
            'response' => $sResponse
        );
        file_put_contents('github-api.json', json_encode($api, JSON_PRETTY_PRINT));
*/

        if ($method == 'PATCH') {
            sleep(10); // wait to let GitHub catch up, not sure if this is a bug, seems to help for now
        }

        #var_dump($this->responseBody);
        return $this->responseBody;
    }

}