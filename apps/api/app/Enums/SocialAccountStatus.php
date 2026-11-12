<?php

namespace App\Enums;

enum SocialAccountStatus: string
{
    case Active = 'active';
    case NeedsReconnect = 'needs_reconnect';
    case Revoked = 'revoked';
    case Restricted = 'restricted';
}
