<?php

namespace App\Contracts\Social;

use App\Exceptions\SocialIntegrationException;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Services\Social\Data\PublishedVideo;
use App\Services\Social\Data\VideoPublication;

/** Implemented by integrations that can publish a video to a connected account. */
interface PublishesVideo
{
    /** @throws SocialIntegrationException */
    public function publishVideo(SocialAccount $account, SocialToken $token, VideoPublication $video): PublishedVideo;
}
