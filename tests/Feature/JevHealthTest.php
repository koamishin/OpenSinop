<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\InstitutionPolicyWorkspace;
use App\Services\JevHealthService;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Helpers/InstitutionPolicyFixtures.php';

beforeEach(function (): void {
    $this->org = Organization::factory()->create();
    config([
        'queue.default' => 'database',
        'eduflow.institution_id' => $this->org->id,
    ]);

    $this->admin = User::factory()->create()->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->financeOfficer = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $this->student = User::factory()->create()->assignRole(Role::findOrCreate('student', 'web'));

    $this->policy = app(InstitutionPolicyWorkspace::class)->save($this->financeOfficer, institutionPolicyInput());
    $this->policy->update(['organization_id' => $this->org->id]);
});

test('jev health service inspects policy engine and ai configuration correctly', function (): void {
    $service = app(JevHealthService::class);
    $health = $service->inspect($this->org, false);

    expect($health)->toHaveKeys(['jev', 'ai', 'timestamp'])
        ->and($health['jev'])->toHaveKeys(['status', 'policy_title', 'categories_count', 'clauses_count', 'has_active_controls'])
        ->and($health['ai'])->toHaveKeys(['status', 'provider', 'model', 'endpoint', 'may_call_provider']);
});

test('jev health ping tests endpoint connectivity and latency', function (): void {
    Http::fake([
        '*/chat/completions' => Http::response([
            'id' => 'test-123',
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'Pong. Ready.']],
            ],
        ], 200),
    ]);

    $service = app(JevHealthService::class);
    $settings = app(AiSettings::class);
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    $settings->save();

    $health = $service->inspect($this->org, true);

    expect($health['ai']['status'])->toBe('online')
        ->and($health['ai']['latency_ms'])->toBeGreaterThanOrEqual(0)
        ->and($health['ai']['message'])->toContain('AI Agent responded successfully');
});

test('finance ai health route is accessible by authorized staff and blocked for unauthorized users', function (): void {
    // 1. Guest blocked
    $this->getJson(route('finance.ai.health'))->assertUnauthorized();

    // 2. Student blocked
    $this->actingAs($this->student)
        ->getJson(route('finance.ai.health'))
        ->assertForbidden();

    // 3. Finance officer allowed
    Http::fake([
        '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200),
    ]);

    $this->actingAs($this->financeOfficer)
        ->getJson(route('finance.ai.health'))
        ->assertOk()
        ->assertJsonStructure([
            'status',
            'data' => [
                'jev' => ['status', 'policy_title', 'categories_count'],
                'ai' => ['status', 'provider', 'model'],
                'timestamp',
            ],
        ]);
});

test('admin can toggle ai advisory via toggle-advisory endpoint', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('finance.ai.toggle-advisory'), ['enable' => true])
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $settings = app(AiSettings::class);
    expect($settings->advisory_enabled)->toBeTrue()
        ->and($settings->disclosure_accepted)->toBeFalse();
});

test('finance jev analytics page renders with health telemetry prop', function (): void {
    $this->actingAs($this->financeOfficer)
        ->get(route('finance.jev-analytics'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('finance/analytics')
            ->has('health')
            ->has('breakdown')
            ->has('approvals')
            ->has('trends')
        );
});

test('finance policies page renders with health telemetry prop', function (): void {
    $this->actingAs($this->admin)
        ->get(route('finance.policies.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('finance/policies/index')
            ->has('health')
            ->has('versions')
            ->has('templates')
        );
});
