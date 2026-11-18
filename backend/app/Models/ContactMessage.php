<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $subject
 * @property string $message
 * @property string|null $ip_address
 * @property string $status
 */
class ContactMessage extends Model
{
    use HasPublicUuid, SoftDeletes;

    protected $fillable = ['user_id', 'name', 'email', 'phone', 'subject', 'message', 'ip_address'];

    protected $attributes = ['status' => 'new', 'user_id' => null, 'phone' => null, 'ip_address' => null];
}
