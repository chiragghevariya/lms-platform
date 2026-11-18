<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $sort
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use Audited, HasFactory, HasPublicUuid;

    protected $fillable = ['parent_id', 'name', 'slug', 'description', 'sort'];

    protected $attributes = ['parent_id' => null, 'description' => null, 'sort' => 0];

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return HasMany<Course, $this> */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
