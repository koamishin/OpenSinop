<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Advisory\AdvisoryGate;
use App\Models\AgentDecision;
use App\Models\AssistanceRequest;
use App\Models\PaymentIntent;
use App\Settings\AiSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Optional commentary only. No signer, recipient, authorization or amount writes. */
class AssessAssistanceDecision implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $decisionId)
    {
        $this->afterCommit();
    }

    public function handle(AdvisoryGate $gate, AiSettings $settings): void
    {
        if (! $settings->mayCallProvider()) {
            return;
        }
        $claim = DB::transaction(function (): ?array {
            $decision = AgentDecision::query()->whereKey($this->decisionId)->lockForUpdate()->firstOrFail();
            if (($decision->metadata['assistance_advice']['state'] ?? null) !== 'queued'
                || $decision->reference_type !== AssistanceRequest::class) {
                return null;
            }
            $request = AssistanceRequest::query()->findOrFail($decision->reference_id);
            $student = $request->student;
            if ($student === null || $student->organization_id !== $decision->organization_id) {
                $this->finish($decision, 'needs_review');

                return null;
            }
            $context = ['reason' => (string) $request->reason,
                'category' => $request->category instanceof \BackedEnum ? $request->category->value : (string) $request->category,
                'attendance_rate' => (float) ($student->attendance_rate ?? 0),
                'enrollment_status' => $student->enrollment_status, 'academic_status' => $student->academic_status];
            $this->finish($decision, 'running');

            return ['context' => $context, 'digest' => PaymentIntent::digest($decision->input_snapshot),
                'request_digest' => PaymentIntent::digest($request->only(['student_id', 'reason', 'category', 'requested_amount']))];
        });
        if ($claim === null) {
            return;
        }
        $advice = $gate->assessHardship($claim['context']);
        DB::transaction(function () use ($advice, $claim): void {
            $decision = AgentDecision::query()->whereKey($this->decisionId)->lockForUpdate()->firstOrFail();
            if (($decision->metadata['assistance_advice']['state'] ?? null) !== 'running') {
                return;
            }
            $request = AssistanceRequest::query()->find($decision->reference_id);
            $currentRequestDigest = $request === null ? '' : PaymentIntent::digest($request->only(['student_id', 'reason', 'category', 'requested_amount']));
            if ($request === null || $request->student?->organization_id !== $decision->organization_id
                || ! hash_equals($claim['request_digest'], $currentRequestDigest)
                || $advice === null || ! app(AiSettings::class)->mayCallProvider() || ! hash_equals($claim['digest'], PaymentIntent::digest($decision->input_snapshot))) {
                $this->finish($decision, 'needs_review');

                return;
            }
            $decision->update(['metadata' => $advice->mergeIntoMetadata($decision->metadata ?? [])]);
            $this->finish($decision, 'completed');
        });
    }

    private function finish(AgentDecision $decision, string $state): void
    {
        $decision->update(['metadata' => [...($decision->metadata ?? []), 'assistance_advice' => ['state' => $state, 'updated_at' => now()->toIso8601String()]]]);
    }

    public function failed(?Throwable $exception): void
    {
        $decision = AgentDecision::query()->find($this->decisionId);
        if ($decision !== null && ($decision->metadata['assistance_advice']['state'] ?? null) === 'running') {
            $this->finish($decision, 'needs_review');
        }
        if ($exception !== null) {
            report($exception);
        }
    }
}
