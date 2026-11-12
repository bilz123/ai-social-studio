<?php

/*
|--------------------------------------------------------------------------
| Social media integrations (Facebook Pages, Instagram, YouTube)
|--------------------------------------------------------------------------
| Credentials come only from the environment (see docs/SOCIAL_PROVIDER_SETUP.md). When a
| provider's credentials are empty its integration reports "not configured" and refuses to start
| an OAuth flow; nothing is ever simulated.
*/

return [

    // The SPA receives the provider redirect and forwards code + state to the API, because the
    // session cookie belongs to the SPA origin. Register this exact URL (per platform) with each
    // provider as an OAuth redirect URI.
    'redirect_path' => '/connections/callback/{platform}',

    // How long the user has to finish the consent screen. State is single use.
    'state_ttl_minutes' => 10,

    // Re-check every connected account at most this often (scheduler runs daily).
    'check_interval_hours' => 20,

    'http' => [
        'timeout' => 20,
        'connect_timeout' => 5,
    ],

    'providers' => [

        'facebook' => [
            'label' => 'Facebook',
            'client_id' => env('META_APP_ID'),
            'client_secret' => env('META_APP_SECRET'),
            'env' => ['client_id' => 'META_APP_ID', 'client_secret' => 'META_APP_SECRET'],
            'graph_version' => env('META_GRAPH_VERSION', 'v26.0'),
            'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'],
            // Page access tokens obtained from a long-lived user token do not expire.
            'refresh_ahead_minutes' => null,
        ],

        'instagram' => [
            'label' => 'Instagram',
            'client_id' => env('INSTAGRAM_APP_ID'),
            'client_secret' => env('INSTAGRAM_APP_SECRET'),
            'env' => ['client_id' => 'INSTAGRAM_APP_ID', 'client_secret' => 'INSTAGRAM_APP_SECRET'],
            'graph_version' => env('INSTAGRAM_GRAPH_VERSION', env('META_GRAPH_VERSION', 'v26.0')),
            'scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],
            // Long-lived tokens last 60 days; refresh once fewer than 14 days remain.
            'refresh_ahead_minutes' => 14 * 24 * 60,
            // Reels are processed asynchronously: poll the container until it is FINISHED.
            'publish' => ['poll_attempts' => 24, 'poll_seconds' => 5],
        ],

        'youtube' => [
            'label' => 'YouTube',
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'env' => ['client_id' => 'GOOGLE_CLIENT_ID', 'client_secret' => 'GOOGLE_CLIENT_SECRET'],
            'scopes' => [
                'https://www.googleapis.com/auth/youtube.upload',
                'https://www.googleapis.com/auth/youtube.readonly',
            ],
            // Access tokens last about an hour: refresh when five minutes remain.
            'refresh_ahead_minutes' => 5,
            // Informational: the default YouTube Data API upload allowance per project and day.
            'uploads_per_day' => (int) env('YOUTUBE_UPLOADS_PER_DAY', 100),
        ],

    ],

];
