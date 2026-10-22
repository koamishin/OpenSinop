<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

test('a patched runtime serves the request normally', function (): void {
    Route::get('/runtime-check', fn (): string => 'served');
    config(['security.runtime_php_version' => '8.5.12']);
    app()->instance('env', 'production');

    $this->get('https://localhost/runtime-check')->assertOk()->assertSee('served');
});

test('a runtime newer than the minimum serves the request normally', function (): void {
    Route::get('/runtime-check', fn (): string => 'served');
    config(['security.runtime_php_version' => '8.5.13']);
    app()->instance('env', 'production');

    $this->get('https://localhost/runtime-check')->assertOk()->assertSee('served');
});

test('production refuses to serve a request on a runtime exposed to the php2shell issues', function (): void {
    Route::get('/runtime-check', fn (): string => 'served');
    config(['security.runtime_php_version' => '8.5.11']);
    app()->instance('env', 'production');

    $this->get('/runtime-check')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '3600')
        ->assertSee('CVE-2026-109618')
        ->assertSee('CVE-2026-109619')
        ->assertDontSee('served');
});

test('the refusal names the running and the minimum version', function (): void {
    Route::get('/runtime-check', fn (): string => 'served');
    config(['security.runtime_php_version' => '8.5.0']);
    app()->instance('env', 'production');

    $this->get('/runtime-check')
        ->assertStatus(503)
        ->assertSee('Running PHP: 8.5.0')
        ->assertSee('Minimum PHP: '.config('security.minimum_php_version'));
});

test('a vulnerable runtime is logged as critical in production', function (): void {
    Route::get('/runtime-check', fn (): string => 'served');
    config(['security.runtime_php_version' => '8.5.11']);
    app()->instance('env', 'production');
    Log::shouldReceive('critical')->once();

    $this->get('/runtime-check')->assertStatus(503);
});

test('outside production the request is served and the risk is logged', function (): void {
    Route::get('/runtime-check', fn (): string => 'served');
    config(['security.runtime_php_version' => '8.5.11']);
    app()->instance('env', 'local');

    $this->get('/runtime-check')->assertOk()->assertSee('served');
});

test('the minimum supported version is the patched php2shell release', function (): void {
    expect(config('security.minimum_php_version'))->toBe('8.5.12');
});
