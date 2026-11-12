<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicId, Notifiable;

    /**
     * Mass assignment allow-list. Everything security relevant (verification state, roles, ids,
     * login timestamps) is deliberately absent and must be set explicitly in code.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'password'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /** @var array<int, string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Emails are case-insensitive identifiers: store them trimmed and lower-cased. */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value): string => mb_strtolower(trim($value)));
    }

    // --- Authorization ---------------------------------------------------------------------

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function hasRole(string $name): bool
    {
        return $this->roles()->where('roles.name', $name)->exists();
    }

    public function assignRole(string $name): void
    {
        $role = Role::query()->where('name', $name)->firstOrFail();
        $this->roles()->syncWithoutDetaching([$role->getKey()]);
        $this->permissionCache = null;
    }

    /** @return array<int, string> */
    public function permissionNames(): array
    {
        return $this->permissionCache ??= Permission::query()
            ->whereHas('roles.users', fn ($query) => $query->whereKey($this->getKey()))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    public function hasPermission(string $name): bool
    {
        return in_array($name, $this->permissionNames(), true);
    }

    // --- Owned records ---------------------------------------------------------------------

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return HasMany<VideoGenerationJob, $this> */
    public function videoGenerationJobs(): HasMany
    {
        return $this->hasMany(VideoGenerationJob::class);
    }

    /** @return HasMany<GeneratedVideo, $this> */
    public function generatedVideos(): HasMany
    {
        return $this->hasMany(GeneratedVideo::class);
    }

    /** @return HasMany<PublishingJob, $this> */
    public function publishingJobs(): HasMany
    {
        return $this->hasMany(PublishingJob::class);
    }

    /** @return HasMany<PublishedPost, $this> */
    public function publishedPosts(): HasMany
    {
        return $this->hasMany(PublishedPost::class);
    }

    /** @return HasMany<ScheduledPost, $this> */
    public function scheduledPosts(): HasMany
    {
        return $this->hasMany(ScheduledPost::class);
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    // --- Mail ------------------------------------------------------------------------------

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /** @param string $token */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
