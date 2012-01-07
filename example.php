<?php

    require_once('StratumClient.inc.php');

    $c = new StratumClient('stratum.bitcoin.cz', 8001);

    $result = '';
    $result2 = '';
    $c->add_request('node.ping', array('ahoj'), $result);
    $c->add_request('firstbits.resolve', array('1marek'), $result2);
    $c->communicate();

    var_dump($result);
    var_dump($result2);

    $result = '';
    $c->add_request('firstbits.ping', array('cus'), $result);
    $c->communicate();

    var_dump($result);

