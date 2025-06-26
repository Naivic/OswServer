<?php

namespace Http;

class Root {

    public function root( \OpenSwoole\HTTP\Request $rawRequest, \OpenSwoole\HTTP\Response $rawResponse ) {
        $fname = dirname(__FILE__).'/root.html';
        $rawResponse->write( file_get_contents( $fname ) );
        $rawResponse->status(200, "OK");
    }

}