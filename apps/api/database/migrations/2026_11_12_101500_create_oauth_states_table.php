<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per started OAuth authorization. Only the SHA-256 of the random `state` value is
        // stored, so a database leak cannot be replayed against the callback. The PKCE verifier is
        // encrypted. Rows are single use (consumed_at) and short lived (expires_at).
        Schema::create('oauth_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('state_hash', 64)->unique();
            $table->string('platform', 32);
            $table->text('code_verifier')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_states');
    }
};
