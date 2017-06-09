<?php
/**
 * Created by PhpStorm.
 * User: wanghongjun
 * Date: 2017/6/9
 * Time: 下午6:25
 */

class ManyWorkerScript {


    public function __construct()
    {
        // init初始化的一些东西.
    }


    /*
     *
     * 使进程成为后台守护进程.
     *
     */
    public function daemonize()
    {
        // 只允许在命令行模式下操作
        if (php_sapi_name() !== 'cli') {
            exit("请在命令行模式下操作\n");
        }

        

    }
}