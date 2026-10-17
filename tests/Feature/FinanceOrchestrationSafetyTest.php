<?php

declare(strict_types=1);

use App\Models\InstitutionPolicyVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\StudentControlledPayment;
use App\Models\User;
use App\Services\Advisory\JevPolicyClassifier;
use App\Services\StudentPaymentProgress;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Classification;
use Spatie\Permission\Models\Role;

test('JEV refuses disabled disclosure without contacting any provider or inventing confidence', function (): void {
    Http::preventStrayRequests();
    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = false;
    expect(fn () => app(JevPolicyClassifier::class)->classify(new InstitutionPolicyVersion, ['description' => 'tuition receipts'], 30))
        ->toThrow(RuntimeException::class, 'Approved AI disclosure');
    Http::assertNothingSent();
});

test('JEV provider failure remains a failure rather than a local model result', function (): void {
    $context = institutionPolicyContext();
    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = true;
    Classification::fake(fn (): never => throw new RuntimeException('provider unavailable'));
    expect(fn () => app(JevPolicyClassifier::class)->classify($context['policy'], ['description' => 'teaching materials'], 30))
        ->toThrow(RuntimeException::class, 'provider unavailable');
});

test('advisory toggle never accepts disclosure even when request includes forged consent', function (): void {
    /** @var Organization $org */
    $org = Organization::factory()->create();
    config(['eduflow.institution_id' => $org->id]);
    $admin = User::factory()->create(['organization_id' => $org->id])->assignRole(Role::findOrCreate('super_admin', 'web'));
    $settings = app(AiSettings::class);
    $settings->advisory_enabled = false;
    $settings->disclosure_accepted = false;
    $settings->save();
    $this->actingAs($admin)->postJson(route('finance.ai.toggle-advisory'), ['enable' => true, 'disclosure_accepted' => true])->assertSuccessful();
    expect(app(AiSettings::class)->disclosure_accepted)->toBeFalse();
});

test('progress reads pending payment without creating approvals providers or transactions', function (): void {
    $payment = new StudentControlledPayment;
    $payment->forceFill(['id' => (string) Str::uuid(), 'posted_at' => null, 'tx_hash' => null]);
    $before = PaymentIntent::query()->count();
    $steps = app(StudentPaymentProgress::class)->forPayment($payment);
    expect(array_column($steps, 'stage'))->toBe(['wallet_approval', 'settlement'])
        ->and(array_column($steps, 'status'))->toBe(['waiting', 'waiting'])
        ->and(PaymentIntent::query()->count())->toBe($before);
});
