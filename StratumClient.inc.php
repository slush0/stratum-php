<?php

/**
 *  Implementation of HTTP Poll / HTTP Push transports 
 *  of Stratum protocol (http://stratum.bitcoin.cz)
 *
 *  TODO: Detect expired session, provide mechanism
 *  for session reinitalization.
 *
 *  @author: slush <info@bitcoin.cz>
 *  @license: public domain
**/

class StratumClient {

    protected $_host;
    protected $_port;
    protected $_timeout;

    protected $_sock;
    protected $_cookie;
    protected $_session_id;
    protected $_session_timeout_at;

    protected $_request_id;
    protected $_buffer;
    protected $_lookup_table;

    public function __construct($host, $port, $timeout=20) {
        $this->_host = $host;
        $this->_port = $port;
        $this->_timeout = $timeout;

        $this->_sock = null;
        $this->_cookie = null;
        $this->_session_id = null;
        $this->_session_timeout_at = time()-1;

        $this->_request_id = 0;
        $this->_buffer = array();
        $this->_lookup_table = array();
    }

    public function __destruct() {
        $this->_close();
    }

    protected function _close() {
        if ($this->_sock) {
            curl_close($this->_sock);
            $this->_sock = null;
        }
    }

    protected function _buildRequest($method, $args, $request_id) {
        $request = array(
            'id' => $request_id,
            'method' => $method,
            'params' => array_values($args),
        );
        return json_encode($request)."\n";
    }

    protected function _sendRequest($payload, $cookie=null) {
        if (!$this->_sock) {
            $this->_sock = curl_init();
        }

        $sock = $this->_sock;
        curl_setopt($sock, CURLOPT_URL, $this->_host); 
        curl_setopt($sock, CURLOPT_PORT ,$this->_port); 
        curl_setopt($sock, CURLOPT_VERBOSE, 0); 
        curl_setopt($sock, CURLOPT_HEADER, 1); 
        #curl_setopt($sock, CURLOPT_SSLVERSION, 3); 
        #curl_setopt($sock, CURLOPT_SSLCERT, getcwd() . "/client.pem"); 
        #curl_setopt($sock, CURLOPT_SSLKEY, getcwd() . "/keyout.pem"); 
        #curl_setopt($sock, CURLOPT_CAINFO, getcwd() . "/ca.pem"); 
        #curl_setopt($sock, CURLOPT_SSL_VERIFYPEER, 1); 
        curl_setopt($sock, CURLOPT_POST, 1); 
        curl_setopt($sock, CURLOPT_RETURNTRANSFER, 1); 
        curl_setopt($sock, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($sock, CURLOPT_HTTPHEADER, array("Content-Type: application/stratum",
                                                     "Connection: Keep-alive",
                                                     "User-Agent: stratum-php/0.1",
                                                     "Content-Length: ".strlen($payload))); 
        if ($cookie) {
            curl_setopt($sock, CURLOPT_COOKIE, $cookie);
        }

        $response = curl_exec($sock); 
        if(curl_errno($sock)) { 
            echo "CURL ERROR: ".curl_error($sock);
        }

        $headers = curl_getinfo($sock);
        if ($headers['content_type'] != 'application/stratum' || $headers['http_code'] != '200') {
            echo "ERROR PROCESSING HEADERS";
            var_dump($headers);
        }

        return $response;
    }

    protected function _parseCookie($cookie)
    {
        $out = array();
        $cook = explode(";", $cookie);
        foreach($cook as $c){
            $parts = explode("=", $c);
            $out[trim($parts[0])] = trim($parts[1]);
        }
        return $out;
    }

    protected function _parseHeader(&$response)
    {
        /* This is modified method taken from some PHP forum.
           It's strange that standard PHP library is missing such functionality. */

        $response = explode("\n\n", str_replace("\r\n\r\n", "\n\n", $response));
        $headers = explode("\n", str_replace("\r", '', $response[0]));
        $response = $response[1];

        $header_data = array();
        foreach($headers as $value){
            $header = explode(": ",$value);
            if($header[0] && !$header[1]) {
                $header_data['http_code'] = $header[0];
            }
            elseif($header[0] && $header[1]){
                $header_data[strtolower($header[0])] = $header[1];
            }
        }
        return $header_data;
    }

    protected function _processResponse($payload) {
        # Read line by line
        #return json_decode($response, true);
    }

    public function add_request($method, $args, &$callback) {
        $this->_request_id++;
        $this->_buffer[] = $this->_buildRequest($method, $args, $this->_request_id);
        $this->_lookup_table[$this->_request_id] = $callback;
        return $this->_request_id;
    }

    public function communicate()
    {
        if (!$this->_buffer) {
            return;
        }

        $payload = implode('', $this->_buffer);

        $response = $this->_sendRequest($payload, $this->_cookie);

        # This strip HTTP header from the response and return
        # it as an array.
        $headers = $this->_parseHeader($response);

        # Check MD5 
        if (md5($response) != $headers['content-md5'])
        {
            echo "Wrong MD5 checksum";
        }

        # Calculate timeout of the session
        $this->_session_timeout_at = time() + intval($headers['x-session-timeout']);

        # Store cookie and parse session ID
        if ($headers['set-cookie'])
        {
            $this->_cookie = $headers['set-cookie'];
            $cookies = $this->_parseCookie($this->_cookie);
            if ($cookies['STRATUM_SESSION']) {
                $this->_session_id = $cookies['STRATUM_SESSION'];
            }
        }

        var_dump($headers);
        print $response;
        
        $response = $this->_processResponse($response);
        $this->_buffer = array();
    }
}
