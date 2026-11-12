<?php

return [

    // Passwords are hashed with bcrypt (salted, adaptive). Rounds are raised over time; hashes
    // created with fewer rounds are transparently upgraded on the next successful login check.
    'driver' => 'bcrypt',

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

];
