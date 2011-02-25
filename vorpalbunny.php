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
 * @version 0.1
 *
 * Usage:
 *
 *  $vb = new VorpalBunny('localhost');
 *  $vb->publish($message, $mimeType, $deliveryType);
 */
class VorpalBunny
{
  protected static $apcPrefix = 'VorpalBunny:';
  protected static $apcIDKey = 'VorpalBunny:id';
  protected static $jsonRPCVersion = 1.1;
  protected static $validMethods = array( 'call', 'cast', 'open', 'poll' );

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
   */
  function __construct( $host, $port = 55672, $user = 'guest', $pass = 'guest', $vhost = '/', $timeout = 30 )
  {
    // Do we have APC support for caching session token?
    if ( is_callable( 'apc_fetch' ) )
    {
      // We can cache data in APC so we don't need a new session each time
      $this->canCacheSession = True;

      // Construct the APC cache key we'll use in init and elsewhere
      $this->cacheKey = self::$apcPrefix . $username . ':' . $password . '@' . $host . ':' . $port . $vhost;

      // Check to see if we already have a session key
      $this->sessionToken = apc_fetch( $this->cacheKey );
    }
    else
    {
      $this->canCacheSession = false;
    }

    // Create our Base URL
    $this->baseURL = 'http://' . $host . ':' . $port . '/rpc/';

    // Hold on to these configuration variables for later use
    $this->user = $user;
    $this->pass = $pass;
    $this->vhost = $vhost;
    $this->timeout = $timeout;

    // Create our CURL instance
    $this->curl = curl_init( );

    // Set our CURL options
    curl_setopt( $this->curl, CURLOPT_POST, True );
    curl_setopt( $this->curl, CURLOPT_RETURNTRANSFER, 1 );
    curl_setopt( $this->curl, CURLOPT_USERAGENT, 'VorpalBunny/0.1' );
    curl_setopt( $this->curl, CURLOPT_HTTPHEADER, array( 'Content-type: application/json' ) );
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
    // Get the ID out of APC if possible
    if ( $this->canCacheSession === true )
    {
      // Assume this is set to 0 since we called for the session
      $this->id = apc_inc( self::$apcIDKey, 1 );
    }
    else
    {
      // Increment our internal varaible
      $this->id++;
    }

    return $this->id;
  }

  /**
   * Retrieves a Session token from the RabbitMQ JSON-RPC Channel Plugin
   *
   * @return void
   * @throws Exception
   */
  private function getSession( )
  {
    // Defind our parameters array
    $parameters = array( $this->user, $this->pass, $this->timeout, $this->vhost );

    // Set our post data
    curl_setopt( $this->curl, CURLOPT_POSTFIELDS, $this->getPayload( 'open', $parameters ) );

    // Set our URL
    curl_setopt( $this->curl, CURLOPT_URL, $this->baseURL . "rabbitmq" );

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
      throw new Exception( "Received a " . $header['http_code'] . " response: " . $body );
    }

    // Decode the body into the object representation
    $response = json_decode( $response );

    // See if we got a RPC error
    if ( isset($response->error ) )
    {
      throw new Exception( "Received a RPC Error " . $response->error->code . " response: " . $response->error->message );
    }

    // Make sure we have a body
    // Expected response example: {"version":"1.1","id":1,"result":{"service":"F01F0D5ADDF995CAA9B1DCD38AB8E239"}}
    if ( ! isset( $response->result ) )
    {
      throw Exception( "Missing Required 'response' attribute in JSON response" );
    }

    // Assign our session token
    $this->sessionToken = $response->result->service;

    if ( $this->canCacheSession === true )
    {
      // Store the value of the token
      apc_store( $this->cacheKey, $this->sessionToken );

      // Set our ID counter to 0
      apc_store( self::$apcIDKey, 0 );
    }
  }

  /**
   * Returns a URL for the specific session id to make calls with
   *
   * @return string Session URL
   */
  function getSessionURL( )
  {
    // If we don't have a valid session token, go get one
    if ( ! $this->sessionToken )
    {
      $this->getSession( );
    }

    // Return our JSON-RPC-Channel Session URL
    return $this->baseURL . $this->sessionToken;
  }

  /**
   * Send a message to RabbitMQ using Basic.Deliver over RPC
   *
   * For more information on the parameters, see http://www.rabbitmq.com/amqp-0-9-1-quickref.html#basic.deliver
   *
   * @param string $message to be published, should aready be escaped/encoded
   * @param string $exchange to publish the message to, can be empty
   * @param string $routing_key to publish the message to
   * @param string $mimetype of message content content
   * @param int $delivery_mode for message: 1 non-persist message, 2 persist message
   * @param bool $mandatory set the mandatory bit
   * @param bool $immediate set the immediate bit
   * @param bool $recursion flag called when trying to recreate a new session
   * @return bool Success/Failure
   * @throws Exception
   */
  function publish( $message,
                    $exchange,
                    $routing_key,
                    $mimetype = "text/plain",
                    $delivery_mode = 1,
                    $mandatory = false,
                    $immediate = false,
                    $recursion = false )
  {
    // Make sure they passed in a message
    if ( ! strlen( $message ) )
    {
      throw new Exception( "You must pass in a message to deliver." );
    }

    // Make sure they passed in a routing_key and exchange
    if ( ! strlen( $exchange ) && ! strlen( $routing_key ) )
    {
      throw new Exception( "You must pass either an exchange or routing key to publish to." );
    }

    // Set our properties array: content_encoding, headers, delivery_mode, priority, correlation_id, reply_to,
    //                           expiration, message_id, timestamp, type, user_id, app_id, cluster_id
    $properties = array ( $mimetype, null, $delivery_mode, null, null, null, null, null, null, null, null, null, null, null );

    // Create our parameter array
    // Second parameter array is: ticket, exchange, routing_key, mandatory, immediate
    $parameters = array ( "basic.publish", array( 0, $exchange, $routing_key, $mandatory, $immediate ), $message, $properties );

    // Set our URL
    curl_setopt( $this->curl, CURLOPT_URL, $this->getSessionURL( ) );

    // Set our post data
    curl_setopt( $this->curl, CURLOPT_POSTFIELDS, $this->getPayload( 'cast', $parameters ) );

    // Make the Call and get the response
    $response = curl_exec( $this->curl );

    // Parse out the header and body
    $header = curl_getinfo( $this->curl );

    // Evaluate the return response to make sure we got a good result before continuing
    if ( $header['http_code'] != 200 )
    {
      throw new Exception( "Received a " . $header['http_code'] . " response: " . $response);
    }

    // Decode our JSON response so we can check for success/failure
    $response = json_decode( $response );

    // See if we got a RPC error
    if ( isset($response->error ) )
    {
      // Try and recurse once to fix the issue of a stale session
      if ( $response->error->code == 404 )
      {
        if ( $recursion !== true )
        {
          return $this->publish( $message, $exchange, $routing_key, $mimetype, $delivery_mode, $mandatory, $immediate, true );
        }
        else
        {
          return false;
        }
      }

      // Was an unexpected error
      throw new Exception( "Received a RPC Error " . $response->error->code . " response: " . $response->error->message );
    }

    // Make sure we have a body
    // Expected response example: {"version":"1.1","id":2,"result":[]}
    if ( ! isset($response->result) )
    {
      throw Exception( "Missing Required 'response' attribute in JSON response" );
    }

    return True;
  }

}
