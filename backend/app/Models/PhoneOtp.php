<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $phone
 * @property string $purpose
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
class PhoneOtp extends Model
{
    protected $fillable = ['user_id', 'phone', 'purpose', 'code_hash', 'expires_at'];

    protected $hidden = ['code_hash'];

    protected $attributes = ['attempts' => 0, 'consumed_at' => null, 'purpose' => 'verify_phone'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'attempts' => 'integer'];
    }
}
