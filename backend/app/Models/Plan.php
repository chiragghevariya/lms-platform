<?php

namespace App\Models;

use App\Enums\MembershipTier;
use App\Models\Concerns\Audited;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property int $tier_level
 * @property string|null $description
 * @property list<array{key: string, label: string, value: bool|string}>|null $features
 * @property bool $is_active
 * @property int $sort
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use Audited, HasFactory, HasPublicUuid, SoftDeletes;

    protected $fillable = ['name', 'slug', 'tier_level', 'description', 'features', 'is_active', 'sort'];

    protected $attributes = ['description' => null, 'features' => null, 'is_active' => true, 'sort' => 0];

    protected function casts(): array
    {
        return ['tier_level' => 'integer', 'features' => 'array', 'is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function tier(): MembershipTier
    {
        return MembershipTier::from($this->tier_level);
    }

    /** @return HasMany<PlanPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /** @return BelongsToMany<Coupon, $this> */
    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class);
    }

    /**
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
