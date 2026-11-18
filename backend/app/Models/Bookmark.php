<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $course_id
 * @property int|null $lesson_id
 * @property int|null $position_seconds
 * @property string|null $label
 * @property Course|null $course
 * @property Lesson|null $lesson
 */
class Bookmark extends Model
{
    use HasPublicUuid;

    protected $fillable = ['user_id', 'course_id', 'lesson_id', 'position_seconds', 'label'];

    protected $attributes = ['lesson_id' => null, 'position_seconds' => null, 'label' => null];

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
}
