<?php

namespace FluffyDiscord\RapiraBundle\Tests\Writer;

use FluffyDiscord\RapiraBundle\Tests\Double\RecordingExchange;
use FluffyDiscord\RapiraBundle\Tests\RapiraTestCase;
use FluffyDiscord\RapiraBundle\Writer\ResponseWriter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResponseWriterTest extends RapiraTestCase
{
    public function testPlainResponseIsOneBodyWrite(): void
    {
        $response = new Response('hello world', 200);
        $exchange = new RecordingExchange($this->makeRequest());

        new ResponseWriter($response, false)->write($exchange);

        self::assertCount(1, $exchange->heads);
        self::assertSame(200, $exchange->heads[0]['status']);
        self::assertSame([['content' => 'hello world', 'eos' => true]], $exchange->bodyWrites);
        self::assertTrue($exchange->isFinalized());
    }

    public function testTransferEncodingDroppedAndContentLengthDroppedWhenNotHead(): void
    {
        $response = new Response('body', 200);
        $response->headers->set('Transfer-Encoding', 'chunked');
        $response->headers->set('Content-Length', '4');

        $exchange = new RecordingExchange($this->makeRequest());
        new ResponseWriter($response, false)->write($exchange);

        $headerKeys = array_map('strtolower', array_keys($exchange->heads[0]['headers']));
        self::assertNotContains('transfer-encoding', $headerKeys);
        self::assertNotContains('content-length', $headerKeys);
    }

    public function testContentLengthKeptForHeadRequest(): void
    {
        $response = new Response('', 200);
        $response->headers->set('Content-Length', '123');

        $exchange = new RecordingExchange($this->makeRequest('HEAD'));
        new ResponseWriter($response, true)->write($exchange);

        $headerKeys = array_map('strtolower', array_keys($exchange->heads[0]['headers']));
        self::assertContains('content-length', $headerKeys);
    }

    public function testStreamedResponseStreamsChunksThenClosesWithEos(): void
    {
        $streamed = new StreamedResponse(function (): void {
            echo 'chunk-1';
            echo 'chunk-2';
            echo 'chunk-3';
        });

        $exchange = new RecordingExchange($this->makeRequest());
        new ResponseWriter($streamed, false)->write($exchange);

        $progressiveChunks = array_values(array_filter(
            $exchange->bodyWrites,
            static fn(array $write) => $write['eos'] === false && $write['content'] !== '',
        ));
        self::assertSame(
            [
                ['content' => 'chunk-1', 'eos' => false],
                ['content' => 'chunk-2', 'eos' => false],
                ['content' => 'chunk-3', 'eos' => false],
            ],
            $progressiveChunks,
        );

        $lastWrite = $exchange->bodyWrites[array_key_last($exchange->bodyWrites)];
        self::assertTrue($lastWrite['eos']);
        self::assertTrue($exchange->isFinalized());
        self::assertGreaterThanOrEqual(3, $exchange->flushCount);

        self::assertSame(['no'], $exchange->heads[0]['headers']['X-Accel-Buffering']);
    }
}
