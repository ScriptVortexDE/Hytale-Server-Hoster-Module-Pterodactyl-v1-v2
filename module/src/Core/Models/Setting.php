<?php

namespace HytaleHosting\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property ?string $value
 */
class Setting extends Model
{
    protected $table = 'ext_hytale_hosting_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];
}
