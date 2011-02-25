Vorpal Bunny
===========

Vorpal Bunny is a publishing client for RabbitMQ's JSON-RPC Channel Plugin

The goal is to be a light-weight tool for higher throughput with smaller
protocol overhead for calling Basic.Publish from PHP applications.

Vorpal Bunny uses PHP with CURL and APC to reduce the traffic footprint for
clients who do nothing but publishing. The workflow is to allow the client
to grab a session off of the RabbitMQ JSON-RPC Channel plugin web server
and reuse that session until it is no longer valid. This is accomplished by
using APC to cache the value of the sessionToken for the given server.

Your exchange and routing key *MUST* be setup prior to use.

Requirements
------------

* PHP 5
* w/ CURL
* APC use is optional but recommended

Example Usage
-------------

     $vb = new VorpalBunny( 'localhost' );
     $vb->publish( "", "test", "Hello World!" );

Class Documentation
-------------------

VorpalBunny

  Constructor __construct (line 43)
  # Initialize the VorpalBunny Class

  __construct (string $host, [int $port = 55672], [string $user = 'guest'], [string $pass = 'guest'], [string $vhost = '/'], [int $timeout = 30])
  - *string* _$host_: RabbitMQ server to use
  - *int* $port: RabbitMQ Server HTTP port to use
  - *string* $user: Username to pass to RabbitMQ when starting a session
  - *string* $pass: Password to send to RabbitMQ when starting a session
  - *string* $vhost: RabbitMQ VHost to use
  - *int* $timeout: Timeout to set on the RabbitMQ JSONRPC Channel side

  publish (line 224)
   # Send a message to RabbitMQ using Basic.Deliver over RPC
   #  For more information on the parameters, see http://www.rabbitmq.com/amqp-0-9-1-quickref.html#basic.deliver

     return: Success/Failure
     throws: Exception
     
     bool publish (string $exchange, string $routing_key, string $message, [string $mimetype = "text/plain"], [int $delivery_mode = 1], [bool $mandatory = false], [bool $immediate = false], [bool $recursion = false])
     - string $exchange: to publish the message to, can be empty
     - string $message: to be published, should already be escaped/encoded
     - string $routing_key: to publish the message to
     - string $mimetype: of message content content
     - int $delivery_mode: for message: 1 non-persist message, 2 persist message
     - bool $mandatory: set the mandatory bit
     - bool $immediate: set the immediate bit
     - bool $recursion: flag called when trying to recreate a new session

License
-------
VorpalBunny is released under the BSD License
