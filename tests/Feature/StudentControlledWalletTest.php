<?php

declare(strict_types=1);

use App\Actions\ReviewCollectionBatch;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Filament\Finance\Widgets\CollectionsOverviewWidget;
use App\Filament\Finance\Widgets\CollectionsTrendChart;
use App\Filament\Pages\Collections;
use App\Filament\Resources\Transactions\Tables\TransactionsTable;
use App\Filament\Resources\TuitionAccounts\Pages\ViewTuitionAccount;
use App\Filament\Resources\TuitionAccounts\RelationManagers\PaymentsRelationManager;
use App\Filament\Widgets\AdoptionActivityChart;
use App\Filament\Widgets\TreasuryOverviewWidget;
use App\Jobs\EvaluateInstitutionPolicy;
use App\Jobs\ReconcileStudentControlledPayment;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\Organization;
use App\Models\PaymentAuthorization;
use App\Models\Student;
use App\Models\StudentBill;
use App\Models\StudentBillPayment;
use App\Models\StudentControlledPayment;
use App\Models\StudentTuitionPayment;
use App\Models\StudentWallet;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\StudentControlledPaymentSettled;
use App\Services\ArcSettlementVerifier;
use App\Services\CircleStudentWallets;
use App\Services\InstallationInstitution;
use App\Services\StudentControlledCheckout;
use App\Services\StudentControlledWallets;
use App\Services\StudentPaymentProgress;
use App\Services\StudentReceiptDispatcher;
use Elliptic\EC;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;
use Illuminate\Validation\ValidationException;
use kornrunner\Keccak;
use Livewire\Livewire;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\JsonTransport;
use Yukazakiri\Lepton\DTOs\HttpResponse;

beforeEach(function (): void {
    config(['student-wallets.enabled' => true, 'lepton.arc.chain' => 'ARC-TESTNET', 'lepton.arc.chain_id' => 5042002,
        'lepton.arc.treasury' => '0x'.str_repeat('b', 40)]);
    /** @var Organization $institution */
    $institution = Organization::factory()->create();
    config(['eduflow.institution_id' => $institution->id]);
    Wallet::factory()->create(['organization_id' => $institution->id, 'address' => config('lepton.arc.treasury'), 'status' => 'active']);
    $owner = User::factory()->create(['organization_id' => $institution->id])->assignRole(Role::findOrCreate('finance_officer', 'web'));
    config(['eduflow.student_tuition.intake_owner_id' => $owner->id]);
    Role::findOrCreate('student', 'web');
    $this->student = Student::factory()->withoutPayoutAddress()->create();
    $this->actingAs($this->student->user->assignRole('student'));
    $this->address = '0x'.str_repeat('a', 40);
    $this->transaction = null;
    $this->receipt = null;
    /** @var ArcNetworkGateway&MockInterface $arc */
    $arc = Mockery::mock(ArcNetworkGateway::class);
    mockExpectation($arc, 'rpc')->andReturnUsing(fn (string $method, array $params = []): mixed => match ($method) {
        'eth_chainId' => '0x4cef52',
        'eth_getTransactionByHash' => $this->transaction,
        'eth_getTransactionReceipt' => $this->receipt,
        'eth_getBlockByNumber' => ['hash' => '0x'.str_repeat('d', 64), 'timestamp' => '0x1'],
        default => null,
    });
    mockExpectation($arc, 'chainCode')->andReturn('ARC-TESTNET');
    mockExpectation($arc, 'chainId')->andReturn(5042002);
    mockExpectation($arc, 'rpcUrl')->andReturn('https://rpc.testnet.arc.io');
    mockExpectation($arc, 'addressExplorerUrl')->andReturn('https://testnet.arcscan.app/address/example');
    mockExpectation($arc, 'explorerUrl')->andReturn('https://testnet.arcscan.app/tx/example');
    $this->app->instance(ArcNetworkGateway::class, $arc);
});

function controlledWallet(Student $student, string $address): void
{
    app(StudentControlledWallets::class)->link($student, $address, 'external');
    $student->refresh();
}

function controlledTransfer(StudentControlledPayment $payment): array
{
    $s = $payment->snapshot;

    return ['to' => StudentControlledWallets::TOKEN, 'from' => $s['sender'], 'input' => $s['data'], 'value' => '0x0', 'blockNumber' => '0x1'];
}

function controlledReceipt(StudentControlledPayment $payment): array
{
    $s = $payment->snapshot;

    return ['status' => '0x1', 'blockNumber' => '0x1', 'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1', 'logs' => [[
        'address' => StudentControlledWallets::TOKEN,
        'topics' => ['0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
            '0x'.str_pad(substr($s['sender'], 2), 64, '0', STR_PAD_LEFT),
            '0x'.str_pad(substr($s['recipient'], 2), 64, '0', STR_PAD_LEFT)],
        'data' => '0x'.str_pad(dechex($s['amount_base_units']), 64, '0', STR_PAD_LEFT),
    ]]];
}

test('browser signatures are cryptographically verified and cannot be replayed', function (): void {
    $key = (new EC('secp256k1'))->keyFromPrivate(str_repeat('1', 64));
    $public = hex2bin(substr($key->getPublic(false, 'hex'), 2));
    $address = '0x'.substr(Keccak::hash($public, 256), -40);
    $response = $this->postJson(route('student.controlled-wallet.challenge'), ['address' => $address])->assertSuccessful();
    $message = $response->json('message');
    $digest = Keccak::hash("\x19Ethereum Signed Message:\n".strlen($message).$message, 256);
    $signed = $key->sign($digest, ['canonical' => true]);
    $signature = '0x'.str_pad($signed->r->toString(16), 64, '0', STR_PAD_LEFT)
        .str_pad($signed->s->toString(16), 64, '0', STR_PAD_LEFT).dechex($signed->recoveryParam + 27);
    $this->postJson(route('student.controlled-wallet.connect'), ['signature' => $signature])->assertSuccessful();
    $this->postJson(route('student.controlled-wallet.connect'), ['signature' => $signature])->assertUnprocessable();
    expect($this->student->fresh()->payout_address)->toBe($address);
});

test('invalid ownership and mainnet configuration are rejected', function (): void {
    $this->postJson(route('student.controlled-wallet.challenge'), ['address' => $this->address])->assertSuccessful();
    $this->postJson(route('student.controlled-wallet.connect'), ['signature' => '0x'.str_repeat('0', 130)])->assertUnprocessable();
    config(['lepton.arc.chain_id' => 5042]);
    $this->postJson(route('student.controlled-wallet.challenge'), ['address' => $this->address])->assertUnprocessable();
    expect(StudentWallet::query()->count())->toBe(0);
});

test('one verified testnet transfer credits tuition only once', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $service = app(StudentControlledCheckout::class);
    $payment = $service->create($this->student, 'tuition', $account->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $hash = '0x'.str_repeat('c', 64);
    $service->confirm($this->student, $payment, $hash);
    if ($payment->tuition_account_id !== null) {
        $service->recordSettlement($payment->fresh());
    }
    $service->confirm($this->student, $payment->fresh(), $hash);
    expect($account->fresh()->paid_amount)->toBe(2000000)
        ->and($payment->fresh()->posted_at)->not->toBeNull();
});

