<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $message
 * @property string|null $link_text
 * @property string|null $link_url
 * @property string $variant info|promo|warning
 * @property string $audience all|guests|members|free
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 */
class Banner extends Model
{
    use Audited, HasPublicUuid;

    protected $fillable = ['message', 'link_text', 'link_url', 'variant', 'audience', 'starts_at', 'ends_at', 'is_active'];

    protected $attributes = [
        'link_text' => null, 'link_url' => null, 'variant' => 'info', 'audience' => 'all',
        'starts_at' => null, 'ends_at' => null, 'is_active' => true,
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    }

    /** @param Builder<Banner> $query */
    public function scopeLive(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
