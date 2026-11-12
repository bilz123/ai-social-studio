<?php

namespace App\Enums;

enum PublishingStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Published = 'published';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
