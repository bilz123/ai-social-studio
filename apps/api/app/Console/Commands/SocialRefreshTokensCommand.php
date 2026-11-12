<?php

namespace App\Console\Commands;

use App\Enums\SocialAccountStatus;
use App\Exceptions\SocialIntegrationException;
use App\Models\SocialAccount;
use App\Services\Social\SocialTokenManager;
use Illuminate\Console\Command;

class SocialRefreshTokensCommand extends Command
{
    protected $signature = 'social:refresh-tokens {--all : Check every account, not only those not checked recently}';

    protected $description = 'Renew expiring social tokens and verify every connected account with its provider';

    public function handle(SocialTokenManager $tokens): int
    {
        $cutoff = now()->subHours((int) config('social.check_interval_hours'));
        $checked = $reconnect = $skipped = 0;

        SocialAccount::query()
            ->whereIn('status', [SocialAccountStatus::Active->value, SocialAccountStatus::Restricted->value])
            ->when(! $this->option('all'), fn ($query) => $query->where(
                fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', $cutoff),
            ))
            ->chunkById(100, function ($accounts) use ($tokens, &$checked, &$reconnect, &$skipped): void {
                foreach ($accounts as $account) {
                    try {
                        $tokens->check($account);
                        $checked++;
                    } catch (SocialIntegrationException $e) {
                        // The manager already recorded the outcome; temporary failures retry tomorrow.
                        $e->requiresReconnect() ? $reconnect++ : $skipped++;
                    }
                }
            });

        $this->info("Checked {$checked}; need reconnect {$reconnect}; skipped (temporary error) {$skipped}.");

        return self::SUCCESS;
    }
}
