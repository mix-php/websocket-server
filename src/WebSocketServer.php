<?php

namespace Mix\WebSocket\Server;

use Mix\Core\Bean\AbstractObject;
use Mix\Core\Coroutine;
use Mix\Helper\ProcessHelper;
use Mix\WebSocket\Frame;

/**
 * Class WebSocketServer
 * @package Mix\WebSocket\Server
 * @author liu,jian <coder.keda@gmail.com>
 */
class WebSocketServer extends AbstractObject
{

    /**
     * 主机
     * @var string
     */
    public $host = '127.0.0.1';

    /**
     * 端口
     * @var int
     */
    public $port = 9502;

    /**
     * 应用配置文件
     * @var string
     */
    public $configFile = '';

    /**
     * 运行参数
     * @var array
     */
    public $setting = [];

    /**
     * 服务名称
     * @var string
     */
    const SERVER_NAME = 'mix-websocketd';

    /**
     * 默认运行参数
     * @var array
     */
    protected $_defaultSetting = [
        // 开启自定义握手
        'enable_handshake'       => true,
        // 开启协程
        'enable_coroutine'       => true,
        // 主进程事件处理线程数
        'reactor_num'            => 8,
        // 工作进程数
        'worker_num'             => 8,
        // 任务进程数
        'task_worker_num'        => 0,
        // PID 文件
        'pid_file'               => '/var/run/mix-websocketd.pid',
        // 日志文件路径
        'log_file'               => '/tmp/mix-websocketd.log',
        // 异步安全重启
        'reload_async'           => true,
        // 退出等待时间
        'max_wait_time'          => 60,
        // 开启后，PDO 协程多次 prepare 才不会有 40ms 延迟
        'open_tcp_nodelay'       => true,
        // 进程的最大任务数
        'max_request'            => 0,
        // 主进程启动事件回调
        'hook_start'             => null,
        // 主进程停止事件回调
        'hook_shutdown'          => null,
        // 管理进程启动事件回调
        'hook_manager_start'     => null,
        // 工作进程错误事件
        'hook_worker_error'      => null,
        // 管理进程停止事件回调
        'hook_manager_stop'      => null,
        // 工作进程启动事件回调
        'hook_worker_start'      => null,
        // 工作进程停止事件回调
        'hook_worker_stop'       => null,
        // 工作进程退出事件回调
        'hook_worker_exit'       => null,
        // 请求事件回调
        'hook_request'           => null,
        // 请求成功回调
        'hook_request_success'   => null,
        // 请求错误回调
        'hook_request_error'     => null,
        // 握手成功回调
        'hook_handshake_success' => null,
        // 握手错误回调
        'hook_handshake_error'   => null,
        // 开启成功回调
        'hook_open_success'      => null,
        // 开启错误回调
        'hook_open_error'        => null,
        // 消息成功回调
        'hook_message_success'   => null,
        // 消息错误回调
        'hook_message_error'     => null,
        // 关闭成功回调
        'hook_close_success'     => null,
        // 关闭错误回调
        'hook_close_error'       => null,
    ];

    /**
     * 运行参数
     * @var array
     */
    protected $_setting = [];

    /**
     * 服务器
     * @var \Swoole\WebSocket\Server
     */
    protected $_server;

    /**
     * 启动服务
     * @return bool
     */
    public function start()
    {
        // 初始化
        $this->_server = new \Swoole\WebSocket\Server($this->host, $this->port);
        // 配置参数
        $this->_setting = $this->setting + $this->_defaultSetting;
        $this->_server->set($this->_setting);
        // 覆盖参数
        $this->_server->set([
            'enable_coroutine' => false, // 关闭默认协程，回调中有手动开启支持上下文的协程
        ]);
        // 绑定事件
        $this->_server->on(SwooleEvent::START, [$this, 'onStart']);
        $this->_server->on(SwooleEvent::SHUTDOWN, [$this, 'onShutdown']);
        $this->_server->on(SwooleEvent::MANAGER_START, [$this, 'onManagerStart']);
        $this->_server->on(SwooleEvent::WORKER_ERROR, [$this, 'onWorkerError']);
        $this->_server->on(SwooleEvent::MANAGER_STOP, [$this, 'onManagerStop']);
        $this->_server->on(SwooleEvent::WORKER_START, [$this, 'onWorkerStart']);
        $this->_server->on(SwooleEvent::WORKER_STOP, [$this, 'onWorkerStop']);
        $this->_server->on(SwooleEvent::WORKER_EXIT, [$this, 'onWorkerExit']);
        $this->_server->on(SwooleEvent::REQUEST, [$this, 'onRequest']);
        if ($this->_setting['enable_handshake']) {
            $this->_server->on(SwooleEvent::HANDSHAKE, [$this, 'onHandshake']);
        } else {
            $this->_server->on(SwooleEvent::OPEN, [$this, 'onOpen']);
        }
        $this->_server->on(SwooleEvent::MESSAGE, [$this, 'onMessage']);
        $this->_server->on(SwooleEvent::CLOSE, [$this, 'onClose']);
        // 欢迎信息
        $this->welcome();
        // 执行回调
        $this->_setting['hook_start'] and call_user_func($this->_setting['hook_start'], $this->_server);
        // 启动
        return $this->_server->start();
    }