test('bill payment updates status only after successful exact chain movement', function (): void {
    controlledWallet($this->student, $this->address);
    $bill = StudentBill::factory()->create(['student_id' => $this->student->id, 'amount_base_units' => 2000000, 'paid_base_units' => 0]);
    $service = app(StudentControlledCheckout::class);
    $payment = $service->create($this->student, 'bill', $bill->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $this->receipt['status'] = '0x0';
    expect(fn () => $service->confirm($this->student, $payment, '0x'.str_repeat('c', 64)))->toThrow(ValidationException::class);
    expect($bill->fresh()->paid_base_units)->toBe(0);
    $this->receipt['status'] = '0x1';
    $service->confirm($this->student, $payment, '0x'.str_repeat('c', 64));
    expect($bill->fresh()->status)->toBe('paid');
});

test('wrong payment reference and another students account are rejected', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $service = app(StudentControlledCheckout::class);
    $payment = $service->create($this->student, 'tuition', $account->id, 2000000);
    $this->transaction = [...controlledTransfer($payment), 'input' => $payment->snapshot['data'].'00'];
    $this->receipt = controlledReceipt($payment);
    expect(fn () => $service->confirm($this->student, $payment, '0x'.str_repeat('c', 64)))->toThrow(ValidationException::class);
    $other = TuitionAccount::factory()->create();
    $this->postJson(route('student.controlled-wallet.quote'), ['kind' => 'tuition', 'account_id' => $other->id, 'amount' => '1'])->assertNotFound();
    expect($account->fresh()->paid_amount)->toBe(0);
});

test('unverified registration wallets cannot pay', function (): void {
    StudentWallet::factory()->create(['student_id' => $this->student->id, 'address' => $this->address, 'provider' => 'external']);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id]);
    $this->postJson(route('student.controlled-wallet.quote'), ['kind' => 'tuition', 'account_id' => $account->id, 'amount' => '1'])->assertUnprocessable();
});

test('Circle sessions create only user-controlled Arc testnet wallets without exposing API keys', function (): void {
    config(['student-wallets.circle_api_key' => 'TEST_API_KEY:test:secret', 'student-wallets.circle_app_id' => 'test-app']);
    $http = Mockery::mock(JsonTransport::class);
    mockExpectation($http, 'request')->andReturnUsing(static function (string $method, string $url, array $headers, ?array $body): HttpResponse {
        expect($headers['Authorization'])->toBe('Bearer TEST_API_KEY:test:secret');
        $data = match (true) {
            str_ends_with($url, '/users') => ['id' => $body['userId']],
            str_contains($url, '/users/') && ! str_ends_with($url, '/users/token') => ['user' => ['id' => '22222222-2222-4222-8222-222222222222']],
            str_ends_with($url, '/users/token') => ['userToken' => 'short-lived-user-token', 'encryptionKey' => 'short-lived-key'],
            str_contains($url, '/wallets?') => ['wallets' => []],
            str_ends_with($url, '/user/initialize') => ['challengeId' => '11111111-1111-4111-8111-111111111111'],
            default => throw new RuntimeException('Unexpected Circle endpoint.'),
        };
        if (str_ends_with($url, '/user/initialize')) {
            expect($body['blockchains'])->toBe(['ARC-TESTNET'])->and($body['accountType'])->toBe('EOA');
        }

        return new HttpResponse(200, ['data' => $data]);
    });
    $this->app->instance(JsonTransport::class, $http);
    $response = $this->postJson(route('student.controlled-wallet.circle-session'))->assertSuccessful();
    expect(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain('TEST_API_KEY')->not->toContain('secret');
    $response->assertHeader('Cache-Control', 'no-store, private');
    expect($this->student->fresh()->getAttribute('circle_user_id'))->not->toBeNull();
});

test('Circle onboarding refuses mainnet API keys before making any HTTP call', function (): void {
    config(['student-wallets.circle_api_key' => 'LIVE_API_KEY:test:secret', 'student-wallets.circle_app_id' => 'test-app']);
    $http = Mockery::mock(JsonTransport::class);
    $http->shouldNotReceive('request');
    $this->app->instance(JsonTransport::class, $http);
    $this->postJson(route('student.controlled-wallet.circle-session'))->assertUnprocessable();
});

test('payment identity cannot be rewritten and another student cannot confirm it', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $payment = app(StudentControlledCheckout::class)->create($this->student, 'tuition', $account->id, 2000000);
    expect(fn () => $payment->forceFill(['snapshot_digest' => str_repeat('0', 64)])->save())->toThrow(LogicException::class);
    $other = Student::factory()->create();
    $this->actingAs($other->user->assignRole('student'));
    $this->postJson(route('student.controlled-wallet.confirm', $payment->id), ['tx_hash' => '0x'.str_repeat('c', 64)])->assertNotFound();
});

test('browser broadcast approval is write once and fences new payment attempts even after expiry', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $payment = app(StudentControlledCheckout::class)->create($this->student, 'tuition', $account->id, 2000000);
    $this->postJson(route('student.controlled-wallet.start', $payment->id), ['consent' => true])->assertSuccessful();
    $this->postJson(route('student.controlled-wallet.start', $payment->id), ['consent' => true])->assertUnprocessable();
    $this->travel(11)->minutes();
    $this->postJson(route('student.controlled-wallet.quote'), ['kind' => 'tuition', 'account_id' => $account->id, 'amount' => '2'])->assertUnprocessable();
    expect($account->fresh()->paid_amount)->toBe(0);
});

test('Circle challenge is tied to one user wallet and recovered idempotently', function (): void {
    config(['student-wallets.circle_api_key' => 'TEST_API_KEY:test:secret', 'student-wallets.circle_app_id' => 'test-app']);
    $walletId = '11111111-1111-4111-8111-111111111111';
    $userId = '22222222-2222-4222-8222-222222222222';
    app(StudentControlledWallets::class)->link($this->student, $this->address, 'circle-user', $userId, $walletId);
    $this->student->refresh();
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $payment = app(StudentControlledCheckout::class)->create($this->student, 'tuition', $account->id, 2000000);
    $http = Mockery::mock(JsonTransport::class);
    mockExpectation($http, 'request')->once()->andReturnUsing(function (string $method, string $url, array $headers, ?array $body) use ($payment, $walletId): HttpResponse {
        expect($url)->toBe('https://api.circle.com/v1/w3s/user/transactions/contractExecution')
            ->and($body['idempotencyKey'])->toBe($payment->id)
            ->and($body['walletId'])->toBe($walletId)
            ->and($body)->not->toHaveKey('blockchain')
            ->and($body['callData'])->toBe($payment->snapshot['data']);

        return new HttpResponse(201, ['data' => ['challengeId' => '33333333-3333-4333-8333-333333333333']]);
    });
    $this->app->instance(JsonTransport::class, $http);
    $this->withSession(['circle_student_auth' => ['student_id' => $this->student->id, 'user_id' => $userId,
        'token' => encrypt('user-token'), 'expires' => now()->addMinutes(30)->timestamp]]);
    $this->postJson(route('student.controlled-wallet.circle-pay', $payment->id), ['consent' => true])->assertSuccessful();
    $this->postJson(route('student.controlled-wallet.circle-pay', $payment->id), ['consent' => true])->assertSuccessful();
    expect($account->fresh()->paid_amount)->toBe(0);
});

