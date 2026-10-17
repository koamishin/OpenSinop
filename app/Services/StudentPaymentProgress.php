<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CollectionBatch;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\StudentControlledPayment;

/** Student-safe projection of persisted facts. Reading progress never advances financial work. */
class StudentPaymentProgress
{
    /** @return list<array{stage:string, message:string, status:string, at:?string}> */
    public function forPayment(StudentControlledPayment $payment): array
    {
        $steps = [];
        $steps[] = ['stage' => 'wallet_approval', 'message' => 'Approve this transfer in your wallet.',
            'status' => $payment->tx_hash !== null ? 'completed' : 'waiting',
            'at' => $payment->approval_started_at?->toIso8601String()];
        $steps[] = ['stage' => 'settlement', 'message' => $payment->posted_at !== null
            ? 'Transfer verified and credited. Institution processing cannot undo your tuition credit.'
            : 'Waiting for matching on-chain settlement. Do not send another transfer.',
            'status' => $payment->posted_at !== null ? 'completed' : 'waiting', 'at' => $payment->posted_at?->toIso8601String()];
        if ($payment->posted_at === null) {
            return $steps;
        }

        $record = $payment->tuitionPayment ?? $payment->billPayment;
        $batch = $record === null ? null : CollectionBatch::query()
            ->where($payment->tuition_account_id !== null ? 'student_tuition_payment_id' : 'student_bill_payment_id', $record->id)->first();
        $steps[] = ['stage' => 'receipt', 'message' => $batch === null
            ? 'Receipt reconciliation pending. Finance may need to repair configuration; your payment remains credited.'
            : 'Institution receipt recorded. Independent finance review remains required.',
            'status' => $batch === null ? 'waiting' : 'completed', 'at' => null];
        if ($batch === null) {
            return $steps;
        }
        $evaluation = InstitutionPolicyEvaluation::query()->where('organization_id', $batch->organization_id)
            ->where('source_type', 'collection')->where('source_id', $batch->id)->latest('id')->first();
        if ($evaluation === null) {
            $steps[] = ['stage' => 'classification', 'message' => 'Classification not started. Authorized finance configuration is required.', 'status' => 'waiting', 'at' => null];
        } else {
            $legacyRules = str_contains(json_encode($evaluation->result['decision_process'] ?? [], JSON_THROW_ON_ERROR), 'deterministic rule engine');
            if ($legacyRules || ($evaluation->state === 'completed' && ! $evaluation->hasValidResult())) {
                $steps[] = ['stage' => 'classification', 'message' => 'Earlier classification needs fresh finance review. No verified model result is available.', 'status' => 'held', 'at' => null];

                return $steps;
            }
            foreach ($evaluation->timeline as $event) {
                $message = match ($event['stage']) {
                    'queued' => 'Policy classification queued for background processing.',
                    'evidence_checks' => 'Checking recorded receipt and published policy evidence.',
                    'classifying' => 'Matching receipt against saved policy categories.',
                    'citations_checked' => 'Published policy citations checked.',
                    'safety_checks' => 'Checking evidence integrity. Classification cannot authorize spending.',
                    'completed' => 'Advisory classification finished.',
                    'needs_review' => 'Finance review required. Student payment remains credited.',
                    default => null,
                };
                if ($message === null) {
                    continue;
                }
                $steps[] = ['stage' => $event['stage'], 'message' => $message,
                    'status' => $event['stage'] === 'needs_review' ? 'held' : 'completed', 'at' => $event['at']];
            }
            $steps[] = ['stage' => 'classification', 'message' => match ($evaluation->state) {
                'queued' => 'Classification queued. Waiting for background worker.',
                'running' => 'JEV is matching evidence against published policy.',
                'completed' => 'Policy classification recorded. Advisory only, not spending approval.',
                default => 'Classification held for finance review. No institution payment authorized.',
            }, 'status' => match ($evaluation->state) {
                'completed' => 'completed', 'running' => 'running', 'queued' => 'waiting', default => 'held',
            }, 'at' => $evaluation->completed_at?->toIso8601String()];
        }
        $steps[] = ['stage' => 'authorization', 'message' => 'Finance must independently review receipts, planning evidence and spending authorization. No automated disbursement is authorized by this payment.', 'status' => 'waiting', 'at' => null];

        return $steps;
    }
}
