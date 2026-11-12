<?php

namespace App\Console\Commands;

use App\Services\Social\SocialIntegrationRegistry;
use Illuminate\Console\Command;

class SocialCheckConfigCommand extends Command
{
    protected $signature = 'social:check-config {--strict : Exit with an error when any platform is not configured}';

    protected $description = 'Show which social platforms have developer credentials and the redirect URLs to register';

    public function handle(SocialIntegrationRegistry $integrations): int
    {
        $rows = [];
        $missingAny = false;

        foreach ($integrations->all() as $integration) {
            $missing = $integration->missingConfiguration();
            $missingAny = $missingAny || $missing !== [];

            $rows[] = [
                $integration->label(),
                $missing === [] ? 'configured' : 'missing '.implode(', ', $missing),
                rtrim((string) config('api.frontend_url'), '/').str_replace('{platform}', $integration->platform()->slug(), (string) config('social.redirect_path')),
                implode(' ', array_map(fn (string $s): string => basename($s), $integration->requiredScopes())),
            ];
        }

        $this->table(['Platform', 'Credentials', 'Redirect URL to register', 'Permissions'], $rows);

        if ($missingAny) {
            $this->warn('Some platforms are not configured. See docs/SOCIAL_PROVIDER_SETUP.md.');
        }

        return $missingAny && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