test('Circle created wallet is linked only from authenticated provider response', function (): void {
    config(['student-wallets.circle_api_key' => 'TEST_API_KEY:test:secret', 'student-wallets.circle_app_id' => 'test-app']);
    $userId = '22222222-2222-4222-8222-222222222222';
    $walletId = '11111111-1111-4111-8111-111111111111';
    $http = Mockery::mock(JsonTransport::class);
    mockExpectation($http, 'request')->once()->andReturn(new HttpResponse(200, ['data' => ['wallets' => [[
        'id' => $walletId, 'userId' => $userId, 'address' => $this->address, 'blockchain' => 'ARC-TESTNET',
        'custodyType' => 'ENDUSER', 'accountType' => 'EOA', 'state' => 'LIVE',
    ]]]]));
    $this->app->instance(JsonTransport::class, $http);
    $this->withSession(['circle_student_auth' => ['student_id' => $this->student->id, 'user_id' => $userId,
        'token' => encrypt('user-token'), 'expires' => now()->addMinutes(30)->timestamp]])
        ->postJson(route('student.controlled-wallet.circle-connect'), ['address' => '0x'.str_repeat('f', 40)])
        ->assertSuccessful()->assertJson(['address' => $this->address]);
    expect($this->student->fresh()->wallet->getAttribute('circle_wallet_id'))->toBe($walletId);
});

test('Circle COMPLETE never credits tuition without independent chain settlement', function (): void {
    config(['student-wallets.circle_api_key' => 'TEST_API_KEY:test:secret', 'student-wallets.circle_app_id' => 'test-app']);
    $walletId = '11111111-1111-4111-8111-111111111111';
    $userId = '22222222-2222-4222-8222-222222222222';
    app(StudentControlledWallets::class)->link($this->student, $this->address, 'circle-user', $userId, $walletId);
    $this->student->refresh();
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $payment = app(StudentControlledCheckout::class)->create($this->student, 'tuition', $account->id, 2000000);
    $payment->forceFill(['circle_challenge_id' => '33333333-3333-4333-8333-333333333333'])->save();
    $hash = '0x'.str_repeat('c', 64);
    $http = Mockery::mock(JsonTransport::class);
    mockExpectation($http, 'request')->andReturnUsing(static fn (string $method, string $url): HttpResponse => new HttpResponse(200, ['data' => str_contains($url, '/challenges/')
        ? ['challenge' => ['status' => 'COMPLETE', 'correlationIds' => ['44444444-4444-4444-8444-444444444444']]]
        : ['transaction' => ['state' => 'COMPLETE', 'walletId' => $walletId, 'blockchain' => 'ARC-TESTNET', 'txHash' => $hash]]]));
    $this->app->instance(JsonTransport::class, $http);
    $this->withSession(['circle_student_auth' => ['student_id' => $this->student->id, 'user_id' => $userId,
        'token' => encrypt('user-token'), 'expires' => now()->addMinutes(30)->timestamp]]);
    $this->postJson(route('student.controlled-wallet.circle-status', $payment->id))->assertUnprocessable();
    expect($account->fresh()->paid_amount)->toBe(0);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $this->postJson(route('student.controlled-wallet.circle-status', $payment->id))->assertSuccessful()->assertJson(['posted' => true]);
    expect($account->fresh()->paid_amount)->toBe(2000000);
});

test('Google and Facebook wallet login binds provider-verified identity and consumes callback nonce', function (string $provider): void {
    config(['student-wallets.circle_api_key' => 'TEST_API_KEY:test:secret', 'student-wallets.circle_app_id' => 'app',
        'student-wallets.google_client_id' => 'google-client', 'student-wallets.facebook_app_id' => 'facebook-app']);
    $id = '22222222-2222-4222-8222-222222222222';
    $http = Mockery::mock(JsonTransport::class);
    mockExpectation($http, 'request')->andReturnUsing(static function (string $method, string $url, array $headers, ?array $body) use ($id): HttpResponse {
        $data = match (true) {
            str_ends_with($url, '/users/social/token') => ['deviceToken' => 'device-token', 'deviceEncryptionKey' => 'device-key'],
            str_ends_with($url, '/user') => ['id' => $id, 'status' => 'ENABLED'],
            str_contains($url, '/wallets?') => ['wallets' => []],
            str_ends_with($url, '/user/initialize') => ['challengeId' => '33333333-3333-4333-8333-333333333333'],
            default => throw new RuntimeException('Unexpected Circle request.'),
        };

        return new HttpResponse(200, ['data' => $data]);
    });
    $this->app->instance(JsonTransport::class, $http);
    $device = $this->postJson(route('student.controlled-wallet.social-device'), ['provider' => $provider, 'deviceId' => 'test-device'])->assertSuccessful();
    $resume = $this->postJson(route('student.controlled-wallet.social-device'), ['provider' => $provider, 'resume' => true])->assertSuccessful();
    expect($resume->json('nonce'))->toBe($device->json('nonce'));
    $payload = ['provider' => $provider, 'nonce' => $device->json('nonce'), 'userToken' => 'verified-token', 'encryptionKey' => 'key', 'userId' => 'untrusted-user'];
    $this->postJson(route('student.controlled-wallet.social-complete'), $payload)->assertSuccessful();
    expect($this->student->fresh()->getAttribute('circle_user_id'))->toBe($id)
        ->and($this->student->fresh()->getAttribute('circle_auth_method'))->toBe($provider);
    $this->postJson(route('student.controlled-wallet.social-complete'), $payload)->assertForbidden();
    $this->postJson(route('student.controlled-wallet.circle-session'))->assertUnprocessable();
})->with(['google', 'facebook']);

test('social wallet login cannot replace an existing PIN identity', function (): void {
    config(['student-wallets.circle_api_key' => 'TEST_API_KEY:test:secret', 'student-wallets.circle_app_id' => 'app']);
    $this->student->forceFill(['circle_user_id' => '11111111-1111-4111-8111-111111111111', 'circle_auth_method' => 'pin'])->save();
    $http = Mockery::mock(JsonTransport::class);
    mockExpectation($http, 'request')->once()->andReturn(new HttpResponse(200, ['data' => ['id' => '22222222-2222-4222-8222-222222222222', 'status' => 'ENABLED']]));
    $this->app->instance(JsonTransport::class, $http);
    $this->withSession(['circle_social_context' => ['student_id' => $this->student->id, 'provider' => 'google', 'expires' => now()->addMinutes(5)->timestamp, 'nonce' => 'callback-nonce']])
        ->postJson(route('student.controlled-wallet.social-complete'), ['provider' => 'google', 'nonce' => 'callback-nonce', 'userToken' => 'token', 'encryptionKey' => 'key'])->assertUnprocessable();
    expect($this->student->fresh()->getAttribute('circle_auth_method'))->toBe('pin');
});

