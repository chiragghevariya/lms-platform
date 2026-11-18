<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $title
 * @property string|null $excerpt
 * @property string $body
 * @property string|null $cover_url
 * @property string $status draft|published
 * @property Carbon|null $published_at
 * @property int|null $author_id
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property User|null $author
 */
class Post extends Model
{
    use Audited, HasPublicUuid, SoftDeletes;

    protected $fillable = ['slug', 'title', 'excerpt', 'body', 'cover_url', 'status', 'published_at', 'author_id', 'seo_title', 'seo_description'];

    protected $attributes = [
        'excerpt' => null, 'cover_url' => null, 'status' => 'draft', 'published_at' => null,
        'author_id' => null, 'seo_title' => null, 'seo_description' => null,
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /** @return list<string> */
    protected function auditExcept(): array
    {
        return ['body']; // long HTML; the log records that it changed via updated_at
    }

    /** @param Builder<Post> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', 'published')->where('published_at', '<=', now());
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
