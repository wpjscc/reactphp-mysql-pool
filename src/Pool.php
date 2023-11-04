<?php

namespace Wpjscc\MySQL;

use React\MySQL\Factory;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectorInterface;
use React\Promise\Deferred;
use React\MySQL\ConnectionInterface;
use React\MySQL\QueryResult;
use React\EventLoop\Loop;
use React\Promise\Timer\TimeoutException;

class Pool
{
    private $min_connections;
    private $max_connections;

    private $keep_alive;

    private $max_wait_queue;
    private $current_connections = 0;
    private $wait_timeout = 0;
    private $idle_connections = [];
    private $wait_queue;
    private $loop;
    private $factory;
    private $uri;


    public function __construct(
        $uri,
        $config = [],
        Factory $factory = null,
        LoopInterface $loop = null,
        ConnectorInterface $connector = null
    ) {
        $this->uri = $uri;
        $this->min_connections = $config['min_connections'] ?? 1;
        $this->max_connections = $config['max_connections'] ?? 10;
        $this->keep_alive = $config['keep_alive'] ?? 60;
        $this->max_wait_queue = $config['max_wait_queue'] ?? 100;
        $this->wait_timeout = $config['wait_timeout'] ?? 1;
        $this->wait_queue = new \SplObjectStorage;
        $this->idle_connections = new \SplObjectStorage;
        $this->loop = $loop ?: Loop::get();
        $this->factory = $factory ?: new Factory($loop, $connector);;
    }

    public function query($sql, array $params = [])
    {
        $deferred = new Deferred();

        $this->getIdleConnection()->then(function (ConnectionInterface $connection) use ($sql, $params, $deferred) {
            $connection->query($sql, $params)->then(function (QueryResult $command) use ($deferred, $connection) {
                $this->releaseConnection($connection);
                try {
                    $deferred->resolve($command);
                } catch (\Throwable $th) {
                    //todo handle $th
                }
            }, function ($e) use ($deferred, $connection) {
                $deferred->reject($e);

                $this->ping($connection);
            });
        }, function ($e) use ($deferred) {
            $deferred->reject($e);
        });

        return $deferred->promise();
    }
    public function queryStream($sql, array $params = [])
    {
        $error = null;

        $stream = \React\Promise\Stream\unwrapReadable(
            $this->getIdleConnection()->then(function (ConnectionInterface $connection) use ($sql, $params) {
                $stream = $connection->queryStream($sql, $params);
                $stream->on('end', function () use ($connection) {
                    $this->releaseConnection($connection);
                });
                $stream->on('error', function ($err) use ($connection) {
                    $this->ping($connection);
                });
                return $stream;
            }, function ($e) use (&$error) {
                $error = $e;
                throw $e;
            })
        );

        if ($error) {
            \React\EventLoop\Loop::addTimer(0.0001, function () use ($stream, $error) {
                $stream->emit('error', [$error]);
            });
        }

        return $stream;
    }

    public function getIdleConnection()
    {
        if ($this->idle_connections->count() > 0) {
            $this->idle_connections->rewind();
            $connection = $this->idle_connections->current();
            if ($timer = $this->idle_connections[$connection]['timer']) {
                \React\EventLoop\Loop::cancelTimer($timer);
            }
            if ($ping = $this->idle_connections[$connection]['ping']) {
                \React\EventLoop\Loop::cancelTimer($ping);
                $ping = null;
            }
            $this->idle_connections->detach($connection);
            return \React\Promise\resolve($connection);
        }

        if ($this->current_connections < $this->max_connections) {
            $this->current_connections++;
            return \React\Promise\resolve($this->factory->createLazyConnection($this->uri));
        }

        if ($this->max_wait_queue && $this->wait_queue->count() >= $this->max_wait_queue) {
            return \React\Promise\reject(new \Exception("over max_wait_queue: " . $this->max_wait_queue . '-current quueue:' . $this->wait_queue->count()));
        }

        $deferred = new Deferred();
        $this->wait_queue->attach($deferred);

        if (!$this->wait_timeout) {
            return $deferred->promise();
        }

        $that = $this;

        return \React\Promise\Timer\timeout($deferred->promise(), $this->wait_timeout, $this->loop)->then(null, function ($e) use ($that, $deferred) {

            $that->wait_queue->detach($deferred);

            if ($e instanceof TimeoutException) {
                throw new \RuntimeException(
                    'wait timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)' . 'and wait queue ' . $that->wait_queue->count() . ' count',
                    \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                );
            }
            throw $e;
        });
    }

