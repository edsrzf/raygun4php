<?php
namespace Raygun4php {
  require_once realpath(__DIR__ . '/RaygunMessage.php');
  require_once realpath(__DIR__ . '/RaygunIdentifier.php');
  require_once realpath(__DIR__ . '/Raygun4PhpException.php');
  require_once realpath(__DIR__ . '/Uuid.php');

  use Raygun4Php\Rhumsaa\Uuid\Uuid;

  class RaygunClient
  {
    protected $apiKey;
    protected $version;
    protected $tags;
    protected $user;
    protected $firstName;
    protected $fullName;
    protected $email;
    protected $isAnonymous;
    protected $uuid;
    protected $httpData;
    protected $useAsyncSending;
    protected $debugSending;
    protected $disableUserTracking;

    /**
     * @var Array Parameter names to filter out of logged form data. Case insensitive.
     * Accepts regular expressions when the name starts with a forward slash.
     * Maps either to TRUE, or to a callable with $key and $value arguments.
     */
    protected $filterParams = array();

    private $host = 'api.raygun.io';
    private $path = '/entries';
    private $transport = 'ssl';
    private $port = 443;

    /*
    * Creates a new RaygunClient instance.
    * @param bool $useAsyncSending If true, attempts to post rapidly and asynchronously the script by forking a cURL process.
    * RaygunClient cannot return the HTTP result when in async mode, however. If false, sends using a blocking socket connection.
    * This is the only method available on Windows.
    * @param bool $debugSending If true, and $useAsyncSending is true, this will output the HTTP response code from posting
    * error messages. See the GitHub documentation for code meaning. This param does nothing if useAsyncSending is set to true.
    */
    public function __construct($key, $useAsyncSending = true, $debugSending = false, $disableUserTracking = false)
    {
      $this->apiKey = $key;
      $this->useAsyncSending = $useAsyncSending;
      $this->debugSending = $debugSending;

      if (!$disableUserTracking) {
        $this->SetUser();
      }

      $this->disableUserTracking = $disableUserTracking;
    }

    /*
    * Transmits an error to the Raygun.io API
    * @param int $errorno The error number
    * @param string $errstr The error string
    * @param string $errfile The file the error occurred in
    * @param int $errline The line the error occurred on
    * @param array $tags An optional array of string tags used to provide metadata for the message
    * @param array $userCustomData An optional associative array that can be used to place custom key-value
    * @param int $timestamp Current Unix timestamp in the local timezone, used to set when an error occurred.
    * data in the message payload
    * @return The HTTP status code of the result when transmitting the message to Raygun.io
    */
    public function SendError($errno, $errstr, $errfile, $errline, $tags = null, $userCustomData = null, $timestamp = null)
    {
      $message = $this->BuildMessage(new \ErrorException($errstr, $errno, 0, $errfile, $errline), $timestamp);

      if ($tags != null)
      {
        $this->AddTags($message, $tags);
      }

      if ($userCustomData != null)
      {
        $this->AddUserCustomData($message, $userCustomData);
      }

      return $this->Send($message);
    }

    /*
    * Transmits an exception to the Raygun.io API
    * @param \Exception $exception An exception object to transmit
    * @param array $tags An optional array of string tags used to provide metadata for the message
    * @param array $userCustomData An optional associative array that can be used to place custom key-value
    * data in the message payload
    * @param int $timestamp Current Unix timestamp in the local timezone, used to set when an exception occurred.
    * @return The HTTP status code of the result when transmitting the message to Raygun.io
    */
    public function SendException($exception, $tags = null, $userCustomData = null, $timestamp = null)
    {
      $message = $this->BuildMessage($exception, $timestamp);

      if ($tags != null)
      {
        $this->AddTags($message, $tags);
      }

      if ($userCustomData != null)
      {
        $this->AddUserCustomData($message, $userCustomData);
      }

      return $this->Send($message);
    }

    /*
     * Sets the version number of your project that will be transmitted
     * to Raygun.io.
     * @param string $version The version number in the form of x.x.x.x,
     * where x is a positive integer.
     *
     */
    public function SetVersion($version)
    {
      $this->version = $version;
    }

