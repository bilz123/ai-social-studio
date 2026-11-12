<?php

namespace App\Support\Health;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the checks listed in config('health.checks'). A failing check is logged in full (with
 * the request id) but reported to callers only as "fail", so nothing about the infrastructure
 * (hostnames, connection strings) is exposed by a health endpoint.
 */
class HealthReporter
{
    public function run(): HealthReport
    {
        $results = [];

        foreach (config('health.checks', []) as $class) {
            /** @var HealthCheck $check */
            $check = app($class);
            $startedAt = hrtime(true);

            try {
                $check->check();

                $results[$check->name()] = [
                    'status' => 'ok',
                    'latency_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                ];
            } catch (Throwable $e) {
                Log::error('readiness_check_failed', [
                    'check' => $check->name(),
                    'exception' => $e,
                ]);

                $results[$check->name()] = ['status' => 'fail'];
            }
        }

        return new HealthReport($results);
    }
}
