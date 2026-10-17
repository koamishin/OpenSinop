<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\JevHealthService;
use App\Settings\AiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class JevHealthController extends Controller
{
    /**
     * Check real-time connectivity and status of JEV engine & AI agent.
     */
    public function check(
        Request $request,
        InstallationInstitution $institutions,
        JevHealthService $healthService
    ): JsonResponse {
        $actor = $request->user();
        abort_unless(
            $actor instanceof User && $actor->hasAnyRole(['admin', 'super_admin', 'finance_officer', 'finance_supervisor']),
            403
        );

        $institution = $institutions->require();
        $forcePing = $request->boolean('force_ping', true);

        $health = $healthService->inspect($institution, $forcePing);

        return response()->json([
            'status' => 'success',
            'data' => $health,
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Toggle advisory without accepting provider disclosure on behalf of an operator.
     */
    public function toggleAdvisory(
        Request $request,
        InstallationInstitution $institutions,
        AiSettings $settings,
        JevHealthService $healthService
    ): JsonResponse {
        $actor = $request->user();
        abort_unless(
            $actor instanceof User && $actor->hasAnyRole(['admin', 'super_admin']),
            403
        );

        $enable = $request->boolean('enable', true);

        $settings->advisory_enabled = $enable;
        $settings->save();

        $institution = $institutions->require();
        $health = $healthService->inspect($institution, true);

        return response()->json([
            'status' => 'success',
            'message' => $enable ? 'AI Advisory enabled successfully.' : 'AI Advisory disabled.',
            'data' => $health,
        ])->header('Cache-Control', 'no-store, private');
    }
}