    /*
    *  Stores the current user of the calling application. This will be added to any messages sent
    *  by this provider. It is used in the dashboard to provide unique user tracking.
    *  If it is an email address, the user's Gravatar can be displayed. This method is optional,
    *  if it is not used a random identifier will be assigned to the current user.
    *  @param string $user A username, email address or other identifier for the current user
    *  of the calling application.
    *
    */
    public function SetUser($user = null, $firstName = null, $fullName = null, $email = null, $isAnonymous = null, $uuid = null)
    {
      $timestamp = time() + 60 * 60 * 24 * 30;

      $this->firstName = $this->StoreOrRetrieveUserCookie('rgfirstname', $firstName);
      $this->fullName = $this->StoreOrRetrieveUserCookie('rgfullname', $fullName);
      $this->email = $this->StoreOrRetrieveUserCookie('rgemail', $email);

      $this->uuid = $this->StoreOrRetrieveUserCookie('rguuidvalue', $uuid);
      $this->isAnonymous = $this->StoreOrRetrieveUserCookie('rgisanonymous', $isAnonymous ? 'true' : 'false') == 'true' ? true : false;

      if (is_string($user))
      {
        $this->user = $user;

        if (php_sapi_name() != 'cli' && !headers_sent())
        {
          setcookie('rguserid', $user, $timestamp);
          setcookie('rguuid', 'false', $timestamp);
        }
      }
      else
      {
        if (!array_key_exists('rguuid', $_COOKIE))
        {
          $this->user = (string)Uuid::uuid4();

          if (php_sapi_name() != 'cli' && !headers_sent())
          {
            setcookie('rguserid', $this->user, $timestamp);
            setcookie('rguuid', 'true', $timestamp);
          }
        }
        else if (array_key_exists('rguserid', $_COOKIE))
        {
          $this->user = $_COOKIE['rguserid'];
        }

        $this->isAnonymous = $this->StoreOrRetrieveUserCookie('rgisanonymous', 'true') == 'true';
      }
    }

    private function StoreOrRetrieveUserCookie($key, $value)
    {
      $timestamp = time() + 60 * 60 * 24 * 30;

      if (is_string($value))
      {
        if (php_sapi_name() != 'cli' && !headers_sent())
        {
          setcookie($key, $value, $timestamp);
        }

        return $value;
      }
      else
      {
        if (array_key_exists($key, $_COOKIE))
        {
          if ($_COOKIE[$key] != $value && php_sapi_name() != 'cli' && !headers_sent())
          {
            setcookie($key, $value, $timestamp);
          }
          return $_COOKIE[$key];
        }
      }

      return null;
    }

    /*
     * Sets a string array of tags relating to the message,
     * used for identification. These will be transmitted along with messages that
     * are sent.
     * @param array $tags The tags relating to your project's version
    */
    private function BuildMessage($errorException, $timestamp = null)
    {
      $message = new RaygunMessage($timestamp);
      $message->Build($errorException);
      $message->Details->Version = $this->version;
      $message->Details->Context = new RaygunIdentifier(session_id());

      if ($this->user != null)
      {
        $message->Details->User = new RaygunIdentifier($this->user, $this->firstName, $this->fullName, $this->email, $this->isAnonymous, $this->uuid);
      }
      else if (!$this->disableUserTracking)
      {
        $message->Details->User = new RaygunIdentifier($_COOKIE['rguserid']);
      }

      return $message;
    }

    private function AddTags(&$message, $tags)
    {
      if (is_array($tags))
      {
        $message->Details->Tags = $tags;
      }
      else
      {
        throw new \Raygun4php\Raygun4PhpException("Tags must be an array");
      }
    }

    private function AddUserCustomData(&$message, $userCustomData)
    {
      if ($this->is_assoc($userCustomData))
      {
        $message->Details->UserCustomData = $userCustomData;
      }
      else
      {
        throw new \Raygun4php\Raygun4PhpException("UserCustomData must be an associative array");
      }
    }

    private function is_assoc($array)
    {
      return (bool)count(array_filter(array_keys($array), 'is_string'));
    }

    /*
     * Transmits a RaygunMessage to the Raygun.io API. The default attempts to transmit asynchronously.
     * To disable this and transmit sync (blocking), pass false in as the 2nd parameter in RaygunClient's
     * constructor. This may be necessary on some Windows installations where the implementation is broken.
     * This is a lower level function used by SendException and SendError and one of those should be used preferrably.
     * @param Raygun4php\RaygunMessage $message A populated message to be posted to the Raygun API
     * @return The HTTP status code of the result after transmitting the message to Raygun.io
     * 202 if accepted, 403 if invalid JSON payload
     */
    public function Send($message)
    {
      if (empty($this->apiKey))
      {
        throw new \Raygun4php\Raygun4PhpException("API not valid, cannot send message to Raygun");
      }

      $message = $this->filterParamsFromMessage($message);
      $message = $this->toJsonRemoveUnicodeSequences($message);

      return $this->post($message, realpath(__DIR__ . '/cacert.crt'));
    }

