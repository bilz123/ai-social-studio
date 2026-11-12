<?php

use App\Support\Health\DatabaseCheck;
use App\Support\Health\RedisCheck;

return [

    // Checks run by GET /api/v1/health/ready and `php artisan health:check`.
    'checks' => array_values(array_filter([
        DatabaseCheck::class,
        env('HEALTH_CHECK_REDIS', true) ? RedisCheck::class : null,
    ])),

];
