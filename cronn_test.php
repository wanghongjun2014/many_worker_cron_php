<?php
//
//require 'DaemonScript.php';
//
//$daemon = new Comm_DaemonScript(__CLASS__, __CLASS__, false);
//
//$daemon->daemonize();
//
//$daemon->start(5);
//
////$pid     = posix_getpid();
////$monitor = $daemon->getMonitorFile();
////$daemon->updateRunTime($pid, $monitor);
//
//function my_log($msg) {
//    error_log($msg . "\n", 3, '/tmp/log.log');
//}
//
//sleep(1000);


require 'ManyWorkerScript.php';
$obj = new ManyWorkerScript();
$obj->main(5);

sleep(40);

