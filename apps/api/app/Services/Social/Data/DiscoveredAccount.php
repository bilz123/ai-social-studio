<?php

namespace App\Services\Social\Data;

/** A destination found during authorization, together with the credentials that control it. */
final readonly class DiscoveredAccount
{
    public function __construct(
        public AccountSnapshot $snapshot,
        public TokenSet $tokens,
    ) {}
}
