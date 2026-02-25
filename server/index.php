<?php

$available_pages = array();
require_once('weather.php');

$total_pages = count($available_pages);
$pagenum = isset($_REQUEST['rn']) ? intval($_REQUEST['rn']) % $total_pages : rand(0, $total_pages);
$available_pages[$pagenum]();

exit(0);
