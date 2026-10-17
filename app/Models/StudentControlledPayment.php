<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A user-approved testnet transfer, never official institutional revenue.
 *
 * @property string $id
 * @property int $student_id
 * @property int $student_wallet_id
 * @property int|null $tuition_account_id
 * @property int|null $student_bill_id
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 * @property string|null $tx_hash
 * @property string|null $circle_challenge_id
 * @property array<string, mixed>|null $evidence
 * @property int $applied_base_units
 * @property int $overpayment_base_units
 * @property Carbon $created_at
 * @property Carbon $expires_at
 * @property Carbon|null $approval_started_at
 * @property Carbon|null $posted_at
 * @property-read StudentTuitionPayment|null $tuitionPayment
 * @property-read StudentBillPayment|null $billPayment
 * @property-read Transaction|null $transaction
 */
class StudentControlledPayment extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    #[\Override]
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'evidence' => 'array', 'expires_at' => 'datetime', 'approval_started_at' => 'datetime', 'posted_at' => 'datetime',
            'applied_base_units' => 'integer', 'overpayment_base_units' => 'integer'];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::updating(function (self $payment): void {
            if ($payment->isDirty(['snapshot', 'snapshot_digest', 'student_id', 'student_wallet_id', 'tuition_account_id', 'student_bill_id', 'expires_at'])
                || ($payment->getRawOriginal('approval_started_at') !== null && $payment->isDirty('approval_started_at'))
                || ($payment->getRawOriginal('tx_hash') !== null && $payment->isDirty('tx_hash'))
                || ($payment->getRawOriginal('circle_challenge_id') !== null && $payment->isDirty('circle_challenge_id'))
                || $payment->getRawOriginal('posted_at') !== null) {
                throw new LogicException('Payment identity and posted settlement are immutable.');
            }
        });
        static::deleting(function (self $payment): void {
            if ($payment->posted_at !== null || $payment->approval_started_at !== null || $payment->circle_challenge_id !== null || $payment->tx_hash !== null) {
                throw new LogicException('Payment evidence cannot be deleted.');
            }
        });
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<StudentWallet, $this> */
    public function studentWallet(): BelongsTo
    {
        return $this->belongsTo(StudentWallet::class);
    }

    /** @return BelongsTo<TuitionAccount, $this> */
    public function tuitionAccount(): BelongsTo
    {
        return $this->belongsTo(TuitionAccount::class);
    }

    /** @return BelongsTo<StudentBill, $this> */
    public function studentBill(): BelongsTo
    {
        return $this->belongsTo(StudentBill::class);
    }

    /** @return HasOne<StudentTuitionPayment, $this> */
    public function tuitionPayment(): HasOne
    {
        return $this->hasOne(StudentTuitionPayment::class, 'request_key', 'id');
    }

    /** @return HasOne<StudentBillPayment, $this> */
    public function billPayment(): HasOne
    {
        return $this->hasOne(StudentBillPayment::class, 'request_key', 'id');
    }

    /** @return HasOne<Transaction, $this> */
    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class, 'provider_tx_hash', 'tx_hash');
    }
}
