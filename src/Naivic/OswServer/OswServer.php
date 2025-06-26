<?php

declare(strict_types=1);

namespace Naivic;

class OswServer {

    protected string $host;
    protected int $port;
    protected int $mode;
    protected int $sockType;

    protected array $settings = [
        \OpenSwoole\Constant::OPTION_OPEN_HTTP2_PROTOCOL => 1,
        \OpenSwoole\Constant::OPTION_ENABLE_COROUTINE    => true,
    ];

    protected array $services = [];
    protected array $workerContexts = [];
    protected $server;
    protected $handler;
    protected $workerContext;

    public function __construct(string $host, int $port = 0, int $mode = \OpenSwoole\Server::SIMPLE_MODE, int $sockType = \OpenSwoole\Constant::SOCK_TCP)
    {
        $this->host     = $host;
        $this->port     = $port;
        $this->mode     = $mode;
        $this->sockType = $sockType;
        $server         = new \OpenSwoole\WebSocket\Server($this->host, $this->port, $this->mode, $this->sockType);
        $server->on('start', function (\OpenSwoole\WebSocket\Server $server) {
            $this->onStart( $server );
        });
        $this->server   = $server;
        $this->server->set([
            "open_http_protocol" => true,
            "open_http2_protocol" => true,
            "open_websocket_protocol" => true,
            "open_mqtt_protocol" => true,
        ]);

        $handler       = (new \OpenSwoole\GRPC\Middleware\StackHandler())->add(new \OpenSwoole\GRPC\Middleware\ServiceHandler());
        $this->handler = $handler;

        return $this;
    }

    public function withWorkerContext(string $context, \Closure $callback): self
    {
        $this->workerContexts[$context] = $callback;
        return $this;
    }

    public function addMiddleware(\OpenSwoole\GRPC\Middleware\MiddlewareInterface $middleware): self
    {
        $this->handler = $this->handler->add($middleware);
        return $this;
    }

    public function set(array $settings): self
    {
        $this->settings = array_merge($this->settings, $settings ?? []);
        return $this;
    }

    public function start()
    {
        $this->server->set($this->settings);
        $this->server->on('workerStart', function (\OpenSwoole\Server $server, int $workerId) {
            $this->workerContext = new \OpenSwoole\GRPC\Context([
                'WORKER_ID'                                 => $workerId,
                static::class                               => $this,
                \OpenSwoole\Server::class                   => $this->server,
            ]);
            foreach ($this->workerContexts as $context => $callback) {
                $this->workerContext = $this->workerContext->withValue($context, $callback->call($this));
            }
        });
        $this->server->on('open', function(\OpenSwoole\Server $server, \OpenSwoole\Http\Request $request) {
            $this->onOpen($server, $request);
        });
        $this->server->on('close', function(\OpenSwoole\Server $server, int $fd) {
            $this->onClose($server, $fd);
        });
        $this->server->on('request', function (\OpenSwoole\HTTP\Request $request, \OpenSwoole\HTTP\Response $response) {
            $this->processRequest($request, $response);
        });
        $this->server->on('message', function(\OpenSwoole\Server $server, \OpenSwoole\WebSocket\Frame $frame) {
            $this->processRequestWs($server, $frame);
        });
        $this->server->start();
    }

    public function onStart( \OpenSwoole\HTTP\Server $server ) {
        \OpenSwoole\Util::LOG(\OpenSwoole\Constant::LOG_INFO, sprintf("\033[32m%s\033[0m", "OwsServer (OpenSwoole GRPC+HTTP+WebSocket Server) is started, {$this->host}:{$this->port}"));
    }

    public function onOpen(\OpenSwoole\Server $server, \OpenSwoole\Http\Request $request) {
        \OpenSwoole\Util::LOG(\OpenSwoole\Constant::LOG_INFO, "connection open: {$request->fd}");
    }

    public function onClose(\OpenSwoole\Server $server, int $fd) {
        \OpenSwoole\Util::LOG(\OpenSwoole\Constant::LOG_INFO, "connection close: {$fd}");
    }

    public function processRequestWs(\OpenSwoole\Server $server, \OpenSwoole\WebSocket\Frame $frame) {
        \OpenSwoole\Util::LOG(\OpenSwoole\Constant::LOG_INFO, "received message: {$frame->data}");
    }

    public function on(string $event, \Closure $callback)
    {
        $this->server->on($event, function () use ($callback) { $callback->call($this); });
        return $this;
    }

    public function addListener( string $host, int $port = 0, int $sockType = \OpenSwoole\Constant::SOCK_TCP ) {
        \OpenSwoole\Util::LOG(\OpenSwoole\Constant::LOG_INFO, sprintf("\033[32m%s\033[0m", "Add Listener to OwsServer, {$host}:{$port}"));
        $this->server->addListener( $host, $port, $sockType );
        return $this;
    }

    public function register(string $class, ?\OpenSwoole\GRPC\ServiceInterface $instance = null): self
    {
        if (!class_exists($class)) {
            throw new \TypeError("{$class} not found");
        }
        // Only recreate the class if the users dont pass in their initialized class
        if (!$instance) {
            $instance = new $class();
        }
        if (!($instance instanceof \OpenSwoole\GRPC\ServiceInterface)) {
            throw new \TypeError("{$class} is not ServiceInterface");
        }
        $service = new \OpenSwoole\GRPC\ServiceContainer($class, $instance);
        $this->services[$service->getName()] = $service;
        return $this;
    }

