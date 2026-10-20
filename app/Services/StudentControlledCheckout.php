<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\ImportTuitionPaymentAsCollection;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\CollectionBatch;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Student;
use App\Models\StudentBill;
use App\Models\StudentBillPayment;
use App\Models\StudentControlledPayment;
use App\Models\StudentTuitionPayment;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Notifications\StudentControlledPaymentSettled;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Support\Amounts;

class StudentControlledCheckout
{
    public function __construct(
        private readonly StudentControlledWallets $wallets,
        private readonly ArcNetworkGateway $arc,
        private readonly ArcSettlementVerifier $verifier,
        private readonly ?InstallationInstitution $institutions = null,
    ) {}

    public function create(Student $student, string $kind, int $accountId, int $amount): StudentControlledPayment
    {
        return DB::transaction(function () use ($student, $kind, $accountId, $amount): StudentControlledPayment {
            Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();

            return $this->createLocked($student->fresh(), $kind, $accountId, $amount);
        });
    }

    private function createLocked(Student $student, string $kind, int $accountId, int $amount): StudentControlledPayment
    {
        $this->wallets->assertTestnet();
        if (! in_array($kind, ['tuition', 'bill'], true)) {
            throw ValidationException::withMessages(['kind' => 'Unsupported bill type.']);
        }
        $wallet = $student->wallet;
        if (! $wallet || ! $wallet->getAttribute('ownership_verified_at') || ! in_array($wallet->provider, ['external', 'circle-user'], true)
            || $wallet->chain !== 'ARC-TESTNET' || $wallet->status !== 'active'
            || strcasecmp($wallet->address, (string) $student->payout_address) !== 0) {
            throw ValidationException::withMessages(['wallet' => 'Connect and verify your student-controlled testnet wallet first.']);
        }
        $recipient = strtolower((string) config('lepton.arc.treasury'));
        if (preg_match(Student::PAYOUT_ADDRESS_PATTERN, $recipient) !== 1 || $recipient === strtolower($wallet->address)) {
            throw ValidationException::withMessages(['recipient' => 'Institution treasury is not configured for this payment.']);
        }
        $account = $kind === 'tuition'
            ? $student->tuitionAccounts()->findOrFail($accountId)
            : StudentBill::query()->where('student_id', $student->id)->where('status', '!=', StudentBill::STATUS_VOID)->findOrFail($accountId);
        if ($amount <= 0 || $amount > $account->remainingAmount()) {
            throw ValidationException::withMessages(['amount' => 'Enter a positive amount no greater than the outstanding balance.']);
        }
        if ($account instanceof TuitionAccount && $account->heldAmount() > 0) {
            throw ValidationException::withMessages(['account' => 'Resolve the existing operator payment hold first.']);
        }
        if (StudentControlledPayment::query()->where('student_id', $student->id)->whereNull('posted_at')
            ->where(function ($query): void {
                $query->whereNotNull('circle_challenge_id')
                    ->orWhereNotNull('tx_hash')
                    ->orWhereNotNull('approval_started_at');
            })->exists()) {
            throw ValidationException::withMessages(['payment' => 'You have an unconfirmed payment in progress. Use the button below to clear it or verify on-chain settlement.']);
        }
        StudentControlledPayment::query()
            ->where('student_id', $student->id)
            ->whereNull('posted_at')
            ->whereNull('circle_challenge_id')
            ->whereNull('tx_hash')
            ->whereNull('approval_started_at')
            ->delete();
        $id = (string) Str::uuid();
        $data = '0xa9059cbb'.str_pad(substr($recipient, 2), 64, '0', STR_PAD_LEFT)
            .str_pad(dechex($amount), 64, '0', STR_PAD_LEFT).bin2hex($id);
        $snapshot = ['purpose' => 'student_controlled_testnet_payment', 'student_id' => $student->id,
            'wallet_id' => $wallet->id, 'kind' => $kind, 'account_id' => $accountId, 'chain_id' => 5042002,
            'sender' => strtolower($wallet->address), 'recipient' => $recipient, 'amount_base_units' => $amount,
            'token' => StudentControlledWallets::TOKEN, 'data' => $data, 'reference' => $id,
            'quoted_at' => now()->timestamp, 'testnet_only' => true];
        $payment = new StudentControlledPayment;
        $payment->forceFill(['id' => $id, 'student_id' => $student->id, 'student_wallet_id' => $wallet->id,
            'tuition_account_id' => $kind === 'tuition' ? $accountId : null, 'student_bill_id' => $kind === 'bill' ? $accountId : null,
            'snapshot' => $snapshot, 'snapshot_digest' => PaymentIntent::digest($snapshot),
            'expires_at' => now()->addMinutes(10)])->save();

        return $payment;
    }

