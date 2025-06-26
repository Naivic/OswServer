<?php

require_once( dirname(__FILE__)."/vendor/autoload.php");


class MyServer extends \Naivic\OswServer {

    // Our gRPC port, as well as the gRPC peer port we will be sending our requests to
    const PORT_GRPC = 9501;

    const PORT_HTTP = 8080;

    public $peer = null;
    public $fd = null;

    public function onStart( \OpenSwoole\HTTP\Server $server ) {
        // Get gRPC peer IP from environment variable
        $this->peer = $_ENV["PEER"];
        \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Server starts with peer {$this->peer}" );

        // Call parent class to provide standard initialization (mandatory)
        parent::onStart( $server );
    }

    public function onOpen( \OpenSwoole\Server $server, \OpenSwoole\Http\Request $request ) {
        \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Client connection open: {$request->fd}" );
        if( $this->fd === null ) $this->fd = $request->fd; // Store ID
    }

    public function onClose( \OpenSwoole\Server $server, int $fd ) {
        \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Client connection close: {$fd}" );
        if( $this->fd === $fd ) $this->fd = null; // Clear ID
    }

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

    public function processRequestGrpc( \OpenSwoole\GRPC\Context $context, \OpenSwoole\HTTP\Request $rawRequest, \OpenSwoole\HTTP\Response $rawResponse ) {
        $info = $context->getValue( 'info' );
        $path = $context->getValue( 'path' );
        if( $info["server_port"] != static::PORT_GRPC ) {
            \OpenSwoole\Util::LOG(\OpenSwoole\Constant::LOG_INFO, "Have got GRPC Request $path on invalid port {$info["server_port"]}" );
            $rawResponse->status( 403, "Forbidden" );
            $rawResponse->end();
            return;
        }
        \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Have got GRPC Request $path on valid port {$info["server_port"]}" );

        // Call parent class to provide standard request processing
        parent::processRequestGrpc( $context, $rawRequest, $rawResponse );
    }

    public function processRequestHttp( \OpenSwoole\GRPC\Context $context, \OpenSwoole\HTTP\Request $rawRequest, \OpenSwoole\HTTP\Response $rawResponse ) {
        $info = $context->getValue( 'info' );
        $path = $context->getValue( 'path' );
        if( $info["server_port"] != static::PORT_HTTP ) {
            \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Have got HTTP Request $path on invalid port {$info["server_port"]}" );
            $rawResponse->status( 403, "Forbidden" );
            $rawResponse->end();
            return;
        }
        \OpenSwoole\Util::LOG( \OpenSwoole\Constant::LOG_INFO, "Have got HTTP Request $path on valid port {$info["server_port"]}" );
        switch( $path ) {
            case "/" : (new \Http\Root)->root( $rawRequest, $rawResponse ); break;
            default  : $rawResponse->status(404, "Not Found");
        }
        $rawResponse->end();
    }

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

}

$serv = (new MyServer( '0.0.0.0', MyServer::PORT_GRPC )) // GRPC
    ->register( \Grpc\Interconnect\HostService::class )
    ->addlistener( "0.0.0.0", MyServer::PORT_HTTP, OpenSwoole\Constant::SOCK_TCP ) // HTTP+WebSocket
    ->start()
;
