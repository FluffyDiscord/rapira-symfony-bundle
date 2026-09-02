<?php

namespace FluffyDiscord\RapiraBundle\Tests\Factory;

use FluffyDiscord\RapiraBundle\Factory\SymfonyRequestFactory;
use FluffyDiscord\RapiraBundle\Tests\RapiraTestCase;
use Rapira\Http\FormField;
use Rapira\Http\Multipart;
use Rapira\Http\Tls;
use Rapira\Http\UploadedFile as RapiraUploadedFile;
use Rapira\UnixAddress;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SymfonyRequestFactoryTest extends RapiraTestCase
{
    /** @var list<string> */
    private array $spooledFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->spooledFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->spooledFiles = [];
    }

    private function factory(): SymfonyRequestFactory
    {
        return new SymfonyRequestFactory('/srv/app/public/index.php');
    }

    public function testSplitsTargetIntoPathAndQuery(): void
    {
        $rapiraRequest = $this->makeRequest(target: '/kategorie/strihaci-strojky?page=2&sort=price');
        $request = $this->factory()->createRequest($rapiraRequest);

        self::assertSame('/kategorie/strihaci-strojky', $request->getPathInfo());
        self::assertSame('2', $request->query->get('page'));
        self::assertSame('price', $request->query->get('sort'));
    }

    public function testHostPortSchemeAndClientIp(): void
    {
        $tls = new Tls('TLSv1.3', 'TLS_AES_128_GCM_SHA256', 'h2', null, null, null, null);
        $rapiraRequest = $this->makeRequest(target: '/', tls: $tls, authority: 'shop.example:8443');

        $request = $this->factory()->createRequest($rapiraRequest);

        self::assertTrue($request->isSecure());
        self::assertSame('shop.example', $request->getHost());
        self::assertSame(8443, $request->getPort());
        self::assertSame('203.0.113.7', $request->getClientIp());
    }

    public function testPlainHttpIsNotSecure(): void
    {
        $request = $this->factory()->createRequest($this->makeRequest());

        self::assertFalse($request->isSecure());
    }

    public function testUnixSocketRemoteMapsToLoopback(): void
    {
        $rapiraRequest = $this->makeRequest(remote: new UnixAddress('/run/rapira.sock'));
        $request = $this->factory()->createRequest($rapiraRequest);

        self::assertSame('127.0.0.1', $request->server->get('REMOTE_ADDR'));
    }

    public function testCookiesParsedFirstOccurrenceWins(): void
    {
        $headers = ['cookie' => ['sid=abc%20def; theme=dark', 'sid=SHOULD_NOT_WIN']];
        $request = $this->factory()->createRequest($this->makeRequest(headers: $headers));

        self::assertSame('abc def', $request->cookies->get('sid'));
        self::assertSame('dark', $request->cookies->get('theme'));
    }

    public function testFormUrlEncodedBodyPopulatesRequestBag(): void
    {
        $rapiraRequest = $this->makeRequest(
            method: 'POST',
            headers: ['content-type' => ['application/x-www-form-urlencoded']],
            body: 'name=Rick&tags%5B%5D=a&tags%5B%5D=b',
        );

        $request = $this->factory()->createRequest($rapiraRequest);

        self::assertSame('Rick', $request->request->get('name'));
        self::assertSame(['a', 'b'], $request->request->all()['tags']);
    }

    public function testJsonBodyLeavesRequestBagEmptyButKeepsContent(): void
    {
        $json = '{"name":"Rick"}';
        $rapiraRequest = $this->makeRequest(
            method: 'POST',
            headers: ['content-type' => ['application/json']],
            body: $json,
        );

        $request = $this->factory()->createRequest($rapiraRequest);

        self::assertSame([], $request->request->all());
        self::assertSame($json, $request->getContent());
        self::assertSame('Rick', $request->toArray()['name']);
    }

    public function testMultipartFieldsAndNestedFiles(): void
    {
        $spoolA = (string) tempnam(sys_get_temp_dir(), 'rapira-upA-');
        $spoolB = (string) tempnam(sys_get_temp_dir(), 'rapira-upB-');
        $this->spooledFiles[] = $spoolA;
        $this->spooledFiles[] = $spoolB;

        $fields = [
            new FormField('title', 'Hello', []),
        ];
        $files = [
            new RapiraUploadedFile('avatar', 'me.png', 'image/png', [], $spoolA, 10),
            new RapiraUploadedFile('docs[a][b]', 'deep.txt', 'text/plain', [], $spoolB, 20),
        ];
        $multipart = new Multipart($fields, $files);

        $rapiraRequest = $this->makeRequest(
            method: 'POST',
            headers: ['content-type' => ['multipart/form-data; boundary=xyz']],
            body: $multipart,
        );

        $request = $this->factory()->createRequest($rapiraRequest);

        self::assertSame('Hello', $request->request->get('title'));
        self::assertSame('', $request->getContent());

        $avatar = $request->files->get('avatar');
        self::assertInstanceOf(UploadedFile::class, $avatar);
        self::assertSame('me.png', $avatar->getClientOriginalName());

        $nested = $request->files->all()['docs'];
        self::assertInstanceOf(UploadedFile::class, $nested['a']['b']);
        self::assertSame('deep.txt', $nested['a']['b']->getClientOriginalName());
    }
}
