<?php

require('config.php');

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

function load_database_or_fail($fname = NULL)
{
    if ($fname == NULL) {
        $fname = 'pico-info-screen.sqlite3';
    }

    $db = new SQLite3($fname, SQLITE3_OPEN_READWRITE);
    if ($db === false) {
        fail503('Failed to open database. Please try again later.');
    }
    return $db;
}

function close_database($db)
{
    if ($db != NULL) {
        $db->close();
    }
}

