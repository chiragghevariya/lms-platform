<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $course_id
 * @property string $title
 * @property int $sort
 * @property Course $course
 */
class CourseSection extends Model
{
    use HasPublicUuid;

    protected $fillable = ['course_id', 'title', 'sort'];

    protected $attributes = ['sort' => 0];

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<Lesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'section_id')->orderBy('sort')->orderBy('id');
    }
}