test('social callback requires matching school session and unexpired login context', function (): void {
    $this->postJson(route('student.controlled-wallet.social-complete'), ['provider' => 'facebook', 'nonce' => 'forged', 'userToken' => 'token', 'encryptionKey' => 'key'])->assertForbidden();
    $this->withSession(['circle_social_context' => ['student_id' => $this->student->id + 1, 'provider' => 'facebook', 'expires' => now()->addMinutes(5)->timestamp, 'nonce' => 'nonce']])
        ->postJson(route('student.controlled-wallet.social-complete'), ['provider' => 'facebook', 'nonce' => 'nonce', 'userToken' => 'token', 'encryptionKey' => 'key'])->assertForbidden();
});

test('Circle linking waits for indexing without treating approval as a created wallet', function (): void {
    config(['student-wallets.circle_api_key' => 'TEST_API_KEY:test:secret', 'student-wallets.circle_app_id' => 'app']);
    $http = Mockery::mock(JsonTransport::class);
    mockExpectation($http, 'request')->once()->andReturn(new HttpResponse(200, ['data' => ['wallets' => []]]));
    $this->app->instance(JsonTransport::class, $http);
    $this->withSession(['circle_student_auth' => ['student_id' => $this->student->id, 'user_id' => '22222222-2222-4222-8222-222222222222',
        'token' => encrypt('user-token'), 'expires' => now()->addMinutes(30)->timestamp]])
        ->postJson(route('student.controlled-wallet.circle-connect'))->assertStatus(202)->assertJson(['state' => 'awaiting_wallet']);
    expect($this->student->fresh()->wallet)->toBeNull();
});

test('connected wallet screen displays verified identity and usable payment actions', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    controlledWallet($this->student, $this->address);
    visit(route('student.controlled-wallet.show'))
        ->assertSee('Connected & verified')
        ->assertSee($this->address)
        ->assertSee('Copy address')
        ->assertSee('View on explorer')
        ->assertSee('Reconnect your wallet session')
        ->assertNoJavaScriptErrors();
})->group('browser');

test('switching from a Circle wallet to a browser wallet retires the old one and keeps its history', function (): void {
    $circleAddress = $this->address;
    $browserAddress = '0x'.str_repeat('f', 40);
    app(StudentControlledWallets::class)->link($this->student, $circleAddress, 'circle-user', '22222222-2222-4222-8222-222222222222', '11111111-1111-4111-8111-111111111111');
    $circleWallet = $this->student->fresh()->wallet;

    $wallet = app(StudentControlledWallets::class)->link($this->student->fresh(), $browserAddress, 'external');
    $this->student->refresh();

    expect($wallet->status)->toBe('active')
        ->and($wallet->address)->toBe($browserAddress)
        ->and($wallet->provider)->toBe('external')
        ->and($circleWallet->fresh()->status)->toBe('replaced')
        ->and($circleWallet->fresh()->address)->toBe($circleAddress)
        ->and($this->student->payout_address)->toBe($browserAddress)
        ->and($this->student->wallet->id)->toBe($wallet->id)
        ->and($this->student->wallets()->count())->toBe(2);
});

test('reconnecting the active wallet is idempotent and never duplicates it', function (): void {
    $first = app(StudentControlledWallets::class)->link($this->student, $this->address, 'external');
    $again = app(StudentControlledWallets::class)->link($this->student->fresh(), $this->address, 'external');

    expect($again->id)->toBe($first->id)
        ->and($this->student->fresh()->wallets()->count())->toBe(1);
});

test('a wallet already linked, or claimed with another wallet type, is refused', function (): void {
    app(StudentControlledWallets::class)->link($this->student, $this->address, 'circle-user', '22222222-2222-4222-8222-222222222222', '11111111-1111-4111-8111-111111111111');
    $this->student->fresh();

    expect(fn () => app(StudentControlledWallets::class)->link($this->student->fresh(), $this->address, 'external'))
        ->toThrow(ValidationException::class);

    $other = Student::factory()->create(['payout_address' => '0x'.str_repeat('e', 40)]);
    expect(fn () => app(StudentControlledWallets::class)->link($this->student->fresh(), $other->payout_address, 'external'))
        ->toThrow(ValidationException::class);
});

test('an unresolved payment blocks a wallet switch', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $payment = app(StudentControlledCheckout::class)->create($this->student, 'tuition', $account->id, 2000000);
    $payment->forceFill(['approval_started_at' => now()])->save();

    expect(fn () => app(StudentControlledWallets::class)->link($this->student->fresh(), '0x'.str_repeat('f', 40), 'external'))
        ->toThrow(ValidationException::class);
    expect($this->student->fresh()->payout_address)->toBe($this->address);
});

test('reconcile list tells a browser payment apart from a Circle payment', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $browser = app(StudentControlledCheckout::class)->create($this->student, 'tuition', $account->id, 2000000);
    // Settled, so it no longer fences the next attempt.
    $browser->forceFill(['tx_hash' => '0x'.str_repeat('a', 64), 'posted_at' => now()])->save();
    $circle = app(StudentControlledCheckout::class)->create($this->student, 'tuition', $account->id, 1000000);
    $circle->forceFill(['circle_challenge_id' => '33333333-3333-4333-8333-333333333333'])->save();

    $this->get(route('student.controlled-wallet.show'))
        ->assertInertia(fn ($page) => $page
            ->where('payments', fn ($payments): bool => collect($payments)
                ->every(fn (array $payment): bool => ($payment['id'] === $circle->id) === $payment['via_circle']))
            ->has('payments', 2));
});

test('asking Circle about a browser payment explains how to verify it instead', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $payment = app(StudentControlledCheckout::class)->create($this->student, 'tuition', $account->id, 2000000);
    $this->withSession(['circle_student_auth' => ['student_id' => $this->student->id, 'user_id' => '22222222-2222-4222-8222-222222222222',
        'token' => encrypt('user-token'), 'expires' => now()->addMinutes(30)->timestamp]])
        ->postJson(route('student.controlled-wallet.circle-status', $payment->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('circle');
});

test('unapproved quotes do not lock out new quotes or wallet switching', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $checkout = app(StudentControlledCheckout::class);

    // Initial quote without starting approval
    $first = $checkout->create($this->student, 'tuition', $account->id, 2000000);
    expect(StudentControlledPayment::query()->where('student_id', $this->student->id)->count())->toBe(1);

    // Re-quoting with another amount succeeds and supersedes the unstarted quote
    $second = $checkout->create($this->student, 'tuition', $account->id, 5000000);
    expect($second->id)->not->toBe($first->id)
        ->and(StudentControlledPayment::query()->where('student_id', $this->student->id)->count())->toBe(1)
        ->and(StudentControlledPayment::query()->find($first->id))->toBeNull();

    // Switching wallets succeeds because the quote was never in-flight
    $newWallet = app(StudentControlledWallets::class)->link($this->student->fresh(), '0x'.str_repeat('e', 40), 'external');
    expect($newWallet->address)->toBe('0x'.str_repeat('e', 40));
});

