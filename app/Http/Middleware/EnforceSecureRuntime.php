<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses to serve traffic on a PHP runtime that is still exposed to the
 * critical "PHP2Shell" remote code execution issues (CVE-2026-109618 and
 * CVE-2026-109619, announced 21 October 2026).
 *
 * The application is only safe on PHP 8.5.12 or newer. A deployment that is
 * still on an older runtime is not merely out of date: it lets an
 * unauthenticated attacker execute code as the web worker. Failing closed is
 * the only honest response, so the request is refused with a 503 and an
 * operator-facing explanation rather than being served.
 *
 * Outside production the request is allowed through and the condition is
 * logged, so a developer working on an unpatched local runtime is not locked
 * out of their own machine.
 */
class EnforceSecureRuntime
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $runtime = (string) config('security.runtime_php_version');
        $minimum = (string) config('security.minimum_php_version');

        if (version_compare($runtime, $minimum, '>=')) {
            return $next($request);
        }

        if (! app()->isProduction()) {
            Log::warning('The PHP runtime is older than the supported minimum. Requests are served, but this runtime is exposed to CVE-2026-109618 and CVE-2026-109619.', [
                'runtime_php_version' => $runtime,
                'minimum_php_version' => $minimum,
            ]);

            return $next($request);
        }

        Log::critical('Refusing to serve a request because the PHP runtime is exposed to CVE-2026-109618 and CVE-2026-109619.', [
            'runtime_php_version' => $runtime,
            'minimum_php_version' => $minimum,
        ]);

        return response($this->message($runtime, $minimum), Response::HTTP_SERVICE_UNAVAILABLE, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Retry-After' => '3600',
        ]);
    }

    /**
     * Build the operator-facing explanation for a refused request.
     */
    private function message(string $runtime, string $minimum): string
    {
        return implode("\n", [
            'OpenSinop is not serving this request because its PHP runtime is unsafe.',
            '',
            "Running PHP: {$runtime}",
            "Minimum PHP: {$minimum}",
            '',
            'PHP 8.5.12 and 8.5.11 and earlier are affected by two critical remote code',
            'execution issues (CVE-2026-109618, CVE-2026-109619) that need no',
            'authentication. Update the application image to a release built on PHP',
            '8.5.12 or newer.',
            '',
            'Operators: rebuild or repull the image (docker compose pull && docker compose up -d),',
            'or reinstall with install.sh --update. The published image tag already carries',
            'the patched runtime.',
            '',
            'See the security advisory in the documentation for the full details.',
        ]);
    }
}
