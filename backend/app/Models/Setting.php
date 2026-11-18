<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $key
 * @property string|null $value JSON-encoded (encrypted when secret)
 * @property bool $is_secret
 * @property int|null $updated_by
 * @property Carbon|null $updated_at
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'is_secret', 'updated_by'];

    protected $attributes = ['value' => null, 'is_secret' => false, 'updated_by' => null];

    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }
}
