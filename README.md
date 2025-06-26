# Naivic\OswServer

## Example: Two HTML pages from two servers, talks each other

### Installation and preparation

1. Go example/host
2. Do 'composer update'
3. Go example (cd ..)
4. Do 'docker-compose up -d --build'
5. Open two tab in browser
6. In one tab - go http://127.0.0.1:11080/
7. In another tab - go http://127.0.0.1:12080/

### Test and use

In the first tab, type 'first' in field 'Your name', type 'hello' in textarea below, and press [enter]

You will see your message in first tab as 'Message from me', and in the second tab as 'Message from First'

Repeat this in the second tab, with another name and another message - and see your message in both tabs.

You can also test connection/disconnection situations by stopping/restarting containers and closing/reopening browser tabs.

The message flow can be viewed in the osw1 and osw2 container logs.

## Basic use case of OswServer

### 0. See the code example

Refer to the code in /example/host at all stages, it really helps.

### 1. Design a gRPC service

We need to describe our gRPC service and messages in .proto format and compile the .proto into PHP classes
(for a more detailed explanation, see the gRPC tutorials)

Let's describe a simple symmetric host-to-host protocol. Two servers exchange messages, each message has a sender's nickname and text. That's it.

```
syntax = "proto3";

package grpc.interconnect;

message MessageRequest {
    string name = 1;
    string message = 2;
}

message MessageResponse {
    bool success = 1;
    string message = 2;
}

service Host {
    rpc Message(MessageRequest) returns (MessageResponse);
}
```

### 2. Add code to gRPC service class

We need to write our code in gRPC service class.

Just send a message from gRPC peer to our websocket client.

```php
namespace Grpc\Interconnect;

use OpenSwoole\GRPC;

class HostService implements HostInterface
{
    public function Message(GRPC\ContextInterface $ctx, MessageRequest $request): MessageResponse
    {
        // Log the request
        $ip = $ctx->getValue( \OpenSwoole\Http\Request::class )->server["remote_addr"];
        \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Received GRPC Message from $ip, contains ".var_export($request->getMessage(), true) );

        // Get instance of main server class
        $serv = $ctx->getValue( 'WORKER_CONTEXT' )->getValue( \MyServer::class );

        // Send message to client via websocket connection
        [$success, $msg] = $serv->sendMsgToClient( $request->getName(), $request->getMessage(), $ip );

        // Reply to gRPC peer
        $message = new \Grpc\Interconnect\MessageResponse();
        $message->setMessage( $msg );
        $message->setSuccess( $success );
        return $message;
    }
}
```

### 3. Create main server class

We need to define our main server class, which will do all the work. This class should be a child of \Naivic\OswClass.

```php
class MyServer extends \Naivic\OswServer {

    // Our gRPC port, as well as the gRPC peer port we will be sending our requests to
    const PORT_GRPC = 9501;

}
```

And we can define an onStart method if we want to do some additional initialization.

```php
class MyServer extends \Naivic\OswServer {

    ...

    public $peer = null;

    public function onStart( \OpenSwoole\HTTP\Server $server ) {
        // Get gRPC peer IP from environment variable
        $this->peer = $_ENV["PEER"];
        \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Server starts with peer {$this->peer}" );

        // Call parent class to provide standard initialization (mandatory)
        parent::onStart( $server );
    }

}
```

### 4. Add code to manage client connections

We need to define two methods to manage WebSocket client connection IDs:
   + onOpen - to store the client connection ID when opening a connection
   + onClose - to clear the client connection ID when closing a connection

Something like this, if you want to maintain only one client connection per server.
If you need more than one client connection per server - you should identify client,
ensure that the connection matches a certain client, and make routing for messages to the corresponding client connections.

```php
class MyServer extends \Naivic\OswServer {

    ...

    public $fd = null;

    public function onOpen( \OpenSwoole\Server $server, \OpenSwoole\Http\Request $request ) {
        \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Client connection open: {$request->fd}" );
        if( $this->fd === null ) $this->fd = $request->fd; // Store ID
    }

    public function onClose( \OpenSwoole\Server $server, int $fd ) {
        \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Client connection close: {$fd}" );
        if( $this->fd === $fd ) $this->fd = null; // Clear ID
    }

}
```

### 5. Add code to handle HTTP and Websocket requests

We need to define our processing methods:
   + processRequestHttp() - for HTTP requests processing;
   + processRequestWs() - for WebSocket requests processing.