test('allow multiple wallets migration rollback cleans up historical rows and restores unique index', function (): void {
    controlledWallet($this->student, $this->address);
    app(StudentControlledWallets::class)->link($this->student->fresh(), '0x'.str_repeat('f', 40), 'external');
    expect($this->student->fresh()->wallets()->count())->toBe(2);

    $migration = require database_path('migrations/2026_10_17_150226_allow_multiple_student_wallets.php');
    $migration->down();

    expect($this->student->fresh()->wallets()->count())->toBe(1)
        ->and($this->student->fresh()->wallet->address)->toBe('0x'.str_repeat('f', 40));

    // Re-running up succeeds as well
    $migration->up();
});

test('unused quotes can be cleared but started approvals stay fenced after expiry', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $checkout = app(StudentControlledCheckout::class);
    $unused = $checkout->create($this->student, 'tuition', $account->id, 1000000);
    $this->postJson(route('student.controlled-wallet.cancel', $unused->id))->assertSuccessful();
    expect(StudentControlledPayment::query()->find($unused->id))->toBeNull();
    $started = $checkout->create($this->student, 'tuition', $account->id, 2000000);
    $started->forceFill(['approval_started_at' => now()])->save();
    $this->travel(11)->minutes();
    $this->postJson(route('student.controlled-wallet.cancel', $started->id))->assertUnprocessable();
    expect(fn () => $checkout->create($this->student, 'tuition', $account->id, 1000000))->toThrow(ValidationException::class);
    expect($started->fresh()->approval_started_at)->not->toBeNull();
});

test('students cannot clear another students unused quote', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $payment = app(StudentControlledCheckout::class)->create($this->student, 'tuition', $account->id, 1000000);
    $other = Student::factory()->create();
    $this->actingAs($other->user->assignRole('student'));
    $this->postJson(route('student.controlled-wallet.cancel', $payment->id))->assertForbidden();
    expect($payment->fresh())->not->toBeNull();
});

test('expired Circle approvals recover the original challenge without creating another transfer', function (): void {
    config(['student-wallets.circle_api_key' => 'TEST_API_KEY:test:secret', 'student-wallets.circle_app_id' => 'app']);
    app(StudentControlledWallets::class)->link($this->student, $this->address, 'circle-user', '22222222-2222-4222-8222-222222222222', '11111111-1111-4111-8111-111111111111');
    $this->student->refresh();
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $payment = app(StudentControlledCheckout::class)->create($this->student, 'tuition', $account->id, 1000000);
    $challenge = '33333333-3333-4333-8333-333333333333';
    $payment->forceFill(['approval_started_at' => now(), 'circle_challenge_id' => $challenge])->save();
    $this->travel(11)->minutes();
    $http = Mockery::mock(JsonTransport::class);
    $http->shouldNotReceive('request');
    $this->app->instance(JsonTransport::class, $http);
    $this->withSession(['circle_student_auth' => ['student_id' => $this->student->id, 'user_id' => '22222222-2222-4222-8222-222222222222',
        'token' => encrypt('token'), 'expires' => now()->addMinutes(30)->timestamp]])
        ->postJson(route('student.controlled-wallet.circle-pay', $payment->id), ['consent' => true])
        ->assertSuccessful()->assertJson(['challengeId' => $challenge]);
    $this->postJson(route('student.controlled-wallet.cancel', $payment->id))->assertUnprocessable();
});

test('Circle request failure before PIN can recover the same payment and idempotency key', function (): void {
    config(['student-wallets.circle_api_key' => 'TEST_API_KEY:test:secret', 'student-wallets.circle_app_id' => 'app']);
    app(StudentControlledWallets::class)->link($this->student, $this->address, 'circle-user', '22222222-2222-4222-8222-222222222222', '11111111-1111-4111-8111-111111111111');
    $this->student->refresh();
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $payment = app(StudentControlledCheckout::class)->create($this->student, 'tuition', $account->id, 1000000);
    $http = Mockery::mock(JsonTransport::class);
    $calls = 0;
    mockExpectation($http, 'request')->times(2)->andReturnUsing(function (string $method, string $url, array $headers, ?array $body) use ($payment, &$calls): HttpResponse {
        expect($body['idempotencyKey'])->toBe($payment->id)
            ->and($body)->not->toHaveKey('blockchain');

        return ++$calls === 1 ? new HttpResponse(503) : new HttpResponse(201, ['data' => ['challengeId' => '33333333-3333-4333-8333-333333333333']]);
    });
    $this->app->instance(JsonTransport::class, $http);
    $this->withSession(['circle_student_auth' => ['student_id' => $this->student->id, 'user_id' => '22222222-2222-4222-8222-222222222222', 'token' => encrypt('token'), 'expires' => now()->addMinutes(30)->timestamp]]);
    $this->postJson(route('student.controlled-wallet.circle-pay', $payment->id), ['consent' => true])->assertUnprocessable();
    expect($payment->fresh()->circle_challenge_id)->toBeNull();
    $this->postJson(route('student.controlled-wallet.circle-pay', $payment->id), ['consent' => true])->assertSuccessful()->assertJson(['challengeId' => '33333333-3333-4333-8333-333333333333']);
    expect($account->fresh()->paid_amount)->toBe(0);
});

test('PIN reconnect checks existing Circle user rather than recreating it', function (): void {
    config(['student-wallets.circle_api_key' => 'TEST_API_KEY:test:secret', 'student-wallets.circle_app_id' => 'app']);
    $id = '22222222-2222-4222-8222-222222222222';
    $http = Mockery::mock(JsonTransport::class);
    mockExpectation($http, 'request')->once()->with('GET', 'https://api.circle.com/v1/w3s/users/'.$id, Mockery::type('array'), null)
        ->andReturn(new HttpResponse(200, ['data' => ['user' => ['id' => $id]]]));
    assert($http instanceof JsonTransport);
    $service = new CircleStudentWallets($http);
    $service->ensureUser($id);
});

test('Circle failure names the operation and never exposes raw provider messages', function (): void {
    config(['student-wallets.circle_api_key' => 'TEST_API_KEY:test:secret', 'student-wallets.circle_app_id' => 'app']);
    $http = Mockery::mock(JsonTransport::class);
    mockExpectation($http, 'request')->once()->andReturn(new HttpResponse(400, ['code' => 155101, 'message' => 'sensitive-provider-detail']));
    assert($http instanceof JsonTransport);
    $service = new CircleStudentWallets($http);
    expect(fn () => $service->request('POST', 'users/token', ['userId' => 'test-user']))
        ->toThrow(ValidationException::class, 'Circle wallet session failed (HTTP 400). Circle code 155101.');
});

test('student gas estimate uses exact native USDC arithmetic without assuming sponsorship', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $payment = app(StudentControlledCheckout::class)->create($this->student, 'tuition', $account->id, 1000000);
    /** @var ArcNetworkGateway&MockInterface $arc */
    $arc = Mockery::mock(ArcNetworkGateway::class);
    mockExpectation($arc, 'rpc')->andReturnUsing(static fn (string $method, array $params = []): mixed => match ($method) {
        'eth_chainId' => '0x4cef52', 'eth_estimateGas' => '0x5208', 'eth_gasPrice' => '0x3b9aca00', default => null,
    });
    $wallets = new StudentControlledWallets($arc);
    $checkout = new StudentControlledCheckout($wallets, $arc, new ArcSettlementVerifier($arc));
    expect($checkout->feeEstimate($payment))->toBe(['estimated_fee' => '0.000021000000000000', 'fee_paid_by' => 'student']);
});

