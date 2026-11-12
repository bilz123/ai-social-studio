<?php

namespace App\Enums;

enum ScheduledPostStatus: string
{
    case Scheduled = 'scheduled';
    case Dispatched = 'dispatched';
    case Canceled = 'canceled';
    case Failed = 'failed';
}
