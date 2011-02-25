VorpalBunny
===========

VorpalBunny is a publishing client for RabbitMQ's JSON-RPC Channel Plugin

The goal is to be a light-weight tool for higher throughput with smaller
protocol overhead for calling Basic.Publish from PHP applications.

VorpalBunny uses PHP with CURL and APC to reduce the traffic footprint for
clients who do nothing but publishing. The workflow is to allow the client
to grab a session off of the RabbitMQ JSON-RPC Channel plugin web server
and reuse that session until it is no longer valid. This is accomplished by
using APC to cache the value of the sessionToken for the given server.

Your exchange and routing key *MUST* be setup prior to use.

Requirements
------------

PHP 5
w/ CURL
APC use is optional but recommended

Example Usage
-------------

     $vb = new VorpalBunny( 'localhost' );
     $vb->publish( "Hello World!", "", "test" );

License
-------
VorpalBunny is released under the BSD License