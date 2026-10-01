# Rapira Runtime for Symfony

Run Symfony as a resident worker under [Rapira](https://github.com/rapira-rs/rapira) dispatcher mode.

HTTP only.

## Features

- [Resident HTTP worker](#usage) — boots once, `services_resetter` runs after every response
- [Worker warmup](#worker-warmup) — zero-config; first request at steady-state speed
- [Streaming](#responsefile-streaming) — `StreamedResponse`, `StreamedJsonResponse`, `BinaryFileResponse`
- [Uploads](#uploads) — parsed by Rapira, handed over as `UploadedFile`
- [Error handling](#error-handling) — kernel reboot after a crash, real `500` responses
- [Sentry](#sentry) — one scope per request, flushed after the response
- [PostgreSQL preconnect](#configuration) — connections open before the first request
- [xhprof profiling](#configuration) — per-request profiles from the resident worker
- [libvips cache limit](#configuration) — bounded RSS for image-heavy apps

## Requirements

- PHP >= 8.4
- Rapira nightly (boot-time `$_SERVER`, [rapira#129](https://github.com/rapira-rs/rapira/issues/129))
- `symfony/*` `^7.4 || ^8`

## Installation

```shell
composer require fluffydiscord/rapira-symfony-bundle
```

`config/bundles.php`:

```diff
 return [
+    FluffyDiscord\RapiraBundle\FluffyDiscordRapiraBundle::class => ['all' => true],
 ];
```

## Usage

1. Select the runtime:

```shell
composer config extra.runtime.class 'FluffyDiscord\RapiraBundle\Runtime\Runtime'
composer dump-autoload
```

2. Swap the kernel trait:

```diff
- use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
+ use FluffyDiscord\RapiraBundle\Kernel\RapiraMicroKernelTrait;

class Kernel extends BaseKernel
{
-    use MicroKernelTrait;
+    use RapiraMicroKernelTrait;
}
```

3. `rapira.toml` in the project root — point `entrypoint` at your stock `public/index.php`. Full sample: `vendor/fluffydiscord/rapira-symfony-bundle/rapira.toml`.

```toml
[http]
listen = "0.0.0.0:8000"

[http.pool]
entrypoint = "public/index.php"
mode = "dispatcher"
processes = 4
```

```shell
rapira serve rapira.toml
```

`public/index.php` and `bin/console` stay as the skeleton ships them. The runtime takes over only under Rapira `dispatcher` mode; console and `classic` mode get the stock Symfony runner.

> Rather not touch `composer.json`? Set the `APP_RUNTIME` env var on the Rapira process instead (Dockerfile `ENV`, compose `environment`). `.env` files are read too late for it.

### dev vs prod

|      | `mode`       | Code changes                 |
| ---- | ------------ | ---------------------------- |
| dev  | `classic`    | picked up on the next request |
| prod | `dispatcher` | restart Rapira               |

## Configuration

`config/packages/rapira.yaml`

```yaml
rapira:
    warmup:
        enabled: true
        learn: true
        learn_requests: 30
        manifest_path: ~
    doctrine:
        preconnect: false
    profiling:
        xhprof:
            enabled: false
            output_dir: ~
    vips:
        enabled: auto
        max_operations: 50
        max_memory_mb: 50
        max_files: 20
```

| Option | Default | Meaning |
|---|---|---|
| `warmup.enabled` | `true` | Master switch for the boot-time warmers and the recorder. See [Worker warmup](#worker-warmup). |
| `warmup.learn` | `true` | Record which classes and cache files real responses load, replay them at every later worker boot. |
| `warmup.learn_requests` | `30` | Stop recording after this many responses per worker. |
| `warmup.manifest_path` | `null` | `null` = `<kernel.cache_dir>/rapira/warmup.manifest.json`. Point outside the cache dir to keep learning across deploys. |
| `doctrine.preconnect` | `false` | Open PostgreSQL connections at worker boot. Needs `doctrine/dbal`. |
| `profiling.xhprof.enabled` | `false` | `true` = profile every request. `auto` = when `ext-xhprof` is loaded and `kernel.debug` is on. |
| `profiling.xhprof.output_dir` | `null` | `null` = ini `xhprof.output_dir`, then `<sys_get_temp_dir>/xhprof`. |
| `vips.enabled` | `auto` | Bound libvips's process-global cache at worker boot. `auto` = when `jcupitt/vips` is installed. |
| `vips.max_*` | `50` / `50` / `20` | libvips cache limits: operations, memory (MB), open files. |

## Worker warmup

The bundle warms during worker boot, before Rapira hands it the first request. Zero config:

1. **Generic warmers** — router, Doctrine metadata, event listeners, form types, Twig runtimes, container preload class list. Missing dependencies are skipped.
2. **Learned manifest** — workers record what real traffic loads; every next worker replays it at boot. Invalidated when the container is rebuilt.

Runs only in `dispatcher` mode — the runtime sets `APP_RUNTIME_MODE=web=1&worker=1`, overriding any `.env` value. In `classic` mode nothing stays warm, so warmup switches itself off.

> Warmed classes live in each worker's opcache — budget `opcache.memory_consumption` × `processes`.

### Warming your own services

```php
use FluffyDiscord\RapiraBundle\Warmup\WorkerWarmerInterface;

class MyCacheWarmer implements WorkerWarmerInterface
{
    public function __construct(private readonly MyExpensiveService $service)
    {
    }

    public function warmup(): void
    {
        $this->service->buildInMemoryIndexes();
    }
}
```

Autoconfigured. Or listen to `WorkerBootingEvent`.

## Response/file streaming

`StreamedResponse` and `StreamedJsonResponse` stream progressively and carry `X-Accel-Buffering: no`.

> Callbacks must `echo` — a `yield`-based callback is **not** run:

```diff
 return new StreamedResponse(
-    function (): \Generator {
-        yield "data";
+    function (): void {
+        echo "data";
     }
 );
```

`BinaryFileResponse` inside the `[http.sendfile]` root is sent by Rapira straight from disk. Files outside it, and `deleteFileAfterSend()`, stream through PHP.

## Request data

Read everything from the injected `Request`:

- `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES` stay empty.
- `$_SERVER` holds the process env, never the current request.
- `echo` / `header()` outside a streamed callback are discarded — respond through the `Response`.

## Uploads

Rapira parses multipart bodies (`[http.uploads]` in `rapira.toml`) and the bundle maps them to Symfony `UploadedFile`s. `move()` works as usual.

```toml
[http.uploads]
dir = "/tmp"
max_file_size_mb = 16
max_files = 20
```

Over the limit → `413`.

## Error handling

| Failure | dev (`kernel.debug`) | prod |
|---|---|---|
| exception in your code | Symfony's exception page | Symfony's error page |
| exception escaping Symfony | `HtmlErrorRenderer` page | bare `500`, empty body |
| exception after the response head was sent | response cut off | response cut off |

After an escaping exception the kernel reboots, so the next request gets a clean container. Details go to the Rapira log and Sentry if installed.

> `error_log()` output is discarded in dispatcher mode — the bundle logs through `\Rapira\log()` (the `app` target).

Not covered:

- kernel boot failure — no error page; the exception lands in the Rapira log as a PHP fatal error
- Rapira `worker` mode — gets the stock Symfony runner, no resident loop; use `dispatcher`

## Sentry

```shell
composer require sentry/sentry-symfony
```

Configure as usual. Each request gets its own scope, flushed after the response.

## Events

| Event | Payload |
|---|---|
| `WorkerBootingEvent` | — |
| `WorkerRequestReceivedEvent` | `Rapira\Http\Request` |
| `WorkerResponseSentEvent` | Symfony `Request` + `Response` |
| `WorkerRequestFailedEvent` | `Rapira\Http\Request` + throwable |

## Developing with Symfony and Rapira

Same rules as any resident worker — see [Developing with Symfony and RoadRunner](https://github.com/FluffyDiscord/roadrunner-symfony-bundle#developing-with-symfony-and-roadrunner): no per-request state in services, static form defaults, lean `User` session serialization.

## Testing

```shell
tests/docker-qa.sh                # PHPStan (level max) + PHPUnit, in a container
tests/docker/run-integration.sh   # IT-101..IT-107 against the real Rapira binary
```
