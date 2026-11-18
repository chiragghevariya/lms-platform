<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $course_id
 * @property int|null $lesson_id
 * @property int $user_id
 * @property string $title
 * @property string $body
 * @property int $answers_count
 * @property Carbon|null $instructor_answered_at
 * @property Carbon|null $created_at
 * @property Course $course
 * @property Lesson|null $lesson
 * @property User $user
 */
class CourseQuestion extends Model
{
    use HasPublicUuid, SoftDeletes;

    protected $fillable = ['course_id', 'lesson_id', 'user_id', 'title', 'body'];

    protected $attributes = ['answers_count' => 0];

    protected function casts(): array
    {
        return ['instructor_answered_at' => 'datetime'];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CourseAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(CourseAnswer::class, 'question_id');
    }
}
