<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Archived = 'archived';
}
