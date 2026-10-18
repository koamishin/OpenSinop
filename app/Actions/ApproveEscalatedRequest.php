<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AssistanceStatus;
use App\Models\AgentDecision;
use App\Models\Approval;
use App\Models\AssistanceFund;
use App\Models\AssistanceRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ApproveEscalatedRequest
{
    public function handle(
        AssistanceRequest $request,
        AgentDecision $decision,
        User $approver,
        AssistanceFund $fund,
        ?string $comment = null,
        bool $forceTuitionOffset = false,
    ): Approval {
        if (! $approver->hasVerifiedEmail() || ! $approver->hasAnyRole(['admin', 'super_admin', 'finance_officer'])
            || $approver->organization_id !== $fund->organization_id
            || $request->student?->organization_id !== $fund->organization_id
            || $request->student->user_id === $approver->id
            || $decision->organization_id !== $fund->organization_id
            || $decision->reference_type !== AssistanceRequest::class || $decision->reference_id !== $request->id) {
            throw new AuthorizationException('Independent institution finance review required.');
        }
        if ($forceTuitionOffset) {
            throw new InvalidArgumentException('Internal tuition credit requires a separate exact authorized credit workflow. No payment or tuition credit recorded.');
        }

        return DB::transaction(function () use ($request, $decision, $approver, $fund, $comment): Approval {
            $stored = AssistanceRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $decision = AgentDecision::query()->whereKey($decision->id)->lockForUpdate()->firstOrFail();
            if ($decision->organization_id !== $fund->organization_id || $decision->reference_type !== AssistanceRequest::class
                || $decision->reference_id !== $stored->id || $stored->student?->organization_id !== $fund->organization_id) {
                throw new AuthorizationException('Assistance ownership changed before review.');
            }
            $existing = Approval::query()->where('agent_decision_id', $decision->id)->first();
            if ($existing !== null && $existing->status === 'approved') {
                if ($existing->approver_id !== $approver->id) {
                    throw new AuthorizationException('Review belongs to another officer.');
                }

                return $existing;
            }
            if ($decision->status !== 'escalated' || ! $decision->requires_approval) {
                throw new InvalidArgumentException('No escalated decision awaits review.');
            }
            $approval = Approval::updateOrCreate(['agent_decision_id' => $decision->id], [
                'organization_id' => $fund->organization_id, 'approver_id' => $approver->id,
                'status' => 'approved', 'comment' => $comment ?? 'Eligibility reviewed; payment authorization remains required.', 'approved_at' => now(),
            ]);
            $decision->update(['status' => 'requires_authorized_workflow']);
            $stored->update(['status' => AssistanceStatus::IN_PROGRESS, 'resolved_at' => null,
                'admin_notes' => 'Finance eligibility review recorded. No funds disbursed or tuition credit applied. Separate payment authorization and verified settlement required.']);

            return $approval;
        });
    }
}
