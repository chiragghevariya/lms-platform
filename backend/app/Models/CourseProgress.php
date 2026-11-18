<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $course_id
 * @property int $percent
 * @property int $completed_lessons
 * @property int $total_lessons
 * @property int|null $last_lesson_id
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $completed_at
 * @property Course|null $course
 * @property Lesson|null $lastLesson
 */
class CourseProgress extends Model
{
    protected $table = 'course_progress';

    protected $fillable = ['user_id', 'course_id', 'percent', 'completed_lessons', 'total_lessons', 'last_lesson_id', 'last_activity_at', 'completed_at'];

    protected $attributes = ['percent' => 0, 'completed_lessons' => 0, 'total_lessons' => 0, 'last_lesson_id' => null, 'last_activity_at' => null, 'completed_at' => null];

    protected function casts(): array
    {
        return ['percent' => 'integer', 'completed_lessons' => 'integer', 'total_lessons' => 'integer', 'last_activity_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lastLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'last_lesson_id');
    }
}