    /** @return array{estimated_fee: ?string, fee_paid_by: string} */
    public function previewFee(Student $student, string $kind, int $accountId, int $amount): array
    {
        $this->wallets->assertTestnet();
        $account = $kind === 'tuition'
            ? $student->tuitionAccounts()->findOrFail($accountId)
            : $student->bills()->where('status', '!=', StudentBill::STATUS_VOID)->findOrFail($accountId);
        $wallet = $student->wallet;
        $recipient = strtolower((string) config('lepton.arc.treasury'));
        if (! $wallet || ! $wallet->ownership_verified_at || $wallet->chain !== 'ARC-TESTNET'
            || $amount <= 0 || $amount > $account->remainingAmount()
            || preg_match(Student::PAYOUT_ADDRESS_PATTERN, $recipient) !== 1) {
            throw ValidationException::withMessages(['payment' => 'Select a verified wallet and a valid outstanding amount to estimate gas.']);
        }
        $preview = new StudentControlledPayment;
        $preview->forceFill(['snapshot' => ['sender' => $wallet->address, 'token' => StudentControlledWallets::TOKEN,
            'data' => '0xa9059cbb'.str_pad(substr($recipient, 2), 64, '0', STR_PAD_LEFT)
                .str_pad(dechex($amount), 64, '0', STR_PAD_LEFT).bin2hex('00000000-0000-4000-8000-000000000000')]]);

        return $this->feeEstimate($preview);
    }

    /** @return array{estimated_fee: ?string, fee_paid_by: string} */
    public function feeEstimate(StudentControlledPayment $payment): array
    {
        $this->wallets->assertTestnet();
        $snapshot = $payment->snapshot;
        try {
            $gas = ChainQuantity::toDecimalString($this->arc->rpc('eth_estimateGas', [[
                'from' => $snapshot['sender'], 'to' => $snapshot['token'], 'data' => $snapshot['data'], 'value' => '0x0',
            ]]));
            $price = ChainQuantity::toDecimalString($this->arc->rpc('eth_gasPrice'));
            $fee = $gas !== null && $price !== null
                ? (string) BigDecimal::of($gas)->multipliedBy($price)->withPointMovedLeft(18)
                : null;
        } catch (\Throwable) {
            $fee = null;
        }

        return ['estimated_fee' => $fee, 'fee_paid_by' => 'student'];
    }

