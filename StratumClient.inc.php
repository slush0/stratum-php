<?php

/**
 *  Implementation of HTTP Poll / HTTP Push transports 
 *  of Stratum protocol (http://stratum.bitcoin.cz)
 *
 *  TODO: Detect expired session, provide mechanism
 *  for session reinitalization.
 *
 *  TODO: ExceptionObject containing exception details
 *
 *  TODO: Add SSL support
 *
 *  @author: slush <info@bitcoin.cz>
 *  @license: public domain
**/

class ResultObject
{
    protected $_request_id = null;
    protected $_finished = false;

    protected $_result = null;
    protected $_err_code = null;
    protected $_err_msg = null;

    public function __construct($request_id)
    {
        $this->_request_id = $request_id;
        $this->_finished = false;
    }

    public function set_result($result, $err_code, $err_msg)
    {
        if($this->_finished)
        {
            throw new Exception("Result for the request request ID {$this->_request_id} is already known.");
        }

        $this->_finished = true;
        $this->_result = $result;
        $this->_err_code = $err_code;
        $this->_err_msg = $err_msg;
    }

    public function get()
    {
        if(!$this->_finished)
        {
            # TODO: Implement custom exception enable catching this state.
            throw new Exception("Result for request ID {$this->_request_id} is not received yet.");
        }

        if($this->_err_code || $this->_err_msg)
        {
            # FIXME: Implement passing parameters to the exception
            throw new Exception("Code {$this->_err_code}: Message {$this->_err_msg}");
        }
         
        return $this->_result;
    }
}

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

    protected $_services;

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

        $this->_services = array();
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

    public function serialize()
    {
        return serialize(array($this->_session_id, $this->_session_timeout_at, $this->_cookie, $this->_request_id, $this->_lookup_table));
    }

    public function unserialize($data)
    {
        list($this->_session_id, $this->_session_timeout_at, $this->_cookie, $this->_request_id, $this->_lookup_table) = unserialize($data);
    }

    protected function _parse_method($method)
    {
        $service_type = implode('.', explode('.', $method, -1));
        $method = str_replace("$service_type.", '', $method);
        return array($service_type, $method);
    }

    public function register_service($service_type, $instance)
    {
        $this->_services[$service_type] = $instance;
    }

    public function _process_local_service($method, $params)
    {
        list($service_type, $m) = $this->_parse_method($method);

        if(!isset($this->_services[$service_type])
            throw new Exception("Local service '$service_type' not found.");

        return call_user_func(array($this->_service[$service_type], "rpc_$m"), $params);
    }

    public function add_request($method, $args) {
        $this->_request_id++;
        $this->_buffer[] = $this->_build_request($this->_request_id, $method, $args);

        $result = new ResultObject($this->_request_id);
        $this->_lookup_table[$this->_request_id] = $result;
        return $result;
    }

    public function process_push()
    {

        # TODO:

        #$this->_process_response($data);
    }

    public function communicate()
    {
        $payload = implode('', $this->_buffer);
        $response = $this->_send_request($payload, $this->_cookie);
        $this->_buffer = array();

        # This strip HTTP header from the response and return
        # it as an array.
        $headers = $this->_parse_header($response);

        # Check MD5 
        if (md5($response) != $headers['content-md5']) {
            echo "Wrong MD5 checksum";
        }

        # Calculate timeout of the session
        $this->_session_timeout_at = time() + intval($headers['x-session-timeout']);

        # Store cookie and parse session ID
        if (isset($headers['set-cookie']))
        {
            $this->_cookie = $headers['set-cookie'];
            $cookies = $this->_parse_cookie($this->_cookie);
            if ($cookies['STRATUM_SESSION']) {
                $this->_session_id = $cookies['STRATUM_SESSION'];
            }
        }

        $this->_process_response($response);
    }

    protected function _build_request($request_id, $method, $args) {
        $request = array(
            'id' => $request_id,
            'method' => $method,
            'params' => array_values($args),
        );
        return json_encode($request)."\n";
    }

    protected function _build_response($request_id, $result, $err_code, $err_msg)
    {
        if($err_code !== null || $err_msg !== null)
        {
            $response = array(
                'id' => $request_id,
                'result' => null,
                'error' => array((int)$err_code, (string)$err_msg);
            }
        } else {
            $response = array(
                'id' => $request_id,
                'result' => $result,
                'error' => null,
            );
        }
        return json_encode($response)."\n";
    }

    protected function _send_request($payload, $cookie=null) {
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

    protected function _process_response($payload) {
        # Read line by line
        $lines = explode("\n", trim($payload));
        foreach($lines as $line)
        {
            $obj = json_decode($line, true);
            if($obj === null)
            {
                # Cannot decode line
                throw new Exception("Cannot decode line '$line'.");
                #continue;
            }

            if(isset($obj['method']))
            {
                # It's the request or notification

                # TODO: Add exception handling
                $resp = $this->_call_local_service($obj['method'], $obj['params']);

                if($obj['id'] !== null)
                {
                    # It's the RPC request, let's include response into the buffer
                    $this->_buffer[] = $this->_build_response($obj['id'], $resp, 0, null);    
                }

            } else {
                # It's the response

                if($obj['error'])
                {
                    $err_code = $obj['error'][0];
                    $err_msg = $obj['error'][1];
                } else {
                    $err_code = null;
                    $err_msg = null;
                }

                $result_object = $this->_lookup_table[$obj['id']];
                if($result_object)
                {
                    $result_object->set_result($obj['result'], $err_code, $err_msg);
                } else {
                    echo "Received unexpected response: {$obj['id']}, {$obj['result']}, $err_code, $err_msg";
                }
            }
        }
    }

    protected function _parse_cookie($cookie)
    {
        $out = array();
        $cook = explode(";", $cookie);
        foreach($cook as $c){
            $parts = explode("=", $c);
            $out[trim($parts[0])] = trim($parts[1]);
        }
        return $out;
    }

    protected function _parse_header(&$response)
    {
        /* This is modified method taken from some PHP forum.
           It's strange that standard PHP library is missing such functionality. */

        $response = explode("\n\n", str_replace("\r\n\r\n", "\n\n", $response));
        $headers = explode("\n", str_replace("\r", '', $response[0]));
        $response = $response[1];

        $header_data = array();
        foreach($headers as $value){
            $header = explode(": ",$value);
            if(isset($header[0]) && !isset($header[1])) {
                $header_data['http_code'] = $header[0];
            }
            elseif(isset($header[0]) && isset($header[1])){
                $header_data[strtolower($header[0])] = $header[1];
            }
        }
        return $header_data;
    }

}
