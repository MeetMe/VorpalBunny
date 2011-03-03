<?php
/**
 * Vorpal Bunny is a publishing client for RabbitMQ's JSON-RPC Channel Plugin
 *
 * The goal is to be a light-weight tool for higher throughput with smaller
 * protocol overhead for calling Basic.Publish from PHP applications.
 *
 * PHP Version 5
 *
 * @package VorpalBunny
 * @author Gavin M. Roy <gmr@myyearbook.com>
 * @copyright 2011 Insider Guides, Inc.
 * @license http://opensource.org/licenses/bsd-license.php BSD License
 * @link http://github.com/myYearbook/VorpalBunny
 * @since 2011-02-24
 * @version 0.2
 *
 * Usage:
 *
 *  $vb = new VorpalBunny('localhost');
 *  $vb->publish( $exchange, $routing_key, $message, $mimeType, $deliveryType);
 */
class VorpalBunny
{
  protected static $apcPrefix = 'VorpalBunny:';
  protected static $apcIDKey = 'VorpalBunny:id';
  protected static $jsonRPCVersion = 1.1;
  protected static $jsonRPCTimeout = 3;
  protected static $validMethods = array( 'call', 'cast', 'open', 'poll' );
  protected static $maxRetries = 3;
  protected static $version = 0.2;

  private $id = 0;
  private $sessionToken = null;

  /**
   * Initialize the VorpalBunny Class
   *
   * @param string $host RabbitMQ server to use
   * @param int $port RabbitMQ Server HTTP port to use
   * @param string $user Username to pass to RabbitMQ when starting a session
   * @param string $pass Password to send to RabbitMQ when starting a session
   * @param string $vhost RabbitMQ VHost to use
   * @param int $timeout Timeout to set on the RabbitMQ JSONRPC Channel side
   * @throws Exception when missing APC
   */
  function __construct( $host, $port = 55672, $user = 'guest', $pass = 'guest', $vhost = '/', $timeout = 300 )
  {
    // Do we have APC support for caching session token?
    if ( ! is_callable( 'apc_fetch' ) )
    {
      throw new Exception( "APC is not available, please install APC" );
    }

    // Construct the APC cache key we'll use in init and elsewhere
    $this->cacheKey = self::$apcPrefix . $username . ':' . $password . '@' . $host . ':' . $port . $vhost;

    // Create our Base URL
    $this->baseURL = 'http://' . $host . ':' . $port . '/rpc/';

    // Hold on to these configuration variables for later use
    $this->user = $user;
    $this->pass = $pass;
    $this->vhost = $vhost;
    $this->timeout = $timeout;

    $this->curl_init( );
  }

  /**
   * Initialize a new curl connection, do this on each new session
   *
   * @return void
   */
  private function curl_init( )
  {
    // Delete the previous CURL instance
    unset( $this->curl );

    // Create our CURL instance
    $this->curl = curl_init( );

    // Set our CURL options
    curl_setopt( $this->curl, CURLOPT_POST, true );
    curl_setopt( $this->curl, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $this->curl, CURLOPT_FORBID_REUSE, false );
    curl_setopt( $this->curl, CURLOPT_FRESH_CONNECT, false );
    curl_setopt( $this->curl, CURLOPT_TIMEOUT, self::$jsonRPCTimeout );
    curl_setopt( $this->curl, CURLOPT_USERAGENT, 'VorpalBunny/' . self::$version );
    curl_setopt( $this->curl, CURLOPT_HTTPHEADER, array( 'Content-type: application/json',
                                                         'x-json-rpc-timeout: ' . self::$jsonRPCTimeout,
                                                         'Connection: keep-alive' ) );
  }

