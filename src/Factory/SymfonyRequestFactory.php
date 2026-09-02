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

        $server = $this->buildServerBag($rapiraRequest, $path, $queryString);
        $cookies = $this->parseCookies($rapiraRequest->headers);

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
     * @return non-empty-array<string, string|int|float>
     */
    private function buildServerBag(RapiraRequest $rapiraRequest, string $path, string $queryString): array
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

        foreach ($rapiraRequest->headers as $name => $values) {
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
     * @return array<string, string>
     */
    private function parseCookies(array $headers): array
    {
        $cookieValues = $headers['cookie'] ?? [];
        $cookieHeader = implode('; ', $cookieValues);
        if ($cookieHeader === '') {
            return [];
        }

        $cookies = [];
        foreach (explode(';', $cookieHeader) as $pair) {
            $equalsPosition = strpos($pair, '=');
            if ($equalsPosition === false) {
                continue;
            }

            $name = trim(substr($pair, 0, $equalsPosition));
            if ($name === '' || \array_key_exists($name, $cookies)) {
                continue;
            }

            $rawValue = substr($pair, $equalsPosition + 1);
            $cookies[$name] = rawurldecode($rawValue);
        }

        return $cookies;
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
        $isFormUrlEncoded = $contentTypeIsString && str_starts_with($contentType, 'application/x-www-form-urlencoded');
        if (!$isFormUrlEncoded) {
            return [];
        }

        $parameters = [];
        parse_str($body, $parameters);

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

        $parameters = [];
        parse_str(implode('&', $pairs), $parameters);

        return $parameters;
    }

    /**
     * @param list<RapiraUploadedFile> $uploadedFiles
     * @return array<array-key, mixed>
     */
    private function buildFiles(array $uploadedFiles): array
    {
        $files = [];
        foreach ($uploadedFiles as $uploadedFile) {
            $symfonyUploadedFile = new UploadedFile(
                $uploadedFile->tmpPath,
                $uploadedFile->clientFilename,
                $uploadedFile->clientMediaType,
                \UPLOAD_ERR_OK,
                true,
            );

            $keys = $this->parseFieldNameKeys($uploadedFile->name);
            $files = $this->insertFile($files, $keys, $symfonyUploadedFile);
        }

        return $files;
    }

    /**
     * @param array<array-key, mixed> $files
     * @param list<string> $keys
     * @return array<array-key, mixed>
     */
    private function insertFile(array $files, array $keys, UploadedFile $file): array
    {
        $key = array_shift($keys);
        if ($key === null) {
            return $files;
        }

        $isLeaf = $keys === [];

        if ($key === '') {
            $files[] = $isLeaf ? $file : $this->insertFile([], $keys, $file);

            return $files;
        }

        if ($isLeaf) {
            $files[$key] = $file;

            return $files;
        }

        $existingChild = $files[$key] ?? [];
        $childArray = \is_array($existingChild) ? $existingChild : [];
        $files[$key] = $this->insertFile($childArray, $keys, $file);

        return $files;
    }

    /**
     * "files[a][b]" → ["files", "a", "b"]; "docs[]" → ["docs", ""]; "avatar" → ["avatar"].
     *
     * @return list<string>
     */
    private function parseFieldNameKeys(string $fieldName): array
    {
        $bracketPosition = strpos($fieldName, '[');
        if ($bracketPosition === false) {
            return [$fieldName];
        }

        $base = substr($fieldName, 0, $bracketPosition);
        $keys = [$base];

        $matches = [];
        preg_match_all('/\[([^\]]*)\]/', $fieldName, $matches);
        foreach ($matches[1] as $key) {
            $keys[] = $key;
        }

        return $keys;
    }
}
