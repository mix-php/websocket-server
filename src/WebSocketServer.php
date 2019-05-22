<?php

namespace Mix\WebSocket\Server;

use Mix\Core\Coroutine;
use Mix\Helper\ProcessHelper;
use Mix\WebSocket\Frame;
use Mix\Server\Event;
use Mix\Server\AbstractServer;

/**
 * Class WebSocketServer
 * @package Mix\WebSocket\Server
 * @author liu,jian <coder.keda@gmail.com>
 */
class WebSocketServer extends AbstractServer
{

    /**
     * 服务名称
     * @var string
     */
    public $name = 'mix-websocketd';

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
     * 启动服务
     * @return bool
     */
    public function start()
    {
        // 初始化
        $this->server = new \Swoole\WebSocket\Server($this->host, $this->port);
        // 配置参数
        $this->setting += $this->_defaultSetting;
        $this->server->set($this->setting);
        // 覆盖参数
        $this->server->set([
            'enable_coroutine' => false, // 关闭默认协程，回调中有手动开启支持上下文的协程
        ]);
        // 绑定事件
        $this->server->on(SwooleEvent::START, [$this, 'onStart']);
        $this->server->on(SwooleEvent::SHUTDOWN, [$this, 'onShutdown']);
        $this->server->on(SwooleEvent::MANAGER_START, [$this, 'onManagerStart']);
        $this->server->on(SwooleEvent::WORKER_ERROR, [$this, 'onWorkerError']);
        $this->server->on(SwooleEvent::MANAGER_STOP, [$this, 'onManagerStop']);
        $this->server->on(SwooleEvent::WORKER_START, [$this, 'onWorkerStart']);
        $this->server->on(SwooleEvent::WORKER_STOP, [$this, 'onWorkerStop']);
        $this->server->on(SwooleEvent::WORKER_EXIT, [$this, 'onWorkerExit']);
        $this->server->on(SwooleEvent::REQUEST, [$this, 'onRequest']);
        if ($this->setting['enable_handshake']) {
            $this->server->on(SwooleEvent::HANDSHAKE, [$this, 'onHandshake']);
        } else {
            $this->server->on(SwooleEvent::OPEN, [$this, 'onOpen']);
        }
        $this->server->on(SwooleEvent::MESSAGE, [$this, 'onMessage']);
        $this->server->on(SwooleEvent::CLOSE, [$this, 'onClose']);
        // 欢迎信息
        $this->welcome();
        // 执行回调
        $this->setting['hook_start'] and call_user_func($this->setting['hook_start'], $this->server);
        // 启动
        return $this->server->start();
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
                ProcessHelper::setProcessTitle($this->name . ": worker #{$workerId}");
            } else {
                ProcessHelper::setProcessTitle($this->name . ": task #{$workerId}");
            }
            // 执行回调
            $this->setting['hook_worker_start'] and call_user_func($this->setting['hook_worker_start'], $server);
            // 实例化App
            new \Mix\WebSocket\Application(require $this->configFile);

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
            $this->setting['hook_request'] and call_user_func($this->setting['hook_request'], $this->server, $request, $response);
            $this->setting['hook_request_success'] and call_user_func($this->setting['hook_request_success'], $this->server, $request);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->setting['hook_request_error'] and call_user_func($this->setting['hook_request_error'], $this->server, $request);
        }
    }

    /**
     * 握手事件
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    public function onHandshake(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        if ($this->setting['enable_coroutine'] && Coroutine::id() == -1) {
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
            \Mix::$app->ws->beforeInitialize($this->server, $fd);
            \Mix::$app->registry->beforeInitialize($fd);
            // 拦截
            \Mix::$app->runHandshake(\Mix::$app->ws, \Mix::$app->request, \Mix::$app->response);
            // 执行回调
            $this->setting['hook_handshake_success'] and call_user_func($this->setting['hook_handshake_success'], $this->server, $request);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->setting['hook_handshake_error'] and call_user_func($this->setting['hook_handshake_error'], $this->server, $request);
        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->setting['enable_coroutine']) {
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
        if ($this->setting['enable_coroutine'] && Coroutine::id() == -1) {
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
            $this->setting['hook_open_success'] and call_user_func($this->setting['hook_open_success'], $server, $request);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->setting['hook_open_error'] and call_user_func($this->setting['hook_open_error'], $server, $request);
        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->setting['enable_coroutine']) {
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
        if ($this->setting['enable_coroutine'] && Coroutine::id() == -1) {
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
            $this->setting['hook_message_success'] and call_user_func($this->setting['hook_message_success'], $server, $fd);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->setting['hook_message_error'] and call_user_func($this->setting['hook_message_error'], $server, $fd);
        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->setting['enable_coroutine']) {
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
        if ($this->setting['enable_coroutine'] && Coroutine::id() == -1) {
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
            $this->setting['hook_close_success'] and call_user_func($this->setting['hook_close_success'], $server, $fd);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->setting['hook_close_error'] and call_user_func($this->setting['hook_close_error'], $server, $fd);
        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

}