    public function confirm(Student $student, StudentControlledPayment $payment, string $hash): StudentControlledPayment
    {
        abort_unless($payment->student_id === $student->id, 404);
        $this->wallets->assertTestnet();
        $s = $payment->snapshot;
        if (! hash_equals($payment->snapshot_digest, PaymentIntent::digest($s))) {
            throw ValidationException::withMessages(['payment' => 'Payment snapshot is invalid.']);
        }
        if ($payment->posted_at !== null) {
            abort_unless($payment->tx_hash === strtolower($hash), 409);

            return $payment;
        }
        if ($payment->tx_hash !== null && $payment->tx_hash !== strtolower($hash)) {
            throw ValidationException::withMessages(['payment' => 'A different transaction is already being reconciled.']);
        }
        $tx = $this->arc->rpc('eth_getTransactionByHash', [$hash]);
        if (! is_array($tx) || strtolower((string) ($tx['to'] ?? '')) !== StudentControlledWallets::TOKEN
            || strtolower((string) ($tx['input'] ?? '')) !== strtolower($s['data'])) {
            throw ValidationException::withMessages(['payment' => 'Transaction does not match this exact payment reference and transfer.']);
        }
        $payment->forceFill(['tx_hash' => strtolower($hash)])->save();
        $evidence = $this->verifier->verify(['chain' => 'ARC-TESTNET', 'chain_id' => 5042002,
            'sender' => $s['sender'], 'recipient' => $s['recipient'], 'amount_base_units' => $s['amount_base_units'],
            'max_fee_base_units' => PHP_INT_MAX], $hash);
        if (($evidence['verdict'] ?? null) !== 'verified') {
            throw ValidationException::withMessages(['payment' => 'Transfer is not yet verified on Arc Testnet. Keep the existing transaction hash; do not pay again.']);
        }

        return DB::transaction(function () use ($payment, $student, $s, $hash, $evidence): StudentControlledPayment {
            Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            $stored = StudentControlledPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($stored->posted_at !== null) {
                abort_unless($stored->tx_hash === strtolower($hash), 409);

                $this->recordSettlementAfterCommit($stored);

                return $stored;
            }
            if (StudentTuitionPayment::query()->where('tx_hash', strtolower($hash))->exists()
                || StudentBillPayment::query()->where('tx_hash', strtolower($hash))->exists()) {
                throw ValidationException::withMessages(['payment' => 'Transaction is already recorded.']);
            }
            $account = $stored->tuition_account_id !== null
                ? TuitionAccount::query()->where('student_id', $student->id)->lockForUpdate()->findOrFail($stored->tuition_account_id)
                : StudentBill::query()->where('student_id', $student->id)->lockForUpdate()->findOrFail($stored->student_bill_id);
            $applied = $account instanceof StudentBill && $account->status === StudentBill::STATUS_VOID ? 0
                : min($s['amount_base_units'], max(0, $account->remainingAmount()));
            if ($account instanceof TuitionAccount) {
                $account->paid_amount += $applied;
                $account->save();
            } else {
                $account->paid_base_units += $applied;
                $account->syncStatus();
            }
            $stored->forceFill(['tx_hash' => strtolower($hash), 'evidence' => $evidence,
                'applied_base_units' => $applied, 'overpayment_base_units' => $s['amount_base_units'] - $applied,
                'posted_at' => now()])->save();

            // Record verified inflow locally before returning success; provider work stays queued.
            try {
                DB::transaction(fn (): StudentControlledPayment => $this->recordLockedSettlement($stored, reconcileReceipt: false));
            } catch (\Throwable $exception) {
                report($exception);
            }
            $this->recordSettlementAfterCommit($stored);

            DB::afterCommit(function () use ($student, $stored): void {
                try {
                    $student->user->notify(new StudentControlledPaymentSettled(
                        reference: 'TEST-ARC-'.$stored->id,
                        amount: Amounts::toDecimalString((int) $stored->snapshot['amount_base_units'], 6),
                        txHash: $stored->tx_hash,
                    ));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

            return $stored;
        });
    }

    private function recordSettlementAfterCommit(StudentControlledPayment $payment): void
    {
        DB::afterCommit(function () use ($payment): void {
            try {
                app(StudentReceiptDispatcher::class)->dispatchPayment($payment->id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }

    public function recordSettlement(StudentControlledPayment $payment): StudentControlledPayment
    {
        return DB::transaction(function () use ($payment): StudentControlledPayment {
            $stored = StudentControlledPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            return $this->recordLockedSettlement($stored);
        });
    }

    private function recordLockedSettlement(StudentControlledPayment $payment, bool $reconcileReceipt = true): StudentControlledPayment
    {
        if ($payment->posted_at === null || $payment->tx_hash === null
            || ($payment->evidence['verdict'] ?? null) !== 'verified'
            || ($payment->evidence['settled'] ?? null) !== true
            || ! hash_equals($payment->snapshot_digest, PaymentIntent::digest($payment->snapshot))
            || $payment->applied_base_units + $payment->overpayment_base_units !== ($payment->snapshot['amount_base_units'] ?? null)) {
            throw ValidationException::withMessages(['settlement' => 'An intact posted settlement with exact allocation is required.']);
        }

        $s = $payment->snapshot;
        $hash = strtolower((string) $payment->tx_hash);
        $student = $payment->student ?? Student::query()->find($payment->student_id);
        if (! $student instanceof Student) {
            return $payment;
        }

        $institutions = $this->institutions ?? app(InstallationInstitution::class);

        $institution = $institutions->require($student->user);
        if (($student->organization_id !== null && $student->organization_id !== $institution->id)
            || ($student->user->organization_id !== null && $student->user->organization_id !== $institution->id)) {
            throw ValidationException::withMessages(['institution' => 'Payment belongs to another institution.']);
        }
        $wallet = $institution->primaryWallet();
        if ($wallet === null || strcasecmp($wallet->address, (string) $s['recipient']) !== 0) {
            throw ValidationException::withMessages(['treasury' => 'Payment destination must match the existing institution treasury wallet.']);
        }

        $tuitionPayment = null;
        $billPayment = null;

        if ($payment->tuition_account_id !== null) {
            $movementKey = PaymentIntent::digest([
                'chain' => $s['chain_id'] ?? 5042002,
                'hash' => $hash,
                'sender' => strtolower((string) $s['sender']),
                'recipient' => strtolower((string) $s['recipient']),
                'amount' => (int) $s['amount_base_units'],
            ]);

            $tuitionPayment = StudentTuitionPayment::query()
                ->where('movement_key', $movementKey)
                ->orWhere('request_key', $payment->id)
                ->orWhere('tx_hash', $hash)
                ->first();

            if ($tuitionPayment === null) {
                $tuitionPayment = StudentTuitionPayment::query()->create([
                    'request_key' => $payment->id,
                    'student_id' => $student->id,
                    'tuition_account_id' => $payment->tuition_account_id,
                    'student_wallet_id' => $payment->student_wallet_id,
                    'tx_hash' => $hash,
                    'amount_base_units' => (int) $s['amount_base_units'],
                    'applied_base_units' => $payment->applied_base_units,
                    'overpayment_base_units' => $payment->overpayment_base_units,
                    'network_fee' => (string) ($payment->evidence['actual_network_fee'] ?? '0.00'),
                    'payer_address' => strtolower((string) $s['sender']),
                    'recipient_address' => strtolower((string) $s['recipient']),
                    'chain' => '[REDACTED]',
                    'tx_verified_on_chain' => true,
                    'simulated' => false,
                    'executed_at' => $payment->posted_at ?? now(),
                    'student_tuition_payment_attempt_id' => null,
                    'movement_key' => $movementKey,
                ]);
            }
        } elseif ($payment->student_bill_id !== null) {
            $billPayment = StudentBillPayment::query()
                ->where('request_key', $payment->id)
                ->orWhere('tx_hash', $hash)
                ->first();

            if ($billPayment === null && $reconcileReceipt) {
                $billPayment = $this->createBillPaymentRecord();
            }
        }

        $tx = Transaction::query()->where('provider_tx_hash', $hash)->first();
        if ($tx === null) {
            $referenceType = Student::class;
            $referenceId = $student->id;
            if ($tuitionPayment instanceof StudentTuitionPayment) {
                $referenceType = StudentTuitionPayment::class;
                $referenceId = $tuitionPayment->id;
            } elseif ($billPayment instanceof StudentBillPayment) {
                $referenceType = StudentBillPayment::class;
                $referenceId = $billPayment->id;
            }

            Transaction::query()->create([
                'organization_id' => $institution->id,
                'wallet_id' => $wallet->id,
                'type' => TransactionType::TUITION_REVENUE,
                'recipient_address' => strtolower((string) $s['recipient']),
                'amount' => Amounts::toDecimalString((int) $s['amount_base_units'], 6),
                'currency' => 'USDC',
                'status' => TransactionStatus::CONFIRMED,
                'provider_tx_hash' => $hash,
                'network' => 'arc',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => [
                    'source' => 'student_controlled_checkout',
                    'student_controlled_payment_id' => $payment->id,
                    'student_id' => $student->id,
                    'student_wallet_id' => $payment->student_wallet_id,
                    'payer_address' => strtolower((string) $s['sender']),
                    'kind' => $s['kind'] ?? ($payment->tuition_account_id !== null ? 'tuition' : 'bill'),
                    'account_id' => $payment->tuition_account_id ?? $payment->student_bill_id,
                    'applied_base_units' => $payment->applied_base_units,
                    'overpayment_base_units' => $payment->overpayment_base_units,
                    'reconciliation' => 'verified',
                    'reconciled_at' => now()->toIso8601String(),
                    'reconciliation_note' => 'Verified on-chain via Arc settlement verifier.',
                    'evidence' => $payment->evidence,
                ],
                'executed_at' => $payment->posted_at ?? now(),
            ]);
        }

        $paymentRecord = $tuitionPayment ?? $billPayment;
        if ($reconcileReceipt && $paymentRecord !== null) {
            $this->ensureCollectionBatch($paymentRecord, $institution);
        }

        return $payment;
    }

    private function createBillPaymentRecord(): ?StudentBillPayment
    {
        throw ValidationException::withMessages(['receipt' => 'Campus bill receipt requires a source-linked student-controlled reconciliation path. Operator consent is never synthesized. Student payment remains credited.']);
    }

    private function ensureCollectionBatch(StudentTuitionPayment|StudentBillPayment $payment, Organization $institution): ?CollectionBatch
    {
        try {
            $existing = $payment instanceof StudentBillPayment
                ? CollectionBatch::query()->where('student_bill_payment_id', $payment->id)->first()
                : CollectionBatch::query()->where('student_tuition_payment_id', $payment->id)->first();

            if ($existing instanceof CollectionBatch) {
                return $existing;
            }

            $owner = $this->resolveIntakeOwner($payment->student, $institution);

            $institutions = $this->institutions ?? app(InstallationInstitution::class);
            $institutions->setExplicitOrganization($institution);

            $captureKey = $payment instanceof StudentBillPayment
                ? Uuid::uuid5(Uuid::NAMESPACE_URL, 'eduflow:bill-intake:'.$payment->movement_key)->toString()
                : Uuid::uuid5(Uuid::NAMESPACE_URL, 'eduflow:tuition-intake:'.$payment->movement_key)->toString();

            $import = app(ImportTuitionPaymentAsCollection::class);

            return $import->handle($owner, $payment, $captureKey);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function resolveIntakeOwner(Student $student, ?Organization $institution = null): User
    {
        $owner = User::query()->find(config('eduflow.student_tuition.intake_owner_id'));
        if (! $owner instanceof User || ! $owner->hasVerifiedEmail()
            || ! $owner->hasAnyRole(['finance_officer', 'admin', 'super_admin'])
            || $owner->id === $student->user_id || ! $institution instanceof Organization
            || $owner->organization_id !== $institution->id) {
            throw ValidationException::withMessages(['intake' => 'Configure a verified institution finance intake owner who is not the payer. No roles or approvals were created.']);
        }

        return $owner;
    }
}
