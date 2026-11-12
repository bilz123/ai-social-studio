<?php

namespace App\Models;

use Database\Factories\SocialTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialToken extends Model
{
    /** @use HasFactory<SocialTokenFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'token_kind', 'access_token', 'refresh_token', 'token_type', 'expires_at', 'scopes', 'provider_user_id',
    ];

    /**
     * Credentials never appear in JSON/array output, even by accident (logs, error pages, API
     * resources that forget to filter).
     *
     * @var list<string>
     */
    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            // Encrypted at rest with APP_KEY (AES-256-CBC + HMAC); the DB only ever sees ciphertext.
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'last_validated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
