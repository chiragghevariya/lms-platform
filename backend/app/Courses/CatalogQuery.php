<?php

namespace App\Courses;

use App\Models\Course;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Catalogue filtering/sorting. Full-text matching goes through Scout (Meilisearch in production);
 * the matching ids are then filtered/sorted/paginated in SQL so every filter works the same way.
 */
class CatalogQuery
{
    /**
     * @param  array{q?: string|null, category?: string|null, level?: list<string>|null, tier?: list<string>|null,
     *   duration?: list<string>|null, rating?: int|float|null, featured?: bool|null, sort?: string|null, per_page?: int|null}  $f
     * @return LengthAwarePaginator<int, Course>
     */
    public function paginate(array $f): LengthAwarePaginator
    {
        $query = Course::published()->with(['instructor', 'category']);
        $ids = null;

        if (! empty($f['q'])) {
            $ids = Course::search((string) $f['q'])->take(1000)->keys()->map(fn ($k) => (int) $k)->all();
            $query->whereIn('courses.id', $ids ?: [0]);
        }

        $query
            ->when($f['category'] ?? null, fn (Builder $q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->when($f['level'] ?? null, fn (Builder $q, $levels) => $q->whereIn('level', $levels))
            ->when($f['tier'] ?? null, function (Builder $q, array $tiers) {
                $levels = array_values(array_filter(array_map(fn ($t) => $t === 'free' ? null : (int) $t, $tiers)));
                $q->where(fn ($w) => $w->whereIn('required_tier', $levels ?: [-1])->when(in_array('free', $tiers, true), fn ($w) => $w->orWhereNull('required_tier')));
            })
            ->when($f['duration'] ?? null, fn (Builder $q, array $buckets) => $q->where(function ($w) use ($buckets) {
                foreach ($buckets as $b) {
                    match ($b) {
                        'short' => $w->orWhere('duration_minutes', '<', 60),
                        'medium' => $w->orWhereBetween('duration_minutes', [60, 300]),
                        'long' => $w->orWhere('duration_minutes', '>', 300),
                        default => null,
                    };
                }
            }))
            ->when($f['rating'] ?? null, fn (Builder $q, $min) => $q->where('rating_avg', '>=', $min))
            ->when($f['featured'] ?? null, fn (Builder $q) => $q->where('is_featured', true));

        $sort = $f['sort'] ?? ($ids !== null ? 'relevance' : 'popular');
        match ($sort) {
            'newest' => $query->orderByDesc('published_at'),
            'rating' => $query->orderByDesc('rating_avg')->orderByDesc('rating_count'),
            'duration_asc' => $query->orderBy('duration_minutes'),
            'duration_desc' => $query->orderByDesc('duration_minutes'),
            'relevance' => $ids
                ? $query->orderByRaw('CASE courses.id '.collect($ids)->map(fn ($id, $i) => 'WHEN '.(int) $id.' THEN '.(int) $i)->implode(' ').' END')
                : $query->orderByDesc('enrollments_count'),
            default => $query->orderByDesc('is_featured')->orderByDesc('enrollments_count')->orderByDesc('rating_avg'),
        };

        return $query->orderByDesc('courses.id')->paginate(min(48, (int) ($f['per_page'] ?? 12)))->withQueryString();
    }
}
