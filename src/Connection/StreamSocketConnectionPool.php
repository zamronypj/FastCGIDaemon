<?php

namespace PHPFastCGI\FastCGIDaemon\Connection;

use PHPFastCGI\FastCGIDaemon\Connection\StreamSocketConnection;
use PHPFastCGI\FastCGIDaemon\ConnectionHandler\ConnectionHandlerFactoryInterface;
use PHPFastCGI\FastCGIDaemon\ConnectionHandler\ConnectionHandlerInterface;

class StreamSocketConnectionPool implements ConnectionPoolInterface
{
    use StreamSocketExceptionTrait;

    /**
     * @var resource 
     */
    protected $serverSocket;

    /**
     * @var resource[] 
     */
    protected $clientSockets;

    /**
     * @var Connection[]
     */
    protected $connections;

    /**
     * @var ConnectionHandlerInterface[] 
     */
    protected $connectionHandlers;

    /**
     * Constructor.
     * 
     * @param resource $socket
     */
    public function __construct($socket)
    {
        stream_set_blocking($socket, 0);

        $this->serverSocket       = $socket;
        $this->clientSockets      = [];
        $this->connections        = [];
        $this->connectionHandlers = [];
    }

    /**
     * {@inheritdoc}
     */
    public function operate(ConnectionHandlerFactoryInterface $connectionHandlerFactory, $timeoutLoop)
    {
        $timeoutLoopSeconds      = (int) floor($timeoutLoop);
        $timeoutLoopMicroseconds = (int) (($timeoutLoop - $timeoutLoopSeconds) * 1000000);

        $write  = [];
        $except = [];

        while (1) {
            $read = array_merge(['pool' => $this->serverSocket], $this->clientSockets);

            stream_select($read, $write, $except, $timeoutLoopSeconds, $timeoutLoopMicroseconds);

            foreach (array_keys($read) as $id) {
                if ('pool' === $id) {
                    $this->acceptConnection($connectionHandlerFactory);
                } else {
                    $this->connectionHandlers[$id]->ready();
                }
            }

            $this->removeClosedConnections();
        }
    }

    protected function acceptConnection(ConnectionHandlerFactoryInterface $connectionHandlerFactory)
    {        
        $clientSocket = stream_socket_accept($this->serverSocket);

        stream_set_blocking($clientSocket, 0);
 
        $connection = new StreamSocketConnection($clientSocket);
        $handler    = $connectionHandlerFactory->createConnectionHandler($connection);

        $id = spl_object_hash($connection);

        $this->clientSockets[$id]      = $clientSocket;
        $this->connections[$id]        = $connection;
        $this->connectionHandlers[$id] = $handler;
    }

    protected function removeClosedConnections()
    {
        foreach ($this->connections as $id => $connection) {
            if ($connection->isClosed()) {
                unset($this->clientSockets[$id]);
                unset($this->connections[$id]);
                unset($this->connectionHandlers[$id]);
            }
        }
    }
}