    private function post($data_to_send, $cert_path)
    {
      $headers = 0;
      $remote = $this->transport . '://' . $this->host . ':' . $this->port;

      $context = stream_context_create();
      $result = stream_context_set_option($context, 'ssl', 'verify_host', true);

      if (!empty($cert_path))
      {
        $result = stream_context_set_option($context, 'ssl', 'cafile', $cert_path);
        $result = stream_context_set_option($context, 'ssl', 'verify_peer', true);
      }
      else
      {
        $result = stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
      }

      if ($this->useAsyncSending && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN')
      {
        $cmd = "curl -X POST -H 'Content-Type: application/json' -H 'X-ApiKey: " . $this->apiKey . "'";
        $cmd .= " -d " . escapeshellarg($data_to_send) . " --cacert '" . realpath(__DIR__ . '/cacert.crt')
           . "' 'https://api.raygun.io:443/entries' > /dev/null 2>&1 &";

        exec($cmd, $output, $exit);
        return $exit;
      }
      else
      {
        $fp = stream_socket_client($remote, $err, $errstr, 10, STREAM_CLIENT_CONNECT, $context);

        if ($fp)
        {
          $req = '';
          $req .= "POST $this->path HTTP/1.1\r\n";
          $req .= "Host: $this->host\r\n";
          $req .= "X-ApiKey: " . $this->apiKey . "\r\n";
          $req .= 'Content-length: ' . strlen($data_to_send) . "\r\n";
          $req .= "Content-type: application/json\r\n";
          $req .= "Connection: close\r\n\r\n";
          fwrite($fp, $req);
          fwrite($fp, $data_to_send);

          $response = "";
          if ($this->debugSending)
          {
            while(!preg_match("/^HTTP\/[\d\.]* (\d{3})/", $response))
            {
              $response .= fgets($fp, 128);
            }

            fclose($fp);

            return $response;
          }
          else
          {
            fclose($fp);

            return 0;
          }
        }
        else
        {
          $errMsg = "<br/><br/>" . "<strong>Raygun Warning:</strong> Couldn't send asynchronously. ";
          $errMsg .= "Try calling new RaygunClient('apikey', FALSE); to use an alternate sending method, or RaygunClient('key', FALSE, TRUE) to echo the HTTP response" . "<br/><br/>";
          echo $errMsg;
          trigger_error('httpPost error: ' . $errstr);
          return null;
        }
      }
    }

    function toJsonRemoveUnicodeSequences($struct) {
      return preg_replace_callback("/\\\\u([a-f0-9]{4})/", function($matches){ return iconv('UCS-4LE','UTF-8',pack('V', hexdec("U$matches[1]"))); }, json_encode($struct));
    }

    /**
     * Optionally applies a value transformation to every matching key, as defined by {@link FilterParams}.
     * Replaces the value by default, but also supports custom transformations through
     * anonymous functions. Applies to form data, environment data, HTTP headers.
     * Does not apply to GET parameters in the request URI.
     * Filters out raw HTTP data in case any filters are defined, since we can't accurately filter it.
     *
     * @param RaygunMessage $message
     * @param  string $replace Value to be inserted by default (unless specified otherwise by custom transformations).
     * @return RaygunMessage
     */
    function filterParamsFromMessage($message, $replace = '[filtered]') {
      $filterParams = $this->getFilterParams();

      // Skip checks if none are defined
      if(!$filterParams) {
        return $message;
      }

      // Ensure all filters are callable
      foreach($filterParams as $filterKey => $filterFn) {
        if(!is_callable($filterFn)) {
          $filterParams[$filterKey] = function($key, $val) use ($replace) {return $replace;};
        }
      }

      $walkFn = function(&$val, $key) use ($filterParams) {
        foreach($filterParams as $filterKey => $filterFn) {
          if(
            (strpos($filterKey, '/') === 0 && preg_match($filterKey, $key))
            || (strpos($filterKey, '/') === FALSE && strtolower($filterKey) == strtolower($key))
          ) {
            $val = $filterFn($key, $val);
          }
        }
      };

      if($message->Details->Request->Form) {
        array_walk_recursive($message->Details->Request->Form, $walkFn);
      }

      if($message->Details->Request->Headers) {
        array_walk_recursive($message->Details->Request->Headers, $walkFn);
      }

      if($message->Details->Request->Data) {
        array_walk_recursive($message->Details->Request->Data, $walkFn);
      }

      if($message->Details->UserCustomData) {
        array_walk_recursive($message->Details->UserCustomData, $walkFn);
      }

      // Unset raw HTTP data since we can't accurately filter it
      if($message->Details->Request->RawData) {
        $message->Details->Request->RawData = null;
      }

      return $message;
    }

    /**
     * @param Array $params
     * @return Raygun4php\RaygunClient
     */
    function setFilterParams($params) {
      $this->filterParams = $params;
      return $this;
    }

    /**
     * @return Array
     */
    function getFilterParams() {
      return $this->filterParams;
    }

    public function __destruct()
    {
      if ($this->httpData)
      {
        curl_close($this->httpData);
      }
    }

  }
}