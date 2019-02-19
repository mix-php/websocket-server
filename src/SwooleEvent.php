<?php

namespace Mix\WebSocket\Server;

/**
 * Class SwooleEvent
 * @package Mix\WebSocket\Server
 * @author LIUJIAN <coder.keda@gmail.com>
 */
class SwooleEvent
{

    /**
     * Start
     */
    const START = 'start';

    /**
     * ManagerStart
     */
    const MANAGER_START = 'managerStart';

    /**
     * WorkerStart
     */
    const WORKER_START = 'workerStart';

    /**
     * HandShake
     */
    const HANDSHAKE = 'handshake';

    /**
     * Message
     */
    const MESSAGE = 'message';

    /**
     * Close
     */
    const CLOSE = 'close';

}
