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
    public function __construct()
    {
        // init初始化的一些东西 .
        self::checkPcntl();
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

            // fork出指定数量的进程  .
            if (self::$_currentWorkerNum < $workerNum) {
                $pid = pcntl_fork();
                if ($pid > 0) {
                    self::$_currentWorkerNum++;
                } elseif ($pid == 0){
                    //子进程直接退出了, 不要重复fork
                    return ;
                } else {
                    exit('fork fatal error');
                    break;
                }
            }

            sleep(1);
        }
    }
    /**
     * @param $message
     * return null
     */
    public static function errorLog($message, $isKill = false) {
        error_log($message . "\n", 3, self::$_debugLog);
        if ($isKill) {
            die;
        }
    }

}