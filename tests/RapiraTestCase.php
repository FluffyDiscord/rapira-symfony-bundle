<?php

namespace FluffyDiscord\RapiraBundle\Tests;

use PHPUnit\Framework\TestCase;
use Rapira\Http\Multipart;
use Rapira\Http\Request;
use Rapira\Http\Tls;
use Rapira\InetAddress;
use Rapira\UnixAddress;

abstract class RapiraTestCase extends TestCase
{
    /**
     * @param array<string, list<string>> $headers
     */
    protected function makeRequest(
        string                  $method = 'GET',
        string                  $target = '/',
        array                   $headers = [],
        string|Multipart        $body = '',
        ?Tls                    $tls = null,
        InetAddress|UnixAddress $remote = new InetAddress('203.0.113.7', 44321),
        ?string                 $authority = 'shop.example',
    ): Request
    {
        $scheme = $tls === null ? 'http' : 'https';

        return new Request(
            method: $method,
            uri: $scheme . '://' . ($authority ?? 'shop.example') . $target,
            target: $target,
            authority: $authority,
            protocol: 'HTTP/1.1',
            headers: $headers,
            body: $body,
            remote: $remote,
            server: new InetAddress('10.0.0.1', 8000),
            tls: $tls,
            receivedAt: 1_788_000_000.5,
        );
    }
}
