<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $course_id
 * @property int $user_id
 * @property int $rating
 * @property string|null $comment
 * @property Carbon|null $hidden_at
 * @property int|null $hidden_by
 * @property string|null $hidden_reason
 * @property Carbon $created_at
 * @property Course $course
 * @property User $user
 */
class CourseReview extends Model
{
    use HasPublicUuid;

    protected $fillable = ['course_id', 'user_id', 'rating', 'comment'];

    protected $attributes = ['hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'hidden_at' => 'datetime'];
    }

    /**
     * Hidden reviews stay in the database (for the audit trail) but are excluded everywhere public.
     *
     * @param  Builder<CourseReview>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
