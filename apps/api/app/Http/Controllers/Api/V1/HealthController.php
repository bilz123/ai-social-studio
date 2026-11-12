<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Health\HealthReporter;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Liveness: the process is up. Touches no dependency, so it is safe to poll frequently.
     */
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'api',
            'api_version' => 'v1',
        ]);
    }

    /**
     * Readiness: dependencies (database, Redis) are usable. Returns 503 when any check fails.
     * The body carries only ok/fail per check; failure details are logged, never returned.
     */
    public function ready(HealthReporter $reporter): JsonResponse
    {
        $report = $reporter->run();

        return response()->json($report->toArray(), $report->healthy() ? 200 : 503);
    }
}