    public function processRequest(\OpenSwoole\HTTP\Request $rawRequest, \OpenSwoole\HTTP\Response $rawResponse)
    {
        $context = new \OpenSwoole\GRPC\Context([
            'WORKER_CONTEXT'                            => $this->workerContext,
            'SERVICES'                                  => $this->services,
            \OpenSwoole\Http\Request::class             => $rawRequest,
            \OpenSwoole\Http\Response::class            => $rawResponse,
            \OpenSwoole\GRPC\Constant::CONTENT_TYPE     => $rawRequest->header[\OpenSwoole\GRPC\Constant::CONTENT_TYPE] ?? '',
            'info'                                      => $this->server->getClientInfo($rawRequest->fd),
            'path'                                      => $rawRequest->server["path_info"],
        ]);

        if( in_array( $context->getValue(\OpenSwoole\GRPC\Constant::CONTENT_TYPE), ['application/grpc', 'application/grpc+proto', 'application/grpc+json'], true ) ) {
            if( !isset($rawRequest->header['te'])) {
                throw \OpenSwoole\GRPC\Exception\InvokeException::create('illegal GRPC request, missing te header');
            }
            $this->processRequestGrpc( $context, $rawRequest, $rawResponse );
        } else {
            $this->processRequestHttp( $context, $rawRequest, $rawResponse );
        }

    }

    public function processRequestHttp( \OpenSwoole\GRPC\Context $context, \OpenSwoole\HTTP\Request $rawRequest, \OpenSwoole\HTTP\Response $rawResponse)
    {
        $rawResponse->status(404, "Not Found");
        $rawResponse->end();
    }

    public function processRequestGrpc( \OpenSwoole\GRPC\Context $context, \OpenSwoole\HTTP\Request $rawRequest, \OpenSwoole\HTTP\Response $rawResponse)
    {

        $context->offsetSet( \OpenSwoole\GRPC\Constant::GRPC_STATUS, \OpenSwoole\GRPC\Status::UNKNOWN );
        $context->offsetSet( \OpenSwoole\GRPC\Constant::GRPC_MESSAGE, '' );

        try {
            [, $service, $method]        = explode('/', $rawRequest->server['request_uri'] ?? '');
            $service                     = '/' . $service;
            $message                     = $rawRequest->getContent() ? substr($rawRequest->getContent(), 5) : '';
            $request                     = new \OpenSwoole\GRPC\Request($context, $service, $method, $message);
            $response = $this->handler->handle($request);
        } catch( \OpenSwoole\GRPC\Exception\GRPCException $e ) {
            \OpenSwoole\Util::log(\OpenSwoole\Constant::LOG_ERROR, $e->getMessage() . ', error code: ' . $e->getCode() . "\n" . $e->getTraceAsString());
            $output          = '';
            $context         = $context->withValue(\OpenSwoole\GRPC\Constant::GRPC_STATUS, $e->getCode());
            $context         = $context->withValue(\OpenSwoole\GRPC\Constant::GRPC_MESSAGE, $e->getMessage());
            $response        = new \OpenSwoole\GRPC\Response($context, $output);
        }

        $this->send($response);
    }

    public function push(Message $message)
    {
        $context = $message->getContext();
        try {
            if ($context->getValue('content-type') !== 'application/grpc+json') {
                $payload = $message->getMessage()->serializeToString();
            } else {
                $payload = $message->getMessage()->serializeToJsonString();
            }
        } catch( \Throwable $e ) {
            throw \OpenSwoole\GRPC\Exception\InvokeException::create($e->getMessage(), \OpenSwoole\GRPC\Status::INTERNAL, $e);
        }

        $payload = pack('CN', 0, strlen($payload)) . $payload;

        $ret = $context->getValue(\OpenSwoole\Http\Response::class)->write($payload);
        if (!$ret) {
            throw new \OpenSwoole\Exception('Client side is disconnected');
        }
        return $ret;
    }

    protected function send(\OpenSwoole\GRPC\Response $response)
    {
        $context     = $response->getContext();
        $rawResponse = $context->getValue(\OpenSwoole\Http\Response::class);
        $headers     = [
            'content-type' => $context->getValue('content-type'),
            'trailer'      => 'grpc-status, grpc-message',
        ];

        $trailers = [
            \OpenSwoole\GRPC\Constant::GRPC_STATUS  => $context->getValue(\OpenSwoole\GRPC\Constant::GRPC_STATUS),
            \OpenSwoole\GRPC\Constant::GRPC_MESSAGE => $context->getValue(\OpenSwoole\GRPC\Constant::GRPC_MESSAGE),
        ];

        $payload = pack('CN', 0, strlen($response->getPayload())) . $response->getPayload();

        try {
            foreach ($headers as $name => $value) {
                $rawResponse->header($name, $value);
            }

            foreach ($trailers as $name => $value) {
                $rawResponse->trailer($name, (string) $value);
            }
            $rawResponse->end($payload);
        } catch (\OpenSwoole\Exception $e) {
            \OpenSwoole\Util::log(\OpenSwoole\Constant::LOG_WARNING, $e->getMessage() . ', error code: ' . $e->getCode() . "\n" . $e->getTraceAsString());
        }
    }

}
