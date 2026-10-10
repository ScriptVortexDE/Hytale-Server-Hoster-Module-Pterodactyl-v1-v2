<?php

namespace HytaleHosting\Core\Models;

use HytaleHosting\Core\Hytale\HytaleEndpoints;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A Hytale provider account authorised through the OAuth device flow.
 *
 * @property int $id
 * @property string $name
 * @property bool $enabled
 * @property ?string $owner_uuid
 * @property ?string $profile_uuid
 * @property ?string $profile_username
 * @property ?array $profiles
 * @property ?string $access_token
 * @property ?\Illuminate\Support\Carbon $access_token_expires_at
 * @property ?string $refresh_token
 * @property ?\Illuminate\Support\Carbon $refresh_token_obtained_at
 * @property bool $needs_reauth
 * @property ?\Illuminate\Support\Carbon $limited_until
 * @property ?string $last_error
 * @property ?\Illuminate\Support\Carbon $last_error_at
 * @property int $sessions_issued
 * @property ?\Illuminate\Support\Carbon $last_used_at
 */
class Account extends Model
{
    protected $table = 'ext_hytale_hosting_accounts';

    protected $fillable = ['name', 'enabled', 'owner_uuid', 'profile_uuid', 'profile_username', 'profiles'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'enabled' => 'boolean',
        'needs_reauth' => 'boolean',
        'profiles' => 'array',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'access_token_expires_at' => 'datetime',
        'refresh_token_obtained_at' => 'datetime',
        'limited_until' => 'datetime',
        'last_error_at' => 'datetime',
        'last_used_at' => 'datetime',
        'sessions_issued' => 'integer',
    ];

    /**
     * Accounts that may currently be asked for a game session.
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('enabled', true)
            ->where('needs_reauth', false)
            ->whereNotNull('refresh_token')
            ->whereNotNull('profile_uuid')
            ->where(fn (Builder $q) => $q->whereNull('limited_until')->orWhere('limited_until', '<', now()));
    }

    public function recordError(string $message): void
    {
        $this->forceFill(['last_error' => mb_substr($message, 0, 255), 'last_error_at' => now()])->save();
    }

    public function refreshTokenExpiresAt(): ?Carbon
    {
        return $this->refresh_token_obtained_at?->copy()->addDays(HytaleEndpoints::REFRESH_TOKEN_TTL_DAYS);
    }
}
