<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $role
 * @property string $quote
 * @property int|null $rating
 * @property string|null $avatar_url
 * @property bool $is_published
 * @property int $sort
 */
class Testimonial extends Model
{
    use Audited, HasPublicUuid;

    protected $fillable = ['name', 'role', 'quote', 'rating', 'avatar_url', 'is_published', 'sort'];

    protected $attributes = ['role' => null, 'rating' => null, 'avatar_url' => null, 'is_published' => true, 'sort' => 0];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'is_published' => 'boolean', 'sort' => 'integer'];
    }
}
