<?php

namespace App\Services\Social\Data;

/**
 * A video to publish. `videoUrl` must be a URL produced by our own storage layer (for example a
 * short-lived signed URL), never a user-supplied address.
 */
final readonly class VideoPublication
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $videoUrl,
        public string $title,
        public string $description = '',
        public array $tags = [],
        public string $privacy = 'private',
    ) {}

    /** Description followed by hashtags, as most platforms expect them in one caption. */
    public function caption(): string
    {
        $hashtags = array_map(
            fn (string $tag): string => '#'.ltrim(preg_replace('/\s+/', '', $tag) ?? '', '#'),
            array_filter($this->tags, fn (string $tag): bool => trim($tag) !== ''),
        );

        return trim($this->description.($hashtags === [] ? '' : "\n\n".implode(' ', $hashtags)));
    }
}
