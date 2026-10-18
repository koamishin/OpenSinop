<?php

declare(strict_types=1);

use App\Actions\ApproveEscalatedRequest;
use App\Ai\Advisory\AdvisoryGate;
use App\Ai\Agents\AssistanceAssessor;
use App\Jobs\AssessAssistanceDecision;
use App\Models\AgentDecision;
use App\Models\AssistanceFund;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentAssistanceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Policies\AssistanceRequestPolicy;
use App\Services\PaymentService;
use App\Settings\AiSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;

test('retired simulated assistance service cannot fabricate settlement', function (): void {
    expect(fn () => app(PaymentService::class)->disburse(new StudentAssistanceRequest, new User, new Organization, 80.0))
        ->toThrow(LogicException::class, 'Legacy simulated assistance payouts are disabled');
    expect(Transaction::query()->count())->toBe(0);
});

test('foreign institution staff cannot view or approve assistance', function (): void {
    /** @var Organization $first */
    $first = Organization::factory()->create();
    /** @var Organization $second */
    $second = Organization::withoutSingleInstitutionGuard(fn () => Organization::factory()->create());
    $student = Student::factory()->create(['organization_id' => $second->id]);
    $request = AssistanceRequest::factory()->create(['student_id' => $student->id, 'user_id' => $student->user_id]);
    $officer = User::factory()->create(['organization_id' => $first->id])->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $this->actingAs($officer);
    expect(app(AssistanceRequestPolicy::class)->view($officer, $request))->toBeFalse();
    $fund = new AssistanceFund(['organization_id' => $second->id]);
    expect(fn () => app(ApproveEscalatedRequest::class)->handle($request, new AgentDecision, $officer, $fund))
        ->toThrow(AuthorizationException::class);
    expect(Transaction::query()->count())->toBe(0);
});

test('assistance commentary job preserves authoritative decision and calls provider once', function (): void {
    /** @var Organization $org */
    $org = Organization::factory()->create();
    $student = Student::factory()->create(['organization_id' => $org->id]);
    $request = AssistanceRequest::factory()->create(['student_id' => $student->id, 'user_id' => $student->user_id]);
    $decision = AgentDecision::query()->create(['action_type' => 'student_assistance', 'decision' => 'escalate', 'reasoning_summary' => 'Finance review required.', 'policy_checked' => 'TEST', 'requested_amount' => 10, 'approved_amount' => 0, 'requires_approval' => true, 'organization_id' => $org->id, 'reference_type' => AssistanceRequest::class,
        'reference_id' => $request->id, 'input_snapshot' => ['approved_base_units' => 0], 'status' => 'escalated',
        'metadata' => ['assistance_advice' => ['state' => 'queued']]]);
    $settings = app(AiSettings::class);
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    config(['ai.default' => 'local', 'ai.providers.local.url' => 'http://localhost:11434/v1', 'ai.providers.local.models.text.default' => 'test']);
    AssistanceAssessor::fake([['hardship_category' => 'medical', 'urgency' => 'high', 'confidence' => 0.9, 'narrative' => 'Review medical context.', 'anomaly_flags' => []]]);
    $gate = app(AdvisoryGate::class);
    $before = $decision->only(['status', 'approved_amount', 'requested_amount', 'policy_checked', 'input_snapshot']);
    $job = new AssessAssistanceDecision($decision->id);
    $job->handle($gate, $settings);
    $job->handle($gate, $settings);
    expect($decision->fresh()->only(array_keys($before)))->toBe($before)
        ->and($decision->fresh()->metadata['assistance_advice']['state'])->toBe('completed')
        ->and($decision->fresh()->metadata['advisory']['advisory_only'])->toBeTrue()
        ->and(Transaction::query()->count())->toBe(0);
});

test('assistance advice recovery refuses sync queues and disabled disclosure sends nothing', function (): void {
    Organization::factory()->create();
    $settings = app(AiSettings::class);
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = false;
    $settings->save();
    Bus::fake();
    $this->artisan('app:dispatch-assistance-advice')->expectsOutputToContain('disabled')->assertSuccessful();
    Bus::assertNothingDispatched();
    $settings->disclosure_accepted = true;
    $settings->save();
    config(['queue.default' => 'sync']);
    $this->artisan('app:dispatch-assistance-advice')->expectsOutputToContain('durable queue')->assertFailed();
    Bus::assertNothingDispatched();
});

test('commentary rejects request edits while provider is responding', function (): void {
    /** @var Organization $org */
    $org = Organization::factory()->create();
    $student = Student::factory()->create(['organization_id' => $org->id]);
    $request = AssistanceRequest::factory()->create(['student_id' => $student->id, 'user_id' => $student->user_id]);
    $decision = AgentDecision::query()->create(['organization_id' => $org->id, 'action_type' => 'student_assistance',
        'reference_type' => AssistanceRequest::class, 'reference_id' => $request->id, 'decision' => 'escalate',
        'reasoning_summary' => 'Review required.', 'policy_checked' => 'TEST', 'requested_amount' => 10,
        'approved_amount' => 0, 'requires_approval' => true, 'status' => 'escalated', 'input_snapshot' => [],
        'metadata' => ['assistance_advice' => ['state' => 'queued']]]);
    $settings = app(AiSettings::class);
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    config(['ai.default' => 'local', 'ai.providers.local.url' => 'http://localhost:11434/v1', 'ai.providers.local.models.text.default' => 'test']);
    AssistanceAssessor::fake(function () use ($request): array {
        $request->update(['reason' => 'Changed evidence while provider was responding.']);

        return ['hardship_category' => 'medical', 'urgency' => 'high', 'confidence' => 0.9,
            'narrative' => 'Stale medical context.', 'anomaly_flags' => []];
    });
    (new AssessAssistanceDecision($decision->id))->handle(app(AdvisoryGate::class), $settings);
    expect($decision->fresh()->metadata['assistance_advice']['state'])->toBe('needs_review')
        ->and($decision->fresh()->metadata)->not->toHaveKey('advisory')
        ->and($decision->fresh()->status)->toBe('escalated')
        ->and(Transaction::query()->count())->toBe(0);
});

test('recovery parks interrupted commentary without redispatching provider or changing verdict', function (): void {
    /** @var Organization $org */
    $org = Organization::factory()->create();
    $decision = AgentDecision::query()->create(['organization_id' => $org->id, 'action_type' => 'student_assistance',
        'reference_type' => AssistanceRequest::class, 'reference_id' => 1, 'decision' => 'escalate',
        'reasoning_summary' => 'Review required.', 'policy_checked' => 'TEST', 'requested_amount' => 10,
        'approved_amount' => 0, 'requires_approval' => true, 'status' => 'escalated', 'input_snapshot' => [],
        'metadata' => ['assistance_advice' => ['state' => 'running', 'updated_at' => now()->subMinutes(6)->toIso8601String()]]]);
    $settings = app(AiSettings::class);
    $settings->advisory_enabled = false;
    $settings->disclosure_accepted = false;
    $settings->save();
    Bus::fake();
    $this->artisan('app:dispatch-assistance-advice')->assertSuccessful();
    expect($decision->fresh()->metadata['assistance_advice']['state'])->toBe('needs_review')
        ->and($decision->fresh()->status)->toBe('escalated');
    Bus::assertNothingDispatched();
});
