<?php

namespace App\Support\Health;

interface HealthCheck
{
    /**
     * Stable lowercase identifier, used as the key in readiness output.
     */
    public function name(): string;

    /**
     * Return normally when the dependency is usable.
     *
     * @throws \Throwable when it is not
     */
    public function check(): void;
}