```php
class MyServer extends \Naivic\OswServer {

    ...

    public function processRequestHttp( \OpenSwoole\GRPC\Context $context, \OpenSwoole\HTTP\Request $rawRequest, \OpenSwoole\HTTP\Response $rawResponse ) {
        // do whatever you do
    }

    public function processRequestWs( \OpenSwoole\Server $server, \OpenSwoole\WebSocket\Frame $frame ) {
        // ...
    }

}
```

For example, make loading of the root html page.

```php
    public function processRequestHttp( \OpenSwoole\GRPC\Context $context, \OpenSwoole\HTTP\Request $rawRequest, \OpenSwoole\HTTP\Response $rawResponse ) {
        $path = $context->getValue( 'path' );
        \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Have got HTTP Request $path" );
        switch( $path ) {
            case "/" : (new \Http\Root)->root( $rawRequest, $rawResponse ); break;
            default  : $rawResponse->status( 404, "Not Found" );
        }
        $rawResponse->end();
    }
```

And process message from client.

```php
    public function processRequestWs( \OpenSwoole\Server $server, \OpenSwoole\WebSocket\Frame $frame ) {
        \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Received message from client: '{$frame->data}'" );
        $json = json_decode( $frame->data, true );
        $message = new \Grpc\Interconnect\MessageRequest();
        $message->setMessage( $json['text'] );
        $message->setName( $json['name'] );
        try {
            $conn = (new \OpenSwoole\GRPC\Client( $this->peer, static::PORT_GRPC ))->connect();
            $out = (new \Grpc\Interconnect\HostClient( $conn ))->Message( $message );
            $conn->close();
            if( $out->getSuccess() ) {
                \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Client's message '{$frame->data}' was sent to peer {$this->peer}" );
            } else {
                \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Client's message '{$frame->data}' was not accepted by peer {$this->peer}, peer reason: '{$out->getMessage()}'" );
                $this->server->push( $this->fd, json_encode( ["name" => null, "text" => "message not delivered, user is currently disconnected"] ) );
            }
        } catch ( \Throwable $e ) {
            \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Client's message '{$frame->data}' was not sent to peer {$this->peer} because of gRPC exception: ".$e->getMessage() );
            $this->server->push( $this->fd, json_encode( ["name" => null, "text" => "message not delivered, server is currently offline"] ) );
        }
    }
```

### 6. Add code to route messages from peer to client

We need a method to deliver a message from a peer to our client - sendMsgToClient(), mentioned in our OpenSwoole\GRPC\HostService::Message()

```php
class MyServer extends \Naivic\OswServer {

    ...

    public function sendMsgToClient( $name, $text, $ip ) {
        if( $this->fd === null ) {
            $msg = "Message '$text' from peer {$ip} was not sent to client, because connection was closed";
            \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, $msg );
            return [false, $msg];
        }
        $this->server->push( $this->fd, json_encode([ "name" => $name, "text" => $text]) );
        $msg = "Message '$text' from peer {$ip} was sent to client connection {$this->fd}";
        \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, $msg );
        return [true, $msg];
    }

}
```

### 7. (Optional) Add code to custom gRPC processing

If we want make some custom processing of gRPC requests - we can add the optional processRequestGrpc() method.

```php

    public function processRequestGrpc( \OpenSwoole\GRPC\Context $context, \OpenSwoole\HTTP\Request $rawRequest, \OpenSwoole\HTTP\Response $rawResponse ) {

        // Additional logging of gRPC requests
        \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Have got GRPC Request {$context->getValue('path')}" );

        // Call parent class to provide standatd request processing
        parent::processRequestGrpc( $context, $rawRequest, $rawResponse );
    }

```

### 8. Start main server

We start our server in usual \OpenSwoole\GRPC\Server manner.

```php
$serv = (new \MyServer('0.0.0.0', MyServer::PORT_GRPC)
    ->register( \Grpc\Interconnect\HostService::class )
    ->start()
```

It is not necessary to open different ports for gRPC and WebSocket/HTTP connections. But, if you want, you can easily do it:

```php
class MyServer extends \Naivic\OswServer {

    ...

    const PORT_HTTP = 8080;

}

$serv = (new \MyServer('0.0.0.0', MyServer::PORT_GRPC)) // gRPC
    ->register(\Grpc\Interconnect\HostService::class) // Register gRPC service
    ->addlistener("0.0.0.0", MyServer::PORT_HTTP, OpenSwoole\Constant::SOCK_TCP) // Add listener to another port for HTTP+WebSocket
    ->start()
;
```

### 9. Add spices to taste

You can easily extend this code to achieve your own goals. Good Luck!

