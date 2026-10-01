# Rapira Runtime for Symfony

Symfony runtime for [Rapira](https://github.com/rapira-rs/rapira). HTTP only.

DDEV users: see [DDEV add-on](#ddev-add-on).

## Features

- [HTTP worker](#usage) — kernel boots once, `services_resetter` runs *after* the response
- [Worker warmup](#worker-warmup) — zero-config; first request at steady-state speed
- [Streaming](#responsefile-streaming) — `StreamedResponse`, `StreamedJsonResponse`, `BinaryFileResponse`
- [Uploads](#uploads) — plain `UploadedFile`s
- [Graceful error handling](#error-handling) — kernel reboot after a crash, real `500` responses
- [Sentry](#sentry) — one scope per request
- [PostgreSQL preconnect](#configuration)
- [xhprof profiling](#configuration)
- [libvips cache limit](#configuration)

## Requirements

- PHP >= 8.4
- Rapira nightly — needs boot-time `$_SERVER` ([rapira#129](https://github.com/rapira-rs/rapira/issues/129))
- Symfony `^7.4 || ^8`

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

1. Set the runtime:

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

3. `rapira.toml` — point `entrypoint` at your `public/index.php`:

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

Full sample: [`rapira.toml`](rapira.toml).

`public/index.php` and `bin/console` stay untouched. Console and `classic` mode run the stock Symfony runner.

> Rather not touch `composer.json`? Set `APP_RUNTIME` on the Rapira process (Dockerfile `ENV`, compose `environment`). `.env` is read too late for it.

### dev vs prod

|      | `mode`       | Code changes                  |
| ---- | ------------ | ----------------------------- |
| dev  | `classic`    | picked up on the next request |
| prod | `dispatcher` | restart Rapira                |

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
| `warmup.enabled` | `true` | Master switch for the warmers and the recorder. See [Worker warmup](#worker-warmup). |
| `warmup.learn` | `true` | Record which classes and cache files real responses load, replay them at every later worker boot. |
| `warmup.learn_requests` | `30` | Stop recording after this many responses per worker. |
| `warmup.manifest_path` | `null` | `null` = `<kernel.cache_dir>/rapira/warmup.manifest.json`. Point outside the cache dir to keep learning across deploys. |
| `doctrine.preconnect` | `false` | Open PostgreSQL connections at worker boot. Needs `doctrine/dbal`. |
| `profiling.xhprof.enabled` | `false` | `true` = profile every request. `auto` = when `ext-xhprof` is loaded and `kernel.debug` is on. |
| `profiling.xhprof.output_dir` | `null` | `null` = ini `xhprof.output_dir`, then `<sys_get_temp_dir>/xhprof`. |
| `vips.enabled` | `auto` | Cap libvips's process-wide cache at worker boot. `auto` = when `jcupitt/vips` is installed. |
| `vips.max_*` | `50` / `50` / `20` | libvips cache limits: operations, memory (MB), open files. |

## Worker warmup

The bundle warms during worker boot, before Rapira sends the first request. Zero config:

1. **Generic warmers** — router, Doctrine metadata, event listeners, form types, Twig runtimes, container preload class list. Missing dependencies are skipped.
2. **Learned manifest** — workers record what real traffic loads; every next worker replays it at boot. Invalidated when the container is rebuilt.

`dispatcher` mode only — the runtime sets `APP_RUNTIME_MODE=web=1&worker=1`, whatever `.env` says. `classic` mode keeps nothing warm, so warmup switches itself off.

Warmed classes live in each worker's opcache — budget `opcache.memory_consumption` × `processes`.

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

`StreamedResponse` and `StreamedJsonResponse` stream as they go and send `X-Accel-Buffering: no`. Callbacks must `echo` — a `\Generator` callback never runs:

```diff
 return new StreamedResponse(
-    function (): \Generator {
-        yield "data";
+    function (): void {
+        echo "data";
     }
 );
```

`BinaryFileResponse` inside the `[http.sendfile]` root → Rapira sends it straight from disk. Outside it, or with `deleteFileAfterSend()` → streamed through PHP.

## Request data

Superglobals aren't per-request. Use the `Request`:

- `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES` are empty.
- `$_SERVER` holds the process env, never the current request.
- `echo` / `header()` outside a streamed callback are dropped — return a `Response`.

## Uploads

Rapira parses multipart bodies, you get regular `UploadedFile`s. `move()` works as usual.

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
| exception after headers were sent | response cut off | response cut off |

- Escaping exception → kernel reboots; the next request gets a clean container.
- Details go to the Rapira log and Sentry if installed.
- `error_log()` output is lost in `dispatcher` mode — the bundle logs through `\Rapira\log()` (target `app`).

Not covered:

- kernel boot failure — no error page, just a PHP fatal in the Rapira log
- Rapira `worker` mode — stock Symfony runner, no worker loop; use `dispatcher`

## Sentry

```shell
composer require sentry/sentry-symfony
```

Configure as usual.

## Events

| Event | Payload |
|---|---|
| `WorkerBootingEvent` | — |
| `WorkerRequestReceivedEvent` | `Rapira\Http\Request` |
| `WorkerResponseSentEvent` | Symfony `Request` + `Response` |
| `WorkerRequestFailedEvent` | `Rapira\Http\Request` + throwable |

## Developing with Symfony and Rapira

Same rules as RoadRunner — see [Developing with Symfony and RoadRunner](https://github.com/FluffyDiscord/roadrunner-symfony-bundle#developing-with-symfony-and-roadrunner): stateless services, static form defaults, lean `User` session serialization.

## DDEV add-on

```shell
ddev add-on get FluffyDiscord/ddev-rapira
```

See the [add-on repository](https://github.com/FluffyDiscord/ddev-rapira) for configuration and usage.

## Testing

```shell
tests/docker-qa.sh                # PHPStan (level max) + PHPUnit
tests/docker/run-integration.sh   # integration suite against the real Rapira binary
```
