<?php

namespace App\Support\Health;

readonly class HealthReport
{
    /**
     * @param  array<string, array{status: string, latency_ms?: float}>  $checks
     */
    public function __construct(public array $checks) {}

    public function healthy(): bool
    {
        foreach ($this->checks as $result) {
            if ($result['status'] !== 'ok') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{status: string, checks: array<string, array{status: string, latency_ms?: float}>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->healthy() ? 'ok' : 'degraded',
            'checks' => $this->checks,
        ];
    }
}
