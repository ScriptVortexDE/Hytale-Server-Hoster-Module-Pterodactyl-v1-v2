<?php

namespace HytaleHosting\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property ?string $server_uuid
 * @property ?int $account_id
 * @property string $event
 * @property string $outcome
 * @property ?string $ip
 * @property ?string $message
 * @property \Illuminate\Support\Carbon $created_at
 */
class AuditEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ext_hytale_hosting_audit';

    protected $fillable = ['server_uuid', 'account_id', 'event', 'outcome', 'ip', 'message'];

    protected $casts = ['created_at' => 'datetime'];
}
