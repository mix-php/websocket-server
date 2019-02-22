<?php

namespace Mix\WebSocket\Server;

use Mix\Core\Bean\AbstractObject;
use Mix\Core\Coroutine;
use Mix\Helpers\ProcessHelper;

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
     * 配置文件
     * @var string
     */
    public $configurationFile = '';

    /**
     * 运行参数
     * @var array
     */
    public $settings = [];

    /**
     * 默认运行参数
     * @var array
     */
    protected $_settings = [
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
        $this->_settings = $this->settings + $this->_settings;
        $this->_server->set($this->_settings);
        // 绑定事件
        $this->_server->on(SwooleEvent::START, [$this, 'onStart']);
        $this->_server->on(SwooleEvent::MANAGER_START, [$this, 'onManagerStart']);
        $this->_server->on(SwooleEvent::WORKER_START, [$this, 'onWorkerStart']);
        $this->_server->on(SwooleEvent::HANDSHAKE, [$this, 'onHandshake']);
        $this->_server->on(SwooleEvent::MESSAGE, [$this, 'onMessage']);
        $this->_server->on(SwooleEvent::CLOSE, [$this, 'onClose']);
        // 欢迎信息
        $this->welcome();
        // 启动
        return $this->_server->start();
    }

    /**
     * 主进程启动事件
     */
    public function onStart(\Swoole\WebSocket\Server $server)
    {
        try {
            // 进程命名
            ProcessHelper::setProcessTitle("mix-websocketd: master {$this->host}:{$this->port}");
        } catch (\Throwable $e) {
            \Mix::$app->error->handleException($e);
        }
    }

    // 管理进程启动事件
    public function onManagerStart(\Swoole\WebSocket\Server $server)
    {
        try {
            // 进程命名
            ProcessHelper::setProcessTitle("mix-websocketd: manager");
        } catch (\Throwable $e) {
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 工作进程启动事件
     */
    public function onWorkerStart(\Swoole\WebSocket\Server $server, int $workerId)
    {
        try {
            // 进程命名
            if ($workerId < $server->setting['worker_num']) {
                ProcessHelper::setProcessTitle("mix-websocketd: worker #{$workerId}");
            } else {
                ProcessHelper::setProcessTitle("mix-websocketd: task #{$workerId}");
            }
            // 实例化App
            $config = require $this->configurationFile;
            new \Mix\WebSocket\Application($config);
        } catch (\Throwable $e) {
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 握手事件
     * @param $request
     * @param $response
     */
    public function onHandshake(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        try {
            // 执行
            \Mix::$app->request->beforeInitialize($request);
            \Mix::$app->response->beforeInitialize($response);
            // 开启协程时，移除容器
            if (($tid = Coroutine::id()) !== -1) {
                \Mix::$app->container->delete($tid);
            }
        } catch (\Throwable $e) {
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 消息事件
     * @param $server
     * @param $frame
     */
    public function onMessage(\Swoole\WebSocket\Server $server, \Swoole\WebSocket\Frame $frame)
    {
        try {
            // 执行

            // 开启协程时，移除容器
            if (($tid = Coroutine::id()) !== -1) {
                \Mix::$app->container->delete($tid);
            }
        } catch (\Throwable $e) {
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 关闭事件
     * @param $server
     * @param $fd
     */
    public function onClose(\Swoole\WebSocket\Server $server, int $fd)
    {
        try {
            // 检查连接是否为有效的WebSocket客户端连接
            if (!$server->isEstablished($fd)) {
                return;
            }
            // 执行


            // 开启协程时，移除容器
            if (($tid = Coroutine::id()) !== -1) {
                \Mix::$app->container->delete($tid);
            }
        } catch (\Throwable $e) {
            \Mix::$app->error->handleException($e);
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
        println('Server         Name:      mix-websocketd');
        println('System         Name:      ' . strtolower(PHP_OS));
        println("PHP            Version:   {$phpVersion}");
        println("Swoole         Version:   {$swooleVersion}");
        println('Framework      Version:   ' . \Mix::$version);
        println('Hot            Update:    ' . ($this->_settings['max_request'] == 1 ? 'enabled' : 'disabled'));
        println('Coroutine      Mode:      ' . ($this->_settings['enable_coroutine'] ? 'enabled' : 'disabled'));
        println("Listen         Addr:      {$this->host}");
        println("Listen         Port:      {$this->port}");
        println('Reactor        Num:       ' . $this->_settings['reactor_num']);
        println('Worker         Num:       ' . $this->_settings['worker_num']);
        println("Configuration  File:      {$this->configurationFile}");
    }

}
