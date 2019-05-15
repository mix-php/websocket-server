<?php

namespace Mix\WebSocket\Server;

/**
 * Class SwooleEvent
 * @package Mix\WebSocket\Server
 * @author liu,jian <coder.keda@gmail.com>
 */
class SwooleEvent
{

    /**
     * Start
     */
    const START = 'start';

    /**
     * Shutdown
     */
    const SHUTDOWN = 'shutdown';

    /**
     * ManagerStart
     */
    const MANAGER_START = 'managerStart';

    /**
     * WorkerError
     */
    const WORKER_ERROR = 'workerError';

    /**
     * ManagerStop
     */
    const MANAGER_STOP = 'managerStop';

    /**
     * WorkerStart
     */
    const WORKER_START = 'workerStart';

    /**
     * WorkerStop
     */
    const WORKER_STOP = 'workerStop';

    /**
     * WorkerExit
     */
    const WORKER_EXIT = 'workerExit';

    /**
     * Request
     */
    const REQUEST = 'request';

    /**
     * HandShake
     */
    const HANDSHAKE = 'handshake';

    /**
     * Open
     */
    const OPEN = 'open';

    /**
     * Message
     */
    const MESSAGE = 'message';

    /**
     * Close
     */
    const CLOSE = 'close';

}