    public function releaseConnection(ConnectionInterface $connection)
    {
        if ($this->wait_queue->count() > 0) {
            $this->wait_queue->rewind();
            $deferred = $this->wait_queue->current();
            $deferred->resolve($connection);
            $this->wait_queue->detach($deferred);
            return;
        }


        $ping = null;
        $timer = \React\EventLoop\Loop::addTimer($this->keep_alive, function () use ($connection, &$ping) {
            if ($this->idle_connections->count() > $this->min_connections) {
                $connection->quit();
                $this->idle_connections->detach($connection);
                $this->current_connections--;
            } else {
              
                $ping = \React\EventLoop\Loop::addPeriodicTimer($this->keep_alive, function () use ($connection, &$ping) {
                   $this->ping($connection)->then(null, function($e) use ($ping){
                       if ($ping) {
                           \React\EventLoop\Loop::cancelTimer($ping);
                       }
                       $ping = null;
                   });
                });
                $this->ping($connection)->then(null, function($e) use ($ping){
                    if ($ping) {
                        \React\EventLoop\Loop::cancelTimer($ping);
                    }
                    $ping = null;
                });

            }
        });

        $this->idle_connections->attach($connection, [
            'timer' => $timer,
            'ping' => &$ping
        ]);
    }


    public function translation(callable $callable)
    {
        $that = $this;
        $deferred = new Deferred();

        $this->getIdleConnection()->then(function (ConnectionInterface $connection) use ($callable, $deferred, $that) {
            $connection->query('BEGIN')
                ->then(function () use ($callable, $connection) {
                    try {
                        return \React\Async\async(function () use ($callable, $connection) {
                            return $callable($connection);
                        })();
                    } catch (\Throwable $th) {
                        throw $th;
                    }
                })
                ->then(function ($result) use ($connection, $deferred, $that) {
                    $connection->query('COMMIT')->then(function () use ($result, $deferred, $connection, $that) {
                        $that->releaseConnection($connection);
                        $deferred->resolve($result);
                    }, function ($error) use ($deferred, $connection, $that) {
                        $that->ping($connection);
                        $deferred->reject($error);
                    });
                }, function ($error) use ($connection, $deferred, $that) {
                    $connection->query('ROLLBACK')->then(function () use ($error, $deferred, $connection, $that) {
                        $that->releaseConnection($connection);
                        $deferred->reject($error);
                    }, function () use ($deferred, $connection, $that, $error) {
                        $that->ping($connection);
                        $deferred->reject($error);
                    });
                });
        }, function ($error) use ($deferred) {
            $deferred->reject($error);
        });

        return $deferred->promise();
    }

    public function ping($connection)
    {
        $that = $this;
        return $connection->ping()->then(function () use ($connection, $that) {
            if (!$that->idle_connections->contains($connection)) {
                $that->releaseConnection($connection);
            }
        }, function ($e) use ($connection, $that) {
            if ($that->idle_connections->contains($connection)) {
                $that->idle_connections->detach($connection);
            }
            $that->current_connections--;
            throw $e;
        });
    }

    public function getPoolCount()
    {
        return $this->current_connections;
    }

    public function getWaitCount()
    {
        return $this->wait_queue->count();
    }

    public function idleConnectionCount()
    {
        return $this->idle_connections->count();
    }
}
