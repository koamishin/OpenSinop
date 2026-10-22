<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Runtime Security Baseline
|--------------------------------------------------------------------------
|
| PHP 8.5.12 is the first release that fixes the two critical "PHP2Shell"
| remote code execution issues announced on 21 October 2026:
|
|   CVE-2026-109618 / GHSA-w8hw-w9fx-j79w - RCE via OOM corruption
|   CVE-2026-109619 / GHSA-6wmf-4rv5-p3rp - RCE via stale global state
|
| Both are unauthenticated, CVSS 9.2, and affect every PHP installation that
| serves web requests. PHP 8.5.x before 8.5.12 is vulnerable.
|
| Neither value may be read from the environment. An operator who can edit
| .env must not be able to talk the application out of its own baseline, and
| an environment override would also be baked into the cached config.
|
*/

return [
    'minimum_php_version' => '8.5.12',

    'runtime_php_version' => PHP_VERSION,
];
