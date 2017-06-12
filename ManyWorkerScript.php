<?php
/**
 * Created by PhpStorm.
 * User: wanghongjun
 * Date: 2017/6/9
 * Time: 下午6:25
 */

class ManyWorkerScript {

    public static $_debugLog = '/tmp/debug.log';  //调试输出的log文件

    public function __construct()
    {
        // init初始化的一些东西.
        self::checkPcntl();
    }


    public static function checkPcntl()
    {
        if (!function_exists('pcntl_signal_dispatch')) {
            declare(ticks = 10);
        }
        if (!function_exists(pcntl_signal())) {
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
     * @param $message
     *
     */
    public static function errorLog($message, $isKill = false) {
        error_log($message . "\n", 3, self::$_debugLog);
        if ($isKill) {
            die;
        }
    }

}