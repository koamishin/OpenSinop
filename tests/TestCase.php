<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // The runtime guard refuses to serve production requests on a PHP that
        // is exposed to CVE-2026-109618 and CVE-2026-109619. The suite must
        // not depend on which PHP the developer happens to run locally, so the
        // runtime is pinned to the supported minimum here. A test that needs
        // the vulnerable path overrides this value itself.
        $this->app['config']->set('security.runtime_php_version', $this->app['config']->get('security.minimum_php_version'));
    }
}
