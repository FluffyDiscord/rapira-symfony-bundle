<?php

namespace FluffyDiscord\RapiraBundle\Tests\Factory;

use FluffyDiscord\RapiraBundle\Factory\SymfonyRequestFactory;
use FluffyDiscord\RapiraBundle\Tests\RapiraTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Rapira\Http\FormField;
use Rapira\Http\Multipart;
use Rapira\Http\Request as RapiraRequest;
use Rapira\Http\UploadedFile as RapiraUploadedFile;
use Rapira\Tls;
use Rapira\UnixAddress;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SymfonyRequestFactoryTest extends RapiraTestCase
{
    private string $spoolDirectory;

    protected function setUp(): void
    {
        $this->spoolDirectory = sys_get_temp_dir() . '/rapira-spool-test-' . bin2hex(random_bytes(4));
        mkdir($this->spoolDirectory);
    }

    protected function tearDown(): void
    {
        $spooledFiles = glob($this->spoolDirectory . '/*') ?: [];
        foreach ($spooledFiles as $file) {
            unlink($file);
        }

        rmdir($this->spoolDirectory);
    }

    private function factory(): SymfonyRequestFactory
    {
        return new SymfonyRequestFactory('/srv/app/public/index.php');
    }

    private function spool(string $content = ''): string
    {
        $path = (string) tempnam($this->spoolDirectory, 'up-');
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @param list<RapiraUploadedFile> $files
     */
    private function makeMultipartRequest(array $files): RapiraRequest
    {
        return $this->makeRequest(
            method: 'POST',
            headers: ['content-type' => ['multipart/form-data; boundary=xyz']],
            body: new Multipart([], $files),
        );
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

    public function testCookiesAreParsedLikePhpFpm(): void
    {
        $cookieHeader = 'sid=abc%20def; theme=dark; sid=x; a.b=1; arr[x]=1; arr[y]=2; arr[x]=3; flag; plus=a+b; enc%20name=v;   spaced=1;=noname; q[]=1; q[]=2; eq=a=b; br[=1; t[a]x=1';
        $request = $this->factory()->createRequest($this->makeRequest(headers: ['cookie' => [$cookieHeader]]));

        $expectedCookies = [
            'sid'        => 'abc def',
            'theme'      => 'dark',
            'a_b'        => '1',
            'arr'        => ['x' => '3', 'y' => '2'],
            'flag'       => '',
            'plus'       => 'a+b',
            'enc%20name' => 'v',
            'spaced'     => '1',
            'q'          => ['1', '2'],
            'eq'         => 'a=b',
            'br_'        => '1',
            't'          => ['a' => '1'],
        ];
        self::assertSame($expectedCookies, $request->cookies->all());
    }

    public function testHeaderNamesAreMatchedCaseInsensitively(): void
    {
        $headers = [
            'Cookie'       => ['sid=abc'],
            'cookie'       => ['theme=dark'],
            'Content-Type' => ['application/json'],
        ];
        $request = $this->factory()->createRequest($this->makeRequest(headers: $headers));

        self::assertSame('abc', $request->cookies->get('sid'));
        self::assertSame('dark', $request->cookies->get('theme'));
        self::assertSame('sid=abc; theme=dark', $request->server->get('HTTP_COOKIE'));
        self::assertSame('application/json', $request->headers->get('content-type'));
    }

    public function testHeaderNamesWithUnderscoresAreDropped(): void
    {
        $headers = [
            'x-forwarded-for'   => ['198.51.100.1'],
            'X_Forwarded_For'   => ['6.6.6.6'],
            'x_only_underscore' => ['1'],
        ];
        $request = $this->factory()->createRequest($this->makeRequest(headers: $headers));

        self::assertSame('198.51.100.1', $request->server->get('HTTP_X_FORWARDED_FOR'));
        self::assertFalse($request->server->has('HTTP_X_ONLY_UNDERSCORE'));
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

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function formContentTypes(): array
    {
        return [
            'mixed case'        => ['Application/X-WWW-Form-Urlencoded', ['a' => '1']],
            'charset parameter' => ['application/x-www-form-urlencoded;charset=UTF-8', ['a' => '1']],
            'comma suffix'      => ['application/x-www-form-urlencoded, x', ['a' => '1']],
            'space suffix'      => ['application/x-www-form-urlencoded x', ['a' => '1']],
            'longer media type' => ['application/x-www-form-urlencodedX', []],
        ];
    }

    /**
     * @param array<string, string> $expectedParameters
     */
    #[DataProvider('formContentTypes')]
    public function testFormMediaTypeIsMatchedLikePhpFpm(string $contentType, array $expectedParameters): void
    {
        $rapiraRequest = $this->makeRequest(method: 'POST', headers: ['content-type' => [$contentType]], body: 'a=1');

        $request = $this->factory()->createRequest($rapiraRequest);

        self::assertSame($expectedParameters, $request->request->all());
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
        $spoolA = $this->spool();
        $spoolB = $this->spool();

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

    public function testFileFieldNamesAreMangledLikePhpFpm(): void
    {
        $request = $this->factory()->createRequest($this->makeMultipartRequest([
            new RapiraUploadedFile('user.avatar', 'a.txt', null, [], $this->spool('a'), 1),
            new RapiraUploadedFile('my file', 'b.txt', null, [], $this->spool('b'), 1),
            new RapiraUploadedFile(' lead', 'c.txt', null, [], $this->spool('c'), 1),
            new RapiraUploadedFile('a.b[c.d]', 'd.txt', null, [], $this->spool('d'), 1),
            new RapiraUploadedFile('my.f[x y][]', 'e.txt', null, [], $this->spool('e'), 1),
        ]));

        $files = $request->files->all();
        self::assertSame(['user_avatar', 'my_file', 'lead', 'a_b', 'my_f'], array_keys($files));
        self::assertInstanceOf(UploadedFile::class, $files['a_b']['c.d']);
        self::assertInstanceOf(UploadedFile::class, $files['my_f']['x y'][0]);
        self::assertSame('e.txt', $files['my_f']['x y'][0]->getClientOriginalName());
    }

    public function testMalformedFileFieldNamesAreDroppedLikePhpFpm(): void
    {
        $malformedSpool = $this->spool('x');

        $request = $this->factory()->createRequest($this->makeMultipartRequest([
            new RapiraUploadedFile('a[b', 'a.txt', null, [], $malformedSpool, 1),
            new RapiraUploadedFile('x[y]z', 'b.txt', null, [], $this->spool('x'), 1),
            new RapiraUploadedFile('a]', 'c.txt', null, [], $this->spool('x'), 1),
            new RapiraUploadedFile('a[[b]]', 'd.txt', null, [], $this->spool('x'), 1),
            new RapiraUploadedFile('ok[k] ', 'e.txt', null, [], $this->spool('x'), 1),
            new RapiraUploadedFile('[x]', 'f.txt', null, [], $this->spool('x'), 1),
            new RapiraUploadedFile('good', 'g.txt', null, [], $this->spool('x'), 1),
        ]));

        self::assertSame(['good'], array_keys($request->files->all()));
        self::assertFileExists($malformedSpool);
        self::assertCount(1, glob($this->spoolDirectory . '/*-kept') ?: []);
    }

    public function testUploadOutlivesTheHostSpoolFile(): void
    {
        $spool = $this->spool('hello');

        $request = $this->factory()->createRequest($this->makeMultipartRequest([
            new RapiraUploadedFile('avatar', 'hello.txt', 'text/plain', [], $spool, 5),
        ]));

        $avatar = $request->files->get('avatar');
        self::assertInstanceOf(UploadedFile::class, $avatar);
        self::assertFileDoesNotExist($spool);
        self::assertStringEqualsFile($avatar->getPathname(), 'hello');
    }

    public function testEmptyFileWithAFilenameIsAValidUpload(): void
    {
        $request = $this->factory()->createRequest($this->makeMultipartRequest([
            new RapiraUploadedFile('avatar', 'empty.txt', 'text/plain', [], $this->spool(), 0),
        ]));

        $avatar = $request->files->get('avatar');
        self::assertInstanceOf(UploadedFile::class, $avatar);
        self::assertSame(\UPLOAD_ERR_OK, $avatar->getError());
        self::assertSame(0, $avatar->getSize());
        self::assertSame('empty.txt', $avatar->getClientOriginalName());
    }

    public function testEmptyFilenamePartIsNotAnUpload(): void
    {
        $spool = $this->spool();

        $files = [
            new RapiraUploadedFile('avatar', '', 'application/octet-stream', [], $spool, 0),
        ];
        $multipart = new Multipart([new FormField('title', 'Hello', [])], $files);

        $rapiraRequest = $this->makeRequest(
            method: 'POST',
            headers: ['content-type' => ['multipart/form-data; boundary=xyz']],
            body: $multipart,
        );

        $request = $this->factory()->createRequest($rapiraRequest);

        self::assertTrue($request->files->has('avatar'));
        self::assertNull($request->files->get('avatar'));
        self::assertSame('Hello', $request->request->get('title'));
    }

    public function testEmptyFilenamePartDoesNotDisturbSiblingUploads(): void
    {
        $spoolEmpty = $this->spool();
        $spoolReal = $this->spool();

        $files = [
            new RapiraUploadedFile('images[0][file]', '', 'application/octet-stream', [], $spoolEmpty, 0),
            new RapiraUploadedFile('images[1][file]', 'photo.png', 'image/png', [], $spoolReal, 10),
        ];
        $multipart = new Multipart([], $files);

        $rapiraRequest = $this->makeRequest(
            method: 'POST',
            headers: ['content-type' => ['multipart/form-data; boundary=xyz']],
            body: $multipart,
        );

        $request = $this->factory()->createRequest($rapiraRequest);

        $images = $request->files->all()['images'];
        self::assertArrayHasKey(0, $images);
        self::assertNull($images[0]['file']);
        self::assertInstanceOf(UploadedFile::class, $images[1]['file']);
        self::assertSame('photo.png', $images[1]['file']->getClientOriginalName());
    }

    public function testEmptyFilenamePartKeepsListIndicesAligned(): void
    {
        $spoolEmpty = $this->spool();
        $spoolReal = $this->spool();

        $files = [
            new RapiraUploadedFile('docs[]', '', 'application/octet-stream', [], $spoolEmpty, 0),
            new RapiraUploadedFile('docs[]', 'deep.txt', 'text/plain', [], $spoolReal, 20),
        ];
        $multipart = new Multipart([], $files);

        $rapiraRequest = $this->makeRequest(
            method: 'POST',
            headers: ['content-type' => ['multipart/form-data; boundary=xyz']],
            body: $multipart,
        );

        $request = $this->factory()->createRequest($rapiraRequest);

        $docs = $request->files->all()['docs'];
        self::assertSame([1], array_keys($docs));
        self::assertSame('deep.txt', $docs[1]->getClientOriginalName());
    }
}