test('gas preview never creates payment records or reserves an approval', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $this->postJson(route('student.controlled-wallet.estimate'), ['kind' => 'tuition', 'account_id' => $account->id, 'amount' => '1'])->assertSuccessful()->assertJson(['fee_paid_by' => 'student']);
    $this->postJson(route('student.controlled-wallet.estimate'), ['kind' => 'tuition', 'account_id' => $account->id, 'amount' => '2'])->assertSuccessful();
    expect(StudentControlledPayment::query()->count())->toBe(0);
});

test('settled student tuition payment bridges into Transaction, StudentTuitionPayment, and CollectionBatch', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $service = app(StudentControlledCheckout::class);
    $payment = $service->create($this->student, 'tuition', $account->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $hash = '0x'.str_repeat('e', 64);

    $service->confirm($this->student, $payment, $hash);
    if ($payment->tuition_account_id !== null) {
        $service->recordSettlement($payment->fresh());
    }

    $tuitionPayment = StudentTuitionPayment::query()->where('tx_hash', $hash)->first();
    expect($tuitionPayment)->not->toBeNull()
        ->and($tuitionPayment->request_key)->toBe($payment->id)
        ->and($tuitionPayment->amount_base_units)->toBe(2000000)
        ->and($tuitionPayment->applied_base_units)->toBe(2000000)
        ->and($tuitionPayment->overpayment_base_units)->toBe(0)
        ->and($tuitionPayment->tx_verified_on_chain)->toBeTrue()
        ->and($tuitionPayment->simulated)->toBeFalse();

    $tx = Transaction::query()->where('provider_tx_hash', $hash)->first();
    expect($tx)->not->toBeNull()
        ->and($tx->type)->toBe(TransactionType::TUITION_REVENUE)
        ->and($tx->status)->toBe(TransactionStatus::CONFIRMED)
        ->and($tx->amount)->toBe(2.0)
        ->and($tx->reference_type)->toBe(StudentTuitionPayment::class)
        ->and($tx->reference_id)->toBe($tuitionPayment->id);

    $batch = CollectionBatch::query()->where('student_tuition_payment_id', $tuitionPayment->id)->first();
    expect($batch)->not->toBeNull()
        ->and($batch->received_minor_units)->toBe(2000000)
        ->and($batch->restricted_minor_units)->toBe(0);

    expect($payment->fresh()->tuitionPayment?->id)->toBe($tuitionPayment->id)
        ->and($payment->fresh()->transaction?->id)->toBe($tx->id);

    // Idempotency: re-confirming changes nothing and creates no duplicates
    $service->confirm($this->student, $payment->fresh(), $hash);
    expect(StudentTuitionPayment::query()->where('tx_hash', $hash)->count())->toBe(1)
        ->and(Transaction::query()->where('provider_tx_hash', $hash)->count())->toBe(1)
        ->and(CollectionBatch::query()->where('student_tuition_payment_id', $tuitionPayment->id)->count())->toBe(1);
});

test('settled campus bill preserves tuition credit without inventing operator consent', function (): void {
    controlledWallet($this->student, $this->address);
    $bill = StudentBill::factory()->create(['student_id' => $this->student->id, 'amount_base_units' => 2000000, 'paid_base_units' => 0]);
    $service = app(StudentControlledCheckout::class);
    $payment = $service->create($this->student, 'bill', $bill->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $hash = '0x'.str_repeat('f', 64);
    $service->confirm($this->student, $payment, $hash);
    expect($bill->fresh()->paid_base_units)->toBe(2000000);
    expect(fn () => $service->recordSettlement($payment->fresh()))->toThrow(ValidationException::class);
    expect(StudentBillPayment::query()->count())->toBe(0);
});

test('backfill command bridges settled payments missing Transaction or CollectionBatch', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $service = app(StudentControlledCheckout::class);
    $payment = $service->create($this->student, 'tuition', $account->id, 3000000);
    $hash = '0x'.str_repeat('9', 64);

    // Simulate historical payment that settled before the bridging fix
    $payment->forceFill([
        'tx_hash' => $hash,
        'evidence' => ['verdict' => 'verified', 'settled' => true, 'receipt' => ['gasUsed' => '0x5208']],
        'applied_base_units' => 3000000,
        'overpayment_base_units' => 0,
        'posted_at' => now()->subDay(),
    ])->save();

    expect(Transaction::query()->where('provider_tx_hash', $hash)->exists())->toBeFalse()
        ->and(StudentTuitionPayment::query()->where('tx_hash', $hash)->exists())->toBeFalse();

    // Dry run does not commit changes
    $this->artisan('app:backfill-student-controlled-payments', ['--dry-run' => true, '--payment' => $payment->id])
        ->expectsOutputToContain('[DRY RUN]')
        ->assertSuccessful();

    expect(Transaction::query()->where('provider_tx_hash', $hash)->exists())->toBeFalse();

    // Actual backfill creates Transaction, StudentTuitionPayment, and CollectionBatch
    $this->artisan('app:backfill-student-controlled-payments', ['--payment' => $payment->id])
        ->expectsOutputToContain('[SYNCED]')
        ->assertSuccessful();

    $tuitionPayment = StudentTuitionPayment::query()->where('tx_hash', $hash)->first();
    expect($tuitionPayment)->not->toBeNull()
        ->and($tuitionPayment->amount_base_units)->toBe(3000000);

    $tx = Transaction::query()->where('provider_tx_hash', $hash)->first();
    expect($tx)->not->toBeNull()
        ->and($tx->type)->toBe(TransactionType::TUITION_REVENUE)
        ->and($tx->status)->toBe(TransactionStatus::CONFIRMED);

    $batch = CollectionBatch::query()->where('student_tuition_payment_id', $tuitionPayment->id)->first();
    expect($batch)->not->toBeNull();

    // Subsequent run indicates already complete
    $this->artisan('app:backfill-student-controlled-payments', ['--payment' => $payment->id])
        ->expectsOutputToContain('[OK]')
        ->assertSuccessful();
});

test('TransactionsTable displays settlement label and color for verified transactions', function (): void {
    $tx = new Transaction([
        'provider_tx_hash' => '0x'.str_repeat('a', 64),
        'network' => 'arc',
        'metadata' => [
            'reconciliation' => 'verified',
        ],
    ]);

    expect(TransactionsTable::settlementLabel($tx))->toBe('Verified on-chain')
        ->and(TransactionsTable::settlementColor($tx))->toBe('success')
        ->and(TransactionsTable::settlementTooltip($tx))->toContain('transfer is verified on-chain');
});

