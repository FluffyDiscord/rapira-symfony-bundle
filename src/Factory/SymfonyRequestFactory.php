<?php

namespace FluffyDiscord\RapiraBundle\Factory;

use Rapira\Http\Multipart;
use Rapira\Http\Request as RapiraRequest;
use Rapira\Http\UploadedFile as RapiraUploadedFile;
use Rapira\InetAddress;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds a Symfony Request from the host-parsed Rapira request. The server bag is assembled
 * from the Rapira request alone; $_SERVER is never merged, so boot-time Dotenv values and
 * secrets never reach the request or anything that inspects it.
 */
readonly class SymfonyRequestFactory implements SymfonyRequestFactoryInterface
{
    public function __construct(
        private string $scriptFilename,
    )
    {
    }

    public function createRequest(RapiraRequest $rapiraRequest): Request
    {
        $target = $rapiraRequest->target;
        $queryPosition = strpos($target, '?');
        if ($queryPosition === false) {
            $path = $target;
            $queryString = '';
        } else {
            $path = substr($target, 0, $queryPosition);
            $queryString = substr($target, $queryPosition + 1);
        }

        $query = [];
        parse_str($queryString, $query);

        $headers = $this->getSafeLowercaseHeaders($rapiraRequest->headers);
        $server = $this->buildServerBag($rapiraRequest, $headers, $path, $queryString);
        $cookies = $this->parseCookies($headers);

        $body = $rapiraRequest->body;
        if ($body instanceof Multipart) {
            $requestParameters = $this->parseMultipartFields($body->fields);
            $files = $this->buildFiles($body->files);
            $content = '';
        } else {
            $files = [];
            $content = $body;
            $requestParameters = $this->parseUrlEncodedBody($server, $body);
        }

        return new Request($query, $requestParameters, [], $cookies, $files, $server, $content);
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<string, list<string>>
     */
    private function getSafeLowercaseHeaders(array $headers): array
    {
        $safeHeaders = [];
        foreach ($headers as $name => $values) {
            $isSafeName = preg_match('/^[A-Za-z0-9-]+$/', $name) === 1;
            if (!$isSafeName) {
                continue;
            }

            $lowercaseName = strtolower($name);
            $existingValues = $safeHeaders[$lowercaseName] ?? [];
            $safeHeaders[$lowercaseName] = [...$existingValues, ...$values];
        }

        return $safeHeaders;
    }

    /**
     * @param array<string, list<string>> $headers
     * @return non-empty-array<string, string|int|float>
     */
    private function buildServerBag(RapiraRequest $rapiraRequest, array $headers, string $path, string $queryString): array
    {
        $requestUri = $queryString === '' ? $path : $path . '?' . $queryString;

        $isSecure = $rapiraRequest->tls !== null;
        $defaultPort = $isSecure ? 443 : 80;

        $authority = $rapiraRequest->authority ?? '';
        $host = $authority;
        $port = $defaultPort;
        $portPosition = strrpos($authority, ':');
        if ($portPosition !== false) {
            $host = substr($authority, 0, $portPosition);
            $parsedPort = (int) substr($authority, $portPosition + 1);
            if ($parsedPort > 0) {
                $port = $parsedPort;
            }
        }

        $remote = $rapiraRequest->remote;
        $remoteAddress = $remote instanceof InetAddress ? $remote->ip : '127.0.0.1';

        $server = [
            'REQUEST_METHOD'     => $rapiraRequest->method,
            'SERVER_PROTOCOL'    => $rapiraRequest->protocol,
            'REQUEST_TIME'       => (int) $rapiraRequest->receivedAt,
            'REQUEST_TIME_FLOAT' => $rapiraRequest->receivedAt,
            'REQUEST_URI'        => $requestUri,
            'QUERY_STRING'       => $queryString,
            'HTTP_HOST'          => $authority,
            'SERVER_NAME'        => $host,
            'SERVER_PORT'        => $port,
            'REMOTE_ADDR'        => $remoteAddress,
            'SCRIPT_NAME'        => '',
            'SCRIPT_FILENAME'    => $this->scriptFilename,
            'DOCUMENT_ROOT'      => \dirname($this->scriptFilename),
        ];

        if ($remote instanceof InetAddress) {
            $server['REMOTE_PORT'] = $remote->port;
        }

        if ($isSecure) {
            $server['HTTPS'] = 'on';
        }

        foreach ($headers as $name => $values) {
            $key = strtoupper(str_replace('-', '_', $name));
            $separator = $name === 'cookie' ? '; ' : ', ';
            $value = implode($separator, $values);

            $isContentHeader = $key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH';
            if ($isContentHeader) {
                $server[$key] = $value;
                continue;
            }

            $server['HTTP_' . $key] = $value;
        }

        return $server;
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<array-key, mixed>
     */
    private function parseCookies(array $headers): array
    {
        $cookieValues = $headers['cookie'] ?? [];
        $cookieHeader = implode('; ', $cookieValues);

        $pairs = [];
        $seenNames = [];
        foreach (explode(';', $cookieHeader) as $cookie) {
            $trimmedCookie = ltrim($cookie, " \t\n\r\v\f");
            [$name, $rawValue] = explode('=', $trimmedCookie, 2) + [1 => ''];
            if ($name === '') {
                continue;
            }

            $encodedName = urlencode($name);
            $registeredCookie = $this->parseQueryString($encodedName);
            $registeredName = array_key_first($registeredCookie);
            if ($registeredName === null) {
                continue;
            }

            $isPlainName = !\is_array($registeredCookie[$registeredName]);
            $isRepeatedPlainName = $isPlainName && isset($seenNames[$registeredName]);
            if ($isRepeatedPlainName) {
                continue;
            }

            $seenNames[$registeredName] = true;

            $decodedValue = rawurldecode($rawValue);
            $pairs[] = $encodedName . '=' . urlencode($decodedValue);
        }

        return $this->parseQueryString(implode('&', $pairs));
    }

    /**
     * @param non-empty-array<string, string|int|float> $server
     * @return array<array-key, mixed>
     */
    private function parseUrlEncodedBody(array $server, string $body): array
    {
        if ($body === '') {
            return [];
        }

        $contentType = $server['CONTENT_TYPE'] ?? '';
        $contentTypeIsString = \is_string($contentType);
        if (!$contentTypeIsString) {
            return [];
        }

        $mediaType = $this->getMediaType($contentType);
        $isFormUrlEncoded = $mediaType === 'application/x-www-form-urlencoded';
        if (!$isFormUrlEncoded) {
            return [];
        }

        return $this->parseQueryString($body);
    }

    private function getMediaType(string $contentType): string
    {
        $mediaTypeLength = strcspn($contentType, ';, ');
        $mediaType = substr($contentType, 0, $mediaTypeLength);

        return strtolower($mediaType);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parseQueryString(string $queryString): array
    {
        $parameters = [];
        parse_str($queryString, $parameters);

        return $parameters;
    }

    /**
     * Rebuilds the request bag from multipart fields the way PHP itself would: encoding each
     * field back into a query string and running parse_str reproduces bracket nesting and the
     * "." / space → "_" key rewrite exactly, and preserves repeated names.
     *
     * @param list<\Rapira\Http\FormField> $fields
     * @return array<array-key, mixed>
     */
    private function parseMultipartFields(array $fields): array
    {
        $pairs = [];
        foreach ($fields as $field) {
            $pairs[] = urlencode($field->name) . '=' . urlencode($field->value);
        }

        return $this->parseQueryString(implode('&', $pairs));
    }

    /**
     * @param list<RapiraUploadedFile> $uploadedFiles
     * @return array<array-key, mixed>
     */
    private function buildFiles(array $uploadedFiles): array
    {
        $pairs = [];
        foreach ($uploadedFiles as $index => $uploadedFile) {
            $isUploadName = $this->isUploadName($uploadedFile->name);
            if ($isUploadName) {
                $pairs[] = urlencode($uploadedFile->name) . '=' . $index;
            }
        }

        $files = $this->parseQueryString(implode('&', $pairs));
        array_walk_recursive($files, function (string &$file) use ($uploadedFiles): void {
            $index = (int) $file;
            $file = $this->createFile($uploadedFiles[$index]);
        });

        return $files;
    }

    /**
     * @return UploadedFile|array{error: int, full_path: string, name: string, size: int, tmp_name: string, type: string}
     */
    private function createFile(RapiraUploadedFile $uploadedFile): UploadedFile|array
    {
        if ($uploadedFile->clientFilename === '') {
            return [
                'error' => \UPLOAD_ERR_NO_FILE,
                'full_path' => '',
                'name' => '',
                'size' => 0,
                'tmp_name' => '',
                'type' => $uploadedFile->clientMediaType ?? '',
            ];
        }

        $keptPath = $this->keepSpooledFile($uploadedFile->tmpPath);

        return new UploadedFile(
            $keptPath,
            $uploadedFile->clientFilename,
            $uploadedFile->clientMediaType,
            \UPLOAD_ERR_OK,
            true,
        );
    }

    private function keepSpooledFile(string $spooledPath): string
    {
        $keptPath = $spooledPath . '-kept';
        rename($spooledPath, $keptPath);

        return $keptPath;
    }

    private function isUploadName(string $fieldName): bool
    {
        $depth = 0;
        $length = \strlen($fieldName);
        for ($position = 0; $position < $length; $position++) {
            $character = $fieldName[$position];
            if ($character === '[') {
                $depth++;
            }

            if ($character === ']') {
                $depth--;

                $nextCharacter = $fieldName[$position + 1] ?? '[';
                if ($nextCharacter !== '[') {
                    return false;
                }
            }

            if ($depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }
}
