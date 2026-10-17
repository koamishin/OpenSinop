<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CollectionBatch;
use App\Models\FinanceWorkflowRun;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\ReceiptPlanTrigger;
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
        $review = $batch->reviews()->first();
        $approved = $review !== null && $review->decision === 'approve_receipts' && $review->hasValidEvidence($batch);
        $steps[] = ['stage' => 'receipt_review', 'message' => $approved
            ? 'Independent finance receipt review recorded.' : 'Waiting for independent finance receipt review.',
            'status' => $approved ? 'completed' : 'waiting', 'at' => $review?->created_at?->toIso8601String()];
        if ($approved) {
            $trigger = ReceiptPlanTrigger::query()->where('organization_id', $batch->organization_id)
                ->where('collection_batch_review_id', $review->id)->first();
            $run = $trigger?->budget_snapshot_id === null ? null : FinanceWorkflowRun::query()
                ->where('organization_id', $batch->organization_id)->where('budget_snapshot_id', $trigger->budget_snapshot_id)->first();
            $validPlan = $run !== null && $run->hasValidResult();
            $steps[] = ['stage' => 'planning', 'message' => match (true) {
                $trigger === null => 'No approved receipt-to-budget route. Finance must supply planning context.',
                $trigger->state === 'blocked' => 'Planning evidence needs finance review. No money moved.',
                $run !== null && in_array($run->state, ['blocked', 'failed'], true) => 'Background planning paused for finance review.',
                $validPlan && $run->state === 'waiting_for_review' => 'Budget proposal ready for finance review. No payment authorized.',
                $validPlan && $run->state === 'completed' => 'Finance planning completed. Spending authorization remains separate.',
                default => 'Approved receipt routing queued for background planning.',
            }, 'status' => match (true) {
                $trigger?->state === 'blocked', $run !== null && in_array($run->state, ['blocked', 'failed'], true) => 'held',
                $validPlan => 'completed', default => 'waiting',
            }, 'at' => $run?->heartbeat_at?->toIso8601String()];
        }
        $steps[] = ['stage' => 'authorization', 'message' => 'Finance must independently review receipts, planning evidence and spending authorization. No automated disbursement is authorized by this payment.', 'status' => 'waiting', 'at' => null];

        return $steps;
    }
}
