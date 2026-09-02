<?php

namespace FluffyDiscord\RapiraBundle\Tests\Writer;

use FluffyDiscord\RapiraBundle\Tests\Double\RecordingExchange;
use FluffyDiscord\RapiraBundle\Tests\RapiraTestCase;
use FluffyDiscord\RapiraBundle\Writer\BinaryFileResponseWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BinaryFileResponseWriterTest extends RapiraTestCase
{
    private string $file;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rapira-feed-');
        self::assertIsString($path);
        file_put_contents($path, 'FEED-CONTENT');
        $this->file = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testSendsFileViaHostSendFile(): void
    {
        $response = new BinaryFileResponse($this->file);
        $exchange = new RecordingExchange($this->makeRequest());

        new BinaryFileResponseWriter($response, false)->write($exchange);

        self::assertCount(1, $exchange->fileSends);
        self::assertSame(realpath($this->file), $exchange->fileSends[0]['path']);
        self::assertSame([], $exchange->bodyWrites);
    }

    public function testFallsBackToStreamingWhenFileNotSendable(): void
    {
        $response = new BinaryFileResponse($this->file);
        $exchange = new RecordingExchange($this->makeRequest(), rejectSendFile: true);

        new BinaryFileResponseWriter($response, false)->write($exchange);

        self::assertSame([], $exchange->fileSends);
        $delivered = implode('', array_column($exchange->bodyWrites, 'content'));
        self::assertStringContainsString('FEED-CONTENT', $delivered);
        self::assertTrue($exchange->isFinalized());
    }
}
