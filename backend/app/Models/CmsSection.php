<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property array<string, mixed> $content
 * @property bool $is_visible
 * @property int $sort
 */
class CmsSection extends Model
{
    use Audited;

    protected $fillable = ['key', 'content', 'is_visible', 'sort'];

    protected $attributes = ['is_visible' => true, 'sort' => 0];

    protected function casts(): array
    {
        return ['content' => 'array', 'is_visible' => 'boolean', 'sort' => 'integer'];
    }
}
