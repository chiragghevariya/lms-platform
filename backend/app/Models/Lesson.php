<?php

namespace App\Models;

use App\Contracts\GatedContent;
use App\Enums\LessonType;
use App\Enums\MembershipTier;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $uuid
 * @property int $course_id
 * @property int $section_id
 * @property string $title
 * @property LessonType $type
 * @property string|null $content
 * @property int|null $video_id
 * @property string|null $attachment_path
 * @property int $duration_seconds
 * @property bool $is_preview
 * @property bool $is_optional
 * @property int $sort
 * @property int $drip_days
 * @property Course $course
 * @property CourseSection $section
 * @property Video|null $video
 */
class Lesson extends Model implements GatedContent
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory, HasPublicUuid;

    protected $fillable = ['course_id', 'section_id', 'title', 'type', 'content', 'video_id', 'attachment_path', 'duration_seconds', 'is_preview', 'is_optional', 'sort', 'drip_days'];

    protected $attributes = ['content' => null, 'video_id' => null, 'attachment_path' => null, 'duration_seconds' => 0, 'is_preview' => false, 'sort' => 0, 'drip_days' => 0];

    protected function casts(): array
    {
        return [
            'type' => LessonType::class,
            'duration_seconds' => 'integer',
            'is_preview' => 'boolean',
            'is_optional' => 'boolean',
            'sort' => 'integer',
            'drip_days' => 'integer',
        ];
    }

    /** Preview lessons are open; others inherit the course tier. */
    public function requiredTier(): ?MembershipTier
    {
        return $this->is_preview ? null : $this->course->requiredTier();
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<CourseSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }

    /** @return BelongsTo<Video, $this> */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    /** @return HasOne<Quiz, $this> */
    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class);
    }

    /** @return HasOne<Assignment, $this> */
    public function assignment(): HasOne
    {
        return $this->hasOne(Assignment::class);
    }

    /** @return HasMany<LessonResource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(LessonResource::class);
    }
}
