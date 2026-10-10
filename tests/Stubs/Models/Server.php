<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Minimal stand-in for the Panel's Server model (same table and helpers the
 * module uses in 1.x and 2.0).
 */
class Server extends Model
{
    public const STATUS_INSTALLING = 'installing';
    public const STATUS_INSTALL_FAILED = 'install_failed';
    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'servers';

    protected $guarded = [];

    public function isInstalled(): bool
    {
        return $this->status !== self::STATUS_INSTALLING && $this->status !== self::STATUS_INSTALL_FAILED;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function transfer(): HasOne
    {
        return $this->hasOne(ServerTransfer::class);
    }
}
