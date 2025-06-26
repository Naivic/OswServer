<?php declare(strict_types=1);

namespace Grpc\Interconnect;

use OpenSwoole\GRPC;

class HostService implements HostInterface
{
    /**
    * @param GRPC\ContextInterface $ctx
    * @param MessageRequest $request
    * @return MessageResponse
    *
    * @throws GRPC\Exception\InvokeException
    */
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
