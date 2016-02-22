<?php
/**
 * Handles PayPal Instant Payment Notifications
 */
class PaypalIpnListener {
    /**
     * Array of message handlers sorted by message type
     * i.e. $handlers[txn_type][handler_id]
     * 
     * one type of message can have more than one handler
     *
     * @var array
     */
    private $handlers = array();
    /**
     * Points to which Paypal api end point to make calls
     * 
     * false: https://www.sandbox.paypal.com/cgi-bin/webscr (sendbox enviroment)
     * true:  https://www.paypal.com/cgi-bin/webscr (live enviroment)
     *
     * @var boolean
     */
    public $is_live = false;
    /**
     * If set into true logging is enabled. disabled otherwise
     *
     * @var boolean
     */
    public $is_logging_enabled = false;
    /**
     * Path to log file
     *
     * @var string
     */
    public $log_file = '';
    /**
     * Stores string representation of message
     *
     * @var string
     */
    private $message_string = '';
    /**
     * Handles Paypal IPN message
     * 
     * @return boolean
     */
    public function handleMessage() {
        
        $message = $this->getMessageFromRawPost();
        
        if($this->validateMessage($message)) {
            
            $this->processMessage($message);
            
            return true;
        }
        
        return false;
    }
    /**
     * Attaches $handler callable to handle message with $message_type 
     * contained in txn_type field of message body
     * 
     * @param string|array $message_type
     * @param callable $handler
     * 
     * @return boolean
     */
    public function bind($message_type, $handler) {
        if(is_callable($handler)) {
            
            if(is_array($message_type)) {
                
                foreach($message_type as $type) {
                    
                    $this->_bind($type, $handler);
                }
                
                return true;
            }
            
            $this->_bind($message_type, $handler);
            
            return true;
        }
        
        return false;
    }
    /**
     * Writes $handler callable to handlers array
     * 
     * @param string $message_type
     * 
     * @param callable $handler
     */
    private function _bind($message_type, $handler) {
        
        $this->handlers[$message_type][] = $handler;
    }
    /**
     * Forms POST data from php input stream
     * 
     * @return array
     */
    protected function getMessageFromRawPost() {
        
        $raw_post_data = file_get_contents('php://input');
        $raw_post_array = explode('&', $raw_post_data);
        
        $post_data = array();
        
        foreach ($raw_post_array as $keyval) {
            
            $keyval = explode ('=', $keyval);
            
            if (count($keyval) == 2) {
                
                $post_data[$keyval[0]] = urldecode($keyval[1]);
                
                if($this->is_logging_enabled)
                    $this->message_string .= "$keyval[0]=$keyval[1];";
            }
        }
        
        if($this->is_logging_enabled)
            $this->log ("Received Message: " . $this->message_string);
        
        return $post_data;
    }
    /**
     * Validates IPN message with paypal in live or sandbox enviroments
     * depending on $is_live parameter value
     * false: sanbox
     * true: live
     * 
     * @param array $message
     * 
     * @return boolean
     */
    protected function validateMessage($message) {
        
        $end_point = $this->is_live
            ? 'https://www.paypal.com/cgi-bin/webscr'
            : 'https://www.sandbox.paypal.com/cgi-bin/webscr'
        ;
        
        $post_string = "cmd=_notify-validate";
        
        if(function_exists('get_magic_quotes_gpc')) {
            $get_magic_quotes_exists = true;
        }
        
        foreach($message as $key => $value) {
            if($get_magic_quotes_exists && get_magic_quotes_gpc() == 1) {
                
                $value = urlencode(stripslashes($value));
            } else {
                
                $value = urlencode($value);
            }
            
            $post_string .= "&$key=$value";
        }
        
        $ch = curl_init($end_point);
        
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));
        /**
            In wamp-like environments that do not come bundled with root authority certificates,
            please download 'cacert.pem' from "http://curl.haxx.se/docs/caextract.html" and set
            the directory path of the certificate as shown below:
            curl_setopt($ch, CURLOPT_CAINFO, dirname(__FILE__) . '/cacert.pem');
         **/
        
        $responce = curl_exec($ch);
        curl_close($ch);
        
        if($responce && strcmp($responce, "VERIFIED") == 0) {
            
            if($this->is_logging_enabled)
                $this->log("Validating success for message: " . $this->message_string);
            
            return true;
        }
        
        if($this->is_logging_enabled)
            $this->log("Validating faled for message: " . $this->message_string);
        
        return false;
    }
    /**
     * Executes handlers assigned for message's txn_type field value
     * 
     * @param array $message
     */
    protected function processMessage($message) {
        
        if(!isset($message['txn_type'])) return;
        
        $message_type = $message['txn_type'];
        
        if( isset($message_type) &&
            is_array($this->handlers[$message_type]) ) {
            
            foreach($this->handlers[$message_type] as $handler) {
                
                $response = $handler($message);
                
                if(!$response) break;
            }
        }
    }
    /**
     * Logging function
     * 
     * @param string $message
     */
    protected function log($message){
        $now = new DateTime();
        
        file_put_contents(
            $this->log_file,
            $now->format(DateTime::COOKIE) . " " . $message . PHP_EOL,
            FILE_APPEND
        );
    }
}
