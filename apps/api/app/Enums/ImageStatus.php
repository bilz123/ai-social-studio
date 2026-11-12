<?php

namespace App\Enums;

enum ImageStatus: string
{
    case PendingUpload = 'pending_upload';
    case Uploaded = 'uploaded';
    case Validated = 'validated';
    case Rejected = 'rejected';
}
