<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AssessAssistanceDecision;
use App\Models\AgentDecision;
use App\Models\AssistanceRequest;
use App\Services\InstallationInstitution;
use App\Settings\AiSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class DispatchAssistanceAdvice extends Command
{
    protected $signature = 'app:dispatch-assistance-advice';

    protected $description = 'Recover queued assistance commentary only; never authorizes or sends funds';

    public function handle(InstallationInstitution $institutions, AiSettings $settings): int
    {
        $institution = $institutions->require();
        $stale = AgentDecision::query()->where('organization_id', $institution->id)
            ->where('reference_type', AssistanceRequest::class)
            ->where('metadata->assistance_advice->state', 'running')
            ->orderBy('id')->limit(100)->get();
        foreach ($stale as $candidate) {
            DB::transaction(function () use ($candidate, $institution): void {
                $decision = AgentDecision::query()->where('organization_id', $institution->id)
                    ->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                $progress = $decision->metadata['assistance_advice'] ?? [];
                $updatedAt = $progress['updated_at'] ?? null;
                if (($progress['state'] ?? null) === 'running' && is_string($updatedAt)
                    && Carbon::parse($updatedAt)->lte(now()->subMinutes(5))) {
                    $decision->update(['metadata' => [...($decision->metadata ?? []),
                        'assistance_advice' => ['state' => 'needs_review', 'updated_at' => now()->toIso8601String(),
                            'reason' => 'Provider work interrupted or exceeded observation window. No automatic replay.']]]);
                }
            });
        }
        if (! $settings->mayCallProvider()) {
            $this->info('Assistance advice disabled; no data sent.');

            return self::SUCCESS;
        }
        $connection = (string) config('queue.default');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)
            || (int) config('queue.connections.'.$connection.'.retry_after', 0) <= 120) {
            $this->error('Assistance advice requires durable queue and retry_after greater than 120 seconds.');

            return self::FAILURE;
        }
        $decisions = AgentDecision::query()->where('organization_id', $institution->id)
            ->where('reference_type', AssistanceRequest::class)->where('metadata->assistance_advice->state', 'queued')->orderBy('id')->limit(100)->get();
        foreach ($decisions as $decision) {
            Bus::dispatch(new AssessAssistanceDecision($decision->id));
        }
        $this->info('Assistance advice jobs dispatched: '.$decisions->count().'. No funds moved.');

        return self::SUCCESS;
    }
}
