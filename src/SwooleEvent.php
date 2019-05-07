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
     * ManagerStart
     */
    const MANAGER_START = 'managerStart';

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
