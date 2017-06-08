<?php

/**
 * Daemon
 *
 * Example:
 *     $daemon = new Comm_DaemonScript();
 *     $daemon->daemonize();
 *     $daemon->start(1); //开进程数
 *   注意kill的时候请使用 15信号，9程序不能捕获
 *
 *     额外功能
 *        1.可以设置 子进程的超时时间，超时自动查杀后再启动
 *        2.整个daemon退出（会等待所有子进程处理退出状态 才会退出）
 *
 * @author xuyong5
 * @version 1.0
 *
 */
class Comm_DaemonScript {

    /**  the stock default directory privdata
     *   default get PRIVATE_DATA_PATH constant
     */
    private static $runtime_dir   = '/data1/www/privdata/stock.weibo.com/';
    public  static $log_dir_name  = 'daemon_log';

    private $_info_dir;
    private $_pid_file;
    private $_log_file;
    private $_terminate     = false;
    private $_workers_count = 0;
    private $_workers_max   = 64;
    private $_set_user      = false;
    private $_user          = 'www';
    private $_skill         = false;
    private $monitor        = '_monitor';
    private $_max_time      = 0;
    private $_child_quit    = '_child_quit';
    private $_shutdown      = '_shutdown';
    private $count          = 0;
    /**
     *
     * @param string $pid_file
     * @param string $log_file
     * @param bool   $skill 强列重启
     */
    public function __construct($pid_file = null, $log_file = null,$skill = false) {
        $this->_info_dir = rtrim(self::$runtime_dir,'/').DIRECTORY_SEPARATOR.self::$log_dir_name;
        if(!is_dir($this->_info_dir)){
            @mkdir($this->_info_dir, 0777,true);
        }
        $this->_skill = $skill;
        if (!$pid_file) {
            $pid_file = get_called_class();
        }
        if (!$log_file) {
            $log_file = get_called_class();
        }
        $this->_checkPcntl();
        $this->_setSignalHandler();

        $this->_setPidFile($pid_file);
        $this->_setLogFile($log_file);
        $this->_setMonitor($log_file);
        $this->_setChldQuit($log_file);
        $this->_setShutdown($log_file);
        // Enable PHP 5.3 garbage collection
        if (function_exists('gc_enable')) {
            gc_enable();
        }
    }

    private function _setPidFile($pid_file = null) {
        if ($pid_file) {
            $this->_pid_file = $this->_info_dir . DIRECTORY_SEPARATOR . trim($pid_file, '/') . '.pid';
            return;
        }
        exit('you should set the pid file');
    }

    private function _setLogFile($log_file = null) {
        if ($log_file) {
            $this->_log_file = $this->_info_dir . DIRECTORY_SEPARATOR . trim($log_file, '/') . '.log';
            return;
        }
        exit('you should set the log file');
    }

    public function setUser($user) {
        $this->_user = $user;
        return $this;
    }

    private function _setMonitor($log_file = null) {
        if ($log_file) {
            $this->monitor = $this->_info_dir . DIRECTORY_SEPARATOR . trim($log_file.$this->monitor, '/') . '.log';
            return;
        }
    }

    private function _setChldQuit($log_file = null) {
        if($log_file){
            $this->_child_quit = $this->_info_dir . DIRECTORY_SEPARATOR . trim($log_file.$this->_child_quit, '/') . '.log';
            return;
        }
    }

    private function _setShutdown($log_file = null) {
        if($log_file){
            $this->_shutdown = $this->_info_dir . DIRECTORY_SEPARATOR . trim($log_file.$this->_shutdown, '/') . '.log';
            return;
        }
    }

    private function _checkPcntl() {
        if (!function_exists('pcntl_signal_dispatch')) {
            declare(ticks = 10);
        }
        if (!function_exists('pcntl_signal')) {
            exit('PHP does not appear to be compiled with the PCNTL extension');
        }
    }

    private function _setSignalHandler() {
        pcntl_signal(SIGTERM, array(__CLASS__, 'signalHandler'), false);
        pcntl_signal(SIGINT, array(__CLASS__, 'signalHandler'), false);
        pcntl_signal(SIGQUIT, array(__CLASS__, 'signalHandler'), false);
        pcntl_signal(SIGCHLD, array(__CLASS__, 'signalHandler'), false);
        pcntl_signal(SIGUSR1, array(__CLASS__, 'signalHandler'), false);
    }

    private function _restoreSignalHandler() {
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
        pcntl_signal(SIGQUIT, SIG_DFL);
        pcntl_signal(SIGCHLD, SIG_DFL);
        pcntl_signal(SIGUSR1, SIG_DFL);
    }


