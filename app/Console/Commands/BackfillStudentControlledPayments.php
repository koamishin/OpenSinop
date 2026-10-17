<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CollectionBatch;
use App\Models\StudentBillPayment;
use App\Models\StudentControlledPayment;
use App\Models\StudentTuitionPayment;
use App\Models\Transaction;
use App\Services\StudentControlledCheckout;
use Illuminate\Console\Command;
use Yukazakiri\Lepton\Support\Amounts;

class BackfillStudentControlledPayments extends Command
{
    protected $signature = 'app:backfill-student-controlled-payments
        {--payment= : Specific StudentControlledPayment UUID to backfill}
        {--dry-run : Report what would be backfilled without saving changes}';

    protected $aliases = ['eduflow:backfill-student-payments', 'eduflow:sync-student-controlled-payments'];

    protected $description = 'Backfill Transaction, StudentTuitionPayment/StudentBillPayment, and CollectionBatch records for settled student-controlled payments';

    public function handle(StudentControlledCheckout $checkout): int
    {
        $paymentId = $this->option('payment');
        $dryRun = (bool) $this->option('dry-run');

        $query = StudentControlledPayment::query()
            ->whereNotNull('posted_at')
            ->whereNotNull('tx_hash');

        if ($paymentId !== null && $paymentId !== '') {
            $query->where('id', $paymentId);
        }

        $payments = $query->orderBy('posted_at')->get();

        if ($payments->isEmpty()) {
            $this->info('No settled student-controlled payments found to backfill.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d settled student-controlled payment(s) to inspect.', $payments->count()));
        $synced = 0;
        $alreadyComplete = 0;

        foreach ($payments as $payment) {
            $hash = strtolower((string) $payment->tx_hash);
            $hasTx = Transaction::query()->where('provider_tx_hash', $hash)->exists();

            $isTuition = $payment->tuition_account_id !== null;
            $hasPaymentRecord = $isTuition
                ? StudentTuitionPayment::query()->where('request_key', $payment->id)->orWhere('tx_hash', $hash)->exists()
                : StudentBillPayment::query()->where('request_key', $payment->id)->orWhere('tx_hash', $hash)->exists();

            $tuitionPayment = $isTuition
                ? StudentTuitionPayment::query()->where('request_key', $payment->id)->orWhere('tx_hash', $hash)->first()
                : null;
            $billPayment = ! $isTuition
                ? StudentBillPayment::query()->where('request_key', $payment->id)->orWhere('tx_hash', $hash)->first()
                : null;
            $paymentRecord = $tuitionPayment ?? $billPayment;

            $hasBatch = $paymentRecord instanceof StudentTuitionPayment
                ? CollectionBatch::query()->where('student_tuition_payment_id', $paymentRecord->id)->exists()
                : ($paymentRecord instanceof StudentBillPayment
                    ? CollectionBatch::query()->where('student_bill_payment_id', $paymentRecord->id)->exists()
                    : false);

            if ($hasTx && $hasPaymentRecord && $hasBatch) {
                $alreadyComplete++;
                $this->line(sprintf('  [OK] Payment %s already complete (Tx: %s).', $payment->id, substr($hash, 0, 10).'...'));

                continue;
            }

            $missing = [];
            if (! $hasTx) {
                $missing[] = 'Transaction';
            }
            if (! $hasPaymentRecord) {
                $missing[] = $isTuition ? 'StudentTuitionPayment' : 'StudentBillPayment';
            }
            if (! $hasBatch) {
                $missing[] = 'CollectionBatch';
            }

            $amountUsdc = Amounts::toDecimalString((int) ($payment->snapshot['amount_base_units'] ?? 0), 6);

            if ($dryRun) {
                $this->warn(sprintf('  [DRY RUN] Payment %s (%s USDC) missing: %s', $payment->id, $amountUsdc, implode(', ', $missing)));
                $synced++;

                continue;
            }

            $checkout->recordSettlement($payment);

            $this->info(sprintf('  [SYNCED] Payment %s (%s USDC) bridged: %s', $payment->id, $amountUsdc, implode(', ', $missing)));
            $synced++;
        }

        $this->newLine();
        $this->info(sprintf(
            'Backfill summary: %d inspected, %d synced, %d already complete.%s',
            $payments->count(),
            $synced,
            $alreadyComplete,
            $dryRun ? ' (DRY RUN - no database changes applied)' : ''
        ));

        return self::SUCCESS;
    }
}
