<?php

return [

    // Requests per minute per client (signed-in user id, otherwise IP) across /api/*.
    'rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 120),

    // Public origin of the SPA. Links in emails (verify address, reset password) point here.
    'frontend_url' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost:8080')), '/'),

];
