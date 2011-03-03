Vorpal Bunny
===========

Vorpal Bunny is an experimental rabbitmq-jsonrpc-channel PHP driver designed 
to allow expedited single call HTTP delivery for Basic.Deliver calls to RabbitMQ.

The goal is to be a light-weight tool for higher throughput with smaller protocol 
overhead for single-call of Basic.Push per application execution. 

Vorpal Bunny uses PHP with CURL and APC to reduce the traffic footprint for
clients who do nothing but publishing. The workflow is to allow the client
to grab a session off of the RabbitMQ JSON-RPC Channel plugin web server
and reuse that session until it is no longer valid. This is accomplished by
using APC to cache the value of the sessionToken for the given server.

he design tries to employ PHP's APC cache if available to keep one session with 
the RPC server active per Apache server on the RabbitMQ server. Application 
flow is as follows:

  1. Construct object, determining of APC cache is available
  2. On first call of publish, establish a session with the rabbitmq-jsonrpc-plugin, 
     then send the message
  3. On subsequent calls, call publish will send without trying to establish a 
     session and then look for an error indicating the session has timed out or is 
     no longer available. If this is the case, re-establish the session and retry 
     the delivery.
  
Your exchange, routing key and queues *MUST* be setup prior to use.

Requirements
------------

* PHP 5
* w/ CURL
* APC

Example Usage
-------------

     $vb = new VorpalBunny( 'localhost' );
     $vb->publish( "", "test", "Hello World!" );

Class Documentation
-------------------

VorpalBunny

  Constructor __construct (line 44)
  Initialize the VorpalBunny Class

     __construct (string $host, [int $port = 55672], [string $user = 'guest'], [string $pass = 'guest'], [string $vhost = '/'], [int $timeout = 300])
     
     * string $host:    RabbitMQ server to use
     * int    $port:    RabbitMQ Server HTTP port to use
     * string $user:    Username to pass to RabbitMQ when starting a session
     * string $pass:    Password to send to RabbitMQ when starting a session
     * string $vhost:   RabbitMQ VHost to use
     * int    $timeout: Timeout to set on the RabbitMQ JSONRPC Channel side


  publish (line 254)
  Send a message to RabbitMQ using Basic.Deliver over JSONRPC

  For more information on the parameters, see http://www.rabbitmq.com/amqp-0-9-1-quickref.html#basic.deliver

     return: Success/Failure
     throws: Exception on failure to make HTTP connection or in response to error in RabbitMQ JSONRPC Channel Plugin
     
     bool publish (string $exchange, string $routing_key, string $message, [string $mimetype = "text/plain"], [int $delivery_mode = 1], [bool $mandatory = false], [bool $immediate = false], [int $recursive = 0])
     
     * string $exchange:      to publish the message to, can be empty
     * string $routing_key:   to publish the message to
     * string $message:       to be published, should already be escaped/encoded
     * string $mimetype:      of message content content
     * int    $delivery_mode: for message: 1 non-persist message, 2 persist message
     * bool   $mandatory:     set the mandatory bit
     * bool   $immediate:     set the immediate bit
     * bool   $recursive:     counter for the number of times the publish function has called itself

License
-------
VorpalBunny is released under the BSD License
