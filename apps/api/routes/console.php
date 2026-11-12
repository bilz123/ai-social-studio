<?php

use App\Models\OAuthState;
use Illuminate\Support\Facades\Schedule;

// Class-based commands in app/Console/Commands are discovered automatically.

// Renew expiring social tokens and detect revoked connections (runs through `schedule:work`).
Schedule::command('social:refresh-tokens')->dailyAt('03:15')->withoutOverlapping()->onOneServer();

// Remove expired OAuth state rows.
Schedule::command('model:prune', ['--model' => [OAuthState::class]])->dailyAt('03:45')->onOneServer();
