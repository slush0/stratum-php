<?php

    # Contains basic functionality for using Stratum protocol
    require_once('StratumClient.inc.php');

    # Extended functionality for exposing RPC services on this client
    require_once('StratumService.inc.php');

    class BlockchainBlockService extends StratumService
    {
        # This method can be called *from* the server as 'blockchain.block.new'
        function rpc_new($params)
        {
            echo "New block received";
        }
    }

    $c = new StratumClient('stratum.bitcoin.cz', 8001);
   
    # Expose service for receiving broadcasts about new blocks 
    $c->register_service('blockchain.block', new BlockchainBlockService());

    # Subscribe for receiving block broadcasts
    $c->add_request('blockchain.block.subscribe', array());

    # Perform some standard RPC calls
    $result = $c->add_request('node.ping', array('ahoj'));
    $result2 = $c->add_request('firstbits.resolve', array('1marek'));
    $c->communicate();

    var_dump($result->get());
    var_dump($result2->get());

    # Another call using the same session, but remote service doesn't exist
    $result = $c->add_request('service_which_does_not_exist.ping', array('cus'));
    $c->communicate();

    try {
        var_dump($result->get());
    } catch(Exception $e) {
        echo "RPC call failed (as expected, because we're calling non-existing service)";
    }

