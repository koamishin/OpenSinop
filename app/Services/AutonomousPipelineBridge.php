<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StudentTuitionPayment;
use Illuminate\Validation\ValidationException;

/** Read-only handoff to existing staff-reviewed finance workflows. Never approves or executes. */
final readonly class AutonomousPipelineBridge
{
    public function __construct(private InstallationInstitution $institutions) {}

    /**
     * Report required next human review for a settled student tuition payment.
     *
     * @param  StudentTuitionPayment|int  $payment  The verified student payment or its ID.
     * @param  int|null  $targetInvoiceId  Retained for command compatibility; never authorizes a vendor payment.
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function bridgePayment(StudentTuitionPayment|int $payment, ?int $targetInvoiceId = null): array
    {
        $payment = is_int($payment) ? StudentTuitionPayment::query()->findOrFail($payment) : $payment;
        $institution = $this->institutions->require();
        $wallet = $institution->primaryWallet();
        if (! $payment->isReal() || $wallet === null
            || strcasecmp($payment->recipient_address, $wallet->address) !== 0
            || ($payment->student->organization_id !== null && $payment->student->organization_id !== $institution->id)) {
            throw ValidationException::withMessages(['payment' => 'Verified payment must belong to this institution treasury.']);
        }

        $batch = $payment->collections()->first();
        if ($batch === null || $batch->organization_id !== $institution->id || ! $batch->hasValidSnapshot()) {
            throw ValidationException::withMessages(['receipt' => 'Reconcile this movement before requesting policy classification.']);
        }

        $review = $batch->reviews()->first();
        $reviewed = $review !== null && $review->decision === 'approve_receipts' && $review->hasValidEvidence($batch);

        return [
            'success' => true,
            'stage' => $reviewed ? 'awaiting_manual_authorization' : 'awaiting_receipt_review',
            'message' => 'Classification is advisory. Independent staff review, budget evidence and payment authorization remain required.',
            'payment_id' => $payment->id,
            'collection_batch_id' => $batch->id,
            'settled' => false,
        ];
    }

    /**
     * Bridge the latest un-reconciled or most recent settled student payment.
     *
     * @return array<string, mixed>
     */
    public function bridgeLatest(?int $targetInvoiceId = null): array
    {
        $institution = $this->institutions->current();
        $wallet = $institution?->primaryWallet();
        $treasury = (string) config('lepton.arc.treasury');
        if ($treasury === '' && $wallet !== null) {
            $treasury = $wallet->address;
        }

        /** @var StudentTuitionPayment|null $payment */
        $payment = StudentTuitionPayment::query()
            ->where('simulated', false)
            ->where('tx_verified_on_chain', true)
            ->where('recipient_address', $treasury)
            ->whereDoesntHave('collections')
            ->orderByDesc('executed_at')
            ->first();

        if ($payment === null) {
            $payment = StudentTuitionPayment::query()
                ->where('simulated', false)
                ->where('tx_verified_on_chain', true)
                ->where('recipient_address', $treasury)
                ->orderByDesc('executed_at')
                ->first();
        }

        if ($payment === null) {
            throw ValidationException::withMessages(['payment' => 'No eligible settled student tuition payment found to bridge.']);
        }

        return $this->bridgePayment($payment, $targetInvoiceId);
    }
}
