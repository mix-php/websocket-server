<?php

namespace Mix\WebSocket\Server;

use Mix\Core\Bean\AbstractObject;
use Mix\Core\Coroutine;
use Mix\Helper\ProcessHelper;

/**
 * Class WebSocketServer
 * @package Mix\WebSocket\Server
 * @author LIUJIAN <coder.keda@gmail.com>
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
    protected $_setting = [
        // 开启协程
        'enable_coroutine' => false,
        // 主进程事件处理线程数
        'reactor_num'      => 8,
        // 工作进程数
        'worker_num'       => 8,
        // 任务进程数
        'task_worker_num'  => 0,
        // PID 文件
        'pid_file'         => '/var/run/mix-websocketd.pid',
        // 日志文件路径
        'log_file'         => '/tmp/mix-websocketd.log',
        // 异步安全重启
        'reload_async'     => true,
        // 退出等待时间
        'max_wait_time'    => 60,
        // 开启后，PDO 协程多次 prepare 才不会有 40ms 延迟
        'open_tcp_nodelay' => true,
        // 开启自定义握手
        'enable_handshake' => false,
    ];

    /**
     * 服务器
     * @var \Swoole\Http\Server
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
        $this->_setting = $this->setting + $this->_setting;
        $this->_server->set($this->_setting);
        // 绑定事件
        $this->_server->on(SwooleEvent::START, [$this, 'onStart']);
        $this->_server->on(SwooleEvent::MANAGER_START, [$this, 'onManagerStart']);
        $this->_server->on(SwooleEvent::WORKER_START, [$this, 'onWorkerStart']);
        if ($this->_setting['enable_handshake']) {
            $this->_server->on(SwooleEvent::HANDSHAKE, [$this, 'onHandshake']);
        } else {
            $this->_server->on(SwooleEvent::OPEN, [$this, 'onOpen']);
        }
        $this->_server->on(SwooleEvent::MESSAGE, [$this, 'onMessage']);
        $this->_server->on(SwooleEvent::CLOSE, [$this, 'onClose']);
        // 欢迎信息
        $this->welcome();
        // 启动
        return $this->_server->start();
    }

    /**
     * 主进程启动事件
     * @param \Swoole\WebSocket\Server $server
     */
    public function onStart(\Swoole\WebSocket\Server $server)
    {
        // 进程命名
        ProcessHelper::setProcessTitle(static::SERVER_NAME . ": master {$this->host}:{$this->port}");
    }

    /**
     * 管理进程启动事件
     * @param \Swoole\WebSocket\Server $server
     */
    public function onManagerStart(\Swoole\WebSocket\Server $server)
    {
        // 进程命名
        ProcessHelper::setProcessTitle(static::SERVER_NAME . ": manager");
    }

    /**
     * 工作进程启动事件
     * @param \Swoole\WebSocket\Server $server
     * @param int $workerId
     */
    public function onWorkerStart(\Swoole\WebSocket\Server $server, int $workerId)
    {
        // 进程命名
        if ($workerId < $server->setting['worker_num']) {
            ProcessHelper::setProcessTitle(static::SERVER_NAME . ": worker #{$workerId}");
        } else {
            ProcessHelper::setProcessTitle(static::SERVER_NAME . ": task #{$workerId}");
        }
        // 实例化App
        new \Mix\WebSocket\Application(require $this->configFile);
    }

    /**
     * 握手事件
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    public function onHandshake(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        try {
            $fd = $request->fd;
            // 前置初始化
            \Mix::$app->request->beforeInitialize($request);
            \Mix::$app->response->beforeInitialize($response);
            \Mix::$app->registry->beforeInitialize($fd);
            // 拦截
            \Mix::$app->registry->handleHandshake(\Mix::$app->request, \Mix::$app->response);
        } catch (\Throwable $e) {
            \Mix::$app->error->handleException($e);
        }
        // 清扫组件容器
        \Mix::$app->cleanComponents();
        // 开启协程时，移除容器
        if (($tid = Coroutine::id()) !== -1) {
            \Mix::$app->container->delete($tid);
        }
    }

    /**
     * 开启事件
     * @param \Swoole\WebSocket\Server $server
     * @param \Swoole\Http\Request $request
     */
    public function onOpen(\Swoole\WebSocket\Server $server, \Swoole\Http\Request $request)
    {
        try {
            $fd = $request->fd;
            // 前置初始化
            \Mix::$app->request->beforeInitialize($request);
            \Mix::$app->ws->beforeInitialize($server, $fd);
            \Mix::$app->registry->beforeInitialize($fd);
            // 处理消息
            \Mix::$app->registry->handleOpen(\Mix::$app->ws, \Mix::$app->request);
        } catch (\Throwable $e) {
            \Mix::$app->error->handleException($e);
        }
        // 清扫组件容器
        \Mix::$app->cleanComponents();
        // 开启协程时，移除容器
        if (($tid = Coroutine::id()) !== -1) {
            \Mix::$app->container->delete($tid);
        }
    }

    /**
     * 消息事件
     * @param \Swoole\WebSocket\Server $server
     * @param \Swoole\WebSocket\Frame $frame
     */
    public function onMessage(\Swoole\WebSocket\Server $server, \Swoole\WebSocket\Frame $frame)
    {
        try {
            $fd = $frame->fd;
            // 前置初始化
            \Mix::$app->ws->beforeInitialize($server, $fd);
            \Mix::$app->frame->beforeInitialize($frame);
            \Mix::$app->registry->beforeInitialize($fd);
            // 处理消息
            \Mix::$app->registry->handleMessage(\Mix::$app->ws, \Mix::$app->frame);
        } catch (\Throwable $e) {
            \Mix::$app->error->handleException($e);
        }
        // 清扫组件容器
        \Mix::$app->cleanComponents();
        // 开启协程时，移除容器
        if (($tid = Coroutine::id()) !== -1) {
            \Mix::$app->container->delete($tid);
        }
    }

    /**
     * 关闭事件
     * @param \Swoole\WebSocket\Server $server
     * @param int $fd
     */
    public function onClose(\Swoole\WebSocket\Server $server, int $fd)
    {
        // 检查连接是否为有效的WebSocket客户端连接
        if (!$server->isEstablished($fd)) {
            return;
        }
        try {
            // 前置初始化
            \Mix::$app->ws->beforeInitialize($server, $fd);
            \Mix::$app->registry->beforeInitialize($fd);
            // 处理连接关闭
            \Mix::$app->registry->handleClose(\Mix::$app->ws);
        } catch (\Throwable $e) {
            \Mix::$app->error->handleException($e);
        }
        // 清扫组件容器
        \Mix::$app->cleanComponents();
        // 开启协程时，移除容器
        if (($tid = Coroutine::id()) !== -1) {
            \Mix::$app->container->delete($tid);
        }
    }

    /**
     * 欢迎信息
     */
    protected function welcome()
    {
        $swooleVersion = swoole_version();
        $phpVersion = PHP_VERSION;
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
