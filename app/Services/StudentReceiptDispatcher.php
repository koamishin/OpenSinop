<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ReconcileStudentControlledPayment;
use App\Models\StudentControlledPayment;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Bounded recovery of already settled receipts. Never resends transfers. */
final readonly class StudentReceiptDispatcher
{
    public function __construct(private InstallationInstitution $institutions) {}

    public function dispatchPayment(string $paymentId): bool
    {
        $connection = (string) config('queue.default');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)
            || (int) config('queue.connections.'.$connection.'.retry_after', 0) <= 120) {
            throw ValidationException::withMessages(['queue' => 'Student receipts require a durable queue with retry_after greater than 120 seconds.']);
        }
        $payment = StudentControlledPayment::query()->findOrFail($paymentId);
        $institution = $this->institutions->require();
        $student = $payment->student()->firstOrFail();
        if ($student->organization_id !== $institution->id && $student->user->organization_id !== $institution->id) {
            throw ValidationException::withMessages(['institution' => 'Receipt dispatch requires matching institution ownership.']);
        }
        if ($payment->posted_at === null || $payment->tx_hash === null) {
            return false;
        }
        /** @var LockProvider $store */
        $store = Cache::store('database');
        $lock = $store->lock('student-receipt-dispatch:'.$paymentId, 180);
        if (! $lock->get()) {
            return false;
        }
        try {
            Bus::dispatch(new ReconcileStudentControlledPayment($paymentId));
        } catch (Throwable $exception) {
            $lock->release();
            throw $exception;
        }

        return true;
    }

    public function dispatch(): int
    {
        $institution = $this->institutions->require();
        $cursorKey = 'student-receipt-sweep:'.$institution->id;
        $cursor = Cache::store('database')->get($cursorKey);
        $payments = StudentControlledPayment::query()->whereNotNull('posted_at')->whereNotNull('tx_hash')
            ->whereNotNull('tuition_account_id')
            ->whereHas('student', fn ($query) => $query->where('organization_id', $institution->id))
            ->when(is_string($cursor), fn ($query) => $query->where('id', '>', $cursor))
            ->orderBy('id')->limit(100)->get();
        $count = 0;
        foreach ($payments as $payment) {
            $count += (int) $this->dispatchPayment($payment->id);
        }

        if ($payments->count() < 100) {
            Cache::store('database')->forget($cursorKey);
        } else {
            Cache::store('database')->put($cursorKey, $payments->last()->id, now()->addDay());
        }

        return $count;
    }
}
