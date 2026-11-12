<?php

use App\Support\Database\SqlCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A publishable destination (Facebook Page, Instagram business account, YouTube channel).
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('platform', 32);
            $table->string('external_id', 64);
            $table->string('name', 191);
            $table->string('username', 191)->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('status', 24)->default('active');
            $table->json('capabilities')->nullable();
            $table->timestamp('last_publish_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'platform', 'external_id']);
            // Lets child tables prove, with a composite foreign key, that they reference an account
            // owned by the same user.
            $table->unique(['id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        SqlCheck::add('social_accounts', 'chk_social_accounts_provider', "provider IN ('meta','google')");
        SqlCheck::add('social_accounts', 'chk_social_accounts_platform', "platform IN ('facebook_page','instagram_business','youtube_channel')");
        SqlCheck::add('social_accounts', 'chk_social_accounts_status', "status IN ('active','needs_reconnect','revoked','restricted')");
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
