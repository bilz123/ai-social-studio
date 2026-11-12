<?php

namespace App\Support\Health;

use Illuminate\Support\Facades\Redis;

class RedisCheck implements HealthCheck
{
    public function name(): string
    {
        return 'redis';
    }

    public function check(): void
    {
        Redis::connection('default')->ping();
    }
}
