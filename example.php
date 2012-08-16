<?php

    # Contains basic functionality for using Stratum protocol
    require_once('StratumClient.inc.php');

    class TimestampReceivingService extends StratumService
    {
        # This catch the notification of the 'example.pubsub.time_event'
        function time_event($params)
        {
            echo "New timestamp received: ";
            var_dump($params);
        }
    }
    
    $c = new StratumClient('california.stratum.bitcoin.cz', 8000);
    #$c->set_push_url('http://marek.palatinus.cz/stratum-push/');

    #$c = new StratumClient('localhost', 8000);
   
    if(file_exists('session.dat')) $c->unserialize(file_get_contents('session.dat'));

    # Expose service for receiving broadcasts about new blocks 
    $c->register_service('example.pubsub', new TimestampReceivingService());

    # Subscribe for receiving unix timestamps from stratum server
    $c->add_request('example.pubsub.subscribe', array(2));

    # Perform some standard RPC calls
    $result = $c->add_request('example.hello_world', array());
    $result2 = $c->add_request('example.ping', array('ahoj'));
    $c->communicate();

    var_dump($result->get());
    var_dump($result2->get());

    # Another call using the same session, but remote service will throw an exception
    $result = $c->add_request('example.throw_exception', array());
    $c->communicate();

    try {
        var_dump($result->get());
    } catch(Exception $e) {
        echo "RPC call failed, which is expected\n";
    }

    # Test of receiving notifications in HTTP Polling mode
    for($x=0;$x<3;$x++) {
        echo "Polling...\n";
        $c->communicate();
        sleep(3);
    }

   file_put_contents('session.dat', $c->serialize());
