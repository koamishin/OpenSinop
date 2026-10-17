<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CollectionBatch;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\Organization;
use App\Models\StudentControlledPayment;
use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyEvaluations;
use App\Services\InstitutionPolicyWorkspace;
use App\Services\StudentControlledCheckout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Ramsey\Uuid\Uuid;
use Throwable;

/** Records existing settlement only. Holds no signer or approval capability. */
class ReconcileStudentControlledPayment implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public string $paymentId)
    {
        $this->afterCommit();
    }

    public function handle(StudentControlledCheckout $checkout, InstallationInstitution $institutions,
        InstitutionPolicyEvaluations $evaluations, InstitutionPolicyWorkspace $policies): void
    {
        $payment = StudentControlledPayment::query()->findOrFail($this->paymentId);
        $student = $payment->student()->firstOrFail();
        $institutionId = $student->organization_id ?? $student->user->organization_id;
        if ($institutionId === null) {
            throw new \RuntimeException('Assign institution ownership before background reconciliation.');
        }
        $institutions->setExplicitOrganization(Organization::query()->findOrFail($institutionId));
        try {
            $checkout->recordSettlement($payment);
            $record = $payment->tuitionPayment()->first();
            if ($record === null) {
                return;
            }
            $batch = CollectionBatch::query()->where('student_tuition_payment_id', $record->id)->first();
            $actor = User::query()->find(config('eduflow.autonomous_pipeline.reviewer_id'));
            if ($batch === null || ! $actor instanceof User || $actor->organization_id !== $institutionId
                || $actor->id === $student->user_id || ! $actor->hasVerifiedEmail()
                || ! $actor->hasAnyRole(['admin', 'super_admin'])) {
                throw new \RuntimeException('Receipt or classification requester missing. Configure an existing verified institution admin; no roles or approvals were created.');
            }
            $publication = $policies->active();
            if ($publication === null) {
                throw new \RuntimeException('No active published institution policy; classification cannot start.');
            }
            $source = $evaluations->source($actor, 'collection', $batch->id);
            $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'student-receipt:'.$payment->id.':'.$source['source_digest'].':'.$publication->publication_digest)->toString();
            if (InstitutionPolicyEvaluation::query()->where('request_key', $key)->exists()) {
                return;
            }
            $evaluations->request($actor, ['request_key' => $key, 'source_type' => 'collection', 'source_id' => $batch->id,
                'source_digest' => $source['source_digest'], 'expected_publication_digest' => $publication->publication_digest,
                'reason' => 'Configured background receipt classification. No review or spending authority granted.']);
        } finally {
            $institutions->setExplicitOrganization(null);
        }
    }

    public function failed(?Throwable $exception): void
    {
        report($exception ?? new \RuntimeException('Student receipt reconciliation stopped; recover through app:backfill-student-controlled-payments. Never resend transfer.'));
    }
}