    public function signalHandler($signo) {
        switch ($signo) {
            // terminate the process
            case SIGTERM:
            case SIGINT:
            case SIGQUIT:
                $this->_terminate = true;
                break;

            // child process exists
            case SIGCHLD:
                while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                    $this->_workers_count--;
                    $this->removeMonitor($pid);
                }
                break;

            // fork more children
            case SIGUSR1:
                if ($this->_workers_count < $this->_workers_max) {
                    $pid = pcntl_fork();
                    if ($pid > 0) {
                        $this->_workers_count ++;
                    }
                }
                break;

        }
    }

    public function daemonize() {
        set_time_limit(0);

        // only run in command line mode
        if (php_sapi_name() != 'cli') {
            exit("only run in cli mode'");
        }

        //$this->_checkPidFile();

        // set umask 0
        umask(0);

        // parent process exit
        if (pcntl_fork() != 0) {
            exit();
        }

        //make the current process a session leader
        posix_setsid();

        //close the first child process
        if (pcntl_fork() != 0) {
            exit();
        }

        //change directory
        chdir('/');

        if ($this->_set_user) {
            if (!$this->_setUser($this->_user)) {
                exit('cannot change owner');
            }
        }

        // close open file description
        fclose(STDIN);
        fclose(STDOUT);

        $this->_createPidFile();
        $this->_createMonitorFile();
        return $this;
    }

    private function _checkPidFile() {
        if (!file_exists($this->_pid_file)) {
            return;
        }
        $pid = (int) trim(file_get_contents($this->_pid_file));
        if($this->_skill){
            posix_kill($pid, SIGTERM);
            usleep(100000);
            return;
        }
        if ($pid > 0 && posix_kill($pid, 0)) {
            $message = 'the daemon process is already started';
        } else {
            $message = 'the daemon process end abnormally, please check pid file ' . $this->_pid_file;
        }
        exit($message);
    }

    /**
     * Create the pid file
     *
     * @return void
     */
    private function _createPidFile() {
        if (!is_dir($this->_info_dir)) {
            mkdir($this->_info_dir);
        }

        $fp = fopen($this->_pid_file, 'w');
        if (!$fp) {
            exit('can not create pid file');
        }

        fwrite($fp, posix_getpid());
        fclose($fp);
        clearstatcache(true,$this->_pid_file);
        $this->log('create pid file ' . $this->_pid_file);
    }

    /**
     * create the monitor file
     */
    private function _createMonitorFile() {
        $fp = fopen($this->monitor, 'w');
        fclose($fp);
        clearstatcache(true,$this->monitor);
        $this->log('create monitor file ' . $this->monitor);
    }

    /**
     *  get onitorFile
     * @return string filename
     */
    public function getMonitorFile() {
        return $this->monitor;
    }

    /**
     * delete pid file
     *
     * @return void
     */
    private function _deletePidFile() {
        $pidFile = $this->_pid_file;
        if (file_exists($pidFile)) {
            unlink($pidFile);
            $this->log('delete pid file ' . $pidFile);
        }
    }

    /**
     * delete monitorFile
     * @return void
     */
    private function _deleteMonitorFile() {
        $monitor = $this->monitor;
        if(file_exists($monitor)){
            unlink($monitor);
            $this->log('delete monitor log file '.$monitor);
        }
    }

    /**
     * delete clhldQuit log file
     * @return void
     */
    private function _deleteChldQuitFile() {
        $chldQuit = $this->_child_quit;
        if(file_exists($chldQuit)){
            unlink($chldQuit);
            $this->log('delete child quit log  file '.$chldQuit);
        }
    }

    private function _deleteShutdownFile() {
        $shutdown = $this->_shutdown;
        if(file_exists($this->_shutdown)){
            unlink($shutdown);
            $this->log('delete shut down log  file '.$shutdown);
        }
    }

    private function _setUser($name) {
        if (empty($name)) {
            return true;
        }

        $user = posix_getpwnam($name);
        if ($user) {
            $uid = $user['uid'];
            $gid = $user['gid'];
            $result = posix_setuid($uid);
            posix_setgid($gid);
            return $result;
        }

        return false;
    }


    public function start($count = 1) {
        $this->log('daemon process is running now');
        $this->count = $count;
        while (true) {
            //calls signal handlers for pending signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            //exit
            if ($this->_terminate) {
                break;
            }

            //fork 
            if ($this->_workers_count < $count) {
                $pid = pcntl_fork();

                if ($pid > 0) {
                    $this->_workers_count++;
                } elseif ($pid == 0) {
                    $this->_restoreSignalHandler();
                    return;
                }
            }

            //check shutdown signal
            if($shutdownWorkers = $this->_getShutdown()){
                if(count($shutdownWorkers) == $this->count){
                    $shutdownWorkers = implode(',', $shutdownWorkers);
                    $this->log("send daemon {$shutdownWorkers} num process  shutdown signal now");
                    break;
                }
            }

            //check child max running time 
            if($this->_max_time !== 0){
                if(file_exists($this->monitor)){
                    $monitor = json_decode(trim(file_get_contents($this->monitor)), true);
                    if ($monitor) {
                        foreach ($monitor as $_pid => $utime) {
                            if (( time() - $utime ) > $this->_max_time) {
                                posix_kill($_pid, SIGTERM);
                            }
                        }
                    }
                    $monitor = null;
                }
            }
            sleep(1);
        }

        $this->_mainQuit();
        exit();
    }

    /**
     * set max running time
     * @param  int $time （second）
     */
    public function setMaxTime($time) {
        $this->_max_time = (int) $time;
    }

    /**
     * update running time
     * @param int $pid
     * @param string $monitor (filename)
     * @return void
     */
    public  function updateRunTime($pid,$monitor) {
        $mode = file_exists($monitor) ? 'r+' : 'w+';
        $fo   = fopen($monitor, $mode);
        if(!flock($fo, LOCK_EX|LOCK_NB)){
            fclose($fo);
            return;
        }
        $size   = @filesize($monitor);
        $pidArr = array();
        if($size > 0){
            $pidArr = json_decode((trim(fread($fo, $size))), true);
            if(!$pidArr){
                $pidArr = array();
            }
        }
        $pidArr[$pid] = time();
        fseek($fo, 0, SEEK_SET);
        ftruncate($fo, 0);
        fwrite($fo, json_encode($pidArr));
        flock($fo, LOCK_UN);
        fclose($fo);
        clearstatcache(true,$monitor);
        $pidArr = null;
        $size   = 0;
        return;
    }

    /**
     * remove quit process monitoring
     * @param int $pid
     * @return void
     */
    public  function removeMonitor($pid) {
        $data = self::getContents($this->monitor);
        if(!$data){
            return;
        }
        $arr  = json_decode($data, true);
        $data = null;
        if(isset($arr[$pid])){
            unset($arr[$pid]);
            $this->childQuitLog($pid);
            @file_put_contents($this->monitor, json_encode($arr),LOCK_EX);
            $arr = null;
            return;
        }
    }

    private function _mainQuit() {
        $this->_deletePidFile();
        $this->_deleteMonitorFile();
        $this->_deleteChldQuitFile();
        $this->_deleteShutdownFile();
        $this->log('daemon process exit now');
        posix_kill(0, SIGKILL);
        exit();
    }

    /**
     * child send a signal to exit
     * @return void
     */
    public function shutdown($pid) {
        $mode    = file_exists($this->_shutdown) ? 'r+' : 'w+';
        $pidArr  = array();
        $fo      = fopen($this->_shutdown, $mode);
        if(!flock($fo, LOCK_EX|LOCK_NB)){
            fclose($fo);
            return;
        }
        $size   = @filesize($this->_shutdown);
        $pidArr = array();
        if($size > 0){
            $pidArr = json_decode((trim(fread($fo, $size))), true);
            if(isset($pidArr[$pid])){
                flock($fo, LOCK_UN);
                fclose($fo);
                return;
            }
        }
        $pidArr[$pid] = time();
        fseek($fo, 0, SEEK_SET);
        ftruncate($fo, 0);
        fwrite($fo, json_encode($pidArr));
        flock($fo, LOCK_UN);
        fclose($fo);
        clearstatcache(true,  $this->_shutdown);
        $pidArr = null;
        $size   = 0;
        return;
    }

    /**
     * get child signal
     * @return int
     */
    public function _getShutdown() {
        if(!file_exists($this->_shutdown)){
            return false;
        }
        if($workers = self::getContents($this->_shutdown)) {
            $workers = json_decode($workers,true);
            if(!$workers){
                return false;
            }
            $shutdownPid = array_map(function($v){
                return (int) $v;
            }, (array_keys($workers)));
            $runningJson  = self::getContents($this->monitor);
            $runningArr   = json_decode($runningJson,true);
            if(!$runningArr){
                return $shutdownPid;
            }
            $runningPid  = array_map(function ($v){
                return (int) $v;
            },(array_keys($runningArr)));
            $workers     = null;
            $runningJson = null;
            $runningArr  = null;
            sort($shutdownPid);
            sort($runningPid);
            if($shutdownPid == $runningPid){
                return $shutdownPid;
            }
        }
        return false;

    }

    public function childQuitLog($pid) {
        $message = date('Y-m-d H:i:s') ."\tpid:".$pid . " process timeout or is killed \n";
        error_log($message, 3, $this->_child_quit);
    }

    public function log($message) {
        $message = date('Y-m-d H:i:s') . "\tpid:" . posix_getpid() . "\tppid:" . posix_getppid() . "\t" . $message . "\n";
        error_log($message, 3, $this->_log_file);
    }

    public static function getContents($path) {
        if (!file_exists($path)) {
            return false;
        }
        $fo     = fopen($path, 'r');
        $locked = flock($fo, LOCK_SH|LOCK_NB);
        if (!$locked) {
            fclose($fo);
            return false;
        }
        $cts = file_get_contents($path);
        flock($fo, LOCK_UN);
        fclose($fo);
        return trim($cts);
    }
}
