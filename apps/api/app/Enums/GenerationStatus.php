<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Queued = 'queued';
    case Analyzing = 'analyzing';
    case Planning = 'planning';
    case Generating = 'generating';
    case Postprocessing = 'postprocessing';
    case ReadyForReview = 'ready_for_review';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
