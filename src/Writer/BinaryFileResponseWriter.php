<?php

namespace FluffyDiscord\RapiraBundle\Writer;

use Rapira\Http\Exception\FileNotSendableException;
use Rapira\Http\Exchange;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BinaryFileResponseWriter extends ResponseWriter
{
    protected function writeBody(Exchange $exchange): void
    {
        $response = $this->response;
        assert($response instanceof BinaryFileResponse);

        $reflection = new \ReflectionObject($response);

        $offsetValue = $reflection->getProperty('offset')->getValue($response);
        $offset = is_int($offsetValue) ? max(0, $offsetValue) : 0;

        $maxLengthValue = $reflection->getProperty('maxlen')->getValue($response);
        $maxLength = is_int($maxLengthValue) ? $maxLengthValue : -1;

        $deleteAfterSendValue = $reflection->getProperty('deleteFileAfterSend')->getValue($response);
        $deleteAfterSend = $deleteAfterSendValue === true;

        $path = $response->getFile()->getPathname();
        $realPath = realpath($path);

        $canSendFile = $realPath !== false && !$deleteAfterSend && $maxLength !== 0;
        if ($canSendFile) {
            assert($realPath !== '');
            $length = $maxLength < 0 ? null : $maxLength;
            try {
                $exchange->sendFile($realPath, $offset, $length, true);

                return;
            } catch (FileNotSendableException) {
            }
        }

        $this->streamBody($exchange);
    }
}
