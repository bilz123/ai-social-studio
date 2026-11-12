<?php

namespace App\Services\Social\Data;

final readonly class PublishedVideo
{
    public function __construct(
        public string $externalId,
        public ?string $url = null,
    ) {}
}
