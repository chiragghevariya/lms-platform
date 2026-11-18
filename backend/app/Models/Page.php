<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string $body
 * @property bool $is_published
 * @property int|null $updated_by
 * @property Carbon $updated_at
 */
class Page extends Model
{
    use Audited;

    protected $fillable = ['slug', 'title', 'body', 'is_published', 'updated_by'];

    protected $attributes = ['is_published' => true, 'updated_by' => null];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    /** @return list<string> */
    protected function auditExcept(): array
    {
        return ['body', 'updated_by'];
    }
}
