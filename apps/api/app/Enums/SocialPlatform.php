<?php

namespace App\Enums;

enum SocialPlatform: string
{
    case FacebookPage = 'facebook_page';
    case InstagramBusiness = 'instagram_business';
    case YoutubeChannel = 'youtube_channel';

    /** The short name used in URLs and config ("facebook", "instagram", "youtube"). */
    public function slug(): string
    {
        return match ($this) {
            self::FacebookPage => 'facebook',
            self::InstagramBusiness => 'instagram',
            self::YoutubeChannel => 'youtube',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_map(fn (self $case): string => $case->slug(), self::cases());
    }

    public function provider(): SocialProvider
    {
        return match ($this) {
            self::FacebookPage => SocialProvider::Meta,
            self::InstagramBusiness => SocialProvider::Instagram,
            self::YoutubeChannel => SocialProvider::Google,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FacebookPage => 'Facebook',
            self::InstagramBusiness => 'Instagram',
            self::YoutubeChannel => 'YouTube',
        };
    }
}
