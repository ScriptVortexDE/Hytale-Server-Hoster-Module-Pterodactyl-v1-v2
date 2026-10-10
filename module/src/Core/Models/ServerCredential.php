<?php

namespace HytaleHosting\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Broker credentials and session bookkeeping for one Pterodactyl server.
 * Only a SHA-256 hash of the broker token is stored here.
 *
 * @property int $id
 * @property string $server_uuid
 * @property string $token_hash
 * @property ?\Illuminate\Support\Carbon $token_rotated_at
 * @property ?int $account_id
 * @property ?string $last_session_token
 * @property ?\Illuminate\Support\Carbon $last_session_expires_at
 * @property ?\Illuminate\Support\Carbon $last_issued_at
 * @property ?string $last_issued_ip
 * @property int $issue_count
 * @property ?\Illuminate\Support\Carbon $locked_at
 * @property ?string $locked_reason
 */
class ServerCredential extends Model
{
    protected $table = 'ext_hytale_hosting_servers';

    protected $fillable = ['server_uuid'];

    protected $hidden = ['token_hash', 'last_session_token'];

    protected $casts = [
        'last_session_token' => 'encrypted',
        'token_rotated_at' => 'datetime',
        'last_session_expires_at' => 'datetime',
        'last_issued_at' => 'datetime',
        'locked_at' => 'datetime',
        'issue_count' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function matchesToken(string $token): bool
    {
        return hash_equals($this->token_hash, self::hashToken($token));
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