  /**
   * Construct the JSON data payload for the POST
   *
   * @param string $method The RPC call to make, one of open, call, cast, poll
   * @param array $params Array of parameters to append to the payload
   * @return string JSON encoded array
   * @throws Exception
   */
  private function getPayload( $method, $params = array( ) )
  {
    // Make sure we're passing in a valid method
    if ( in_array( $method, self::$validMethods ) )
    {
      // Build our main JSON key/value object
      $output = array();
      $output['version'] = self::$jsonRPCVersion;
      $output['method'] = $method;
      $output['id'] = $this->getNextId( );
      $output['params'] = $params;

      // JSON Encode and return the data
      return json_encode( $output );
    }

    // Better to be strict since invalid data can cause RabbitMQ to kill off the connection with no response
    throw new Exception( "Invalid RPC method passed: " . $method );
  }

  /**
   * Return the next communication ID sequence number
   *
   * @return int
   */
  private function getNextId( )
  {
    // Assume this is set to 0 since we called for the session
    $this->id = apc_fetch( self::$apcIDKey ) + 1;
    apc_store( self::$apcIDKey, $this->id );
    return $this->id;
  }

  /**
   * Retrieves a Session token from the RabbitMQ JSON-RPC Channel Plugin
   *
   * @param int $recursive request retry counter
   * @return void
   * @throws Exception
   */
  private function getSession( $recursive = 0 )
  {
    // Reset the session request counter
    apc_store( self::$apcIDKey, 0 );

    // Defind our parameters array
    $parameters = array( $this->user, $this->pass, $this->timeout, $this->vhost );

    // Set our post data
    $payload = $this->getPayload( 'open', $parameters );
    curl_setopt( $this->curl, CURLOPT_POSTFIELDS, $payload );

    // Set our URL
    $url = $this->baseURL . "rabbitmq";
    curl_setopt( $this->curl, CURLOPT_URL, $url );

    // Make the Call and get the response
    $response = curl_exec( $this->curl );

    // Make sure the call succeeded
    if ( ! $response )
    {
      throw new Exception( "Could not connect to the RabbitMQ HTTP Server" );
    }

    // Parse out the header and body
    $header = curl_getinfo( $this->curl );

    // Evaluate the return response to make sure we got a good result before continuing
    if ( $header['http_code'] != 200 )
    {
      throw new Exception( "Received a HTTP Error #" . $header['http_code'] . " while getting session. URL: " . $url .
                           " Payload:" . $payload. " Response: " . $response );
    }

    // Decode the body into the object representation
    $response = json_decode( $response );

    // See if we got a RPC error
    if ( isset($response->error ) )
    {
      if ( $recursive < self::$maxRetries )
      {
        // Rebuild our Curl Object
        $this->curl_init( );

        // Make a second attept
        return $this->getSession( $recursive + 1 );
      }
      else
      {
        throw new Exception( "Received " . $recursive . " RPC Errors, while obtaining session. Last  URL: " . $url  .
                             " Payload:" . $payload. "  Response: " . $response );
      }
    }

    // Make sure we have a body
    // Expected response example: {"version":"1.1","id":1,"result":{"service":"F01F0D5ADDF995CAA9B1DCD38AB8E239"}}
    if ( ! isset( $response->result ) )
    {
      throw Exception( "Missing Required 'response' attribute in JSON response:" . json_encode( $response ) );
    }

    // Assign our session token
    $token = $response->result->service;

    // Store the value of the token, timing out before Rabbit does as to reduce number of requests when timed out
    apc_store( $this->cacheKey, $token, intval( $this->timeout - ( $this->timeout / 8 ) ) );

    // Set our ID counter to 0
    apc_store( self::$apcIDKey, 0 );

    return $token;
  }

  /**
   * Returns a URL for the specific session id to make calls with
   *
   * @return string Session URL
   */
  private function getSessionURL( )
  {

    $token = trim( apc_fetch( $this->cacheKey ) );

    // If we don't have a valid session token, go get one
    if ( ! $token )
    {
      $token = $this->getSession( );
    }

    // Return our JSON-RPC-Channel Session URL
    return $this->baseURL . $token;
  }

