<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\StudentWallet;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\StudentControlledWallets;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

test('financial assistance page renders policy and history for students', function (): void {
    $user = User::factory()->create();
    $payoutAddress = '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    Student::factory()->create([
        'user_id' => $user->id,
        'payout_address' => $payoutAddress,
    ]);

    $response = $this->actingAs($user)->get(route('financial-assistance.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('financial-assistance')
        ->has('requests')
        ->has('policy')
        ->where('policy.auto_limit', 100)
        ->where('policy.currency', 'USDC')
        ->where('wallet.address', $payoutAddress)
    );
});

test('request within policy limit awaits authorization without simulated payout', function (): void {
    $organization = Organization::factory()->create([
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);
    Wallet::factory()->create([
        'organization_id' => $organization->id,
        'balance' => 25420.00,
    ]);
    Budget::factory()->create([
        'organization_id' => $organization->id,
        'category' => 'scholarships',
        'allocated_amount' => 2000.00,
        'remaining_amount' => 2000.00,
    ]);

    $user = User::factory()->create();
    $payoutAddress = '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    Student::factory()->create([
        'user_id' => $user->id,
        'payout_address' => $payoutAddress,
    ]);

    $response = $this->actingAs($user)->post(route('financial-assistance.store'), [
        'amount' => 80,
        'reason' => 'Emergency medicine costs after a clinic visit.',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $request = DB::table('student_assistance_requests')
        ->where('user_id', $user->id)
        ->first();

    expect($request)->not->toBeNull();
    expect((float) $request->approved_amount)->toBe(80.0);
    expect($request->status)->toBe('escalated');

    $transaction = Transaction::query()
        ->where('reference_type', 'student_assistance_request')
        ->where('reference_id', $request->id)
        ->first();

    expect($transaction)->toBeNull();

    $decision = DB::table('agent_decisions')
        ->where('reference_type', 'student_assistance_request')
        ->where('reference_id', $request->id)
        ->first();

    expect($decision)->not->toBeNull();
    expect($decision->decision)->toBe('auto_approve');
    expect($decision->policy_checked)->toBe('EMERGENCY_ASSISTANCE_V1');
});

test('request above the automatic limit is partially approved and escalated', function (): void {
    $organization = Organization::factory()->create([
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);
    Wallet::factory()->create([
        'organization_id' => $organization->id,
        'balance' => 25420.00,
    ]);
    Budget::factory()->create([
        'organization_id' => $organization->id,
        'category' => 'scholarships',
        'allocated_amount' => 2000.00,
        'remaining_amount' => 2000.00,
    ]);

    $user = User::factory()->create();
    $payoutAddress = '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    Student::factory()->create([
        'user_id' => $user->id,
        'payout_address' => $payoutAddress,
    ]);

    $response = $this->actingAs($user)->post(route('financial-assistance.store'), [
        'amount' => 150,
        'reason' => 'Replacement laptop charger and one week of meals.',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $request = DB::table('student_assistance_requests')
        ->where('user_id', $user->id)
        ->first();

    expect($request)->not->toBeNull();
    expect((float) $request->approved_amount)->toBe(100.0);
    expect($request->status)->toBe('escalated');

    $decision = DB::table('agent_decisions')
        ->where('reference_type', 'student_assistance_request')
        ->where('reference_id', $request->id)
        ->first();

    expect($decision)->not->toBeNull();
    expect($decision->decision)->toBe('partial_approval');
    expect((int) $decision->requires_approval)->toBe(1);

    // Only the auto-approved portion is disbursed.
    $paid = Transaction::query()
        ->where('reference_type', 'student_assistance_request')
        ->where('reference_id', $request->id)
        ->sum('amount');

    expect((float) $paid)->toBe(0.0);
});

test('submission without a linked wallet is rejected', function (): void {
    Organization::factory()->create([
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);

    $user = User::factory()->create();
    Student::factory()->withoutPayoutAddress()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post(route('financial-assistance.store'), [
        'amount' => 50,
        'reason' => 'Emergency medicine costs after a clinic visit.',
    ]);

    $response->assertSessionHasErrors(['wallet_address']);

    expect(DB::table('student_assistance_requests')->count())->toBe(0);
});

test('payments page lists confirmed payouts for the linked wallet', function (): void {
    $organization = Organization::factory()->create([
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);
    $walletAddress = '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    $user = User::factory()->create();
    $student = Student::factory()->create([
        'user_id' => $user->id,
        'payout_address' => $walletAddress,
    ]);

    Transaction::factory()->create([
        'organization_id' => $organization->id,
        'type' => 'student_assistance',
        'recipient_address' => $walletAddress,
        'amount' => 80.00,
        'status' => 'confirmed',
    ]);

    Transaction::factory()->create([
        'organization_id' => $organization->id,
        'type' => 'vendor_payment',
        'recipient_address' => '0x9999999999999999999999999999999999999999',
        'amount' => 500.00,
        'status' => 'confirmed',
    ]);

    $response = $this->actingAs($student->user)->get(route('payments.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('payments')
        ->has('transactions.data', 1)
        ->where('transactions.data.0.amount', 80)
        ->where('totals.confirmed', 80)
        ->where('wallet.address', $walletAddress)
    );
});

test('payments page matches the wallet address regardless of letter case', function (): void {
    $organization = Organization::factory()->create();
    $storedAddress = '0xABCDEF1234567890ABCDEF1234567890ABCDEF12';
    $usedAddress = '0xabcdef1234567890abcdef1234567890abcdef12';

    $user = User::factory()->create();
    $student = Student::factory()->create([
        'user_id' => $user->id,
        'payout_address' => $usedAddress,
    ]);

    Transaction::factory()->create([
        'organization_id' => $organization->id,
        'type' => 'student_assistance',
        'recipient_address' => $storedAddress,
        'amount' => 120.00,
        'status' => 'confirmed',
    ]);

    $this->actingAs($student->user)
        ->get(route('payments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('transactions.data', 1)
            ->where('totals.confirmed', 120)
        );
});

test('payments page lists official e-receipts for the students verified tuition payments', function (): void {
    $user = User::factory()->create();
    $student = Student::factory()->create([
        'user_id' => $user->id,
        'payout_address' => '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
    ]);
    $account = TuitionAccount::factory()->for($student)->create([
        'total_amount' => 250_000000,
        'paid_amount' => 10_000000,
    ]);

    $payment = StudentTuitionPayment::factory()->create([
        'student_id' => $student->id,
        'tuition_account_id' => $account->id,
        'amount_base_units' => 10_000000,
        'applied_base_units' => 10_000000,
        'overpayment_base_units' => 0,
    ]);

    StudentTuitionPayment::factory()->create(['amount_base_units' => 7_000000]);

    $this->actingAs($user)
        ->get(route('payments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('payments')
            ->has('receipts', 1)
            ->where('receipts.0.payment_type', 'tuition')
            ->where('receipts.0.amount_base_units', '10000000')
            ->where('receipts.0.remaining_balance_base_units', '240000000')
            ->where('receipts.0.tx_hash', $payment->tx_hash)
            ->where('receipts.0.student_number', $student->student_number)
            ->where('totals.tuition_paid', 10)
        );
});

test('payment history follows every wallet a student has ever linked', function (): void {
    $address = '0xa1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    $next = '0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E';
    $role = Role::query()->firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    /** @var Organization $organization */
    $organization = Organization::factory()->create();
    /** @var Wallet $treasury */
    $treasury = Wallet::factory()->create(['organization_id' => $organization->id]);
    /** @var Student $student */
    $student = Student::factory()->create(['payout_address' => $address, 'organization_id' => $organization->id]);
    StudentWallet::factory()->create(['student_id' => $student->id, 'address' => $address]);
    app(StudentControlledWallets::class)->link($student->fresh(), $next, 'external');
    Transaction::factory()->create([
        'organization_id' => $organization->id,
        'wallet_id' => $treasury->id,
        'type' => 'student_assistance',
        'recipient_address' => $address,
        'amount' => 30.00,
        'status' => 'confirmed',
    ]);

    $student->user->assignRole($role);
    $this->actingAs($student->user)
        ->get(route('payments.index'))
        ->assertInertia(fn ($page) => $page->where('wallet.address', strtolower($next))
            ->where('transactions.total', 1)
            ->where('totals.confirmed', 30)
            ->etc());
});
