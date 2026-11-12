<?php

use App\Support\Database\SqlCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            // Why an account is not "active" (shown to the user), and when it was last verified.
            $table->string('status_reason', 255)->nullable()->after('status');
            $table->timestamp('last_checked_at')->nullable()->after('last_publish_at');
            // Provider facts shown on the account card (followers, subscribers, account type, …).
            $table->json('profile')->nullable()->after('capabilities');
        });

        // Instagram Login has its own app and tokens, so it is its own provider.
        SqlCheck::drop('social_accounts', 'chk_social_accounts_provider');
        SqlCheck::add('social_accounts', 'chk_social_accounts_provider', "provider IN ('meta','instagram','google')");
    }

    public function down(): void
    {
        // Instagram rows could not satisfy the narrower constraint, so they are removed first.
        DB::table('social_accounts')->where('provider', 'instagram')->delete();

        SqlCheck::drop('social_accounts', 'chk_social_accounts_provider');
        SqlCheck::add('social_accounts', 'chk_social_accounts_provider', "provider IN ('meta','google')");

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['status_reason', 'last_checked_at', 'profile']);
        });
    }
};