  /**
   * Send a message to RabbitMQ using Basic.Deliver over RPC
   *
   * For more information on the parameters, see http://www.rabbitmq.com/amqp-0-9-1-quickref.html#basic.deliver
   *
   * @param string $exchange to publish the message to, can be empty
   * @param string $routing_key to publish the message to
   * @param string $message to be published, should already be escaped/encoded
   * @param string $mimetype of message content content
   * @param int $delivery_mode for message: 1 non-persist message, 2 persist message
   * @param bool $mandatory set the mandatory bit
   * @param bool $immediate set the immediate bit
   * @param int $recursive retry counter when trying to recreate a new session
   * @return bool Success/Failure
   * @throws Exception
   */
  function publish( $exchange,
                    $routing_key,
                    $message,
                    $mimetype = "text/plain",
                    $delivery_mode = 1,
                    $mandatory = false,
                    $immediate = false,
                    $recursive = 0 )
  {
    // Make sure they passed in a message
    if ( ! strlen( $message ) )
    {
      throw new Exception( "You must pass in a message to deliver." );
    }

    // See if we can json decode the message, if so throw an exception
    if ( json_decode( $message ) )
    {
      throw new Exception( "You can not send JSON encoded data as the message body." );
    }

    // Make sure they passed in a routing_key and exchange
    if ( ! strlen( $exchange ) && ! strlen( $routing_key ) )
    {
      throw new Exception( "You must pass either an exchange or routing key to publish to." );
    }

    // Set our properties array
    $properties = array ( $mimetype,       // Content-type
                          null,            // Content-encoding
                          null,            // Headers
                          $delivery_mode,  // Delivery Mode
                          null,            // Priority
                          null,            // Correlation ID
                          null,            // Reply To
                          null,            // Expiration
                          null,            // Message ID
                          null,            // Timestamp
                          null,            // Type
                          null,            // User ID
                          null,            // App ID
                          null );          // Cluster ID

    // Second parameter array is: ticket, exchange, routing_key, mandatory, immediate
    $parameters = array ( "basic.publish", array(0, $exchange, $routing_key, $mandatory, $immediate ), $message, $properties );

    // Set our URL
    curl_setopt( $this->curl, CURLOPT_URL, $this->getSessionURL( ) );

    // Set our post data
    $payload = $this->getPayload( 'cast', $parameters );

    curl_setopt( $this->curl, CURLOPT_POSTFIELDS, $payload );

    // Make the Call and get the response
    $response = curl_exec( $this->curl );

    // Parse out the header and body
    $header = curl_getinfo( $this->curl );

    // Evaluate the return response to make sure we got a good result before continuing
    if ( $header['http_code'] != 200 )
    {
      throw new Exception( "Received a HTTP Error #" . $header['http_code'] . " while sending basic.publish. Response: " . $response);
    }

    // Decode our JSON response so we can check for success/failure
    $response = json_decode( $response );

    // See if we got a RPC error
    if ( isset($response->error ) )
    {
      // Try and recurse once to fix the issue of a stale session
      if ( in_array( $response->error->code, array( 404, 500 ) ) )
      {
        if ( $recursive < self::$maxRetries )
        {
          // Rebuild our Curl Object
          $this->curl_init( );

          // Remove the existing session key
          apc_delete( $this->cacheKey );

          // Pubish
          return $this->publish( $exchange, $routing_key, $message, $mimetype, $delivery_mode, $mandatory, $immediate, $recursive + 1 );
        }
      }

      // Remove the cache key
      apc_delete( $this->cacheKey );

      // Was an unexpected error
      throw new Exception( "Received " . $recursive . " RPC Errors while sending basic.publish. Last URL: " . $url .
                           " Payload: " . $payload . "Response: " . json_encode( $response ) );
    }

    // Make sure we have a body
    // Expected response example: {"version":"1.1","id":2,"result":[]}
    if ( ! isset( $response->result ) )
    {
      throw Exception( "Missing Required 'response' attribute in JSON response: " . json_encode( $response ) );
    }

    return True;
  }

}
