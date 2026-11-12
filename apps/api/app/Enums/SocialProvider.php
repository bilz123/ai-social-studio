<?php

namespace App\Enums;

/** The company whose OAuth system issued the credentials (one set of developer credentials each). */
enum SocialProvider: string
{
    /** Facebook Login (Facebook Pages). */
    case Meta = 'meta';
    /** Instagram API with Instagram Login (professional accounts). */
    case Instagram = 'instagram';
    /** Google OAuth 2.0 (YouTube). */
    case Google = 'google';
}
