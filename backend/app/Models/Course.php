<?php

namespace App\Models;

use App\Contracts\GatedContent;
use App\Enums\CourseLevel;
use App\Enums\CourseStatus;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\HasRequiredTier;
use App\Support\Money;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property string $uuid
 * @property int $instructor_id
 * @property int|null $category_id
 * @property string $title
 * @property string $slug
 * @property string|null $subtitle
 * @property string|null $description
 * @property CourseLevel $level
 * @property string $language
 * @property string|null $thumbnail_path
 * @property array<int|string, string>|null $thumbnail_variants width => path (WebP)
 * @property int|null $promo_video_id
 * @property int|null $required_tier
 * @property CourseStatus $status
 * @property bool $is_featured
 * @property int $duration_minutes
 * @property int $lessons_count
 * @property int|null $price_amount
 * @property string|null $currency
 * @property string $rating_avg
 * @property int $rating_count
 * @property int $enrollments_count
 * @property string|null $review_notes
 * @property Carbon|null $submitted_at
 * @property Carbon|null $published_at
 * @property User $instructor
 * @property Category|null $category
 * @property Video|null $promoVideo
 */
class Course extends Model implements GatedContent
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory, HasPublicUuid, HasRequiredTier, Searchable, SoftDeletes;

    protected $fillable = [
        'instructor_id', 'category_id', 'title', 'slug', 'subtitle', 'description', 'level', 'language',
        'thumbnail_path', 'promo_video_id', 'required_tier', 'is_featured', 'price_amount', 'currency',
    ];

    protected $attributes = [
        'category_id' => null, 'subtitle' => null, 'description' => null, 'level' => 'all_levels', 'language' => 'en',
        'thumbnail_path' => null, 'thumbnail_variants' => null, 'promo_video_id' => null, 'required_tier' => null, 'status' => 'draft',
        'is_featured' => false, 'duration_minutes' => 0, 'lessons_count' => 0, 'price_amount' => null, 'currency' => null,
        'rating_avg' => '0', 'rating_count' => 0, 'enrollments_count' => 0, 'review_notes' => null,
        'submitted_at' => null, 'published_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'thumbnail_variants' => 'array',
            'level' => CourseLevel::class,
            'status' => CourseStatus::class,
            'required_tier' => 'integer',
            'is_featured' => 'boolean',
            'duration_minutes' => 'integer',
            'lessons_count' => 'integer',
            'price_amount' => 'integer',
            'rating_avg' => 'decimal:2',
            'rating_count' => 'integer',
            'enrollments_count' => 'integer',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<User, $this> */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /**
     * Co-instructors.
     *
     * @return BelongsToMany<User, $this>
     */
    public function coInstructors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_instructors')->withTimestamps();
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** @return BelongsTo<Video, $this> */
    public function promoVideo(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'promo_video_id');
    }

    /** @return HasMany<CourseSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<Lesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /** @return HasMany<CourseReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(CourseReview::class);
    }

    /** Recomputes the cached rating from reviews that aren't hidden by moderation. */
    public function refreshRating(): void
    {
        $this->forceFill([
            'rating_avg' => round((float) $this->reviews()->visible()->avg('rating'), 2),
            'rating_count' => $this->reviews()->visible()->count(),
        ])->save();
    }

    /** @return HasMany<CoursePurchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(CoursePurchase::class);
    }

    /**
     * @param  Builder<Course>  $query
     * @return Builder<Course>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', CourseStatus::Published)->whereNotNull('published_at');
    }

    public function isPublished(): bool
    {
        return $this->status === CourseStatus::Published;
    }

    /** Owner or co-instructor. */
    public function isTaughtBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->instructor_id === $user->id || $this->coInstructors()->whereKey($user->id)->exists();
    }

    public function price(): ?Money
    {
        return $this->price_amount !== null && $this->currency ? Money::of($this->price_amount, $this->currency) : null;
    }

    public function thumbnailUrl(): ?string
    {
        return $this->thumbnail_path ? Storage::disk((string) config('video.thumbnails_disk'))->url($this->thumbnail_path) : null;
    }

    /** "url 480w, url 960w, …" for <img srcset>, or null when no variants exist. */
    public function thumbnailSrcset(): ?string
    {
        if (! $this->thumbnail_variants) {
            return null;
        }
        $disk = Storage::disk((string) config('video.thumbnails_disk'));

        return collect($this->thumbnail_variants)->map(fn ($path, $w) => $disk->url($path).' '.$w.'w')->implode(', ');
    }

    /** Recomputes cached totals after curriculum edits. */
    public function refreshStats(): void
    {
        $this->forceFill([
            'lessons_count' => $this->lessons()->count(),
            'duration_minutes' => (int) ceil($this->lessons()->sum('duration_seconds') / 60),
        ])->save();
    }

    // ---- Scout (Meilisearch in production) -----------------------------

    public function shouldBeSearchable(): bool
    {
        return $this->isPublished();
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        $this->loadMissing('instructor', 'category', 'tags');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'description' => strip_tags((string) $this->description),
            'instructor' => $this->instructor->name,
            'category' => $this->category?->slug,
            'tags' => $this->tags->pluck('name')->all(),
            'level' => $this->level->value,
            'required_tier' => $this->required_tier ?? 0,
            'duration_minutes' => $this->duration_minutes,
            'rating_avg' => (float) $this->rating_avg,
            'published_at' => $this->published_at?->getTimestamp(),
        ];
    }
}