test('admin and finance dashboards and relation managers reflect bridged student payments', function (): void {
    Role::findOrCreate('super_admin', 'web');
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('finance_officer', 'web');

    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $service = app(StudentControlledCheckout::class);
    $payment = $service->create($this->student, 'tuition', $account->id, 5000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $hash = '0x'.str_repeat('8', 64);

    $service->confirm($this->student, $payment, $hash);
    if ($payment->tuition_account_id !== null) {
        $service->recordSettlement($payment->fresh());
    }

    $tuitionPayment = StudentTuitionPayment::query()->where('tx_hash', $hash)->firstOrFail();

    // 1. PaymentsRelationManager under Tuition Account displays the payment
    $admin = User::factory()->create()->assignRole('admin');
    $this->actingAs($admin);
    Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $account->fresh(),
        'pageClass' => ViewTuitionAccount::class,
    ])
        ->assertSuccessful()
        ->assertSee('5.00 USDC')
        ->assertSee($tuitionPayment->tx_hash);

    // 2. TreasuryOverviewWidget renders evidence and ledger status
    Livewire::test(TreasuryOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('Exact evidence records')
        ->assertSee('Settlement status');

    // 3. CollectionsOverviewWidget and CollectionsTrendChart in Finance
    $financeOfficer = User::factory()->create()->assignRole('finance_officer');
    $this->actingAs($financeOfficer);
    Livewire::test(CollectionsOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('USDC')
        ->assertSee('5.00');

    Livewire::test(CollectionsTrendChart::class)
        ->assertSuccessful();

    // 4. AdoptionActivityChart renders activity
    $this->actingAs($admin);
    Livewire::test(AdoptionActivityChart::class)
        ->assertSuccessful();

    // 5. Collections page in Finance lists the batch
    $batch = CollectionBatch::query()->where('student_tuition_payment_id', $tuitionPayment->id)->firstOrFail();
    $this->actingAs($financeOfficer);
    Livewire::test(Collections::class)
        ->assertSuccessful()
        ->assertSee($batch->source_reference);
});

test('student dashboard includes existing verified student-controlled payments without allocating twice', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $checkout = app(StudentControlledCheckout::class);
    $payment = $checkout->create($this->student, 'tuition', $account->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $checkout->confirm($this->student, $payment, '0x'.str_repeat('c', 64));

    $this->get(route('student.dashboard'))->assertSuccessful()->assertInertia(fn ($page) => $page
        ->where('recentTransactions.0.id', 'controlled-'.$payment->id)
        ->where('recentTransactions.0.amount', 2)
        ->where('recentTransactions.0.is_verified', true)
        ->where('recentTransactions.0.direction', 'outbound'));
    expect($account->fresh()->paid_amount)->toBe(2000000);
});

test('student dashboard shows started payments as pending and hides unused quotes', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $payment = app(StudentControlledCheckout::class)->create($this->student, 'tuition', $account->id, 1000000);
    $this->get(route('student.dashboard'))->assertSuccessful()->assertInertia(fn ($page) => $page->has('recentTransactions', 0));
    $payment->forceFill(['approval_started_at' => now()])->save();
    $this->get(route('student.dashboard'))->assertSuccessful()->assertInertia(fn ($page) => $page
        ->where('recentTransactions.0.id', 'controlled-'.$payment->id)
        ->where('recentTransactions.0.status', 'pending')
        ->where('recentTransactions.0.is_verified', false));
});

test('student-controlled activity stays private to its owner', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $payment = app(StudentControlledCheckout::class)->create($this->student, 'tuition', $account->id, 1000000);
    $payment->forceFill(['approval_started_at' => now()])->save();
    $other = Student::factory()->withoutPayoutAddress()->create();
    $this->actingAs($other->user->assignRole('student'));
    $this->get(route('student.dashboard'))->assertSuccessful()->assertInertia(fn ($page) => $page->has('recentTransactions', 0));
});

test('My Payments includes stored verified student-controlled e-receipts without another transfer', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $service = app(StudentControlledCheckout::class);
    $payment = $service->create($this->student, 'tuition', $account->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $service->confirm($this->student, $payment, '0x'.str_repeat('c', 64));
    $this->get(route('payments.index'))->assertSuccessful()->assertInertia(fn ($page) => $page
        ->where('receipts.0.receipt_number', 'TEST-ARC-'.$payment->id)
        ->where('receipts.0.amount_base_units', '2000000')
        ->where('receipts.0.tx_hash', '0x'.str_repeat('c', 64))
        ->where('totals.tuition_paid', 2));
    expect($account->fresh()->paid_amount)->toBe(2000000);
});

test('payment receipt notification supports email and states testnet limitations', function (): void {
    $notification = new StudentControlledPaymentSettled('TEST-ARC-example', '2', '0x'.str_repeat('c', 64));
    expect($notification->via($this->student->user))->toBe(['database', 'mail']);
    $mail = $notification->toMail($this->student->user);
    expect(implode(' ', $mail->introLines))->toContain('not proof of official college tuition payment')
        ->and($mail->actionUrl)->toBe(route('payments.index'));
});

