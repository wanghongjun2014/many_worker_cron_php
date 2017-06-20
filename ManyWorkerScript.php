<?php
/**
 * Created by PhpStorm.
 * User: wanghongjun
 * Date: 2017/6/9
 * Time: 下午6:25
 */

class ManyWorkerScript {

    public static $_debugLog = '/tmp/debug.log';  // 调试输出的log文件 .

    public static $_maxWorkerNum = 10;  // 某台机器所允许的最大进程数, 后期需要自动计算进程运算占用的内存自动优化 .

    public static $_killChangeProcessResult = false; // 杀死某个正在运行的进程是否影响结果, 默认不影响 .

    public static $_currentWorkerNum = 0; // 当前正在运行的进程数 .

    public static $_maxChildTime = 0; // 子进程的最大超时时间, 0表示无时间限制 .

    public static $_childAutoRestart = true; // 子进程退出时(包含kill -15掉)是否自动重新启动, 会造成无限fork子进程

    /**
     * ManyWorkerScript constructor.
     * init初始化的一些东西
     */
    public function __construct()
    {
        // 检查pcntl扩展.
        self::checkPcntl();

        // 注册信号处理机制 .
        self::registerSignal();
    }


    public static function checkPcntl()
    {
        if (!function_exists('pcntl_signal_dispatch')) {
            declare(ticks = 10);
        }
        if (!function_exists('pcntl_signal')) {
            exit('PHP lacks PCNTL extensions');
        }
    }

    /**
     *
     * 使进程成为后台守护进程.
     *
     */
    public static function daemonize()
    {
        set_time_limit(0);

        // 只允许在命令行模式下操作
        if (php_sapi_name() !== 'cli') {
            exit("请在命令行模式下操作\n");
        }

        umask(0);

        // start child process
        if (0 != pcntl_fork()) {
            exit(0);
        }

        posix_setsid();

        // kill child process, start grandchild process.
        if (0 != pcntl_fork()) {
            exit(0);
        }

        if (false == chdir('/')) {
            exit(0);
        }

        // close file description
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
    }

    /**
     * @param int $workerNum
     * 多进程主程序入口
     */
    public function main($workerNum = 2)
    {
        self::daemonize();

        $workerNum = $workerNum > self::$_maxWorkerNum ? self::$_maxWorkerNum : $workerNum;

        while (true) {

            // 实时监测各种信号 .
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // fork出指定数量的进程 .
            if (self::$_currentWorkerNum < $workerNum) {
                $pid = pcntl_fork();
                if ($pid > 0) {
                    self::$_currentWorkerNum++;
                } elseif ($pid == 0){
                    //todo 子进程是否需要重新注册信号处理, 需要验证
                    return ;
                } else {
                    exit('fork fatal error');
                    break;
                }
            }
            sleep(1);
        }
    }

    public static function registerSignal()
    {
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGCHLD, "self::signalHandle", false);
        pcntl_signal(SIGINT, "self::signalHandle", false);
        pcntl_signal(SIGQUIT, "self::signalHandle", false);
    }

    public static function childRegisterSignal()
    {
        pcntl_signal(SIGTERM, "self::signalHandle");
        pcntl_signal(SIGCHLD, "self::signalHandle");
    }

    /**
     * @param $signal
     */
    public static function signalHandle($signal)
    {
        switch ($signal) {
            case SIGINT:
                self::errorLog("SIGINT\n");
                break;

            case SIGQUIT:
                self::errorLog("SIGQUIT\n");
                break;

            case SIGTERM:
                // kill杀死父进程(kill -15优雅的杀死)时 .
                self::errorLog("某个进程被杀死\n");
                break;

            case SIGCHLD:

                // 子进程正常结束(或者用kill -15杀死子进程的时候会向父进程发送该信号, ps: 用kill杀死子进程的时候并不会发送SIGTERM信号) .
                self::errorLog("某个子进程正常结束了\n");

                // 下面的$childPid表示退出的子进程的pid
                while (($childPid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                    if (self::$_childAutoRestart) {
                        self::$_currentWorkerNum--;
                    }
                }

                break;

            // 其他信号, 想到再加上
            default:
                self::errorLog("此" . $signal ."信号没有在程序中注册使用\n");
                break;
        }
    }

    /**
     * @param $message
     * @param $isKill
     * message字符串格式, isKill 表示打log之后是否终止程序的执行
     */
    public static function errorLog($message, $isKill = false) {
        error_log($message . "\n", 3, self::$_debugLog);
        if ($isKill) {
            die;
        }
    }

}