    /**
     * 主进程启动事件
     * 仅允许echo、打印Log、修改进程名称，不得执行其他操作
     * @param \Swoole\WebSocket\Server $server
     */
    public function onStart(\Swoole\WebSocket\Server $server)
    {
        // 进程命名
        ProcessHelper::setProcessTitle(static::SERVER_NAME . ": master {$this->host}:{$this->port}");
    }

    /**
     * 主进程停止事件
     * 请勿在onShutdown中调用任何异步或协程相关API，触发onShutdown时底层已销毁了所有事件循环设施
     * @param \Swoole\Server $server
     */
    public function onShutdown(\Swoole\Server $server)
    {
        try {

            // 执行回调
            $this->_setting['hook_shutdown'] and call_user_func($this->_setting['hook_shutdown'], $server);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 管理进程启动事件
     * 可以使用基于信号实现的同步模式定时器swoole_timer_tick，不能使用task、async、coroutine等功能
     * @param \Swoole\WebSocket\Server $server
     */
    public function onManagerStart(\Swoole\WebSocket\Server $server)
    {
        try {

            // 进程命名
            ProcessHelper::setProcessTitle(static::SERVER_NAME . ": manager");
            // 执行回调
            $this->_setting['hook_manager_start'] and call_user_func($this->_setting['hook_manager_start'], $server);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 工作进程错误事件
     * 当Worker/Task进程发生异常后会在Manager进程内回调此函数。
     * @param \Swoole\Server $server
     */
    public function onWorkerError(\Swoole\Server $server, int $workerId, int $workerPid, int $exitCode, int $signal)
    {
        try {

            // 执行回调
            $this->_setting['hook_worker_error'] and call_user_func($this->_setting['hook_worker_error'], $server, $workerId, $workerPid, $exitCode, $signal);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 管理进程停止事件
     * @param \Swoole\WebSocket\Server $server
     */
    public function onManagerStop(\Swoole\WebSocket\Server $server)
    {
        try {

            // 执行回调
            $this->_setting['hook_manager_stop'] and call_user_func($this->_setting['hook_manager_stop'], $server);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 工作进程启动事件
     * @param \Swoole\WebSocket\Server $server
     * @param int $workerId
     */
    public function onWorkerStart(\Swoole\WebSocket\Server $server, int $workerId)
    {
        try {

            // 进程命名
            if ($workerId < $server->setting['worker_num']) {
                ProcessHelper::setProcessTitle(static::SERVER_NAME . ": worker #{$workerId}");
            } else {
                ProcessHelper::setProcessTitle(static::SERVER_NAME . ": task #{$workerId}");
            }
            // 执行回调
            $this->_setting['hook_worker_start'] and call_user_func($this->_setting['hook_worker_start'], $server);
            // 实例化App
            new \Mix\WebSocket\Application(require $this->configFile);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 工作进程停止事件
     * @param \Swoole\WebSocket\Server $server
     * @param int $workerId
     */
    public function onWorkerStop(\Swoole\WebSocket\Server $server, int $workerId)
    {
        try {

            // 执行回调
            $this->_setting['hook_worker_stop'] and call_user_func($this->_setting['hook_worker_stop'], $server);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 工作进程退出事件
     * 仅在开启reload_async特性后有效。异步重启特性，会先创建新的Worker进程处理新请求，旧的Worker进程自行退出
     * @param \Swoole\Server $server
     */
    public function onWorkerExit(\Swoole\Server $server, int $workerId)
    {
        try {

            // 执行回调
            $this->_setting['hook_worker_exit'] and call_user_func($this->_setting['hook_worker_exit'], $server, $workerId);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 请求事件
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    public function onRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        try {

            // 执行回调
            $this->_setting['hook_request'] and call_user_func($this->_setting['hook_request'], $this->_server, $request, $response);
            $this->_setting['hook_request_success'] and call_user_func($this->_setting['hook_request_success'], $this->_server, $request);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->_setting['hook_request_error'] and call_user_func($this->_setting['hook_request_error'], $this->_server, $request);
        }
    }

    /**
     * 握手事件
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    public function onHandshake(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        if ($this->_setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($request, $response) {
                call_user_func([$this, 'onHandshake'], $request, $response);
            });
            return;
        }
        try {

            $fd = $request->fd;
            // 前置初始化
            \Mix::$app->request->beforeInitialize($request);
            \Mix::$app->response->beforeInitialize($response);
            \Mix::$app->ws->beforeInitialize($this->_server, $fd);
            \Mix::$app->registry->beforeInitialize($fd);
            // 拦截
            \Mix::$app->runHandshake(\Mix::$app->ws, \Mix::$app->request, \Mix::$app->response);
            // 执行回调
            $this->_setting['hook_handshake_success'] and call_user_func($this->_setting['hook_handshake_success'], $this->_server, $request);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->_setting['hook_handshake_error'] and call_user_func($this->_setting['hook_handshake_error'], $this->_server, $request);
        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 开启事件
     * @param \Swoole\WebSocket\Server $server
     * @param \Swoole\Http\Request $request
     */
    public function onOpen(\Swoole\WebSocket\Server $server, \Swoole\Http\Request $request)
    {
        if ($this->_setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($server, $request) {
                call_user_func([$this, 'onOpen'], $server, $request);
            });
            return;
        }
        try {

            $fd = $request->fd;
            // 前置初始化
            \Mix::$app->request->beforeInitialize($request);
            \Mix::$app->ws->beforeInitialize($server, $fd);
            \Mix::$app->registry->beforeInitialize($fd);
            // 处理消息
            \Mix::$app->runOpen(\Mix::$app->ws, \Mix::$app->request);
            // 执行回调
            $this->_setting['hook_open_success'] and call_user_func($this->_setting['hook_open_success'], $server, $request);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->_setting['hook_open_error'] and call_user_func($this->_setting['hook_open_error'], $server, $request);
        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 消息事件
     * @param \Swoole\WebSocket\Server $server
     * @param \Swoole\WebSocket\Frame $frame
     */
    public function onMessage(\Swoole\WebSocket\Server $server, \Swoole\WebSocket\Frame $frame)
    {
        if ($this->_setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($server, $frame) {
                call_user_func([$this, 'onMessage'], $server, $frame);
            });
            return;
        }
        try {

            $fd = $frame->fd;
            // 前置初始化
            \Mix::$app->ws->beforeInitialize($server, $fd);
            \Mix::$app->registry->beforeInitialize($fd);
            // 处理消息
            \Mix::$app->runMessage(\Mix::$app->ws, new Frame($frame));
            // 执行回调
            $this->_setting['hook_message_success'] and call_user_func($this->_setting['hook_message_success'], $server, $fd);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->_setting['hook_message_error'] and call_user_func($this->_setting['hook_message_error'], $server, $fd);
        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 关闭事件
     * @param \Swoole\WebSocket\Server $server
     * @param int $fd
     * @param int $reactorId
     */
    public function onClose(\Swoole\WebSocket\Server $server, int $fd, int $reactorId)
    {
        // 检查连接是否为有效的WebSocket客户端连接
        if (!$server->isEstablished($fd)) {
            return;
        }
        if ($this->_setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($server, $fd, $reactorId) {
                call_user_func([$this, 'onClose'], $server, $fd, $reactorId);
            });
            return;
        }
        try {

            // 前置初始化
            \Mix::$app->ws->beforeInitialize($server, $fd);
            \Mix::$app->registry->beforeInitialize($fd);
            // 处理连接关闭
            \Mix::$app->runClose(\Mix::$app->ws);
            \Mix::$app->registry->afterInitialize();
            // 执行回调
            $this->_setting['hook_close_success'] and call_user_func($this->_setting['hook_close_success'], $server, $fd);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->_setting['hook_close_error'] and call_user_func($this->_setting['hook_close_error'], $server, $fd);
        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 欢迎信息
     */
    protected function welcome()
    {
        $swooleVersion = swoole_version();
        $phpVersion    = PHP_VERSION;
        echo <<<EOL
                             _____
_______ ___ _____ ___   _____  / /_  ____
__/ __ `__ \/ /\ \/ /__ / __ \/ __ \/ __ \
_/ / / / / / / /\ \/ _ / /_/ / / / / /_/ /
/_/ /_/ /_/_/ /_/\_\  / .___/_/ /_/ .___/
                     /_/         /_/


EOL;
        println('Server         Name:      ' . static::SERVER_NAME);
        println('System         Name:      ' . strtolower(PHP_OS));
        println("PHP            Version:   {$phpVersion}");
        println("Swoole         Version:   {$swooleVersion}");
        println('Framework      Version:   ' . \Mix::$version);
        $this->_setting['max_request'] == 1 and println('Hot            Update:    enabled');
        $this->_setting['enable_coroutine'] and println('Coroutine      Mode:      enabled');
        println("Listen         Addr:      {$this->host}");
        println("Listen         Port:      {$this->port}");
        println('Reactor        Num:       ' . $this->_setting['reactor_num']);
        println('Worker         Num:       ' . $this->_setting['worker_num']);
        println("Configuration  File:      {$this->configFile}");
    }

}
