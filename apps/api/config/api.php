<?php

return [

    // Requests per minute per client IP across /api/*.
    'rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 120),

];
