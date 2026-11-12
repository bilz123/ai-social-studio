<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OAuth credentials for a social account. access_token / refresh_token hold ciphertext
        // (Laravel "encrypted" cast, AES-256 with APP_KEY), hence TEXT columns that cannot be
        // indexed or searched. Rows are removed with the account / user.
        Schema::create('social_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('token_kind', 16)->default('access');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->string('token_type', 24)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('provider_user_id', 64)->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'token_kind']);
            // Scans for tokens about to expire.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_tokens');
    }
};