test('background receipt reconciliation is idempotent and never creates spending authority', function (): void {
    controlledWallet($this->student, $this->address);
    $institutionId = (int) config('eduflow.institution_id');
    $this->student->forceFill(['organization_id' => $institutionId])->save();
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $checkout = app(StudentControlledCheckout::class);
    $payment = $checkout->create($this->student, 'tuition', $account->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $checkout->confirm($this->student, $payment, '0x'.str_repeat('7', 64));
    expect(Transaction::query()->count())->toBe(1);
    $job = new ReconcileStudentControlledPayment($payment->id);
    config(['eduflow.autonomous_pipeline.reviewer_id' => $this->student->user_id]);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    expect(Transaction::query()->count())->toBe(1)
        ->and(CollectionBatch::query()->count())->toBe(1)
        ->and(CollectionBatchReview::query()->count())->toBe(0)
        ->and(PaymentAuthorization::query()->count())->toBe(0)
        ->and($account->fresh()->paid_amount)->toBe(2000000)
        ->and(app(InstallationInstitution::class)->current()?->id)->toBe($institutionId);
    $steps = app(StudentPaymentProgress::class)->forPayment($payment->fresh());
    expect(array_column($steps, 'stage'))->toContain('receipt', 'classification', 'authorization');
});

test('missing intake owner never grants a role and backfill reports incomplete reconciliation', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $checkout = app(StudentControlledCheckout::class);
    $payment = $checkout->create($this->student, 'tuition', $account->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $checkout->confirm($this->student, $payment, '0x'.str_repeat('6', 64));
    config(['eduflow.student_tuition.intake_owner_id' => null]);
    $beforeUsers = User::query()->count();
    $beforeRoles = DB::table('model_has_roles')->count();
    $this->artisan('app:backfill-student-controlled-payments', ['--payment' => $payment->id])
        ->expectsOutputToContain('[INCOMPLETE]')->assertFailed();
    expect(User::query()->count())->toBe($beforeUsers)
        ->and(DB::table('model_has_roles')->count())->toBe($beforeRoles)
        ->and(CollectionBatch::query()->count())->toBe(0)
        ->and($account->fresh()->paid_amount)->toBe(2000000);
});

test('configured background classification queues once without approving receipts', function (): void {
    $institutionId = (int) config('eduflow.institution_id');
    $context = institutionPolicyContext();
    $context['reviewer']->forceFill(['organization_id' => $institutionId])->save();
    config(['eduflow.institution_policy.classification_engine' => 'text', 'eduflow.autonomous_pipeline.reviewer_id' => $context['reviewer']->id]);
    $published = pilotPublishedDefaultPolicy($context);
    Bus::fake();
    controlledWallet($this->student, $this->address);
    $this->student->forceFill(['organization_id' => $institutionId])->save();
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $checkout = app(StudentControlledCheckout::class);
    $payment = $checkout->create($this->student, 'tuition', $account->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $checkout->confirm($this->student, $payment, '0x'.str_repeat('4', 64));
    $job = new ReconcileStudentControlledPayment($payment->id);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    expect(InstitutionPolicyEvaluation::query()->count())->toBe(1)
        ->and(InstitutionPolicyEvaluation::query()->sole()->state)->toBe('queued')
        ->and(CollectionBatchReview::query()->count())->toBe(0)
        ->and(PaymentAuthorization::query()->count())->toBe(0);
    Bus::assertDispatchedTimes(EvaluateInstitutionPolicy::class, 1);
});

test('receipt recovery dispatch is leased and rejects sync queues', function (): void {
    controlledWallet($this->student, $this->address);
    $this->student->forceFill(['organization_id' => (int) config('eduflow.institution_id')])->save();
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $checkout = app(StudentControlledCheckout::class);
    $payment = $checkout->create($this->student, 'tuition', $account->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $checkout->confirm($this->student, $payment, '0x'.str_repeat('2', 64));
    Bus::fake();
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 360]);
    $dispatcher = app(StudentReceiptDispatcher::class);
    $this->travel(181)->seconds();
    expect($dispatcher->dispatchPayment($payment->id))->toBeTrue()
        ->and($dispatcher->dispatchPayment($payment->id))->toBeFalse();
    Bus::assertDispatchedTimes(ReconcileStudentControlledPayment::class, 1);
    $this->travel(181)->seconds();
    expect($dispatcher->dispatchPayment($payment->id))->toBeTrue();
    Bus::assertDispatchedTimes(ReconcileStudentControlledPayment::class, 2);
    config(['queue.default' => 'sync']);
    expect(fn () => $dispatcher->dispatchPayment($payment->id))->toThrow(ValidationException::class);
    expect(PaymentAuthorization::query()->count())->toBe(0);
});

test('timeline reports real independent receipt review and missing planning route', function (): void {
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $checkout = app(StudentControlledCheckout::class);
    $payment = $checkout->create($this->student, 'tuition', $account->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $checkout->confirm($this->student, $payment, '0x'.str_repeat('1', 64));
    $checkout->recordSettlement($payment->fresh());
    $batch = CollectionBatch::query()->sole();
    $reviewer = User::factory()->create(['organization_id' => (int) config('eduflow.institution_id')])->assignRole(Role::findOrCreate('admin', 'web'));
    app(ReviewCollectionBatch::class)->handle($reviewer, $batch, $batch->snapshot_digest,
        'approve_receipts', 'Independent evidence check', 'Receipt and restrictions independently checked.');
    $steps = app(StudentPaymentProgress::class)->forPayment($payment->fresh());
    $review = collect($steps)->firstWhere('stage', 'receipt_review');
    $planning = collect($steps)->firstWhere('stage', 'planning');
    expect($review['status'])->toBe('completed')->and($planning['status'])->toBe('waiting')
        ->and($planning['message'])->toContain('No approved receipt-to-budget route')
        ->and(PaymentAuthorization::query()->count())->toBe(0);
});

test('scheduled receipt recovery dispatches committed payments without running them inline', function (): void {
    controlledWallet($this->student, $this->address);
    $this->student->forceFill(['organization_id' => (int) config('eduflow.institution_id')])->save();
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $checkout = app(StudentControlledCheckout::class);
    $payment = $checkout->create($this->student, 'tuition', $account->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $checkout->confirm($this->student, $payment, '0x'.str_repeat('3', 64));
    $this->travel(181)->seconds();
    Bus::fake();
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 360]);
    $this->artisan('app:dispatch-student-receipts')->expectsOutputToContain('Student receipt jobs dispatched: 1')->assertSuccessful();
    $this->artisan('app:dispatch-student-receipts')->expectsOutputToContain('Student receipt jobs dispatched: 0')->assertSuccessful();
    Bus::assertDispatchedTimes(ReconcileStudentControlledPayment::class, 1);
    expect(Transaction::query()->count())->toBe(1)->and($account->fresh()->paid_amount)->toBe(2000000);
});

test('student payment activity persists through browser refresh without duplicate tuition credit', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $checkout = app(StudentControlledCheckout::class);
    $payment = $checkout->create($this->student, 'tuition', $account->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $checkout->confirm($this->student, $payment, '0x'.str_repeat('5', 64));
    $checkout->recordSettlement($payment->fresh());
    $page = visit(route('student.pay', ['account' => $account->id, 'mode' => 'tuition']))
        ->assertSee('Receipt and policy progress')
        ->assertSee('Transfer verified and credited.')
        ->assertSee('Institution receipt recorded.')
        ->assertSee('Classification not started.')
        ->assertSee('Classification does not authorize a transfer.')
        ->assertNoJavaScriptErrors();
    $page->refresh()->assertSee('Receipt and policy progress')->assertSee('Institution receipt recorded.')->assertNoJavaScriptErrors();
    expect($account->fresh()->paid_amount)->toBe(2000000)
        ->and(Transaction::query()->count())->toBe(1)
        ->and(PaymentAuthorization::query()->count())->toBe(0);
})->group('browser');

test('student payment timeline stays readable on mobile with finance review hold', function (): void {
    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot'));
    controlledWallet($this->student, $this->address);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $checkout = app(StudentControlledCheckout::class);
    $payment = $checkout->create($this->student, 'tuition', $account->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $checkout->confirm($this->student, $payment, '0x'.str_repeat('8', 64));
    $checkout->recordSettlement($payment->fresh());
    $page = visit(route('student.pay', ['account' => $account->id, 'mode' => 'tuition']))->on()->mobile()
        ->assertSee('Receipt and policy progress')
        ->assertSee('Waiting for independent finance receipt review.')
        ->assertSee('No automated disbursement is authorized by this payment.')
        ->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue()
        ->and(PaymentAuthorization::query()->count())->toBe(0);
})->group('browser');

test('successful tuition confirmation records admin transaction with stopped workers and no intake owner', function (): void {
    controlledWallet($this->student, $this->address);
    config(['queue.default' => 'sync', 'eduflow.student_tuition.intake_owner_id' => null]);
    $account = TuitionAccount::factory()->create(['student_id' => $this->student->id, 'total_amount' => 10000000, 'paid_amount' => 0]);
    $checkout = app(StudentControlledCheckout::class);
    $payment = $checkout->create($this->student, 'tuition', $account->id, 2000000);
    $this->transaction = controlledTransfer($payment);
    $this->receipt = controlledReceipt($payment);
    $hash = '0x'.str_repeat('9', 64);
    $checkout->confirm($this->student, $payment, $hash);
    expect(Transaction::query()->where('provider_tx_hash', $hash)->count())->toBe(1)
        ->and($account->fresh()->paid_amount)->toBe(2000000)
        ->and(CollectionBatch::query()->count())->toBe(0);
    $checkout->confirm($this->student, $payment->fresh(), $hash);
    expect(Transaction::query()->where('provider_tx_hash', $hash)->count())->toBe(1);
});
