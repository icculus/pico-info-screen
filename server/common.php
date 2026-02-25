<?php

require_once('config.php');

$user_agent = 'pico-info-screen/0.1';

function fail($response, $msg)
{
    if ($response != NULL) {
        header("HTTP/1.0 $response");
    }

    header('Content-Type: text/plain');
    echo "\n\n$msg\n\n\n";

    exit(0);
}

function fail503($msg)
{
    fail('503 Service Unavailable', $msg);
}

