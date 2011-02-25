<?php
/**
 * Timed Test
 *
 * Usage:
 *   php timetest.php [host [qty]]
 *
 * @package VorpalBunny
 * @author Gavin M. Roy <gmr@myyearbook.com>
 */
require_once( "vorpalbunny.php" );

// Allow a command line override of localhost
if ( count( $argv ) > 1 )
{
  $broker = $argv[1];
}
else
{
  $broker = 'localhost';
}


// Allow a command line override the quantity
if ( count( $argv ) >= 3 )
{
  $quantity = $argv[2];
}
else
{
  $quantity = 1000;
}

// Create our VorpalBunny object
$vb = new VorpalBunny( $broker );

// Start time
$start = microtime( true );

// Send the messages
for ( $x = 0; $x < $quantity; $x++ )
{
  // Publish to our rabbitmq broker
  if ( ! $vb->publish( "", "test", "Hello World #" . $x ) )
  {
    print "Error publishing, exiting.\n";
    break;
  }
}

print number_format( $quantity, 0 ) . " messages sent in " . number_format( microtime( true ) - $start, 2 ) . " seconds\n";
