$daemon = new Comm_DaemonScript(__CLASS__, __CLASS__, false);
        $daemon->daemonize();
        $daemon->start($this->worker);
        $pid     = posix_getpid();
        $monitor = $daemon->getMonitorFile();
        $daemon->updateRunTime($pid, $monitor);
