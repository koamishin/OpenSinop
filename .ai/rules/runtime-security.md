---
paths:
    - 'Dockerfile'
    - 'docker/Dockerfile'
    - 'docker/php.ini'
    - 'config/security.php'
    - 'app/Http/Middleware/EnforceSecureRuntime.php'
    - '.github/workflows/ci.yml'
---

# Runtime Security

## The production runtime must stay on PHP 8.5.12 or newer
PHP 8.5.12 (21 October 2026) fixed CVE-2026-109618 and CVE-2026-109619, two
unauthenticated remote code execution issues (CVSS 9.2). PHP 8.5.x before
8.5.12 is vulnerable, and the shipped image used to be built on
`dunglas/frankenphp:1.9-php8.5`, which carried PHP 8.5.0.

- `Dockerfile` and `docker/Dockerfile` pin `PHP_VERSION=8.5.12` and
  `FRANKENPHP_VERSION=1.13`. Do not float these back to a minor tag: the
  `1.9-php8.5` tag was frozen on an unpatched PHP.
- CI pins `php-version: '8.5.12'` for the same reason. `setup-php` with a bare
  `8.5` will float, which is how this drifted in the first place.
- `config/security.php` holds the minimum version and the running version.
  Neither may read from the environment: an operator who can edit `.env` must
  not be able to talk the application out of its own baseline.
- `EnforceSecureRuntime` refuses production requests on an older runtime with
  a 503. Do not weaken it to a log line.

## memory_limit = -1 is deliberate, and it needs a container memory limit
`docker/php.ini` sets `memory_limit = -1` because a finite per-request limit is
the predictable out-of-memory trigger that CVE-2026-109618 abuses. The
availability boundary moved to the container: `mem_limit` in
`docker-compose.yml` and `--limit-memory` in `install.sh`, both defaulting to
`APP_MEMORY_LIMIT=2g`. If you change one, change the other. A bare
`docker run` with no limit has no boundary at all.

## `session.upload_progress.enabled = 0` is not optional
Session upload progress keeps per-request state alive in the Octane worker
between requests, which is the producer side of the CVE-2026-109619 stale
global state use-after-free. The application does not use upload progress.

## The test suite must not depend on the developer's PHP version
`tests/TestCase.php` pins `security.runtime_php_version` to the supported
minimum so the suite behaves the same on every machine. A test that needs the
vulnerable path overrides the config itself; see
`tests/Feature/SecureRuntimeTest.php`.

## composer update must stay surgical
Updating Filament with `--with-all-dependencies` also pulls `laravel/framework`
past 13.38, which adds `QUERY` to `Router::$verbs`. Wayfinder then generates a
`"query"` method that Inertia's `Method` type rejects and `npm run types:check`
fails. Pin the framework and Livewire explicitly when updating Filament:

```bash
composer update filament/filament \
    --with "filament/filament:5.8.4" \
    --with "laravel/framework:13.34.0" \
    --with "livewire/livewire:4.3.5" \
    -W --no-scripts
```

Remember `--no-scripts`: `post-update-cmd` runs `php artisan install:features`,
which consumes the chisel feature scaffolding.

## vite-plus 1.3.0 moved the chisel markers
The formatter in vite-plus 1.3.0 relocates `/* @end-chisel-* */` comments. In
`resources/js/pages/settings/security.tsx` it moved the marker from before the
semicolon to after it, which changes the bytes Chisel strips when the feature
is toggled off. Chisel pairs markers by tag and removes everything between
them, so a moved end marker changes the removed region.

After any formatter upgrade, run `npm run check:fix` and then confirm the
marker pairs are still balanced before committing:

```bash
npm run check:fix
```

Chisel strips by byte offset between an opening and closing marker with the
same tag, so the counts must stay equal per tag (`2fa`, `email-verification`,
`passkeys`, `password-confirmation`, `registration`). Never run
`php artisan install:features` to check: it consumes the markers and deletes
the chisel tooling.
