<?php
require 'DaemonScript.php';

$daemon = new Comm_DaemonScript(__CLASS__, __CLASS__, false);
$daemon->daemonize();
$daemon->start(5);
$pid     = posix_getpid();
$monitor = $daemon->getMonitorFile();
$daemon->updateRunTime($pid, $monitor);

sleep(100);